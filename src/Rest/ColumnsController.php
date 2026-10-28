<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\Columns;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET  /projects/v1/columns – the columns viewers and guests see: { viewer: string[], guest: string[] } (the owner – all)
 * POST /projects/v1/columns { viewer, guest } – saves both
 */
final class ColumnsController extends Controller
{
    public function registerRoutes(): void
    {
        $columns = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Columns::ALL], 'required' => true];

        register_rest_route(self::NAMESPACE, '/columns', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => static fn () => ['viewer' => Columns::forView('viewer'), 'guest' => Columns::forView('guest')],
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => static fn (WP_REST_Request $request) => Columns::save((array) $request['guest'], (array) $request['viewer']),
                'permission_callback' => [$this, 'permission'],
                'args' => ['viewer' => $columns, 'guest' => $columns],
            ],
        ]);
    }
}
