<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use MKProjects\ContentTypes;
use WP_Error;
use WP_Post;

/**
 * Projects (posts of the `mk_project` type) as plain arrays:
 * { id, name, position, ...Fields::ALL, todo, reportedAt, token, updatedAt }.
 *
 * Two views: the full one for the owner, the public one – only listed projects with a domain, only their name,
 * category, order and Fields::publicFields() – for everyone else. Strings and numbers that are empty are null,
 * booleans never are. `position` is the user's order within the project's category (null = never placed: last, by name);
 * `todo` is the linked Todo project with its open task counts (owner only).
 */
final class ProjectRepository
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly TodoBridge $todo,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(bool $full): array
    {
        $posts = get_posts([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'no_found_rows' => true,
        ]);

        $projects = array_map([$this, 'toArray'], $posts);

        if ($full) {
            $projects = array_map(fn (array $project): array => $project + ['todo' => $this->todo->summary($project['todoProject'])], $projects);
        } else {
            $fields = array_flip(['id', 'name', 'categoryId', 'position', ...Fields::publicFields()]);
            $projects = array_values(array_map(
                static fn (array $project): array => array_intersect_key($project, $fields),
                array_filter($projects, static fn (array $project): bool => $project['listed'] && $project['domain']),
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

        return $this->all(true);
    }

    /** Moves the project to the trash – it can still be restored in wp-admin. */
    public function delete(int $id): true|WP_Error
    {
        if (!$this->post($id)) {
            return $this->notFound();
        }

        return wp_trash_post($id) ? true : new WP_Error('projects_delete_failed', __('Nie udało się usunąć projektu.', 'wp-plugin-projects'), ['status' => 500]);
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

    /**
     * Saves what a site reported about itself (only Fields::reported()) and stamps the time.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>|WP_Error the project
     */
    public function applyReport(string $token, array $report): array|WP_Error
    {
        $post = $this->byToken($token);

        if (!$post) {
            return new WP_Error('projects_invalid_token', __('Nieprawidłowy token raportu.', 'wp-plugin-projects'), ['status' => 401]);
        }

        $this->save($post->ID, array_intersect_key($report, array_flip(Fields::reported())));
        update_post_meta($post->ID, ContentTypes::META_REPORTED_AT, gmdate(DATE_ATOM));
        // the whole report (plugins, environment …) for a closer look later
        update_post_meta($post->ID, ContentTypes::META_REPORT, wp_slash((string) wp_json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        return $this->toArray($post);
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
     * @param array{type: string, format?: string, enum?: string[], max?: int} $definition
     * @return string|int|null what is stored; null = nothing
     */
    private function clean(mixed $value, array $definition): string|int|null
    {
        if ($definition['type'] === 'boolean') {
            return $value ? 1 : 0;
        }

        if ($definition['type'] === 'integer') {
            return (int) $value > 0 ? (int) $value : null;
        }

        $value = trim(sanitize_text_field((string) ($value ?? '')));

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
                default => (string) $value === '' ? null : (string) $value,
            };
        }

        $project['reportedAt'] = get_post_meta($post->ID, ContentTypes::META_REPORTED_AT, true) ?: null;
        $project['token'] = get_post_meta($post->ID, ContentTypes::META_TOKEN, true) ?: null;
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
