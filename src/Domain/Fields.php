<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The fields of a project besides its name (the post title) – one list for the meta registration,
 * the REST schema, saving and reading. Each is post meta `_project_{snake_case}`.
 *
 * `reported` fields are also written by the site itself (MK Core's `report` module, POST /report).
 * `categoryId` is a term of `mk_project_category`, `todoProject` a project of wp-plugin-todo (a `todo_project` term)
 * whose open tasks the list shows.
 */
final class Fields
{
    public const STATUSES = ['online', 'dev', 'suspended', 'offline'];

    /** @var array<string, array{type: string, format?: string, enum?: string[], reported?: bool, max?: int, multiline?: bool}> */
    public const ALL = [
        'categoryId' => ['type' => 'integer'],
        // the site's addresses, without https:// – one project can have several (e.g. language versions)
        'domains' => ['type' => 'array', 'max' => 20],
        'status' => ['type' => 'string', 'enum' => self::STATUSES],
        'server' => ['type' => 'string', 'max' => 100],
        'serverUrl' => ['type' => 'string', 'format' => 'uri'],
        'serverAccount' => ['type' => 'string', 'max' => 100],
        'php' => ['type' => 'string', 'max' => 20, 'reported' => true],
        'engine' => ['type' => 'string', 'max' => 50, 'reported' => true],
        'version' => ['type' => 'string', 'max' => 20, 'reported' => true],
        'theme' => ['type' => 'string', 'max' => 100, 'reported' => true],
        'repo' => ['type' => 'string', 'format' => 'uri', 'reported' => true],
        'deploy' => ['type' => 'boolean', 'reported' => true],
        'bedrock' => ['type' => 'boolean', 'reported' => true],
        'security' => ['type' => 'boolean', 'reported' => true],
        'localUrl' => ['type' => 'string', 'format' => 'uri'],
        // access: the panels (WordPress admin) of production, staging and the local copy, staging's address, the login
        // (the password is kept apart, encrypted – ProjectRepository::setPassword())
        'adminUrl' => ['type' => 'string', 'format' => 'uri'],
        'stagingUrl' => ['type' => 'string', 'format' => 'uri'],
        'stagingAdminUrl' => ['type' => 'string', 'format' => 'uri'],
        'localAdminUrl' => ['type' => 'string', 'format' => 'uri'],
        'login' => ['type' => 'string', 'max' => 100],
        // HTTP Basic Auth in front of the whole site – the list fetches the report with it (its password: META_AUTH_PASSWORD)
        'authUser' => ['type' => 'string', 'max' => 100],
        // staging behind HTTP Basic Auth – the list checks it with these (the password apart, encrypted)
        'stagingAuthUser' => ['type' => 'string', 'max' => 100],
        'acf' => ['type' => 'boolean', 'reported' => true],
        'miModal' => ['type' => 'boolean', 'reported' => true],
        'todoProject' => ['type' => 'integer'],
        // reCAPTCHA v3: the site key (its secret apart, encrypted like the passwords – META_RECAPTCHA_SECRET)
        'recaptchaSiteKey' => ['type' => 'string', 'max' => 100],
        // Google Maps' API key – public in the site's pages anyway (restricted to its domains in Google Cloud)
        'googleMapsKey' => ['type' => 'string', 'max' => 100],
        // Markdown – its lines kept (`multiline`)
        'notes' => ['type' => 'string', 'max' => 5000, 'multiline' => true],
    ];

    public static function metaKey(string $field): string
    {
        return '_project_' . strtolower((string) preg_replace('/[A-Z]/', '_$0', $field));
    }

    /** @return string[] */
    public static function reported(): array
    {
        return array_keys(array_filter(self::ALL, static fn (array $field): bool => !empty($field['reported'])));
    }

    /**
     * What everyone sees on the public list – the fields of the columns the owner made public (filter
     * `wp_plugin_projects/public_fields`).
     *
     * @return string[]
     */
    public static function publicFields(): array
    {
        return array_values(array_intersect(
            (array) apply_filters('wp_plugin_projects/public_fields', Columns::fields('guest')),
            array_keys(self::ALL),
        ));
    }
}
