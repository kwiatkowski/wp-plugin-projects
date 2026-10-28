<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\SiteUpdater;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET  /projects/v1/projects/{id}/update – how the site's Composer update goes (SiteUpdater)
 * POST /projects/v1/projects/{id}/update – start it: composer update, commit and push of composer.lock on production
 */
final class UpdateController extends Controller
{
    public function __construct(private readonly SiteUpdater $updater)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/projects/(?P<id>\d+)/update', [
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->updater->call((int) $request['id'], 'GET')),
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->updater->call((int) $request['id'], 'POST')),
                'permission_callback' => [$this, 'permission'],
            ],
        ]);
    }
}
