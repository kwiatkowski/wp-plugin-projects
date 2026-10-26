<?php

declare(strict_types=1);

namespace MKProjects;

use MKProjects\App\AppShell;
use MKProjects\Domain\CategoryRepository;
use MKProjects\Domain\ChangeMarker;
use MKProjects\Domain\ProjectRepository;
use MKProjects\Domain\TodoBridge;
use MKProjects\Rest\CategoriesController;
use MKProjects\Rest\ProjectsController;
use MKProjects\Rest\ReportController;

/**
 * Wires the plugin together.
 *
 * Filters:
 *  - `wp_plugin_projects/capability` (string) – who manages the projects (all fields, editing), default "manage_options"
 *  - `wp_plugin_projects/path` (string)       – URL path of the app, default "projects"
 *  - `wp_plugin_projects/app_index` (string)  – built index.html of the Vue app, default {web root}/projects/web/index.html
 *  - `wp_plugin_projects/public_fields` (string[]) – fields everyone sees on the public list
 */
final class Plugin
{
    public static function boot(): void
    {
        $categories = new CategoryRepository();
        $todo = new TodoBridge();
        $projects = new ProjectRepository($categories, $todo);

        (new ContentTypes())->register();
        (new ChangeMarker())->register();
        (new ProjectsController($projects, $todo))->register();
        (new CategoriesController($categories))->register();
        (new ReportController($projects))->register();
        (new AppShell())->register();

        add_filter('mk_core/dashboard/packages', [self::class, 'dashboardCard']);
    }

    public static function capability(): string
    {
        return (string) apply_filters('wp_plugin_projects/capability', 'manage_options');
    }

    public static function canManage(): bool
    {
        return current_user_can(self::capability());
    }

    /** Card in the MK Core dashboard widget, when MK Core is installed. */
    public static function dashboardCard(array $packages): array
    {
        if (class_exists(\MKCore\Support\Package::class)) {
            $packages[] = \MKCore\Support\Package::fromPluginFile(WP_PLUGIN_PROJECTS_FILE, __('Aplikacja', 'wp-plugin-projects'), 'dashicons-portfolio');
        }

        return array_filter($packages);
    }
}
