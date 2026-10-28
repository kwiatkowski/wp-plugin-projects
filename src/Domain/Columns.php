<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The list's columns and which are shown: the owner's own choice (user meta – the same on every device) and the public
 * list's (an option). The public one also decides what the server sends to everyone else – a hidden column's data
 * isn't in the page at all. Some columns never go public: access data or the owner's own work.
 */
final class Columns
{
    public const ALL = [
        'name', 'status', 'server', 'php', 'engine', 'theme', 'repo', 'deploy', 'bedrock', 'security',
        'local', 'stage', 'acf', 'miModal', 'todo', 'report',
    ];

    /** only for the owner – the hosting and its account, the repository, the copies' addresses, Todo, the report */
    public const PRIVATE = ['server', 'repo', 'local', 'stage', 'todo', 'report'];

    /** the public list until the owner chooses – as it always was */
    public const PUBLIC_DEFAULT = ['name', 'status', 'engine', 'theme'];

    /** the fields each column that can go public shows – besides `domains` (the name links to the site) */
    private const FIELDS = [
        'name' => [],
        'status' => ['status'],
        'php' => ['php'],
        'engine' => ['engine', 'version'],
        'theme' => ['theme'],
        'deploy' => ['deploy'],
        'bedrock' => ['bedrock'],
        'security' => ['security'],
        'acf' => ['acf'],
        'miModal' => ['miModal'],
    ];

    private const OPTION = 'wp_plugin_projects_public_columns';
    private const USER_META = 'wp_plugin_projects_columns';

    /** @return string[] what everyone else sees, in the list's order; the name always */
    public static function publicColumns(): array
    {
        $saved = get_option(self::OPTION, null);

        return self::clean(is_array($saved) ? $saved : self::PUBLIC_DEFAULT, true);
    }

    /** @return string[]|null the owner's own choice; null – never chosen, every column */
    public static function mine(int $userId): ?array
    {
        $saved = get_user_meta($userId, self::USER_META, true);

        return is_array($saved) ? self::clean($saved, false) : null;
    }

    /**
     * Saves both; null for the owner's – every column again. Returns them as kept.
     *
     * @param mixed[]|null $mine
     * @param mixed[] $public
     * @return array{mine: string[]|null, public: string[]}
     */
    public static function save(int $userId, ?array $mine, array $public): array
    {
        $mine === null ? delete_user_meta($userId, self::USER_META) : update_user_meta($userId, self::USER_META, self::clean($mine, false));
        update_option(self::OPTION, self::clean($public, true), true);

        return ['mine' => self::mine($userId), 'public' => self::publicColumns()];
    }

    /** @return string[] the fields the public list sends: the domains and those of its columns */
    public static function publicFields(): array
    {
        return array_values(array_unique(['domains', ...array_merge(...array_map(
            static fn (string $column): array => self::FIELDS[$column] ?? [],
            self::publicColumns(),
        ))]));
    }

    /**
     * Known columns only, in the list's order, the name always; for the public list none of the private ones.
     *
     * @param mixed[] $columns
     * @return string[]
     */
    private static function clean(array $columns, bool $public): array
    {
        $wanted = ['name', ...array_filter($columns, 'is_string')];

        return array_values(array_filter(
            self::ALL,
            static fn (string $column): bool => in_array($column, $wanted, true) && (!$public || !in_array($column, self::PRIVATE, true)),
        ));
    }
}
