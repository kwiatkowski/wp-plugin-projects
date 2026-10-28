<?php

declare(strict_types=1);

namespace MKProjects\App;

use MKProjects\Domain\Columns;
use MKProjects\Domain\Fields;
use MKProjects\Domain\Viewer;
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

    /**
     * Every WordPress release since 5.0 (filter `wp_plugin_projects/wordpress_since`), newest first – the version list.
     * From wordpress.org (stable-check), cached 12 h; [] when unknown.
     *
     * @return string[]
     */
    public static function wordpressVersions(): array
    {
        $cached = get_transient('wp_plugin_projects_wordpress_releases');

        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get('https://api.wordpress.org/core/stable-check/1.0/', ['timeout' => 5]);
        $all = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        $since = (string) apply_filters('wp_plugin_projects/wordpress_since', '5.0');

        $versions = array_values(array_filter(
            array_map('strval', array_keys(is_array($all) ? $all : [])),
            static fn (string $version): bool => (bool) preg_match('/^\d+\.\d+(\.\d+)?$/', $version) && version_compare($version, $since, '>='),
        ));
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        // a failed check is remembered for an hour only, so it is retried soon
        set_transient('wp_plugin_projects_wordpress_releases', $versions, $versions ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);

        return $versions;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $owner = Plugin::canManage();
        // ?podglad=gosc / ?podglad=zalogowany – the owner looks as a guest or a viewer, still logged in: the list's
        // requests say so (X-Projects-View) and the server answers them as it would answer those
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $preview = $owner && isset($_GET['podglad']) ? (sanitize_key((string) $_GET['podglad']) === 'zalogowany' ? 'viewer' : 'guest') : null;
        $view = $preview ?? Plugin::view();
        $manage = $view === 'admin';
        $user = wp_get_current_user();
        $account = Viewer::account();

        return [
            'restUrl' => esc_url_raw(rest_url('projects/v1/')),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : null,
            // core endpoint that hands out a fresh REST nonce – used when the tab stays open past the nonce lifetime
            'nonceUrl' => is_user_logged_in() ? admin_url('admin-ajax.php?action=rest-nonce') : null,
            'basePath' => (string) wp_parse_url(self::url(), PHP_URL_PATH),
            'canManage' => $manage,
            // admin, viewer or guest – what this page shows; preview – the owner's look as one of the other two
            'view' => $view,
            'preview' => $preview,
            // the list's own account: logged in with it, whether there is one (the guest's "Zaloguj"), and for the
            // owner its login (the settings)
            'viewer' => !$owner && $view === 'viewer' && $account ? ['login' => $account['login']] : null,
            'viewerAccount' => $account !== null,
            'viewerLogin' => $owner ? ($account['login'] ?? null) : null,
            'user' => is_user_logged_in() ? [
                'name' => $user->display_name,
                'avatar' => get_avatar_url($user->ID, ['size' => 64]),
            ] : null,
            'loginUrl' => wp_login_url(self::url()),
            // the guest's view keeps "Wyloguj" in the account's menu – it is a plain link, no REST
            'logoutUrl' => is_user_logged_in() ? html_entity_decode(wp_logout_url(self::url()), ENT_QUOTES, 'UTF-8') : null,
            // the path only – MK Core resolves it against the list's own address (kwiatkowski.co), whichever copy shows it
            'reportUrl' => (string) wp_parse_url(rest_url('projects/v1/report'), PHP_URL_PATH),
            'todoUrl' => $manage && class_exists(\MKTodo\App\AppShell::class) ? \MKTodo\App\AppShell::url() : null,
            'publicFields' => Fields::publicFields(),
            // the columns: what this page shows, and for the owner the viewers' and the guests' (the settings)
            'columns' => [
                'visible' => Columns::forView($view),
                'viewer' => $owner ? Columns::forView('viewer') : null,
                'guest' => $owner ? Columns::forView('guest') : null,
            ],
            'latestWordpress' => self::latestWordpress(),
            'wordpressVersions' => $manage ? self::wordpressVersions() : [],
            'siteName' => get_bloginfo('name'),
            'homeUrl' => home_url('/'),
            'version' => WP_PLUGIN_PROJECTS_VERSION,
        ];
    }
}
