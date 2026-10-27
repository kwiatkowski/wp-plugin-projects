<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\App\AppShell;
use MKProjects\Domain\ProjectRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /projects/v1/report – a site reports about itself (the `report` module of MK Core).
 *
 * Authorization: Bearer {token of the project} (or X-Projects-Token: {token}). Body: { homeCode, php, engine, version, theme, bedrock, security, acf, miModal,
 * users?, plugins?, pluginUpdates?, environment?, home? } – only the fields in Fields::reported() change the project, the whole
 * report is kept (GET /projects/{id}/report). Answers { ok, project, list, reportedAt, previousReportAt, changes, projects } –
 * changes: what the report changed on the list, [{ what: field|'plugin'|'users'|'online', name?, from, to }];
 * projects: every project (name, last report, current – the one just reported), for the terminal of the site.
 * No WordPress login: the token alone says which project it is.
 */
final class ReportController extends Controller
{
    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/report', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'report'],
            'permission_callback' => '__return_true', // the token is checked in the callback
        ]);
    }

    public function report(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // some hosts strip Authorization before PHP – the site sends the token in X-Projects-Token too
        $token = trim((string) preg_replace('/^Bearer\s+/i', '', (string) ($request->get_header('authorization') ?: $request->get_header('x_projects_token'))));
        $report = $request->get_json_params();

        if (!is_array($report)) {
            return new WP_Error('projects_invalid_report', __('Raport musi być obiektem JSON.', 'wp-plugin-projects'), ['status' => 400]);
        }

        $project = $this->projects->applyReport($token, $report);

        if (is_wp_error($project)) {
            return $project;
        }

        return new WP_REST_Response([
            'ok' => true,
            'project' => $project['name'],
            'list' => AppShell::url(),
            'reportedAt' => $project['reportedAt'],
            'previousReportAt' => $project['previousReportAt'],
            'changes' => $project['changes'],
            // the whole list, so the terminal can show which project this report updated and how fresh the others are
            'projects' => array_map(
                static fn (array $item): array => ['name' => $item['name'], 'reportedAt' => $item['reportedAt'], 'current' => $item['id'] === $project['id']],
                $this->projects->reportTimes(),
            ),
        ]);
    }
}
