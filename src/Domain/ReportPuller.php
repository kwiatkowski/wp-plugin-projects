<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use WP_Error;
use WpOrg\Requests\Exception as RequestsException;
use WpOrg\Requests\Exception\Transport\Curl as CurlException;
use WpOrg\Requests\Hooks;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

/**
 * The list fetches the sites' reports itself: GET {site}/wp-json/projects-report/v1/report with the project's token
 * (MK Core's or plugin_meden's report module answers it) and a look at the home page – every site at once.
 * `wp projects-report` on the list's site runs it (filter `mk_core/report/pull`), so the cron there keeps all of them
 * fresh. The home page's code sets online / offline even when the report doesn't come (a site that breaks entirely).
 *
 * The cron (every 5 minutes) pulls everything – the reports, the certificates – once an hour (fullDue()); in between
 * only check() – the home pages and the stagings: a site that goes down shows up within minutes, and the sites don't
 * build a report 288 times a day.
 */
final class ReportPuller
{
    public const PATH = '/wp-json/projects-report/v1/report';

    /** a full pull from the cron this often – a status check in between */
    private const FULL_EVERY = HOUR_IN_SECONDS;
    /** a cron run a bit early still counts – every 5 minutes, it would otherwise miss the hour by seconds */
    private const SLACK = 5 * MINUTE_IN_SECONDS;
    private const LAST_FULL = 'wp_plugin_projects_last_full_pull';
    /** a home page or staging that didn't answer (or answered 5xx) is asked once more after this many seconds – a blip
     *  of the network doesn't take a site down */
    private const RETRY_AFTER = 5;
    /** this many requests at once – more of them at a time (several sites share a server) inflate the response times */
    private const AT_ONCE = 4;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly SslChecker $ssl = new SslChecker(),
        private readonly History $history = new History(),
    ) {
    }

    /**
     * Every project in the list's order: ok – true the report came, false it didn't (error says why), null not asked
     * (the list's own site with $ownToken – it has just sent its report).
     *
     * @return array<int, array{name: string, reportedAt: string|null, ok: bool|null, error: string|null, changes: array<int, array<string, mixed>>}>
     */
    public function pull(string $ownToken = ''): array
    {
        update_option(self::LAST_FULL, time(), false);
        $this->history->prune();

        return $this->rows($this->run($this->projects->pullTargets(), $ownToken));
    }

    /**
     * The status check between the full pulls: only the home pages (online / offline) and the stagings – nothing asked
     * of the sites' reports, no certificates. Rows as pull() gives them; ok is null (no report asked for).
     *
     * @return array<int, array{name: string, reportedAt: string|null, ok: bool|null, error: string|null, changes: array<int, array<string, mixed>>}>
     */
    public function check(): array
    {
        return $this->rows($this->run($this->projects->pullTargets(), '', false));
    }

    /** whether the cron's next run should pull everything – an hour since the last full pull */
    public function fullDue(): bool
    {
        return time() - (int) get_option(self::LAST_FULL, 0) >= self::FULL_EVERY - self::SLACK;
    }

    /**
     * @param array<int, array{ok: bool|null, error: string|null, changes: array<int, array<string, mixed>>}> $results
     * @return array<int, array{name: string, reportedAt: string|null, ok: bool|null, error: string|null, changes: array<int, array<string, mixed>>}>
     */
    private function rows(array $results): array
    {
        return array_map(
            static fn (array $project): array => ['name' => $project['name'], 'reportedAt' => $project['reportedAt']] + ($results[$project['id']] ?? ['ok' => null, 'error' => null, 'changes' => []]),
            $this->projects->reportTimes(),
        );
    }

    /**
     * One project's report, now – "Pobierz raport" in its dialog: whether it came (or why not), what it changed, and
     * the project as it is after it.
     *
     * @return array{ok: bool|null, error: string|null, changes: array<int, array<string, mixed>>, project: array<string, mixed>}|WP_Error
     */
    public function pullOne(int $id): array|WP_Error
    {
        $target = $this->projects->pullTarget($id);

        if ($target === null) {
            return new WP_Error('projects_not_found', __('Nie ma takiego projektu.', 'wp-plugin-projects'), ['status' => 404]);
        }

        $result = $this->run([$target])[$id];
        $project = $this->projects->find($id);

        return is_wp_error($project) ? $project : $result + ['project' => $project];
    }

    /**
     * Pulls the given sites at once: their certificates, stagings, home pages and reports – keyed by the project's id.
     * Without $reports only the stagings and the home pages.
     *
     * @param array<int, array<string, mixed>> $all as ProjectRepository::pullTargets() gives them
     * @return array<int, array{ok: bool|null, error: string|null, changes: array<int, array<string, mixed>>}>
     */
    private function run(array $all, string $ownToken = '', bool $reports = true): array
    {
        $targets = [];
        $results = [];

        foreach ($all as $target) {
            // the certificate of every site with a domain – a token isn't needed for it
            if ($reports && $target['url'] !== null) {
                $this->projects->applySsl($target['id'], $this->ssl->check((string) parse_url($target['url'], PHP_URL_HOST)));
            }

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
        $stagings = [];
        $homes = [];

        // every staging – with its own Basic Auth login – answers or not; a token isn't needed for it
        foreach ($all as $target) {
            if ($target['staging'] !== null) {
                $stagings[] = $target['id'];
                $requests["staging-{$target['id']}"] = [
                    'url' => $target['staging'],
                    'headers' => $target['stagingAuth'] ? ['Authorization' => 'Basic ' . base64_encode(implode(':', $target['stagingAuth']))] : [],
                ];
            }

            // every home page too – the availability of a site without a token (no report) counts as well
            if ($target['url'] !== null) {
                $homes[] = $target['id'];
                $auth = $target['auth'] ? ['Authorization' => 'Basic ' . base64_encode(implode(':', $target['auth']))] : [];
                $requests["home-{$target['id']}"] = ['url' => $target['url'] . '/', 'headers' => $auth];
            }
        }

        foreach ($targets as $id => $target) {
            // a site behind Basic Auth gets its login in Authorization – the token then goes in X-Projects-Token alone;
            // the header is set by hand: Requests' `auth` option hooks into curl.before_send, which request_multiple
            // never fires, so the login would not be sent at all
            $auth = $target['auth'] ? ['Authorization' => 'Basic ' . base64_encode(implode(':', $target['auth']))] : [];
            $token = ['X-Projects-Token' => $target['token']] + ($auth ?: ['Authorization' => 'Bearer ' . $target['token']]);

            if ($reports) {
                $requests["report-$id"] = ['url' => $target['url'] . self::PATH, 'headers' => ['Accept' => 'application/json'] + $auth + $token];
            }
        }

        $times = [];
        $responses = $this->fetch($requests, $times);

        // a home page or staging without an answer – once more a moment later, before it counts
        $again = array_filter($requests, fn (string $key): bool => !str_starts_with($key, 'report-') && !self::answered($responses[$key] ?? null), ARRAY_FILTER_USE_KEY);

        if ($again) {
            sleep(self::RETRY_AFTER);
            $responses = $this->fetch($again, $times) + $responses;
        }

        foreach ($stagings as $id) {
            $staging = $responses["staging-$id"] ?? null;
            $code = $staging instanceof Response ? (int) $staging->status_code : 0;
            $this->projects->applyStaging($id, $code, self::failure($staging));
            $this->history->check($id, 'staging', $code, $times["staging-$id"] ?? null, self::failure($staging));
        }

        foreach ($homes as $id) {
            $home = $responses["home-$id"] ?? null;
            $this->history->check($id, 'home', $home instanceof Response ? (int) $home->status_code : 0, $times["home-$id"] ?? null, self::failure($home));

            // a site without a token (no report) – its status from the home page alone
            if (!isset($targets[$id]) && $results[$id]['ok'] !== null) {
                $change = $this->projects->applyHomeCode($id, $home instanceof Response ? (int) $home->status_code : 0);
                $results[$id]['changes'] = $change ? [$change] : [];
            }
        }

        foreach ($targets as $id => $target) {
            $home = $responses["home-$id"] ?? null;
            $code = $home instanceof Response ? (int) $home->status_code : 0;

            // the status check: the home page alone – no report was asked for
            if (!$reports) {
                $change = $this->projects->applyHomeCode($id, $code);
                $results[$id] = ['ok' => null, 'error' => $code ? null : 'brak odpowiedzi', 'changes' => $change ? [$change] : []];
                continue;
            }

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

        return $results;
    }

    /**
     * The requests, AT_ONCE at a time; how long each took (ms, redirects included) goes into $times.
     *
     * @param array<string, array<string, mixed>> $requests
     * @param array<string, int> $times
     * @return array<string, mixed> a Response, or the exception of a request that got no answer
     */
    private function fetch(array $requests, array &$times): array
    {
        foreach ($requests as $key => &$request) {
            unset($times[$key]);
            // curl's own measure of each request – Requests' Response doesn't keep it
            $hooks = new Hooks();
            $hooks->register('curl.after_request', static function (mixed $headers, mixed $info = null) use ($key, &$times): void {
                if (is_array($info) && isset($info['total_time'])) {
                    $times[$key] = ($times[$key] ?? 0) + (int) round((float) $info['total_time'] * 1000);
                }
            });
            $request['options'] = ['hooks' => $hooks];
        }

        unset($request);
        $responses = [];

        foreach (array_chunk($requests, self::AT_ONCE, true) as $batch) {
            $responses += Requests::request_multiple($batch, [
                'timeout' => 20,
                'connect_timeout' => 10,
                'redirects' => 5,
                'verify' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
                'useragent' => 'wp-plugin-projects/' . WP_PLUGIN_PROJECTS_VERSION,
            ]);
        }

        return $responses;
    }

    /** whether a request got an answer that counts – not a server error (5xx), not none */
    private static function answered(mixed $response): bool
    {
        return $response instanceof Response && $response->status_code > 0 && $response->status_code < 500;
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

    /**
     * Why a request got no answer at all, for the list: dns (no such domain), refused (nothing listens), timeout, ssl
     * (the certificate), redirects (a loop), other – null when it got one.
     */
    private static function failure(mixed $response): ?string
    {
        if ($response instanceof Response) {
            return null;
        }

        if ($response instanceof RequestsException && $response->getType() === 'toomanyredirects') {
            return 'redirects';
        }

        // curl's error number: a CurlException's code from a parallel request, or "cURL error N:" from a redirect's own
        $code = match (true) {
            $response instanceof CurlException => (int) $response->getCode(),
            $response instanceof RequestsException && preg_match('/^cURL error (\d+)/', $response->getMessage(), $match) === 1 => (int) $match[1],
            default => 0,
        };

        return match ($code) {
            5, 6 => 'dns',
            7 => 'refused',
            28 => 'timeout',
            35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => 'ssl',
            47 => 'redirects',
            default => 'other',
        };
    }
}
