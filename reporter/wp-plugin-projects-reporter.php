<?php
/*
 * Plugin Name: Projekty – raport
 * Description: Raz dziennie i po aktualizacjach wysyła do listy projektów (wp-plugin-projects) dane tej strony: PHP, WordPress, motyw, Bedrock, Wordfence, ACF, MI Modal, wtyczki.
 * Author: Marcin Kwiatkowski
 * Author URI: https://kwiatkowski.co
 * Text Domain: wp-plugin-projects
 * Version: 2026.10.26
 * Requires PHP: 8.1
 *
 * Standalone – one file, no dependency on wp-plugin-projects. Configuration (constant or .env / environment):
 *   PROJECTS_REPORT_URL    https://kwiatkowski.co/wp-json/projects/v1/report
 *   PROJECTS_REPORT_TOKEN  token of this site's project (the projects app → project → Raport)
 * Only production (WP_ENV, without it wp_get_environment_type()) reports, unless the filter
 * `wp_plugin_projects_reporter/enabled` says otherwise. WP-CLI: `wp projects-report [--dry-run] [--force]`.
 */

declare(strict_types=1);

if (!defined('ABSPATH') || defined('WP_PLUGIN_PROJECTS_REPORTER_VERSION')) {
    return;
}

define('WP_PLUGIN_PROJECTS_REPORTER_VERSION', '2026.10.26');

final class WpPluginProjectsReporter
{
    public const HOOK = 'wp_plugin_projects_reporter/send';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'send']);
        add_action('init', [self::class, 'schedule']);

        // something that changes the report – send it a minute later (once, however many changes come)
        foreach (['upgrader_process_complete', 'switch_theme', 'activated_plugin', 'deactivated_plugin', '_core_updated_successfully'] as $hook) {
            add_action($hook, [self::class, 'soon']);
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('projects-report', [self::class, 'cli']);
        }
    }

    public static function config(string $name): string
    {
        if (defined($name)) {
            return (string) constant($name);
        }

        return (string) ($_ENV[$name] ?? $_SERVER[$name] ?? getenv($name) ?: '');
    }

    public static function configured(): bool
    {
        return self::config('PROJECTS_REPORT_URL') !== '' && self::config('PROJECTS_REPORT_TOKEN') !== '';
    }

    /** Bedrock's WP_ENV first – older Bedrock configs don't set WP_ENVIRONMENT_TYPE, which then says "production" everywhere. */
    public static function environment(): string
    {
        return defined('WP_ENV') ? (string) WP_ENV : wp_get_environment_type();
    }

    public static function enabled(): bool
    {
        return self::configured() && (bool) apply_filters('wp_plugin_projects_reporter/enabled', self::environment() === 'production');
    }

    public static function schedule(): void
    {
        if (!self::enabled()) {
            wp_clear_scheduled_hook(self::HOOK);

            return;
        }

        if (!wp_next_scheduled(self::HOOK)) {
            // a random hour, so the sites don't all knock at the same moment
            wp_schedule_event(time() + wp_rand(60, DAY_IN_SECONDS), 'daily', self::HOOK);
        }
    }

    public static function soon(): void
    {
        if (self::enabled() && !wp_next_scheduled(self::HOOK, ['soon'])) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK, ['soon']);
        }
    }

    /** @return true|WP_Error */
    public static function send(): bool|WP_Error
    {
        if (!self::configured()) {
            return new WP_Error('projects_report_not_configured', 'Brak PROJECTS_REPORT_URL albo PROJECTS_REPORT_TOKEN.');
        }

        $token = self::config('PROJECTS_REPORT_TOKEN');
        $response = wp_remote_post(self::config('PROJECTS_REPORT_URL'), [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'X-Projects-Token' => $token,
            ],
            'body' => wp_json_encode(self::report(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);

            return new WP_Error('projects_report_failed', sprintf('HTTP %d: %s', $code, $body['message'] ?? wp_remote_retrieve_response_message($response)));
        }

        update_option('wp_plugin_projects_reporter_sent', gmdate(DATE_ATOM), false);

        return true;
    }

    /** @return array<string, mixed> */
    public static function report(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $theme = wp_get_theme();
        $plugins = [];

        foreach (get_plugins() as $file => $plugin) {
            if (is_plugin_active($file)) {
                $plugins[] = ['name' => $plugin['Name'], 'version' => $plugin['Version'], 'file' => $file];
            }
        }

        foreach (get_mu_plugins() as $file => $plugin) {
            $plugins[] = ['name' => $plugin['Name'], 'version' => $plugin['Version'], 'file' => $file, 'mu' => true];
        }

        // Bedrock loads the plugins in mu-plugin directories through its autoloader – get_mu_plugins() doesn't see them
        $autoloader = get_site_option('bedrock_autoloader');

        foreach (is_array($autoloader) ? (array) ($autoloader['plugins'] ?? []) : [] as $file => $plugin) {
            $plugins[] = ['name' => $plugin['Name'] ?? $file, 'version' => $plugin['Version'] ?? '', 'file' => $file, 'mu' => true];
        }

        return [
            'home' => home_url('/'),
            'environment' => self::environment(),
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'engine' => 'wordpress',
            'version' => get_bloginfo('version'),
            'theme' => $theme->get('Name'),
            'parentTheme' => $theme->parent() ? $theme->parent()->get('Name') : null,
            'bedrock' => class_exists(\Roots\WPConfig\Config::class),
            'security' => defined('WORDFENCE_VERSION'),
            'acf' => class_exists('ACF'),
            'miModal' => defined('MEDEN_MODAL_VERSION'),
            'multisite' => is_multisite(),
            'plugins' => $plugins,
            'reporter' => WP_PLUGIN_PROJECTS_REPORTER_VERSION,
        ];
    }

    /**
     * Sends the report now.
     *
     * [--dry-run]
     * : Only print the report.
     *
     * [--force]
     * : Send also outside production.
     *
     * @param string[] $args
     * @param array<string, bool> $assoc
     */
    public static function cli(array $args, array $assoc): void
    {
        if (!empty($assoc['dry-run'])) {
            \WP_CLI::line((string) wp_json_encode(self::report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        if (!self::enabled() && empty($assoc['force'])) {
            \WP_CLI::error(self::configured() ? 'Raport wysyła tylko produkcja – dodaj --force.' : 'Brak PROJECTS_REPORT_URL albo PROJECTS_REPORT_TOKEN.');
        }

        $result = self::send();

        is_wp_error($result) ? \WP_CLI::error($result->get_error_message()) : \WP_CLI::success('Raport wysłany.');
    }
}

WpPluginProjectsReporter::register();
