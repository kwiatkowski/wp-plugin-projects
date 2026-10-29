<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * PHP's branches as php.net gives them – each with its state (stable: active support, security: security fixes only,
 * eol: none), the newest release of the supported ones, and the dates the support ends. Cached 12 hours (an hour
 * after a failed check). The list marks a site's PHP by it: an unpatched or unsupported one red.
 */
final class PhpReleases
{
    private const TRANSIENT = 'wp_plugin_projects_php_branches';

    /** @return array<string, array{state: string, latest: string|null, activeEnd: string|null, securityEnd: string|null}> by branch ("8.3") */
    public static function branches(): array
    {
        $cached = get_transient(self::TRANSIENT);

        if (is_array($cached)) {
            return $cached;
        }

        $states = self::json('https://www.php.net/releases/states.php');
        $active = self::json('https://www.php.net/releases/active.php');
        $branches = [];

        foreach ($states as $major) {
            foreach ((array) $major as $branch => $info) {
                $branches[(string) $branch] = [
                    'state' => (string) ($info['state'] ?? ''),
                    'latest' => null,
                    'activeEnd' => isset($info['active_support_end']) ? substr((string) $info['active_support_end'], 0, 10) : null,
                    'securityEnd' => isset($info['security_support_end']) ? substr((string) $info['security_support_end'], 0, 10) : null,
                ];
            }
        }

        foreach ($active as $major) {
            foreach ((array) $major as $branch => $info) {
                if (isset($branches[(string) $branch]) && !empty($info['version'])) {
                    $branches[(string) $branch]['latest'] = (string) $info['version'];
                }
            }
        }

        uksort($branches, static fn (string $a, string $b): int => version_compare($b, $a));
        set_transient(self::TRANSIENT, $branches, $branches ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);

        return $branches;
    }

    /** @return array<string, mixed> */
    private static function json(string $url): array
    {
        $response = wp_remote_get($url, ['timeout' => 8]);
        $data = is_wp_error($response) ? null : json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : [];
    }
}
