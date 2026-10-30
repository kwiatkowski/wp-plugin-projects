<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The app's own errors, as the browsers met them (a render that broke, a promise nobody caught) – sent by the app, kept
 * here so they can be read later (`wp projects-errors`) instead of being retyped from a toast. The last KEEP of them,
 * in an option that isn't autoloaded; each field cut to its length.
 */
final class ClientErrors
{
    private const OPTION = 'wp_plugin_projects_client_errors';
    private const KEEP = 100;

    /** what is kept of a report – and how long each may be */
    private const FIELDS = ['message' => 500, 'stack' => 3000, 'info' => 200, 'url' => 300, 'project' => 120, 'version' => 40, 'agent' => 300];

    /** @param array<string, mixed> $error as the app sends it */
    public static function add(array $error): void
    {
        $entry = ['at' => gmdate(DATE_ATOM), 'view' => \MKProjects\Plugin::view()];

        foreach (self::FIELDS as $field => $length) {
            $entry[$field] = mb_substr(trim((string) ($error[$field] ?? '')), 0, $length);
        }

        $all = self::all();
        $all[] = $entry;
        update_option(self::OPTION, array_slice($all, -self::KEEP), false);
    }

    /** @return array<int, array<string, string>> oldest first */
    public static function all(): array
    {
        $all = get_option(self::OPTION, []);

        return is_array($all) ? array_values($all) : [];
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }
}
