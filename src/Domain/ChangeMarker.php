<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use MKProjects\ContentTypes;

/**
 * When the list last changed – any project, its fields (a site's report too) or a category. The app asks for it
 * every few seconds (GET /projects/v1/changed) and fetches the whole list only when it moved, so a report shows up
 * at once without polling the full list.
 *
 * Kept in the option `wp_plugin_projects_changed_at` (microtime), written once per request, on shutdown.
 */
final class ChangeMarker
{
    public const OPTION = 'wp_plugin_projects_changed_at';

    private bool $dirty = false;

    public function register(): void
    {
        add_action('save_post_' . ContentTypes::PROJECT, [$this, 'touch']);
        add_action('trashed_post', [$this, 'touchPost']);
        add_action('untrashed_post', [$this, 'touchPost']);
        add_action('deleted_post', [$this, 'touchPost']);

        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [$this, 'touchMeta'], 10, 2);
        }

        foreach (['created_', 'edited_', 'delete_'] as $prefix) {
            add_action($prefix . ContentTypes::CATEGORY, [$this, 'touch']);
        }

        foreach (['added_term_meta', 'updated_term_meta', 'deleted_term_meta'] as $hook) {
            add_action($hook, [$this, 'touchTermMeta'], 10, 2);
        }

        add_action('shutdown', [$this, 'save']);
    }

    public static function get(): string
    {
        return (string) get_option(self::OPTION, '0');
    }

    public function touch(): void
    {
        $this->dirty = true;
    }

    public function touchPost(int $postId): void
    {
        if (get_post_type($postId) === ContentTypes::PROJECT) {
            $this->dirty = true;
        }
    }

    /** @param int|int[] $metaIds */
    public function touchMeta(int|array $metaIds, int $postId): void
    {
        $this->touchPost($postId);
    }

    /** @param int|int[] $metaIds */
    public function touchTermMeta(int|array $metaIds, int $termId): void
    {
        $term = get_term($termId);

        if ($term instanceof \WP_Term && $term->taxonomy === ContentTypes::CATEGORY) {
            $this->dirty = true;
        }
    }

    public function save(): void
    {
        if ($this->dirty) {
            update_option(self::OPTION, sprintf('%.4F', microtime(true)), true);
        }
    }
}
