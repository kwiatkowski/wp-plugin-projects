<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The fields of a project besides its name (the post title) – one list for the meta registration,
 * the REST schema, saving and reading. Each is post meta `_project_{snake_case}`.
 *
 * `reported` fields are also written by the reporter plugin of the site itself (POST /report).
 * `categoryId` is a term of `mk_project_category`, `todoProject` a project of wp-plugin-todo (a `todo_project` term)
 * whose open tasks the list shows.
 */
final class Fields
{
    public const STATUSES = ['online', 'dev', 'suspended', 'offline'];

    /** @var array<string, array{type: string, format?: string, enum?: string[], reported?: bool, max?: int}> */
    public const ALL = [
        'categoryId' => ['type' => 'integer'],
        'domain' => ['type' => 'string', 'max' => 200],
        'status' => ['type' => 'string', 'enum' => self::STATUSES],
        'server' => ['type' => 'string', 'max' => 100],
        'serverUrl' => ['type' => 'string', 'format' => 'uri'],
        'serverAccount' => ['type' => 'string', 'max' => 100],
        'php' => ['type' => 'string', 'max' => 20, 'reported' => true],
        'engine' => ['type' => 'string', 'max' => 50, 'reported' => true],
        'version' => ['type' => 'string', 'max' => 20, 'reported' => true],
        'theme' => ['type' => 'string', 'max' => 100, 'reported' => true],
        'repo' => ['type' => 'string', 'format' => 'uri'],
        'deploy' => ['type' => 'boolean'],
        'dev' => ['type' => 'boolean'],
        'bedrock' => ['type' => 'boolean', 'reported' => true],
        'security' => ['type' => 'boolean', 'reported' => true],
        'localUrl' => ['type' => 'string', 'format' => 'uri'],
        'acf' => ['type' => 'boolean', 'reported' => true],
        'miModal' => ['type' => 'boolean', 'reported' => true],
        'dbSync' => ['type' => 'string', 'format' => 'date'],
        'listed' => ['type' => 'boolean'],
        'todoProject' => ['type' => 'integer'],
        'notes' => ['type' => 'string', 'max' => 5000],
    ];

    /** What everyone sees on the public list (filter `wp_plugin_projects/public_fields`). */
    public const PUBLIC = ['domain', 'status', 'engine', 'version', 'theme'];

    public static function metaKey(string $field): string
    {
        return '_project_' . strtolower((string) preg_replace('/[A-Z]/', '_$0', $field));
    }

    /** @return string[] */
    public static function reported(): array
    {
        return array_keys(array_filter(self::ALL, static fn (array $field): bool => !empty($field['reported'])));
    }

    /** @return string[] */
    public static function publicFields(): array
    {
        return array_values(array_intersect(
            (array) apply_filters('wp_plugin_projects/public_fields', self::PUBLIC),
            array_keys(self::ALL),
        ));
    }
}
