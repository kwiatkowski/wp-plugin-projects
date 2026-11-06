<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\HostRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The hosting accounts – the owner's only:
 * GET    /projects/v1/hosts                 – every account, with its projects' ids
 * POST   /projects/v1/hosts                 { name, server?, serverUrl?, serverAccount?, paidUntil?, sshHost?, sshPort?, sshUser? }
 * PATCH  /projects/v1/hosts/{id}            any of those; null clears a field
 * DELETE /projects/v1/hosts/{id}            – its projects stay, without one
 * GET    /projects/v1/hosts/{id}/password   – the panel's password: { password } (null – none)
 * POST   /projects/v1/hosts/{id}/password   { password } – sets it (encrypted), empty/null removes; returns the account
 */
final class HostsController extends Controller
{
    public function __construct(private readonly HostRepository $hosts)
    {
    }

    public function registerRoutes(): void
    {
        $fields = ['name' => ['type' => 'string', 'maxLength' => 100]];

        foreach (HostRepository::FIELDS as $field => $definition) {
            $fields[$field] = ['type' => [($definition['type'] ?? 'string'), 'null']];
        }

        register_rest_route(self::NAMESPACE, '/hosts', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn () => $this->respond($this->hosts->all()),
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->hosts->create($this->input($request)), 201),
                'permission_callback' => [$this, 'permission'],
                'args' => $fields,
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/hosts/(?P<id>\d+)', [
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->hosts->update((int) $request['id'], $this->input($request))),
                'permission_callback' => [$this, 'permission'],
                'args' => $fields,
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'permission'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/hosts/(?P<id>\d+)/password', [
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => function (WP_REST_Request $request) {
                    $password = $this->hosts->password((int) $request['id']);
                    $response = $this->respond(is_wp_error($password) ? $password : ['password' => $password]);
                    // a secret – never kept by a cache on the way
                    if (!is_wp_error($response)) {
                        $response->header('Cache-Control', 'no-store');
                    }

                    return $response;
                },
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->respond($this->hosts->setPassword((int) $request['id'], $request['password'])),
                'permission_callback' => [$this, 'permission'],
                'args' => ['password' => ['type' => ['string', 'null'], 'maxLength' => 500]],
            ],
        ]);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->hosts->delete((int) $request['id']);

        return $this->respond($result === true ? ['deleted' => true] : $result);
    }

    /** @return array<string, mixed> only what the request has – a missing field stays as it is */
    private function input(WP_REST_Request $request): array
    {
        return $this->sent($request, ['name', ...array_keys(HostRepository::FIELDS)]);
    }
}
