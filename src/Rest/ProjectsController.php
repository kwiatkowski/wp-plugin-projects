<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\Fields;
use MKProjects\Domain\ProjectRepository;
use MKProjects\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET    /projects/v1/projects             – everyone: the public list; the owner: every project, every field
 * POST   /projects/v1/projects             { name, ...fields }
 * PATCH  /projects/v1/projects/{id}        { name?, ...fields } – null clears a field
 * DELETE /projects/v1/projects/{id}        – to the trash
 * POST   /projects/v1/projects/{id}/token  – a new reporter token; returns the project
 */
final class ProjectsController extends Controller
{
    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/projects', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn () => $this->respond($this->projects->all(Plugin::canManage())),
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->projects->create($this->sent($request, $this->fields())), 201),
                'permission_callback' => [$this, 'permission'],
                'args' => $this->args(true),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/projects/(?P<id>\d+)/token', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond($this->projects->regenerateToken((int) $request['id'])),
            'permission_callback' => [$this, 'permission'],
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/projects/(?P<id>\d+)', [
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->projects->update((int) $request['id'], $this->sent($request, $this->fields()))),
                'permission_callback' => [$this, 'permission'],
                'args' => $this->args(false),
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
        $result = $this->projects->delete((int) $request['id']);

        return $this->respond($result === true ? ['deleted' => true] : $result);
    }

    /** @return string[] */
    private function fields(): array
    {
        return ['name', ...array_keys(Fields::ALL)];
    }

    /** Types only – the repository cleans the values (URLs, dates, allowed statuses). */
    private function args(bool $creating): array
    {
        $args = [
            'name' => [
                'type' => 'string',
                'required' => $creating,
                'minLength' => 1,
                'maxLength' => 100,
                'sanitize_callback' => static fn ($value) => sanitize_text_field($value),
            ],
        ];

        foreach (Fields::ALL as $field => $definition) {
            $args[$field] = [
                'type' => $definition['type'] === 'boolean' ? 'boolean' : ['string', 'null'],
                'validate_callback' => 'rest_validate_request_arg',
            ];

            if (isset($definition['enum'])) {
                $args[$field]['enum'] = [...$definition['enum'], null];
            }
        }

        return $args;
    }
}
