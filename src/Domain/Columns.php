<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The list's columns and who sees which: the owner (admin) – all of them; a viewer (the list's own account, Viewer) and
 * a guest – what the owner chose for each (options). The choice also decides what the server sends – a hidden column's
 * data isn't in the page at all. Some columns never go to guests: access data or the owner's own work.
 */
final class Columns
{
    public const ALL = [
        'name', 'status', 'uptime', 'server', 'php', 'engine', 'theme', 'repo', 'deploy', 'bedrock', 'security',
        'local', 'stage', 'acf', 'miModal', 'todo', 'report',
    ];

    /** never for guests – the hosting and its account, the repository, the copies' addresses, Todo, the report */
    public const PRIVATE = ['server', 'repo', 'local', 'stage', 'todo', 'report'];

    /** until the owner chooses – the public list as it always was, for both */
    public const DEFAULT = ['name', 'status', 'engine', 'theme'];

    /** the fields each column shows – besides `domains` (the name links to the site) */
    private const FIELDS = [
        'name' => [],
        // the home page's status; the site's other domains – what they answered, their certificates
        'status' => ['status', 'domainChecks'],
        // the home page over 30 days (History)
        'uptime' => ['uptime', 'responseMs'],
        'server' => ['server', 'serverUrl', 'serverAccount'],
        // the version, the full one (patched or not); the limits – the tooltip
        'php' => ['php', 'phpVersion', 'phpSapi', 'phpIni'],
        'engine' => ['engine', 'version'],
        'theme' => ['theme'],
        'repo' => ['repo'],
        'deploy' => ['deploy'],
        'bedrock' => ['bedrock'],
        'security' => ['security'],
        'local' => ['localUrl', 'localAdminUrl'],
        'stage' => ['stagingUrl', 'stagingAdminUrl', 'staging'],
        'acf' => ['acf'],
        'miModal' => ['miModal'],
        'todo' => ['todo'],
        // the dot and its popup: when, what sent it, what waits, the certificate; `token` only as "there is one"
        'report' => ['reportedAt', 'reporter', 'updates', 'translationUpdates', 'ssl', 'token', 'vulnerabilities', 'missingTranslations', 'sites'],
    ];

    /** a viewer sees the theme's tooltip too (its version and author) */
    private const VIEWER_FIELDS = ['theme' => ['themeDetails', 'parentTheme']];

    public const VIEWS = ['admin', 'viewer', 'guest'];

    private const OPTIONS = ['guest' => 'wp_plugin_projects_public_columns', 'viewer' => 'wp_plugin_projects_viewer_columns'];

    /** @return string[] the columns this view shows, in the list's order; the name always */
    public static function forView(string $view): array
    {
        if ($view === 'admin') {
            return self::ALL;
        }

        $saved = get_option(self::OPTIONS[$view] ?? self::OPTIONS['guest'], null);

        return self::clean(is_array($saved) ? $saved : self::DEFAULT, $view);
    }

    /**
     * Saves the guests' and the viewers' columns. Returns them as kept.
     *
     * @param mixed[] $guest
     * @param mixed[] $viewer
     * @return array{guest: string[], viewer: string[]}
     */
    public static function save(array $guest, array $viewer): array
    {
        update_option(self::OPTIONS['guest'], self::clean($guest, 'guest'), true);
        update_option(self::OPTIONS['viewer'], self::clean($viewer, 'viewer'), true);

        return ['guest' => self::forView('guest'), 'viewer' => self::forView('viewer')];
    }

    /** @return string[] the fields a viewer or a guest gets: the domains and those of their columns */
    public static function fields(string $view): array
    {
        $fields = ['domains'];

        foreach (self::forView($view) as $column) {
            array_push($fields, ...self::FIELDS[$column] ?? [], ...($view === 'viewer' ? self::VIEWER_FIELDS[$column] ?? [] : []));
        }

        return array_values(array_unique($fields));
    }

    /**
     * Known columns only, in the list's order, the name always; for guests none of the private ones.
     *
     * @param mixed[] $columns
     * @return string[]
     */
    private static function clean(array $columns, string $view): array
    {
        $wanted = ['name', ...array_filter($columns, 'is_string')];

        return array_values(array_filter(
            self::ALL,
            static fn (string $column): bool => in_array($column, $wanted, true) && ($view !== 'guest' || !in_array($column, self::PRIVATE, true)),
        ));
    }
}
