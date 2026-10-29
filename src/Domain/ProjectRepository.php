<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use MKProjects\ContentTypes;
use WP_Error;
use WP_Post;

/**
 * Projects (posts of the `mk_project` type) as plain arrays:
 * { id, name, position, ...Fields::ALL, todo, reportedAt, token, hasPassword, updatedAt }.
 *
 * Three views: the full one for the owner (admin); the viewer's (the list's own account) – every project, with the
 * fields of the columns chosen for viewers; the guest's – only projects with a domain, their name, category, order
 * and Fields::publicFields(). Strings and numbers that are empty are null,
 * booleans never are. `position` is the user's order within the project's category (null = never placed: last, by name);
 * `todo` is the linked Todo project with its open task counts (owner only).
 */
final class ProjectRepository
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly TodoBridge $todo,
        private readonly Secrets $secrets = new Secrets(),
        private readonly History $history = new History(),
    ) {
    }

    /** the home pages' availability and response times – read once per request, for every project */
    private ?array $summaries = null;

    /**
     * A project's history (History::project()).
     *
     * @return array<string, mixed>|WP_Error
     */
    public function history(int $id): array|WP_Error
    {
        return $this->post($id) ? $this->history->project($id) : $this->notFound();
    }

    /**
     * @param string $view admin, viewer or guest (Columns::VIEWS)
     * @return array<int, array<string, mixed>>
     */
    public function all(string $view): array
    {
        $posts = get_posts([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'no_found_rows' => true,
        ]);

        $projects = array_map([$this, 'toArray'], $posts);

        if ($view !== 'guest') {
            $projects = array_map(fn (array $project): array => $project + ['todo' => $this->todo->summary($project['todoProject'])], $projects);
        }

        if ($view === 'viewer') {
            $fields = array_flip(['id', 'name', 'categoryId', 'position', ...Columns::fields('viewer')]);
            // the token itself stays the owner's – a viewer learns only that the site reports
            $projects = array_map(
                static fn (array $project): array => array_intersect_key(['token' => $project['token'] ? 'ukryty' : null] + $project, $fields),
                $projects,
            );
        } elseif ($view === 'guest') {
            $fields = array_flip(['id', 'name', 'categoryId', 'position', ...Fields::publicFields()]);
            $projects = array_values(array_map(
                static fn (array $project): array => array_intersect_key($project, $fields),
                array_filter($projects, static fn (array $project): bool => (bool) $project['domains']),
            ));
        }

        $collator = class_exists(\Collator::class) ? new \Collator(get_locale()) : null;
        usort($projects, static fn (array $a, array $b): int => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX)
            ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strnatcasecmp($a['name'], $b['name'])));

        return $projects;
    }

    /** @return array<string, mixed>|WP_Error the owner's view */
    public function find(int $id): array|WP_Error
    {
        $post = $this->post($id);

        if (!$post) {
            return $this->notFound();
        }

        $project = $this->toArray($post);

        return $project + ['todo' => $this->todo->summary($project['todoProject'])];
    }

    /**
     * @param array<string, mixed> $data `name` and any of Fields::ALL
     * @return array<string, mixed>|WP_Error
     */
    public function create(array $data): array|WP_Error
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return $this->invalid(__('Projekt musi mieć nazwę.', 'wp-plugin-projects'));
        }

        if ($error = $this->validate($data)) {
            return $error;
        }

        $id = wp_insert_post(wp_slash([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'post_title' => $data['name'],
        ]), true);

        if (is_wp_error($id)) {
            return $this->invalid($id->get_error_message());
        }

        $this->save($id, $data);
        $this->placeLast($id);

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $changes any of `name` and Fields::ALL; null clears a field
     * @return array<string, mixed>|WP_Error
     */
    public function update(int $id, array $changes): array|WP_Error
    {
        $post = $this->post($id);

        if (!$post) {
            return $this->notFound();
        }

        if ($error = $this->validate($changes)) {
            return $error;
        }

        if (array_key_exists('name', $changes)) {
            if (trim((string) $changes['name']) === '') {
                return $this->invalid(__('Projekt musi mieć nazwę.', 'wp-plugin-projects'));
            }

            $result = wp_update_post(wp_slash(['ID' => $id, 'post_title' => $changes['name']]), true);

            if (is_wp_error($result)) {
                return $this->invalid($result->get_error_message());
            }
        }

        $category = $this->toArray($post)['categoryId'];
        $this->save($id, $changes);

        // moved to another category – at its end
        if (array_key_exists('categoryId', $changes) && $this->toArray($post)['categoryId'] !== $category) {
            $this->placeLast($id);
        }

        // bump the modified date also when only meta changed
        if (!array_key_exists('name', $changes)) {
            wp_update_post(['ID' => $id]);
        }

        clean_post_cache($id);

        return $this->find($id);
    }

    /**
     * Sets the order of the given projects – usually one category's, as they stand in the list.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>|WP_Error all projects (owner's view)
     */
    public function reorder(array $ids): array|WP_Error
    {
        $ids = array_map('intval', $ids);

        if (!$ids || count($ids) !== count(array_unique($ids)) || array_filter($ids, fn (int $id): bool => !$this->post($id))) {
            return $this->invalid(__('Nieprawidłowa kolejność projektów.', 'wp-plugin-projects'));
        }

        foreach ($ids as $position => $id) {
            update_post_meta($id, ContentTypes::META_POSITION, $position);
        }

        return $this->all('admin');
    }

    /** Moves the project to the trash – it can still be restored in wp-admin. */
    public function delete(int $id): true|WP_Error
    {
        if (!$this->post($id)) {
            return $this->notFound();
        }

        return wp_trash_post($id) ? true : new WP_Error('projects_delete_failed', __('Nie udało się usunąć projektu.', 'wp-plugin-projects'), ['status' => 500]);
    }

    /** The secrets kept encrypted: the panel's password, the HTTP Basic Auth ones (the site's, the staging's), reCAPTCHA's secret key. */
    public const PASSWORDS = [
        'panel' => ContentTypes::META_PASSWORD,
        'auth' => ContentTypes::META_AUTH_PASSWORD,
        'staging' => ContentTypes::META_STAGING_AUTH_PASSWORD,
        'recaptcha' => ContentTypes::META_RECAPTCHA_SECRET,
    ];

    /**
     * A password (panel – the panel's, auth – Basic Auth's), decrypted; null – none (or no key opens it – Secrets).
     */
    public function password(int $id, string $kind = 'panel'): string|null|WP_Error
    {
        if (!$this->post($id)) {
            return $this->notFound();
        }

        $stored = (string) get_post_meta($id, self::PASSWORDS[$kind] ?? ContentTypes::META_PASSWORD, true);

        return $stored === '' ? null : $this->secrets->decrypt($stored);
    }

    /**
     * Saves a password (panel / auth) encrypted (Secrets – the list's own key, outside the database: a database dump
     * alone doesn't reveal it); empty or null removes it. Returns the project.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function setPassword(int $id, ?string $password, string $kind = 'panel'): array|WP_Error
    {
        $post = $this->post($id);
        $key = self::PASSWORDS[$kind] ?? ContentTypes::META_PASSWORD;

        if (!$post) {
            return $this->notFound();
        }

        if ($password === null || $password === '') {
            delete_post_meta($id, $key);
        } else {
            update_post_meta($id, $key, $this->secrets->encrypt(mb_substr($password, 0, 500)));
        }

        return $this->toArray($post);
    }

    /**
     * Every stored secret onto the list's own key (Secrets) – those still under the AUTH salt's; how many moved, and how
     * many no key opens (lost).
     *
     * @return array{moved: int, unreadable: int}
     */
    public function rekeySecrets(): array
    {
        $moved = $unreadable = 0;

        foreach (self::PASSWORDS as $key) {
            foreach (get_posts(['post_type' => ContentTypes::PROJECT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => $key]) as $id) {
                $stored = (string) get_post_meta($id, $key, true);
                $plain = $stored === '' ? null : $this->secrets->decrypt($stored);

                if ($plain === null) {
                    $unreadable += $stored === '' ? 0 : 1;
                } elseif ($this->secrets->legacy($stored)) {
                    update_post_meta($id, $key, $this->secrets->encrypt($plain));
                    $moved++;
                }
            }
        }

        return ['moved' => $moved, 'unreadable' => $unreadable];
    }

    /** A new reporter token – the old one stops working at once. */
    public function regenerateToken(int $id): array|WP_Error
    {
        if (!$this->post($id)) {
            return $this->notFound();
        }

        update_post_meta($id, ContentTypes::META_TOKEN, wp_generate_password(40, false));

        return $this->find($id);
    }

    /** No token – the site's reports are refused from now on; the last report and its time stay. */
    public function removeToken(int $id): array|WP_Error
    {
        if (!$this->post($id)) {
            return $this->notFound();
        }

        delete_post_meta($id, ContentTypes::META_TOKEN);

        return $this->find($id);
    }

    /**
     * Saves what a site reported about itself (only Fields::reported(), and online / offline from homeCode) and stamps the time.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>|WP_Error the project, with `previousReportAt` and `changes` – what the report changed
     *   on the list: [{ what: field|'plugin'|'users'|'online', name?, from, to }]
     */
    public function applyReport(string $token, array $report): array|WP_Error
    {
        $post = $this->byToken($token);

        if (!$post) {
            return new WP_Error('projects_invalid_token', __('Nieprawidłowy token raportu.', 'wp-plugin-projects'), ['status' => 401]);
        }

        $before = $this->toArray($post);
        $previous = json_decode((string) get_post_meta($post->ID, ContentTypes::META_REPORT, true), true);

        $data = array_intersect_key($report, array_flip(Fields::reported()));

        // deploy – git on production: the report brings its origin (null without git); an older reporter without
        // `repo` leaves it alone
        if (array_key_exists('repo', $report)) {
            $data['deploy'] = !empty($report['repo']);
        }

        // a site without git (or an older reporter) doesn't clear the repository typed in by hand
        if (empty($data['repo'])) {
            unset($data['repo']);
        }

        // the home page's HTTP code (0 – no answer) sets online / offline
        $status = isset($report['homeCode']) ? $this->statusFor($before['status'], (int) $report['homeCode']) : null;

        if ($status !== null) {
            $data['status'] = $status;
        }

        $this->save($post->ID, $data);
        update_post_meta($post->ID, ContentTypes::META_REPORTED_AT, gmdate(DATE_ATOM));
        // the whole report (plugins, environment …) for a closer look later
        update_post_meta($post->ID, ContentTypes::META_REPORT, wp_slash((string) wp_json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        $project = $this->toArray($post);
        $project['previousReportAt'] = $before['reportedAt'];
        $project['changes'] = $this->reportChanges($before, $project, is_array($previous) ? $previous : [], $report);
        $this->logChanges($post->ID, $project['changes']);

        return $project;
    }

    /**
     * The status from the list's own look at the home page, when the site's report didn't come.
     *
     * @return array{what: string, from: mixed, to: mixed}|null the change
     */
    public function applyHomeCode(int $id, int $code): ?array
    {
        $before = get_post_meta($id, Fields::metaKey('status'), true) ?: null;
        $status = $this->statusFor($before, $code);

        if ($status === null || $status === $before) {
            return null;
        }

        $this->save($id, ['status' => $status]);
        $change = ['what' => 'status', 'from' => $before, 'to' => $status];
        $this->logChanges($id, [$change]);

        return $change;
    }

    /** Online when the home page answers without an error (2xx, 3xx – or 401: it works, behind a password), else offline; null – a site in progress or suspended keeps its status (set by hand). */
    private function statusFor(?string $current, int $code): ?string
    {
        if (in_array($current, ['dev', 'suspended'], true)) {
            return null;
        }

        return ($code >= 200 && $code < 400) || $code === 401 ? 'online' : 'offline';
    }

    /**
     * Projects whose site the list can ask for its report: a token and a domain – the site's address is the first domain;
     * auth – the Basic Auth login and password the site sits behind (null – none).
     *
     * @return array<int, array{id: int, token: string|null, url: string|null, auth: array{0: string, 1: string}|null}>
     */
    public function pullTargets(): array
    {
        $posts = get_posts(['post_type' => ContentTypes::PROJECT, 'post_status' => 'publish', 'numberposts' => -1, 'no_found_rows' => true]);

        return array_map([$this, 'target'], $posts);
    }

    /** What the staging answered (0 – nothing, and why: ReportPuller::failure()), when the list asked. */
    public function applyStaging(int $id, int $code, ?string $error = null): void
    {
        $staging = ['code' => $code, 'checkedAt' => gmdate(DATE_ATOM)] + ($error !== null ? ['error' => $error] : []);
        update_post_meta($id, ContentTypes::META_STAGING, wp_slash((string) wp_json_encode($staging)));
    }

    /** Keeps what SslChecker found for the project. */
    public function applySsl(int $id, array $ssl): void
    {
        update_post_meta($id, ContentTypes::META_SSL, wp_slash((string) wp_json_encode($ssl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }

    /**
     * One project's site, as pullTargets() gives it – null when there is no such project.
     *
     * @return array{id: int, token: string|null, url: string|null, auth: array{0: string, 1: string}|null}|null
     */
    public function pullTarget(int $id): ?array
    {
        $post = $this->post($id);

        return $post ? $this->target($post) : null;
    }

    /** @return array{id: int, token: string|null, url: string|null, auth: array{0: string, 1: string}|null, staging: string|null, stagingAuth: array{0: string, 1: string}|null} */
    private function target(WP_Post $post): array
    {
        $staging = trim((string) get_post_meta($post->ID, Fields::metaKey('stagingUrl'), true));

        $domains = (array) get_post_meta($post->ID, Fields::metaKey('domains'), true);
        $domain = trim((string) preg_replace('#^https?://#i', '', (string) ($domains[0] ?? '')), '/');

        return [
            'id' => $post->ID,
            'token' => get_post_meta($post->ID, ContentTypes::META_TOKEN, true) ?: null,
            'url' => $domain !== '' ? 'https://' . $domain : null,
            'auth' => ($user = (string) get_post_meta($post->ID, Fields::metaKey('authUser'), true)) !== ''
                ? [$user, (string) $this->password($post->ID, 'auth')]
                : null,
            'staging' => $staging !== '' ? $staging : null,
            'stagingAuth' => ($stagingUser = (string) get_post_meta($post->ID, Fields::metaKey('stagingAuthUser'), true)) !== ''
                ? [$stagingUser, (string) $this->password($post->ID, 'staging')]
                : null,
        ];
    }

    /**
     * What changed goes into the history: the status as its own event (down, back), the rest as "change" – the users
     * online don't, they change all the time.
     *
     * @param array<int, array<string, mixed>> $changes
     */
    private function logChanges(int $id, array $changes): void
    {
        foreach ($changes as $change) {
            if (($change['what'] ?? '') !== 'online') {
                $this->history->event($id, $change['what'] === 'status' ? 'status' : 'change', $change);
            }
        }
    }

    /**
     * What a report changed: the project's reported fields, the plugins (version, installed, removed) and users,
     * against the report before it.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $report
     * @return array<int, array{what: string, name?: string, from: mixed, to: mixed}>
     */
    private function reportChanges(array $before, array $after, array $previous, array $report): array
    {
        $changes = [];

        foreach ([...Fields::reported(), 'status'] as $field) {
            if ($before[$field] !== $after[$field]) {
                $changes[] = ['what' => $field, 'from' => $before[$field], 'to' => $after[$field]];
            }
        }

        // the first report has nothing to compare plugins and users with
        if (!$previous) {
            return $changes;
        }

        $plugins = static fn (array $data): array => array_column(array_filter((array) ($data['plugins'] ?? []), 'is_array'), null, 'file');
        $old = $plugins($previous);
        $new = $plugins($report);

        foreach ($new as $file => $plugin) {
            $from = isset($old[$file]) ? (string) ($old[$file]['version'] ?? '') : null;
            $to = (string) ($plugin['version'] ?? '');

            if ($from !== $to) {
                $changes[] = ['what' => 'plugin', 'name' => (string) ($plugin['name'] ?? $file), 'from' => $from, 'to' => $to];
            }
        }

        foreach (array_diff_key($old, $new) as $file => $plugin) {
            $changes[] = ['what' => 'plugin', 'name' => (string) ($plugin['name'] ?? $file), 'from' => (string) ($plugin['version'] ?? ''), 'to' => null];
        }

        // the roles' counts – a new administrator shows up in the history
        $roles = static fn (array $data): array => array_column(array_filter((array) ($data['users']['roles'] ?? []), 'is_array'), null, 'role');
        $oldRoles = $roles($previous);
        $newRoles = $roles($report);

        foreach (array_keys($oldRoles + $newRoles) as $role) {
            $from = isset($oldRoles[$role]) ? (int) ($oldRoles[$role]['count'] ?? 0) : 0;
            $to = isset($newRoles[$role]) ? (int) ($newRoles[$role]['count'] ?? 0) : 0;

            if ($from !== $to) {
                $changes[] = ['what' => 'role', 'name' => (string) ($newRoles[$role]['name'] ?? $oldRoles[$role]['name'] ?? $role), 'role' => (string) $role, 'from' => $from, 'to' => $to];
            }
        }

        foreach (['total' => 'users', 'online' => 'online'] as $key => $what) {
            $from = $previous['users'][$key] ?? null;
            $to = $report['users'][$key] ?? null;

            if ($from !== $to) {
                $changes[] = ['what' => $what, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * Every project with the time of its last report, in the list's order – categories in theirs, the projects by their
     * position within, the uncategorized last – what a site sees after reporting (`wp projects-report`).
     *
     * @return array<int, array{id: int, name: string, reportedAt: string|null}>
     */
    public function reportTimes(): array
    {
        $categoryOrder = array_flip(array_column($this->categories->all(), 'id'));
        $projects = array_map(
            fn (WP_Post $post): array => [
                'id' => $post->ID,
                'name' => html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8'),
                'reportedAt' => get_post_meta($post->ID, ContentTypes::META_REPORTED_AT, true) ?: null,
                'category' => $categoryOrder[(int) get_post_meta($post->ID, Fields::metaKey('categoryId'), true)] ?? PHP_INT_MAX,
                'position' => is_numeric($position = get_post_meta($post->ID, ContentTypes::META_POSITION, true)) ? (int) $position : PHP_INT_MAX,
            ],
            get_posts(['post_type' => ContentTypes::PROJECT, 'post_status' => 'publish', 'numberposts' => -1, 'no_found_rows' => true]),
        );

        // the app's compareManual: placed by position, never placed ones after them, by name
        usort($projects, static fn (array $a, array $b): int => [$a['category'], $a['position']] <=> [$b['category'], $b['position']]
            ?: strnatcasecmp($a['name'], $b['name']));

        return array_map(static fn (array $project): array => array_diff_key($project, ['category' => 0, 'position' => 0]), $projects);
    }

    /**
     * The last report of the project's site as it came (users, plugins …), with its time; report null – none yet.
     *
     * @return array{reportedAt: string|null, report: array<string, mixed>|null}|WP_Error
     */
    public function report(int $id): array|WP_Error
    {
        if (!$this->post($id)) {
            return $this->notFound();
        }

        $report = json_decode((string) get_post_meta($id, ContentTypes::META_REPORT, true), true);

        return [
            'reportedAt' => get_post_meta($id, ContentTypes::META_REPORTED_AT, true) ?: null,
            'report' => is_array($report) ? $report : null,
        ];
    }

    /** The project goes after the others of its category. */
    private function placeLast(int $id): void
    {
        $category = $this->toArray($this->post($id))['categoryId'];
        $others = get_posts(['post_type' => ContentTypes::PROJECT, 'post_status' => 'publish', 'numberposts' => -1, 'exclude' => [$id]]);
        $positions = array_column(array_filter(
            array_map([$this, 'toArray'], $others),
            static fn (array $project): bool => $project['categoryId'] === $category && $project['position'] !== null,
        ), 'position');

        update_post_meta($id, ContentTypes::META_POSITION, $positions ? max($positions) + 1 : 0);
    }

    /** What the types can't say: the category and the Todo project exist. */
    private function validate(array $data): ?WP_Error
    {
        if (!empty($data['categoryId']) && !$this->categories->exists((int) $data['categoryId'])) {
            return $this->invalid(__('Nie ma takiej kategorii.', 'wp-plugin-projects'));
        }

        if (!empty($data['todoProject']) && !isset($this->todo->projects()[(int) $data['todoProject']])) {
            return $this->invalid(__('Nie ma takiego projektu w Todo.', 'wp-plugin-projects'));
        }

        return null;
    }

    private function byToken(string $token): ?WP_Post
    {
        if (strlen($token) < 20) {
            return null;
        }

        $posts = get_posts([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'numberposts' => 1,
            'no_found_rows' => true,
            'meta_query' => [['key' => ContentTypes::META_TOKEN, 'value' => $token]],
        ]);

        // the query compares case-insensitively – the token must match exactly
        $post = $posts[0] ?? null;

        return $post && hash_equals((string) get_post_meta($post->ID, ContentTypes::META_TOKEN, true), $token) ? $post : null;
    }

    /** @param array<string, mixed> $data */
    private function save(int $id, array $data): void
    {
        foreach (Fields::ALL as $field => $definition) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $key = Fields::metaKey($field);
            $value = $this->clean($data[$field], $definition);

            $value === null ? delete_post_meta($id, $key) : update_post_meta($id, $key, wp_slash($value));
        }
    }

    /**
     * @param array{type: string, format?: string, enum?: string[], max?: int, multiline?: bool} $definition
     * @return string|int|string[]|null what is stored; null = nothing
     */
    private function clean(mixed $value, array $definition): string|int|array|null
    {
        // domains: bare host names – no scheme, no trailing slash, lower case, each once
        if ($definition['type'] === 'array') {
            $items = array_map(
                static fn ($item): string => strtolower(trim((string) preg_replace(['#^https?://#i', '#/.*$#'], '', trim(sanitize_text_field((string) $item))))),
                is_array($value) ? $value : [],
            );
            $items = array_slice(array_values(array_unique(array_filter($items))), 0, $definition['max'] ?? 20);

            return $items ?: null;
        }

        if ($definition['type'] === 'boolean') {
            return $value ? 1 : 0;
        }

        if ($definition['type'] === 'integer') {
            return (int) $value > 0 ? (int) $value : null;
        }

        // a multiline one (the notes) keeps its line breaks – sanitize_text_field() would make them spaces
        $value = trim(empty($definition['multiline']) ? sanitize_text_field((string) ($value ?? '')) : sanitize_textarea_field((string) ($value ?? '')));

        if ($value === '') {
            return null;
        }

        return match (true) {
            isset($definition['enum']) => in_array($value, $definition['enum'], true) ? $value : null,
            ($definition['format'] ?? '') === 'uri' => esc_url_raw($value) ?: null,
            ($definition['format'] ?? '') === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null,
            default => mb_substr($value, 0, $definition['max'] ?? 200),
        };
    }

    private function post(int $id): ?WP_Post
    {
        $post = get_post($id);

        return $post instanceof WP_Post && $post->post_type === ContentTypes::PROJECT && $post->post_status === 'publish' ? $post : null;
    }

    /** @return array<string, mixed> */
    private function toArray(WP_Post $post): array
    {
        $position = get_post_meta($post->ID, ContentTypes::META_POSITION, true);
        $project = [
            'id' => $post->ID,
            'name' => html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8'),
            'position' => is_numeric($position) ? (int) $position : null,
        ];

        foreach (Fields::ALL as $field => $definition) {
            $value = get_post_meta($post->ID, Fields::metaKey($field), true);
            $project[$field] = match ($definition['type']) {
                'boolean' => (bool) $value,
                'integer' => (int) $value ?: null,
                'array' => is_array($value) ? array_values($value) : [],
                default => (string) $value === '' ? null : (string) $value,
            };
        }

        $project['reportedAt'] = get_post_meta($post->ID, ContentTypes::META_REPORTED_AT, true) ?: null;

        // from the last report: the theme's version and author (the tooltip on the theme), what sent it (on the report)
        $report = json_decode((string) get_post_meta($post->ID, ContentTypes::META_REPORT, true), true);
        $project['parentTheme'] = is_array($report) && is_string($report['parentTheme'] ?? null) ? $report['parentTheme'] : null;
        $project['themeDetails'] = is_array($report) && is_array($report['themeDetails'] ?? null) ? $report['themeDetails'] : null;
        $project['reporter'] = is_array($report) && is_string($report['reporter'] ?? null) ? $report['reporter'] : null;
        // the translations waiting for an update (core, plugins, themes) – "Aktualizuj tłumaczenia" when they are all there is
        $project['translationUpdates'] = array_values(array_filter(
            is_array($report) && is_array($report['translationUpdates'] ?? null) ? $report['translationUpdates'] : [],
            'is_array',
        ));
        // where the site uses reCAPTCHA (CF7, Wordfence's login): the site key, the secret's SHA-256 – the dialog checks
        // them against the project's keys; null – the report doesn't look (plugin_meden before 1.15.0)
        $project['recaptcha'] = !is_array($report) || !is_array($report['recaptcha'] ?? null) ? null : array_values(array_map(
            static fn (array $place): array => [
                'place' => (string) ($place['place'] ?? ''),
                'enabled' => (bool) ($place['enabled'] ?? false),
                'siteKey' => is_string($place['siteKey'] ?? null) ? $place['siteKey'] : null,
                'secretHash' => is_string($place['secretHash'] ?? null) ? $place['secretHash'] : null,
            ],
            array_filter($report['recaptcha'], 'is_array'),
        ));
        // the plugins waiting for an update – the report's dot turns orange and lists them
        $project['updates'] = array_values(array_map(
            static fn (array $plugin): array => ['name' => (string) ($plugin['name'] ?? ''), 'version' => (string) ($plugin['version'] ?? ''), 'update' => (string) $plugin['update']],
            array_filter(
                is_array($report) && is_array($report['plugins'] ?? null) ? $report['plugins'] : [],
                static fn (mixed $plugin): bool => is_array($plugin) && !empty($plugin['update']),
            ),
        ));

        // the TLS certificate as the list saw it at its last pull: until when, by whom, whether it is fine
        $ssl = json_decode((string) get_post_meta($post->ID, ContentTypes::META_SSL, true), true);
        $project['ssl'] = is_array($ssl) ? $ssl : null;

        $project['token'] = get_post_meta($post->ID, ContentTypes::META_TOKEN, true) ?: null;
        // the password itself only on request (GET /projects/{id}/password) – not in every list
        $project['hasPassword'] = get_post_meta($post->ID, ContentTypes::META_PASSWORD, true) !== '';
        $project['hasAuthPassword'] = get_post_meta($post->ID, ContentTypes::META_AUTH_PASSWORD, true) !== '';
        $project['hasStagingAuthPassword'] = get_post_meta($post->ID, ContentTypes::META_STAGING_AUTH_PASSWORD, true) !== '';
        $project['hasRecaptchaSecret'] = get_post_meta($post->ID, ContentTypes::META_RECAPTCHA_SECRET, true) !== '';
        $staging = json_decode((string) get_post_meta($post->ID, ContentTypes::META_STAGING, true), true);
        $project['staging'] = is_array($staging) ? $staging : null;
        // the home page over 30 days: availability in %, the mean response time of the last day
        $this->summaries ??= $this->history->summaries();
        $project['uptime'] = $this->summaries[$post->ID]['uptime'] ?? null;
        $project['responseMs'] = $this->summaries[$post->ID]['ms'] ?? null;
        // the plugins' known vulnerabilities (VulnerabilityScanner) – null: not scanned yet
        $project['vulnerabilities'] = VulnerabilityScanner::of($post->ID)['items'] ?? null;
        $project['updatedAt'] = $post->post_modified_gmt !== '0000-00-00 00:00:00' ? gmdate(DATE_ATOM, (int) strtotime($post->post_modified_gmt . ' UTC')) : null;

        return $project;
    }

    private function invalid(string $message): WP_Error
    {
        return new WP_Error('projects_invalid', $message, ['status' => 400]);
    }

    private function notFound(): WP_Error
    {
        return new WP_Error('projects_not_found', __('Nie ma takiego projektu.', 'wp-plugin-projects'), ['status' => 404]);
    }
}
