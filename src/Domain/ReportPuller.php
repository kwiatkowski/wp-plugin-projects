<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

/**
 * The list fetches the sites' reports itself: GET {site}/wp-json/projects-report/v1/report with the project's token
 * (MK Core's or plugin_meden's report module answers it) and a look at the home page – every site at once.
 * `wp projects-report` on the list's site runs it (filter `mk_core/report/pull`), so the cron there keeps all of them
 * fresh. The home page's code sets online / offline even when the report doesn't come (a site that breaks entirely).
 */
final class ReportPuller
{
    public const PATH = '/wp-json/projects-report/v1/report';

    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    /**
     * Every project in the list's order: ok – true the report came, false it didn't (error says why), null not asked
     * (the list's own site with $ownToken – it has just sent its report).
     *
     * @return array<int, array{name: string, reportedAt: string|null, ok: bool|null, error: string|null, changes: array<int, array<string, mixed>>}>
     */
    public function pull(string $ownToken = ''): array
    {
        $targets = [];
        $results = [];

        foreach ($this->projects->pullTargets() as $target) {
            $results[$target['id']] = match (true) {
                $target['token'] === null => ['ok' => false, 'error' => 'brak tokenu', 'changes' => []],
                $target['url'] === null => ['ok' => false, 'error' => 'brak domeny', 'changes' => []],
                $ownToken !== '' && hash_equals($target['token'], $ownToken) => ['ok' => null, 'error' => null, 'changes' => []],
                default => null,
            };

            if ($results[$target['id']] === null) {
                $targets[$target['id']] = $target;
            }
        }

        $requests = [];

        foreach ($targets as $id => $target) {
            // a site behind Basic Auth gets its login in Authorization – the token then goes in X-Projects-Token alone
            $options = $target['auth'] ? ['auth' => $target['auth']] : [];
            $token = ['X-Projects-Token' => $target['token']] + ($target['auth'] ? [] : ['Authorization' => 'Bearer ' . $target['token']]);

            $requests["home-$id"] = ['url' => $target['url'] . '/', 'options' => $options];
            $requests["report-$id"] = ['url' => $target['url'] . self::PATH, 'headers' => ['Accept' => 'application/json'] + $token, 'options' => $options];
        }

        $responses = $requests ? Requests::request_multiple($requests, [
            'timeout' => 20,
            'connect_timeout' => 10,
            'redirects' => 5,
            'verify' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
            'useragent' => 'wp-plugin-projects/' . WP_PLUGIN_PROJECTS_VERSION,
        ]) : [];

        foreach ($targets as $id => $target) {
            $home = $responses["home-$id"] ?? null;
            $code = $home instanceof Response ? (int) $home->status_code : 0;
            [$report, $error] = $this->read($responses["report-$id"] ?? null, $target['auth'] !== null);

            if ($report !== null) {
                // the list's own look at the home page counts, not the site's
                $project = $this->projects->applyReport($target['token'], ['homeCode' => $code] + $report);

                if (!is_wp_error($project)) {
                    $results[$id] = ['ok' => true, 'error' => null, 'changes' => $project['changes']];
                    continue;
                }

                $error = $project->get_error_message();
            }

            $change = $this->projects->applyHomeCode($id, $code);
            $results[$id] = ['ok' => false, 'error' => $error, 'changes' => $change ? [$change] : []];
        }

        return array_map(
            static fn (array $project): array => ['name' => $project['name'], 'reportedAt' => $project['reportedAt']] + ($results[$project['id']] ?? ['ok' => null, 'error' => null, 'changes' => []]),
            $this->projects->reportTimes(),
        );
    }

    /**
     * The report from the site's answer, or why there is none.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function read(mixed $response, bool $withAuth): array
    {
        if (!$response instanceof Response) {
            return [null, 'brak odpowiedzi'];
        }

        $data = json_decode($response->body, true);

        // the server's own password prompt, not WordPress's answer – some servers (LiteSpeed) add the Basic Auth
        // header to WordPress's own 401 too, so a REST error in the body means the password got through
        if ($response->status_code === 401 && isset($response->headers['www-authenticate']) && !isset($data['code'])) {
            return [null, $withAuth ? 'Basic Auth: zły login lub hasło' : 'strona za hasłem (Basic Auth) – login i hasło w zakładce Dostęp'];
        }

        return match (true) {
            $response->status_code === 200 && is_array($data) => [$data, null],
            $response->status_code === 200 => [null, 'odpowiedź to nie raport'],
            $response->status_code === 404 => [null, 'brak modułu raportu (MK Core / plugin_meden)'],
            in_array($response->status_code, [401, 403], true) => [null, 'token się nie zgadza'],
            default => [null, 'HTTP ' . $response->status_code],
        };
    }
}
