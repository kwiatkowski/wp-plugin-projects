<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\ProjectRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /projects/v1/report – a site reports about itself (the reporter plugin, reporter/ in this repository).
 *
 * Authorization: Bearer {token of the project} (or X-Projects-Token: {token}). Body: { php, engine, version, theme, bedrock, security, acf, miModal,
 * plugins?, environment?, home? } – only the fields in Fields::reported() change the project, the whole report is kept.
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
        // some hosts strip Authorization before PHP – the reporter sends the token in X-Projects-Token too
        $token = trim((string) preg_replace('/^Bearer\s+/i', '', (string) ($request->get_header('authorization') ?: $request->get_header('x_projects_token'))));
        $report = $request->get_json_params();

        if (!is_array($report)) {
            return new WP_Error('projects_invalid_report', __('Raport musi być obiektem JSON.', 'wp-plugin-projects'), ['status' => 400]);
        }

        $project = $this->projects->applyReport($token, $report);

        return is_wp_error($project) ? $project : new WP_REST_Response(['ok' => true, 'project' => $project['name'], 'reportedAt' => $project['reportedAt']]);
    }
}
