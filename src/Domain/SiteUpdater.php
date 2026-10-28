<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use WP_Error;

/**
 * "Aktualizuj przez Composer": the list asks the site to update itself – plugin_meden's Updater
 * ({site}/wp-json/projects-report/v1/update, the project's token, Basic Auth when the site sits behind it). POST starts
 * it, GET says how it goes: { available, reason, state: idle|running|done|nothing|failed, startedAt, finishedAt, log }.
 */
final class SiteUpdater
{
    public const PATH = '/wp-json/projects-report/v1/update';

    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    /** @return array<string, mixed>|WP_Error */
    public function call(int $id, string $method): array|WP_Error
    {
        $target = $this->projects->pullTarget($id);

        if ($target === null) {
            return new WP_Error('projects_not_found', __('Nie ma takiego projektu.', 'wp-plugin-projects'), ['status' => 404]);
        }

        if ($target['token'] === null || $target['url'] === null) {
            return new WP_Error('projects_update_unavailable', 'Projekt bez tokenu raportu albo bez domeny.', ['status' => 409]);
        }

        // as ReportPuller: behind Basic Auth the login goes in Authorization, the token in X-Projects-Token alone
        $headers = ['Accept' => 'application/json', 'X-Projects-Token' => $target['token']]
            + ($target['auth'] ? ['Authorization' => 'Basic ' . base64_encode(implode(':', $target['auth']))] : ['Authorization' => 'Bearer ' . $target['token']]);

        $response = wp_remote_request($target['url'] . self::PATH, [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 20,
            'user-agent' => 'wp-plugin-projects/' . WP_PLUGIN_PROJECTS_VERSION,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('projects_update_failed', 'Strona nie odpowiada: ' . $response->get_error_message(), ['status' => 502]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        return match (true) {
            $code === 200 && is_array($data) => $data,
            $code === 404 => new WP_Error('projects_update_unavailable', 'Strona nie ma aktualizacji z listy – zaktualizuj plugin_meden do 1.13.0.', ['status' => 409]),
            $code === 401 || $code === 403 => new WP_Error('projects_update_failed', 'Strona odrzuciła token.', ['status' => 502]),
            is_array($data) && isset($data['message']) => new WP_Error('projects_update_failed', (string) $data['message'], ['status' => $code >= 400 && $code < 500 ? $code : 502]),
            default => new WP_Error('projects_update_failed', "Strona odpowiedziała HTTP $code.", ['status' => 502]),
        };
    }
}
