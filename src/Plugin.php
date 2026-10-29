<?php

declare(strict_types=1);

namespace MKProjects;

use MKProjects\App\AppShell;
use MKProjects\Cli\SecretsCommand;
use MKProjects\Domain\CategoryRepository;
use MKProjects\Domain\Alerts;
use MKProjects\Domain\ChangeMarker;
use MKProjects\Domain\History;
use MKProjects\Domain\ProjectRepository;
use MKProjects\Domain\ReportPuller;
use MKProjects\Domain\SiteUpdater;
use MKProjects\Domain\TodoBridge;
use MKProjects\Domain\Viewer;
use MKProjects\Rest\AlertsController;
use MKProjects\Rest\CategoriesController;
use MKProjects\Rest\ColumnsController;
use MKProjects\Rest\ProjectsController;
use MKProjects\Rest\PullController;
use MKProjects\Rest\ReportController;
use MKProjects\Rest\UpdateController;
use MKProjects\Rest\ViewerController;

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

        History::install();
        (new ContentTypes())->register();
        (new ChangeMarker())->register();
        (new ProjectsController($projects, $todo))->register();
        (new CategoriesController($categories))->register();
        (new ColumnsController())->register();
        (new ViewerController())->register();
        (new ReportController($projects))->register();
        $alerts = new Alerts($projects);
        (new UpdateController(new SiteUpdater($projects, $alerts)))->register();
        (new AlertsController($alerts))->register();

        // `wp projects-report` on the list's site (MK Core) fetches every other site's report; the dialog – one of them
        $puller = new ReportPuller($projects, alerts: $alerts);
        (new PullController($puller))->register();
        add_filter('mk_core/report/pull', static fn (mixed $pulled, string $ownToken = ''): array => $puller->pull($ownToken), 10, 2);
        // the cron runs it every 5 minutes: everything once an hour, only the home pages and stagings in between
        add_filter('mk_core/report/full_due', static fn (): bool => $puller->fullDue());
        add_filter('mk_core/report/check', static fn (): array => $puller->check());
        (new AppShell())->register();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('projects-secrets', new SecretsCommand($projects));
        }

        add_filter('mk_core/dashboard/packages', [self::class, 'dashboardCard']);

        self::dropRetiredFields();
    }

    /** Fields the list no longer has – their stored values go, once (the option remembers it was done). */
    private const RETIRED_FIELDS = ['_project_db_sync', '_project_listed', '_project_dev'];

    private static function dropRetiredFields(): void
    {
        $done = (array) get_option('wp_plugin_projects_retired_fields', []);
        $todo = array_diff(self::RETIRED_FIELDS, $done);

        if (!$todo) {
            return;
        }

        foreach ($todo as $key) {
            delete_post_meta_by_key($key);
        }

        update_option('wp_plugin_projects_retired_fields', self::RETIRED_FIELDS, true);
    }

    public static function capability(): string
    {
        return (string) apply_filters('wp_plugin_projects/capability', 'manage_options');
    }

    public static function canManage(): bool
    {
        return current_user_can(self::capability());
    }

    /**
     * Who looks at the list: admin (the owner), viewer (the list's own account, Viewer) or guest. The owner can look as
     * either of the others – `$as`, the list's preview (?podglad, the X-Projects-View header).
     */
    public static function view(?string $as = null): string
    {
        if (self::canManage()) {
            return in_array($as, ['viewer', 'guest'], true) ? $as : 'admin';
        }

        return Viewer::active() ? 'viewer' : 'guest';
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
