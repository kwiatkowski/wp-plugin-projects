<?php

declare(strict_types=1);

namespace MKProjects\Rest;

use MKProjects\Domain\AlertSettings;
use MKProjects\Domain\Alerts;
use MKProjects\Domain\Notifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET  /projects/v1/alerts – the alerts' settings (AlertSettings::get())
 * POST /projects/v1/alerts { settings, smtpPassword?, slackWebhook? } – saves them; a secret: absent/null kept, '' removed
 * POST /projects/v1/alerts/test { channel, what: test|weekly } – a test message, or the weekly summary now, to one channel
 * POST /projects/v1/projects/{id}/mute { minutes } – mutes a project's alerts so long; null / 0 unmutes it
 */
final class AlertsController extends Controller
{
    public function __construct(private readonly Alerts $alerts)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/alerts', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => static fn () => AlertSettings::get(),
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => static fn (WP_REST_Request $request) => AlertSettings::save(
                    (array) $request['settings'],
                    is_string($request['smtpPassword']) ? $request['smtpPassword'] : null,
                    is_string($request['slackWebhook']) ? $request['slackWebhook'] : null,
                ),
                'permission_callback' => [$this, 'permission'],
                'args' => ['settings' => ['type' => 'object', 'required' => true]],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/alerts/test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test'],
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'channel' => ['type' => 'string', 'enum' => AlertSettings::CHANNELS, 'required' => true],
                'what' => ['type' => 'string', 'enum' => ['test', 'weekly'], 'default' => 'test'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/projects/(?P<id>\d+)/mute', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (WP_REST_Request $request) {
                $id = (int) $request['id'];

                if (get_post_type($id) !== \MKProjects\ContentTypes::PROJECT) {
                    return new WP_Error('projects_not_found', __('Nie ma takiego projektu.', 'wp-plugin-projects'), ['status' => 404]);
                }

                $this->alerts->mute($id, is_numeric($request['minutes']) ? (int) $request['minutes'] : null);

                return ['mutedUntil' => ($until = Alerts::mutedUntil($id)) ? gmdate(DATE_ATOM, $until) : null];
            },
            'permission_callback' => [$this, 'permission'],
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    /** sent – or why not, as an error the dialog shows */
    public function test(WP_REST_Request $request): array|WP_Error
    {
        $channel = (string) $request['channel'];
        $result = $request['what'] === 'weekly'
            ? $this->alerts->weekly([$channel])
            : (new Notifier())->send([$channel], '🟢 Test powiadomień', "🟢 Test powiadomień z listy projektów – działa.\n\n" . \MKProjects\App\AppShell::url(), Notifier::layout('Test powiadomień', '<p style="margin:12px 0 0">' . Notifier::dot('ok') . '&nbsp; Powiadomienia z listy projektów działają – tak będą wyglądać alerty.</p>', wp_date('j.m.Y, H:i')));

        return ($result[$channel] ?? null) === true
            ? ['sent' => true]
            : new WP_Error('projects_alert_failed', (string) ($result[$channel] ?? 'nie wysłano'), ['status' => 502]);
    }
}
