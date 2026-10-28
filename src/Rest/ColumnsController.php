<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\Columns;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET /projects/v1/columns – the columns shown: { mine: string[]|null (null – all), public: string[] }
 * POST /projects/v1/columns { mine, public } – saves both (the owner's on their account, the public list's for everyone)
 */
final class ColumnsController extends Controller
{
    public function registerRoutes(): void
    {
        $columns = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Columns::ALL]];

        register_rest_route(self::NAMESPACE, '/columns', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => static fn () => ['mine' => Columns::mine(get_current_user_id()), 'public' => Columns::publicColumns()],
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => static fn (WP_REST_Request $request) => Columns::save(get_current_user_id(), $request['mine'], (array) $request['public']),
                'permission_callback' => [$this, 'permission'],
                'args' => [
                    // null (or left out) – every column
                    'mine' => ['type' => ['array', 'null'], 'items' => $columns['items'], 'default' => null],
                    'public' => $columns + ['required' => true],
                ],
            ],
        ]);
    }
}
