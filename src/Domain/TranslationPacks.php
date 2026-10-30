<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use MKProjects\ContentTypes;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

/**
 * Which plugins and themes have a translation to install – the language packs of translate.wordpress.org (a pack exists
 * once a language is translated far enough). A missing translation with no pack for the site's language can't be
 * installed by any update, so the list leaves it out.
 *
 * Each slug's languages are kept CACHE seconds; the pull asks for the ones not known yet.
 */
final class TranslationPacks
{
    private const API = 'https://api.wordpress.org/translations';
    private const CACHE = 12 * HOUR_IN_SECONDS;
    private const TRANSIENT = 'mk_projects_l10n_';
    private const AT_ONCE = 8;

    /** asks for the packs of everything the reports miss a translation of – what isn't known yet */
    public function warm(): void
    {
        $ask = [];

        foreach (get_posts(['post_type' => ContentTypes::PROJECT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => ContentTypes::META_REPORT]) as $id) {
            $report = json_decode((string) get_post_meta($id, ContentTypes::META_REPORT, true), true);

            $items = array_merge((array) ($report['missingTranslations']['items'] ?? []), ...array_map(
                static fn (mixed $site): array => is_array($site) ? (array) ($site['missingTranslations']['items'] ?? []) : [],
                (array) ($report['sites'] ?? []),
            ));

            // the network's sites too (multisite)
            foreach ($items as $item) {
                $type = is_array($item) ? (string) ($item['type'] ?? '') : '';
                $slug = is_array($item) ? (string) ($item['slug'] ?? '') : '';

                if (in_array($type, ['plugin', 'theme'], true) && $slug !== '' && self::languages($type, $slug) === null) {
                    $ask["$type/$slug"] = ['url' => self::API . "/{$type}s/1.0/?slug=" . rawurlencode($slug)];
                }
            }
        }

        foreach (array_chunk($ask, self::AT_ONCE, true) as $batch) {
            $responses = Requests::request_multiple($batch, [
                'timeout' => 20,
                'connect_timeout' => 10,
                'verify' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
                'useragent' => 'wp-plugin-projects/' . WP_PLUGIN_PROJECTS_VERSION,
            ]);

            foreach ($responses as $key => $response) {
                $body = $response instanceof Response && $response->status_code === 200 ? json_decode($response->body, true) : null;

                // no answer – nothing kept, asked again at the next pull
                if (!is_array($body) || !is_array($body['translations'] ?? null)) {
                    continue;
                }

                set_transient(self::TRANSIENT . md5((string) $key), array_values(array_unique(array_column($body['translations'], 'language'))), self::CACHE);
            }
        }
    }

    /**
     * Whether the item has a pack for the locale – WordPress itself always has one; null: not known yet.
     *
     * @param array<string, mixed> $item a missing translation of the report (type, slug)
     */
    public static function available(array $item, string $locale): ?bool
    {
        $type = (string) ($item['type'] ?? '');

        if (!in_array($type, ['plugin', 'theme'], true)) {
            return true;
        }

        $languages = self::languages($type, (string) ($item['slug'] ?? ''));

        return $languages === null ? null : in_array($locale, $languages, true);
    }

    /** @return string[]|null the languages with a pack; null – not asked yet */
    private static function languages(string $type, string $slug): ?array
    {
        $cached = get_transient(self::TRANSIENT . md5("$type/$slug"));

        return is_array($cached) ? $cached : null;
    }
}
