<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\ClientErrors;
use MKProjects\Domain\Viewer;
use MKProjects\Plugin;
use WP_REST_Request;
use WP_REST_Server;

/**
 * POST /projects/v1/client-errors { message, stack?, info?, url?, project?, version?, agent? } – an error the app met in
 * the browser, kept for later (ClientErrors). Only from the owner and the viewer account – a guest's page sends none.
 */
final class ClientErrorsController extends Controller
{
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/client-errors', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => static function (WP_REST_Request $request): array {
                ClientErrors::add((array) $request->get_json_params());

                return ['ok' => true];
            },
            'permission_callback' => static fn (): bool => Plugin::canManage() || Viewer::active(),
            'args' => [
                'message' => ['type' => 'string', 'required' => true, 'maxLength' => 2000],
            ],
        ]);
    }
}
