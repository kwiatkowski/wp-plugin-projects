<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\CategoryRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET    /projects/v1/categories        – everyone (the public list is grouped by them too)
 * POST   /projects/v1/categories        { name }
 * PATCH  /projects/v1/categories/{id}   { name }
 * DELETE /projects/v1/categories/{id}   – its projects stay, without a category
 * POST   /projects/v1/categories/order  { ids: [3, 1, 2] } – every category exactly once, in the new order; returns the list
 */
final class CategoriesController extends Controller
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    public function registerRoutes(): void
    {
        $name = [
            'type' => 'string',
            'required' => true,
            'minLength' => 1,
            'maxLength' => 100,
            'sanitize_callback' => static fn ($value) => sanitize_text_field($value),
        ];

        register_rest_route(self::NAMESPACE, '/categories', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn () => $this->respond($this->categories->all()),
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->categories->create($request['name']), 201),
                'permission_callback' => [$this, 'permission'],
                'args' => ['name' => $name],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/categories/order', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond($this->categories->reorder((array) $request['ids'])),
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'ids' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer', 'minimum' => 1], 'validate_callback' => 'rest_validate_request_arg'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/categories/(?P<id>\d+)', [
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->categories->rename((int) $request['id'], $request['name'])),
                'permission_callback' => [$this, 'permission'],
                'args' => ['name' => $name],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'permission'],
            ],
        ]);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->categories->delete((int) $request['id']);

        return $this->respond($result === true ? ['deleted' => true] : $result);
    }
}
