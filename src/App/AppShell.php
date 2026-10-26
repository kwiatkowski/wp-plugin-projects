<?php

declare(strict_types=1);

namespace MKProjects\App;

use MKProjects\Domain\Fields;
use MKProjects\Plugin;
use WP;

/**
 * Serves the Vue app under /projects/ (and every deeper path – the app has its own router).
 *
 * Open to everyone: visitors get the public list, the owner (logged in, `wp_plugin_projects/capability`)
 * every field and editing. The built index.html gets the runtime config injected (REST URL, nonce, user),
 * so no secret ever lands in the JS bundle.
 */
final class AppShell
{
    public function register(): void
    {
        add_action('parse_request', [$this, 'maybeServe'], 0);
        add_action('admin_bar_menu', [$this, 'adminBar'], 81);
    }

    public static function path(): string
    {
        return trim((string) apply_filters('wp_plugin_projects/path', 'projects'), '/');
    }

    public static function url(): string
    {
        return home_url('/' . self::path() . '/');
    }

    public function maybeServe(WP $wp): void
    {
        $path = self::path();

        if ($wp->request !== $path && !str_starts_with($wp->request, $path . '/')) {
            return;
        }

        $index = (string) apply_filters('wp_plugin_projects/app_index', dirname(WP_CONTENT_DIR) . '/' . $path . '/web/index.html');

        if (!is_readable($index)) {
            wp_die(esc_html__('Aplikacja nie jest zbudowana – uruchom „npm run build” w jej katalogu.', 'wp-plugin-projects'), '', ['response' => 500]);
        }

        $html = (string) file_get_contents($index);
        $config = '<script>window.__PROJECTS__ = ' . wp_json_encode($this->config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';

        if (!headers_sent()) {
            nocache_headers();
            status_header(200);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: same-origin');
        }

        echo preg_replace('#</head>#i', $config . "\n</head>", $html, 1);
        exit;
    }

    public function adminBar(\WP_Admin_Bar $bar): void
    {
        if (!Plugin::canManage()) {
            return;
        }

        $bar->add_node([
            'id' => 'wp-plugin-projects',
            'title' => '<span class="ab-icon dashicons dashicons-portfolio" style="top:2px"></span>' . esc_html__('Projekty', 'wp-plugin-projects'),
            'href' => self::url(),
        ]);
    }

    /** The newest WordPress release (wordpress.org, cached 12 h) – the list marks the sites that run it; null when unknown. */
    public static function latestWordpress(): ?string
    {
        $cached = get_transient('wp_plugin_projects_latest_wordpress');

        if (is_string($cached)) {
            return $cached ?: null;
        }

        $response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/', ['timeout' => 5]);
        $data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        $version = (string) ($data['offers'][0]['current'] ?? '');

        // a failed check is remembered for an hour only, so it is retried soon
        set_transient('wp_plugin_projects_latest_wordpress', $version, $version ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);

        return $version ?: null;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $manage = Plugin::canManage();
        $user = wp_get_current_user();

        return [
            'restUrl' => esc_url_raw(rest_url('projects/v1/')),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : null,
            // core endpoint that hands out a fresh REST nonce – used when the tab stays open past the nonce lifetime
            'nonceUrl' => is_user_logged_in() ? admin_url('admin-ajax.php?action=rest-nonce') : null,
            'basePath' => (string) wp_parse_url(self::url(), PHP_URL_PATH),
            'canManage' => $manage,
            'user' => is_user_logged_in() ? [
                'name' => $user->display_name,
                'avatar' => get_avatar_url($user->ID, ['size' => 64]),
            ] : null,
            'loginUrl' => wp_login_url(self::url()),
            'logoutUrl' => is_user_logged_in() ? html_entity_decode(wp_logout_url(self::url()), ENT_QUOTES, 'UTF-8') : null,
            // the path only – MK Core resolves it against the list's own address (kwiatkowski.co), whichever copy shows it
            'reportUrl' => (string) wp_parse_url(rest_url('projects/v1/report'), PHP_URL_PATH),
            'todoUrl' => $manage && class_exists(\MKTodo\App\AppShell::class) ? \MKTodo\App\AppShell::url() : null,
            'publicFields' => Fields::publicFields(),
            'latestWordpress' => self::latestWordpress(),
            'siteName' => get_bloginfo('name'),
            'homeUrl' => home_url('/'),
            'version' => WP_PLUGIN_PROJECTS_VERSION,
        ];
    }
}
