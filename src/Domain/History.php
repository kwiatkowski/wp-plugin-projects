<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * What happened to the projects over time – two tables of their own (a check every 5 minutes is too much for post meta):
 *
 *  - checks: every look at a home page / staging / another domain of the site (target: its host) – its HTTP code (0 – no answer, `error` why), the time it took;
 *    the availability and the response times come from it
 *  - events: what changed – the status (down, back), what a report brought (versions, plugins, users), updates run
 *    from the list, alerts sent
 *
 * Both are kept KEEP_DAYS days.
 */
final class History
{
    private const VERSION = '2';
    private const VERSION_OPTION = 'wp_plugin_projects_db_version';
    public const KEEP_DAYS = 90;

    /** home pages: up when they answer as the status takes it (2xx, 3xx, 401 – behind a password); stagings: 200 */
    private const UP_HOME = '(code BETWEEN 200 AND 399 OR code = 401)';
    private const UP_STAGING = 'code = 200';

    public static function checksTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'projects_checks';
    }

    public static function eventsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'projects_events';
    }

    /** Creates or updates the tables when the plugin's schema changed. */
    public static function install(): void
    {
        global $wpdb;

        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $checks = self::checksTable();
        $events = self::eventsTable();

        dbDelta("CREATE TABLE $checks (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            target varchar(100) NOT NULL,
            checked_at datetime NOT NULL,
            code smallint(5) unsigned NOT NULL,
            ms int(10) unsigned DEFAULT NULL,
            error varchar(16) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY project_target_time (project_id, target, checked_at),
            KEY checked_at (checked_at)
        ) $charset;");

        dbDelta("CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            type varchar(32) NOT NULL,
            created_at datetime NOT NULL,
            data longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY project_time (project_id, created_at),
            KEY type_time (type, created_at)
        ) $charset;");

        update_option(self::VERSION_OPTION, self::VERSION, true);
    }

    /** One look at a home page (target "home"), a staging ("staging") or another domain of the site (its host). */
    public function check(int $projectId, string $target, int $code, ?int $ms, ?string $error): void
    {
        global $wpdb;

        $wpdb->insert(self::checksTable(), [
            'project_id' => $projectId,
            'target' => $target,
            'checked_at' => gmdate('Y-m-d H:i:s'),
            'code' => $code,
            'ms' => $ms,
            'error' => $error,
        ], ['%d', '%s', '%s', '%d', '%d', '%s']);
    }

    /**
     * Something that happened to a project – `type`: status, change (what a report brought), update, alert, mute.
     *
     * @param array<string, mixed> $data
     */
    public function event(int $projectId, string $type, array $data): void
    {
        global $wpdb;

        $wpdb->insert(self::eventsTable(), [
            'project_id' => $projectId,
            'type' => $type,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'data' => (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], ['%d', '%s', '%s', '%s']);
    }

    /**
     * The last checks of a project's target, newest first – how many failed in a row (the alerts wait for a few).
     */
    public function failedInRow(int $projectId, string $target, int $limit = 10): int
    {
        global $wpdb;

        $up = $target === 'staging' ? self::UP_STAGING : self::UP_HOME;
        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT ' . $up . ' FROM ' . self::checksTable() . ' WHERE project_id = %d AND target = %s ORDER BY checked_at DESC, id DESC LIMIT %d',
            $projectId,
            $target,
            $limit,
        ));
        $count = 0;

        foreach ($rows as $isUp) {
            if ((int) $isUp === 1) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * The last check of a project's target – its code and why it failed.
     *
     * @return array{code: int, error: string|null}|null
     */
    public function lastCheck(int $projectId, string $target): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT code, error FROM ' . self::checksTable() . ' WHERE project_id = %d AND target = %s ORDER BY checked_at DESC, id DESC LIMIT 1',
            $projectId,
            $target,
        ), ARRAY_A);

        return $row ? ['code' => (int) $row['code'], 'error' => $row['error']] : null;
    }

    /**
     * Every project's home page over the last 30 days, for the list: availability in % (null – never checked) and the
     * mean response time of the last 24 hours, in ms.
     *
     * @return array<int, array{uptime: float|null, ms: int|null}>
     */
    public function summaries(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT project_id, COUNT(*) AS total, SUM(' . self::UP_HOME . ') AS up,
                AVG(CASE WHEN checked_at >= %s AND ' . self::UP_HOME . ' THEN ms END) AS ms
             FROM ' . self::checksTable() . ' WHERE target = %s AND checked_at >= %s GROUP BY project_id',
            gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
            'home',
            gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS),
        ), ARRAY_A);
        $summaries = [];

        foreach ($rows as $row) {
            $summaries[(int) $row['project_id']] = [
                'uptime' => (int) $row['total'] > 0 ? round(100 * (int) $row['up'] / (int) $row['total'], 2) : null,
                'ms' => $row['ms'] !== null ? (int) round((float) $row['ms']) : null,
            ];
        }

        return $summaries;
    }

    /**
     * One project's history for its dialog: per target (the home page, the staging, the site's other domains – by
     * their host) the availability over 24 hours, 7 and 30 days, the mean
     * response time, the last day's checks (for a chart) and the outages of 30 days; and the events, newest first.
     *
     * @return array<string, mixed>
     */
    public function project(int $projectId, bool $home = true, array $domains = []): array
    {
        $targets = [];
        $all = ['home' => self::UP_HOME, 'staging' => self::UP_STAGING] + array_fill_keys($domains, self::UP_HOME);

        foreach ($all as $target => $up) {
            // a site in progress or suspended – its home pages aren't watched, their old checks aren't shown either
            if ($target !== 'staging' && !$home) {
                continue;
            }

            $checks = $this->checks($projectId, $target, $up, 30 * DAY_IN_SECONDS);

            if (!$checks) {
                continue;
            }

            $targets[$target] = [
                'uptime' => [
                    'day' => $this->uptime($checks, DAY_IN_SECONDS),
                    'week' => $this->uptime($checks, WEEK_IN_SECONDS),
                    'month' => $this->uptime($checks, 30 * DAY_IN_SECONDS),
                ],
                'ms' => $this->meanMs($checks, DAY_IN_SECONDS),
                'msWeek' => $this->meanMs($checks, WEEK_IN_SECONDS),
                'recent' => array_values(array_map(
                    static fn (array $check): array => ['at' => $check['at'], 'up' => $check['up'], 'code' => $check['code'], 'ms' => $check['ms']],
                    array_filter($checks, static fn (array $check): bool => $check['time'] >= time() - DAY_IN_SECONDS),
                )),
                'outages' => $this->outages($checks),
            ];
        }

        return ['targets' => $targets, 'events' => $this->events($projectId)];
    }

    /**
     * The events of every project since a time, oldest first – the weekly summary.
     *
     * @return array<int, array{projectId: int, type: string, at: string, data: array<string, mixed>}>
     */
    public function eventsSince(int $since, ?string $type = null): array
    {
        global $wpdb;

        $sql = 'SELECT project_id, type, created_at, data FROM ' . self::eventsTable() . ' WHERE created_at >= %s';
        $args = [gmdate('Y-m-d H:i:s', $since)];

        if ($type !== null) {
            $sql .= ' AND type = %s';
            $args[] = $type;
        }

        return array_map([self::class, 'eventRow'], (array) $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY created_at, id', ...$args), ARRAY_A));
    }

    /**
     * The outages of every project's home page – and its other domains – since a time, per target: the weekly summary.
     *
     * @return array<int, array<string, array<int, array{from: string, to: string|null, minutes: int, code: int, error: string|null}>>>
     */
    public function outagesSince(int $since): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT DISTINCT project_id, target FROM ' . self::checksTable() . ' WHERE target <> %s AND checked_at >= %s AND NOT ' . self::UP_HOME,
            'staging',
            gmdate('Y-m-d H:i:s', $since),
        ), ARRAY_A);
        $outages = [];

        foreach ($rows as $row) {
            $found = $this->outages($this->checks((int) $row['project_id'], (string) $row['target'], self::UP_HOME, time() - $since));

            if ($found) {
                $outages[(int) $row['project_id']][(string) $row['target']] = $found;
            }
        }

        return $outages;
    }

    /** Drops what is older than KEEP_DAYS – once a day is enough. */
    public function prune(): void
    {
        global $wpdb;

        $before = gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::checksTable() . ' WHERE checked_at < %s', $before));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::eventsTable() . ' WHERE created_at < %s', $before));
    }

    /**
     * @return array<int, array{time: int, at: string, up: bool, code: int, ms: int|null, error: string|null}>
     */
    private function checks(int $projectId, string $target, string $up, int $seconds): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT checked_at, code, ms, error, ' . $up . ' AS up FROM ' . self::checksTable() . '
             WHERE project_id = %d AND target = %s AND checked_at >= %s ORDER BY checked_at, id',
            $projectId,
            $target,
            gmdate('Y-m-d H:i:s', time() - $seconds),
        ), ARRAY_A);

        return array_map(static function (array $row): array {
            $time = (int) strtotime($row['checked_at'] . ' UTC');

            return [
                'time' => $time,
                'at' => gmdate(DATE_ATOM, $time),
                'up' => (int) $row['up'] === 1,
                'code' => (int) $row['code'],
                'ms' => $row['ms'] !== null ? (int) $row['ms'] : null,
                'error' => $row['error'],
            ];
        }, $rows);
    }

    /** @param array<int, array{time: int, up: bool}> $checks */
    private function uptime(array $checks, int $seconds): ?float
    {
        $window = array_filter($checks, static fn (array $check): bool => $check['time'] >= time() - $seconds);

        return $window ? round(100 * count(array_filter($window, static fn (array $check): bool => $check['up'])) / count($window), 2) : null;
    }

    /** @param array<int, array{time: int, up: bool, ms: int|null}> $checks */
    private function meanMs(array $checks, int $seconds): ?int
    {
        $times = array_filter(array_map(
            static fn (array $check): ?int => $check['up'] && $check['time'] >= time() - $seconds ? $check['ms'] : null,
            $checks,
        ), static fn (?int $ms): bool => $ms !== null);

        return $times ? (int) round(array_sum($times) / count($times)) : null;
    }

    /**
     * The runs of failed checks: from the first failed one to the first one up again (null – still down).
     *
     * @param array<int, array{time: int, at: string, up: bool, code: int, error: string|null}> $checks oldest first
     * @return array<int, array{from: string, to: string|null, minutes: int, code: int, error: string|null}> newest first
     */
    private function outages(array $checks): array
    {
        $outages = [];
        $open = null;

        foreach ($checks as $check) {
            if (!$check['up'] && $open === null) {
                $open = $check;
            } elseif ($check['up'] && $open !== null) {
                $outages[] = ['from' => $open['at'], 'to' => $check['at'], 'minutes' => (int) round(($check['time'] - $open['time']) / 60), 'code' => $open['code'], 'error' => $open['error']];
                $open = null;
            }
        }

        if ($open !== null) {
            $outages[] = ['from' => $open['at'], 'to' => null, 'minutes' => (int) round((time() - $open['time']) / 60), 'code' => $open['code'], 'error' => $open['error']];
        }

        return array_reverse($outages);
    }

    /**
     * @return array<int, array{type: string, at: string, data: array<string, mixed>}> newest first
     */
    private function events(int $projectId, int $limit = 200): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT project_id, type, created_at, data FROM ' . self::eventsTable() . ' WHERE project_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
            $projectId,
            $limit,
        ), ARRAY_A);

        return array_map(static fn (array $row): array => array_diff_key(self::eventRow($row), ['projectId' => true]), $rows);
    }

    /**
     * @param array<string, string> $row
     * @return array{projectId: int, type: string, at: string, data: array<string, mixed>}
     */
    private static function eventRow(array $row): array
    {
        $data = json_decode($row['data'], true);

        return [
            'projectId' => (int) $row['project_id'],
            'type' => $row['type'],
            'at' => gmdate(DATE_ATOM, (int) strtotime($row['created_at'] . ' UTC')),
            'data' => is_array($data) ? $data : [],
        ];
    }
}
