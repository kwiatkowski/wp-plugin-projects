<?php

declare(strict_types=1);

namespace MKProjects;

use MKProjects\Domain\Fields;

/**
 * Storage: projects are posts of the `mk_project` type, their fields post meta (see Domain\Fields).
 * Not public on the front end – the list is served by the app under /projects/; in wp-admin they are
 * visible in the menu *Projekty* (a backup view, editing happens in the app).
 *
 * Categories of the list are terms of `mk_project_category`, used for storage only – a project points to its
 * category with the `categoryId` field (post meta), like Todo's projects point to their areas. Projects and categories
 * keep their manual order in `_project_position` (post / term meta).
 */
final class ContentTypes
{
    public const PROJECT = 'mk_project';
    public const CATEGORY = 'mk_project_category';

    public const META_POSITION = '_project_position';

    public const META_TOKEN = '_project_report_token';
    public const META_PASSWORD = '_project_password';
    /** the HTTP Basic Auth password the site sits behind – encrypted like the panel's */
    public const META_AUTH_PASSWORD = '_project_auth_password';
    public const META_REPORTED_AT = '_project_reported_at';
    public const META_REPORT = '_project_report';
    /** the site's TLS certificate as the list last saw it (SslChecker), JSON */
    public const META_SSL = '_project_ssl';

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

        register_taxonomy(self::CATEGORY, [self::PROJECT], [
            'labels' => [
                'name' => __('Kategorie projektów', 'wp-plugin-projects'),
                'singular_name' => __('Kategoria', 'wp-plugin-projects'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_admin_column' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            'capabilities' => [
                'manage_terms' => $capability,
                'edit_terms' => $capability,
                'delete_terms' => $capability,
                'assign_terms' => $capability,
            ],
            'rewrite' => false,
            'query_var' => false,
        ]);

        register_term_meta(self::CATEGORY, self::META_POSITION, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => [Plugin::class, 'canManage'],
        ]);

        register_post_meta(self::PROJECT, self::META_POSITION, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => [Plugin::class, 'canManage'],
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
