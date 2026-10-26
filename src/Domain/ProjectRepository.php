<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use MKProjects\ContentTypes;
use WP_Error;
use WP_Post;

/**
 * Projects (posts of the `mk_project` type) as plain arrays: { id, name, ...Fields::ALL, reportedAt, token, updatedAt }.
 *
 * Two views: the full one for the owner, the public one – only listed projects with a domain and only
 * Fields::publicFields() – for everyone else. Strings that are empty are null, booleans never are.
 */
final class ProjectRepository
{
    /** @return array<int, array<string, mixed>> */
    public function all(bool $full): array
    {
        $posts = get_posts([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        $projects = array_map(fn (WP_Post $post): array => $this->toArray($post, true), $posts);

        if (!$full) {
            $fields = array_flip(['id', 'name', ...Fields::publicFields()]);
            $projects = array_values(array_map(
                static fn (array $project): array => array_intersect_key($project, $fields),
                array_filter($projects, static fn (array $project): bool => $project['listed'] && $project['domain']),
            ));
        }

        $collator = class_exists(\Collator::class) ? new \Collator(get_locale()) : null;
        usort($projects, static fn (array $a, array $b): int => $collator ? (int) $collator->compare($a['name'], $b['name']) : strnatcasecmp($a['name'], $b['name']));

        return $projects;
    }

    /** @return array<string, mixed>|WP_Error */
    public function find(int $id): array|WP_Error
    {
        $post = $this->post($id);

        return $post ? $this->toArray($post, true) : $this->notFound();
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

        $id = wp_insert_post(wp_slash([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'post_title' => $data['name'],
        ]), true);

        if (is_wp_error($id)) {
            return $this->invalid($id->get_error_message());
        }

        $this->save($id, $data);

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $changes any of `name` and Fields::ALL; null clears a field
     * @return array<string, mixed>|WP_Error
     */
    public function update(int $id, array $changes): array|WP_Error
    {
        if (!$this->post($id)) {
            return $this->notFound();
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

        $this->save($id, $changes);

        // bump the modified date also when only meta changed
        if (!array_key_exists('name', $changes)) {
            wp_update_post(['ID' => $id]);
        }

        clean_post_cache($id);

        return $this->find($id);
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

        return $this->find($post->ID);
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
    private function toArray(WP_Post $post, bool $full): array
    {
        $project = [
            'id' => $post->ID,
            'name' => html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8'),
        ];

        foreach (Fields::ALL as $field => $definition) {
            $value = get_post_meta($post->ID, Fields::metaKey($field), true);
            $project[$field] = $definition['type'] === 'boolean' ? (bool) $value : ((string) $value === '' ? null : (string) $value);
        }

        if ($full) {
            $project['reportedAt'] = get_post_meta($post->ID, ContentTypes::META_REPORTED_AT, true) ?: null;
            $project['token'] = get_post_meta($post->ID, ContentTypes::META_TOKEN, true) ?: null;
            $project['updatedAt'] = $post->post_modified_gmt !== '0000-00-00 00:00:00' ? gmdate(DATE_ATOM, (int) strtotime($post->post_modified_gmt . ' UTC')) : null;
        }

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
