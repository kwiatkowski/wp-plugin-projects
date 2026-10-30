<?php
/*
 * Plugin Name: Projekty
 * Description: Lista projektów (strony, aplikacje) – typ treści, REST API (projects/v1), raporty ze stron i powłoka aplikacji Vue pod /projects/.
 * Author: Marcin Kwiatkowski
 * Author URI: https://kwiatkowski.co
 * Text Domain: wp-plugin-projects
 * Version: 2026.10.30.16
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WP_PLUGIN_PROJECTS_VERSION', '2026.10.30.16');
define('WP_PLUGIN_PROJECTS_FILE', __FILE__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MKProjects\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

add_action('plugins_loaded', [MKProjects\Plugin::class, 'boot']);
