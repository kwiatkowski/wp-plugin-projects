<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The alerts – checked after every look at the sites (the cron, every 5 minutes):
 *
 *  - down: the home page failed `after` checks in a row; recovery – back up after an alert (with how long it was down)
 *  - staging: the same for the staging
 *  - ssl: the certificate ends within `days` days, or isn't valid
 *  - report: a site with a token sent nothing for `hours` hours
 *  - vulnerability: a new known vulnerability of a plugin (VulnerabilityScanner)
 *  - the weekly summary: what waits for an update, what was down, what ends, the vulnerabilities
 *
 * Each goes once (what was said is kept in STATE); all of one run go together – one message per channel. A muted
 * project (by hand, or for half an hour after an update started from the list) says nothing; what it would have said
 * comes once the mute ends, if it is still true.
 */
final class Alerts
{
    private const STATE = 'wp_plugin_projects_alert_state';
    public const META_MUTED = '_project_muted_until';
    /** an update started from the list mutes the project this long – the site may be down for a moment */
    public const UPDATE_MUTE = 30 * MINUTE_IN_SECONDS;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly History $history = new History(),
        private readonly Notifier $notifier = new Notifier(),
    ) {
    }

    public static function mutedUntil(int $id): ?int
    {
        $until = (int) get_post_meta($id, self::META_MUTED, true);

        return $until > time() ? $until : null;
    }

    /** Mutes a project for so many minutes – null unmutes it. */
    public function mute(int $id, ?int $minutes, string $why = 'ręcznie'): void
    {
        if ($minutes === null || $minutes <= 0) {
            delete_post_meta($id, self::META_MUTED);
            $this->history->event($id, 'mute', ['text' => 'Powiadomienia włączone']);

            return;
        }

        $until = time() + $minutes * MINUTE_IN_SECONDS;

        // a longer mute already set stays
        if ((self::mutedUntil($id) ?? 0) < $until) {
            update_post_meta($id, self::META_MUTED, $until);
            $this->history->event($id, 'mute', ['text' => sprintf('Powiadomienia wyciszone do %s (%s)', wp_date('Y.m.d H:i', $until), $why)]);
        }
    }

    public function run(): void
    {
        $settings = AlertSettings::get();
        $state = get_option(self::STATE, []);
        $state = is_array($state) ? $state : [];
        $lastRun = (int) ($state['lastRun'] ?? time());
        // the very first run only takes note – what is already down or ending isn't news
        $first = !isset($state['lastRun']);
        $rules = $settings['rules'];
        $alerts = [];

        foreach ($this->projects->all('admin') as $project) {
            $id = (int) $project['id'];
            $muted = $first || self::mutedUntil($id) !== null;
            $was = (array) ($state['projects'][$id] ?? []);
            $now = $was;

            // the home page: sites in progress or suspended aren't watched
            if (!empty($project['domains']) && in_array($project['status'], ['online', 'offline'], true)) {
                $this->watch($id, 'home', $project, $rules['down'], $rules['recovery'], $muted, $now, $alerts, $first);
            } else {
                unset($now['home']);
            }

            if (!empty($project['stagingUrl'])) {
                $this->watch($id, 'staging', $project, $rules['staging'], $rules['staging'], $muted, $now, $alerts, $first);
            }

            // the certificate: ending soon (by its end date) or not valid (by its error) – each said once
            $ssl = (array) ($project['ssl'] ?? []);
            $sslKey = null;

            if (!empty($ssl['error'])) {
                $sslKey = 'bad:' . $ssl['error'];
                $sslText = sprintf('certyfikat SSL nieprawidłowy – %s', $ssl['error']);
            } elseif (!empty($ssl['validTo'])) {
                $days = (int) floor((strtotime((string) $ssl['validTo']) - time()) / DAY_IN_SECONDS);

                if ($days <= $rules['ssl']['days']) {
                    $sslKey = 'soon:' . $ssl['validTo'];
                    $sslText = sprintf('certyfikat SSL wygasa za %d dni (%s)', max($days, 0), wp_date('Y.m.d', (int) strtotime((string) $ssl['validTo'])));
                }
            }

            if ($sslKey === null) {
                unset($now['ssl']);
            } elseif (($now['ssl'] ?? null) !== $sslKey && ($first || ($rules['ssl']['enabled'] && !$muted))) {
                if (!$first) {
                    $alerts[] = ['rule' => 'ssl', 'id' => $id, 'icon' => '🟠', 'text' => sprintf('%s: %s', $project['name'], $sslText ?? '')];
                }

                $now['ssl'] = $sslKey;
            }

            // the report: a site with a token that went quiet
            $reportedAt = $project['reportedAt'] ? (int) strtotime((string) $project['reportedAt']) : 0;
            $quiet = !empty($project['token']) && $reportedAt > 0 && time() - $reportedAt > $rules['report']['hours'] * HOUR_IN_SECONDS;

            if (!$quiet) {
                unset($now['report']);
            } elseif (($now['report'] ?? null) !== $reportedAt && ($first || ($rules['report']['enabled'] && !$muted))) {
                if (!$first) {
                    $alerts[] = ['rule' => 'report', 'id' => $id, 'icon' => '🟠', 'text' => sprintf('%s: brak raportu od %s', $project['name'], wp_date('Y.m.d H:i', $reportedAt))];
                }

                $now['report'] = $reportedAt;
            }

            $state['projects'][$id] = $now;
        }

        // new vulnerabilities since the last run – from the history
        if ($rules['vulnerability']['enabled']) {
            foreach ($this->history->eventsSince($lastRun, 'vulnerability') as $event) {
                if (self::mutedUntil($event['projectId']) === null) {
                    $alerts[] = ['rule' => 'vulnerability', 'id' => $event['projectId'], 'icon' => '🛡️', 'text' => sprintf('%s: %s', get_the_title($event['projectId']), (string) ($event['data']['text'] ?? ''))];
                }
            }
        }

        $this->deliver($alerts, $rules);

        if ($this->weeklyDue($settings['weekly'], (int) ($state['lastWeekly'] ?? 0))) {
            $this->weekly($settings['weekly']['channels']);
            $state['lastWeekly'] = time();
        }

        $state['lastRun'] = time();
        update_option(self::STATE, $state, false);
    }

    /**
     * A home page or staging: down after `after` failed checks in a row (said once), back up when it answers again –
     * said only when the going down was.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $down the rule for going down
     * @param array<string, mixed> $back the rule for coming back
     * @param array<string, mixed> $now the project's state, changed here
     * @param array<int, array<string, mixed>> $alerts
     */
    private function watch(int $id, string $target, array $project, array $down, array $back, bool $muted, array &$now, array &$alerts, bool $first = false): void
    {
        $failed = $this->history->failedInRow($id, $target, max((int) ($down['after'] ?? 2), 2) + 1);
        $what = $target === 'home' ? $project['name'] : $project['name'] . ' (staging)';
        $rule = $target === 'home' ? 'down' : 'staging';

        // already down at the first run – noted as said, so its coming back is
        if ($first && $failed >= (int) ($down['after'] ?? 2)) {
            $now[$target] = time();
        } elseif ($failed >= (int) ($down['after'] ?? 2) && empty($now[$target]) && $down['enabled'] && !$muted) {
            $now[$target] = time() - ($failed - 1) * 5 * MINUTE_IN_SECONDS;
            $check = $this->history->lastCheck($id, $target);
            $alerts[] = ['rule' => $rule, 'id' => $id, 'icon' => '🔴', 'text' => sprintf('%s nie działa%s', $what, $check ? ' – ' . self::reason($check['code'], $check['error']) : '')];
        } elseif ($failed === 0 && !empty($now[$target]) && !$first) {
            $minutes = (int) round((time() - (int) $now[$target]) / 60);

            if ($back['enabled'] && !$muted) {
                $alerts[] = ['rule' => $target === 'home' ? 'recovery' : 'staging', 'id' => $id, 'icon' => '🟢', 'text' => sprintf('%s znów działa – niedostępna ok. %d min', $what, max($minutes, 1))];
            }

            unset($now[$target]);
        }
    }

    /** why a staging (or a home page) didn't answer, in words – as the list shows it */
    private static function reason(int $code, ?string $error): string
    {
        if ($code === 0) {
            return ['dns' => 'nie ma takiej domeny (DNS)', 'refused' => 'serwer odrzuca połączenie', 'timeout' => 'brak odpowiedzi w 20 s', 'ssl' => 'błąd certyfikatu SSL', 'redirects' => 'pętla przekierowań'][$error ?? ''] ?? 'brak połączenia';
        }

        return "HTTP $code";
    }

    /**
     * One message per channel with all of the run's alerts – only the channels set up; each alert into the history,
     * with how its sending went.
     *
     * @param array<int, array<string, mixed>> $alerts
     * @param array<string, array<string, mixed>> $rules
     */
    private function deliver(array $alerts, array $rules): void
    {
        $sent = [];

        foreach (self::channelsOn() as $channel) {
            $mine = array_filter($alerts, static fn (array $alert): bool => in_array($channel, $rules[$alert['rule']]['channels'], true));

            if (!$mine) {
                continue;
            }

            $lines = array_map(static fn (array $alert): string => $alert['icon'] . ' ' . $alert['text'], $mine);
            $first = reset($mine);
            $subject = count($mine) === 1 ? $first['text'] : sprintf('%d powiadomień: %s', count($mine), implode(', ', array_slice(array_map(static fn (array $alert): string => (string) get_the_title($alert['id']), array_values($mine)), 0, 4)));
            $result = $this->notifier->send([$channel], '[Lista projektów] ' . $subject, implode("\n", $lines) . "\n" . self::listUrl());

            foreach (array_keys($mine) as $index) {
                $sent[$index][$channel] = $result[$channel];
            }
        }

        foreach ($alerts as $index => $alert) {
            $this->history->event($alert['id'], 'alert', ['text' => $alert['icon'] . ' ' . $alert['text'], 'sent' => $sent[$index] ?? []]);
        }
    }

    /** @return string[] the channels set up: e-mail on with a recipient, Slack on with a webhook */
    public static function channelsOn(): array
    {
        $settings = AlertSettings::get();

        return array_values(array_filter([
            !empty($settings['email']['enabled']) && $settings['email']['to'] !== '' ? 'email' : null,
            !empty($settings['slack']['enabled']) && $settings['hasSlackWebhook'] ? 'slack' : null,
        ]));
    }

    private static function listUrl(): string
    {
        return home_url('/' . trim((string) apply_filters('wp_plugin_projects/path', 'projects'), '/') . '/');
    }

    /**
     * This week's moment (the day and hour, in the site's time zone) has come, and the summary wasn't sent since.
     *
     * @param array<string, mixed> $weekly
     */
    private function weeklyDue(array $weekly, int $lastSent): bool
    {
        if (!$weekly['enabled'] || !$weekly['channels']) {
            return false;
        }

        $now = new \DateTimeImmutable('now', wp_timezone());
        $slot = $now->setISODate((int) $now->format('o'), (int) $now->format('W'), (int) $weekly['day'])->setTime((int) $weekly['hour'], 0);

        return $now >= $slot && $lastSent < $slot->getTimestamp();
    }

    /**
     * The weekly summary: what was down (with the availability), what waits for an update, the vulnerabilities, the
     * certificates that end within 30 days, the sites without a report for a day.
     *
     * @param string[] $channels
     * @return array<string, true|string>
     */
    public function weekly(array $channels): array
    {
        $since = time() - WEEK_IN_SECONDS;
        $outages = $this->history->outagesSince($since);
        $latest = \MKProjects\App\AppShell::latestWordpress();
        $sections = ['down' => [], 'updates' => [], 'vulnerabilities' => [], 'ssl' => [], 'report' => []];

        foreach ($this->projects->all('admin') as $project) {
            $id = (int) $project['id'];
            $name = (string) $project['name'];

            if (!empty($outages[$id])) {
                $minutes = array_sum(array_column($outages[$id], 'minutes'));
                $sections['down'][] = sprintf('%s – %d× niedostępna, razem %d min', $name, count($outages[$id]), $minutes);
            }

            $waiting = [];

            if ($latest && ($project['engine'] ?? '') === 'wordpress' && !empty($project['version']) && version_compare((string) $project['version'], $latest, '<')) {
                $waiting[] = sprintf('WordPress %s → %s', $project['version'], $latest);
            }

            if (!empty($project['updates'])) {
                $waiting[] = sprintf('wtyczki: %d', count((array) $project['updates']));
            }

            if (!empty($project['translationUpdates'])) {
                $waiting[] = 'tłumaczenia';
            }

            if ($waiting) {
                $sections['updates'][] = $name . ' – ' . implode(', ', $waiting);
            }

            if (!empty($project['vulnerabilities'])) {
                $sections['vulnerabilities'][] = sprintf('%s – %s', $name, implode(', ', array_unique(array_column((array) $project['vulnerabilities'], 'name'))));
            }

            $validTo = $project['ssl']['validTo'] ?? null;

            if (!empty($project['ssl']['error'])) {
                $sections['ssl'][] = sprintf('%s – certyfikat nieprawidłowy (%s)', $name, $project['ssl']['error']);
            } elseif ($validTo && strtotime((string) $validTo) - time() < 30 * DAY_IN_SECONDS) {
                $sections['ssl'][] = sprintf('%s – SSL do %s', $name, wp_date('Y.m.d', (int) strtotime((string) $validTo)));
            }

            if (!empty($project['token']) && !empty($project['reportedAt']) && time() - (int) strtotime((string) $project['reportedAt']) > DAY_IN_SECONDS) {
                $sections['report'][] = sprintf('%s – ostatni raport %s', $name, wp_date('Y.m.d H:i', (int) strtotime((string) $project['reportedAt'])));
            }
        }

        $titles = [
            'down' => 'Niedostępność w tym tygodniu',
            'updates' => 'Do aktualizacji',
            'vulnerabilities' => 'Podatności wtyczek',
            'ssl' => 'Certyfikaty SSL (30 dni)',
            'report' => 'Brak raportu ponad dobę',
        ];
        $text = "*Lista projektów – podsumowanie tygodnia*\n";
        $html = '<h2 style="font:600 18px sans-serif">Lista projektów – podsumowanie tygodnia</h2>';

        foreach ($titles as $key => $title) {
            $items = $sections[$key];
            $text .= "\n*$title*" . ($items ? "\n• " . implode("\n• ", $items) : "\n– nic") . "\n";
            $html .= '<h3 style="font:600 15px sans-serif;margin:18px 0 6px">' . esc_html($title) . '</h3>'
                . ($items ? '<ul style="font:14px sans-serif;margin:0;padding-left:18px">' . implode('', array_map(static fn (string $item): string => '<li>' . esc_html($item) . '</li>', $items)) . '</ul>' : '<p style="font:14px sans-serif;color:#777;margin:0">nic</p>');
        }

        $text .= "\n" . self::listUrl();
        $html .= '<p style="font:14px sans-serif;margin-top:20px"><a href="' . esc_url(self::listUrl()) . '">Otwórz listę projektów</a></p>';

        return $this->notifier->send($channels, '[Lista projektów] Podsumowanie tygodnia', $text, $html);
    }
}
