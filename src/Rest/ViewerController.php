<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\Viewer;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The list's viewer account (not a WordPress user):
 * POST /projects/v1/viewer/login { login, password } – a session cookie; { ok }
 * POST /projects/v1/viewer/logout – ends it
 * POST /projects/v1/viewer/account { login, password? } – the owner sets it (an empty login removes it); { login }
 */
final class ViewerController extends Controller
{
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/viewer/login', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond(($result = Viewer::login((string) $request['login'], (string) $request['password'])) === true ? ['ok' => true] : $result),
            'permission_callback' => '__return_true',
            'args' => [
                'login' => ['type' => 'string', 'required' => true, 'maxLength' => 100],
                'password' => ['type' => 'string', 'required' => true, 'maxLength' => 500],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/viewer/logout', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => static function (): array {
                Viewer::logout();

                return ['ok' => true];
            },
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/viewer/account', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request) => $this->respond(($login = Viewer::setAccount((string) $request['login'], $request['password'])) instanceof \WP_Error ? $login : ['login' => $login]),
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'login' => ['type' => 'string', 'required' => true, 'maxLength' => 100],
                'password' => ['type' => ['string', 'null'], 'maxLength' => 500, 'default' => null],
            ],
        ]);
    }
}
