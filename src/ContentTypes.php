<?php

declare(strict_types=1);

namespace MKProjects;

use MKProjects\Domain\Fields;

/**
 * Storage: projects are posts of the `mk_project` type, their fields post meta (see Domain\Fields).
 * Not public on the front end – the list is served by the app under /projects/; in wp-admin they are
 * visible in the menu *Projekty* (a backup view, editing happens in the app).
 *
 * wp-plugin-todo keeps its own fields of a project (colour, category, order) on the same post.
 */
final class ContentTypes
{
    public const PROJECT = 'mk_project';

    public const META_TOKEN = '_project_report_token';
    public const META_REPORTED_AT = '_project_reported_at';
    public const META_REPORT = '_project_report';

    public function register(): void
    {
        add_action('init', [$this, 'registerTypes']);
    }

    public function registerTypes(): void
    {
        $capability = Plugin::capability();

        register_post_type(self::PROJECT, [
            'labels' => [
                'name' => __('Projekty', 'wp-plugin-projects'),
                'singular_name' => __('Projekt', 'wp-plugin-projects'),
                'menu_name' => __('Projekty', 'wp-plugin-projects'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false, // own endpoints in projects/v1
            'menu_icon' => 'dashicons-portfolio',
            'menu_position' => 7,
            'supports' => ['title', 'custom-fields'],
            'capabilities' => [
                'edit_post' => $capability,
                'read_post' => $capability,
                'delete_post' => $capability,
                'edit_posts' => $capability,
                'edit_others_posts' => $capability,
                'publish_posts' => $capability,
                'read_private_posts' => $capability,
                'create_posts' => $capability,
                'delete_posts' => $capability,
            ],
            'rewrite' => false,
            'query_var' => false,
        ]);

        foreach (Fields::ALL as $field => $definition) {
            register_post_meta(self::PROJECT, Fields::metaKey($field), [
                'type' => $definition['type'],
                'single' => true,
                'show_in_rest' => false,
                'auth_callback' => [Plugin::class, 'canManage'],
            ]);
        }
    }
}
