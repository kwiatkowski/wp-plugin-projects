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
                    $alerts[] = ['rule' => 'ssl', 'id' => $id, 'icon' => str_starts_with($sslKey, 'soon:') ? '🟠' : '🔴', 'tone' => str_starts_with($sslKey, 'soon:') ? 'warn' : 'danger', 'what' => $project['name'], 'url' => $project['url'] ?? null, 'detail' => '– ' . ($sslText ?? '')];
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
                    $alerts[] = ['rule' => 'report', 'id' => $id, 'icon' => '🟠', 'tone' => 'warn', 'what' => $project['name'], 'url' => $project['url'] ?? null, 'detail' => sprintf('– brak raportu od %s', wp_date('Y.m.d H:i', $reportedAt))];
                }

                $now['report'] = $reportedAt;
            }

            $state['projects'][$id] = $now;
        }

        // new vulnerabilities since the last run – from the history
        if ($rules['vulnerability']['enabled']) {
            foreach ($this->history->eventsSince($lastRun, 'vulnerability') as $event) {
                if (self::mutedUntil($event['projectId']) === null) {
                    $serious = in_array($event['data']['severity'] ?? null, ['h', 'c'], true);
                    $alerts[] = ['rule' => 'vulnerability', 'id' => $event['projectId'], 'icon' => '🛡️', 'tone' => $serious ? 'danger' : 'warn', 'what' => get_the_title($event['projectId']), 'url' => null, 'detail' => '– ' . (string) ($event['data']['text'] ?? '')];
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
            $alerts[] = ['rule' => $rule, 'id' => $id, 'icon' => '🔴', 'tone' => 'danger', 'what' => $what, 'url' => $project['url'] ?? null, 'detail' => sprintf('nie działa%s', $check ? ' – ' . self::reason($check['code'], $check['error']) : '')];
        } elseif ($failed === 0 && !empty($now[$target]) && !$first) {
            $minutes = (int) round((time() - (int) $now[$target]) / 60);

            if ($back['enabled'] && !$muted) {
                $alerts[] = ['rule' => $target === 'home' ? 'recovery' : 'staging', 'id' => $id, 'icon' => '🟢', 'tone' => 'ok', 'what' => $what, 'url' => $project['url'] ?? null, 'detail' => sprintf('znów działa – niedostępna ok. %d min', max($minutes, 1))];
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

            $lines = array_map(static fn (array $alert): string => $alert['icon'] . ' ' . self::text($alert), $mine);
            $first = reset($mine);
            // the subject's dot: the worst of the alerts
            $tones = array_column($mine, 'tone');
            $icon = Notifier::DOTS[in_array('danger', $tones, true) ? 'danger' : (in_array('warn', $tones, true) ? 'warn' : 'ok')];
            $subject = count($mine) === 1 ? self::text($first) : sprintf('%d powiadomień: %s', count($mine), implode(', ', array_slice(array_map(static fn (array $alert): string => (string) get_the_title($alert['id']), array_values($mine)), 0, 4)));
            $result = $this->notifier->send([$channel], $icon . ' ' . $subject, implode("\n", $lines) . "\n\n" . self::listUrl(), Notifier::layout(count($mine) === 1 ? self::text($first) : sprintf('%d powiadomień', count($mine)), self::rows(array_map(static fn (array $alert): array => ['tone' => $alert['tone'], 'name' => $alert['what'], 'url' => $alert['url'], 'detail' => $alert['detail']], $mine)), wp_date('j.m.Y, H:i')));

            foreach (array_keys($mine) as $index) {
                $sent[$index][$channel] = $result[$channel];
            }
        }

        foreach ($alerts as $index => $alert) {
            $this->history->event($alert['id'], 'alert', ['text' => $alert['icon'] . ' ' . self::text($alert), 'sent' => $sent[$index] ?? []]);
        }
    }

    /** @param array<string, mixed> $alert */
    private static function text(array $alert): string
    {
        return $alert['what'] . ' ' . $alert['detail'];
    }

    /**
     * The e-mail's rows: a dot in the tone, the site's name (a link to it), what about it.
     *
     * @param array<int, array{tone: string, name: string, url: ?string, detail: string}> $items
     */
    private static function rows(array $items): string
    {
        $rows = array_map(static function (array $item): string {
            $name = esc_html($item['name']);

            return '<tr>'
                . '<td width="22" style="padding:9px 0;border-bottom:1px solid #f0f2f1;vertical-align:top;line-height:20px">' . Notifier::dot($item['tone']) . '</td>'
                . '<td style="padding:9px 0;border-bottom:1px solid #f0f2f1;line-height:20px">'
                . ($item['url'] ? '<a href="' . esc_url($item['url']) . '" style="color:#1f2a24;font-weight:600;text-decoration:none">' . $name . '</a>' : '<strong>' . $name . '</strong>')
                . ' <span style="color:#5d6862">' . esc_html($item['detail']) . '</span></td>'
                . '</tr>';
        }, array_values($items));

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">' . implode('', $rows) . '</table>';
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
        return \MKProjects\App\AppShell::url();
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
            $item = static fn (string $tone, string $detail): array => ['tone' => $tone, 'name' => (string) $project['name'], 'url' => $project['url'] ?? null, 'detail' => $detail];

            if (!empty($outages[$id])) {
                $minutes = array_sum(array_column($outages[$id], 'minutes'));
                $sections['down'][] = $item('danger', sprintf('%d× niedostępna, razem %d min', count($outages[$id]), $minutes));
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
                $sections['updates'][] = $item('warn', implode(', ', $waiting));
            }

            if (!empty($project['vulnerabilities'])) {
                $vulnerabilities = (array) $project['vulnerabilities'];
                $serious = array_intersect(array_column($vulnerabilities, 'severity'), ['h', 'c']);
                $sections['vulnerabilities'][] = $item($serious ? 'danger' : 'warn', implode(', ', array_unique(array_column($vulnerabilities, 'name'))));
            }

            $validTo = $project['ssl']['validTo'] ?? null;

            if (!empty($project['ssl']['error'])) {
                $sections['ssl'][] = $item('danger', sprintf('certyfikat nieprawidłowy (%s)', $project['ssl']['error']));
            } elseif ($validTo && strtotime((string) $validTo) - time() < 30 * DAY_IN_SECONDS) {
                $sections['ssl'][] = $item('warn', sprintf('ważny do %s', wp_date('j.m.Y', (int) strtotime((string) $validTo))));
            }

            if (!empty($project['token']) && !empty($project['reportedAt']) && time() - (int) strtotime((string) $project['reportedAt']) > DAY_IN_SECONDS) {
                $sections['report'][] = $item('warn', sprintf('ostatni raport %s', wp_date('j.m.Y H:i', (int) strtotime((string) $project['reportedAt']))));
            }
        }

        $titles = [
            'down' => 'Niedostępność',
            'vulnerabilities' => 'Podatności wtyczek',
            'ssl' => 'Certyfikaty SSL (30 dni)',
            'updates' => 'Do aktualizacji',
            'report' => 'Brak raportu ponad dobę',
        ];
        $period = sprintf('%s – %s', wp_date('j.m', $since), wp_date('j.m.Y'));
        $tones = array_column(array_merge(...array_values($sections)), 'tone');
        $tone = in_array('danger', $tones, true) ? 'danger' : ($tones ? 'warn' : 'ok');
        $text = "*Podsumowanie tygodnia* ($period)\n";
        $html = '';
        $clean = [];

        foreach ($titles as $key => $title) {
            $items = $sections[$key];

            if (!$items) {
                $clean[] = $title;
                continue;
            }

            $text .= "\n*$title*\n" . implode("\n", array_map(static fn (array $item): string => Notifier::DOTS[$item['tone']] . ' ' . $item['name'] . ' – ' . $item['detail'], $items)) . "\n";
            $html .= '<div style="margin:20px 0 4px;font-size:15px;font-weight:600">' . esc_html($title)
                . ' <span style="display:inline-block;min-width:18px;padding:1px 7px;border-radius:10px;background:#eef1ef;color:#5d6862;font-size:12px;font-weight:600;text-align:center">' . count($items) . '</span></div>'
                . self::rows(array_map(static fn (array $item): array => ['detail' => '– ' . $item['detail']] + $item, $items));
        }

        if ($clean) {
            $text .= "\n🟢 Bez uwag: " . implode(', ', $clean) . "\n";
            $html .= '<div style="margin:22px 0 0;padding:12px 14px;background:#f1f8f4;border-radius:8px;color:#2b5a3f;font-size:13px">'
                . Notifier::dot('ok') . '&nbsp; <strong>Bez uwag:</strong> ' . esc_html(implode(' · ', $clean)) . '</div>';
        }

        $text .= "\n" . self::listUrl();

        return $this->notifier->send($channels, Notifier::DOTS[$tone] . ' Podsumowanie tygodnia · ' . $period, $text, Notifier::layout('Podsumowanie tygodnia', $html, $period));
    }
}
