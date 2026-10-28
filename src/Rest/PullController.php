<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\ReportPuller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * POST /projects/v1/projects/{id}/report – the list fetches this project's report now (as `wp projects-report` does for
 * all of them): { ok, error, changes, project }
 */
final class PullController extends Controller
{
    public function __construct(private readonly ReportPuller $puller)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/projects/(?P<id>\d+)/report', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond($this->puller->pullOne((int) $request['id'])),
            'permission_callback' => [$this, 'permission'],
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }
}
