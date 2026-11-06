<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use MKProjects\ContentTypes;
use WP_Error;
use WP_Term;

/**
 * The hosting accounts (terms of `mk_project_host`) – one account holds several projects, its data typed once:
 * { id, name, server, serverUrl, serverAccount, paidUntil, sshHost, sshPort, sshUser, hasPassword, projects: int[] }.
 * A project points to its account with the `hostId` field; the account's panel password is kept encrypted (Secrets),
 * sent only on request.
 */
final class HostRepository
{
    /** @var array<string, array{type?: string, format?: string, max?: int}> the account's fields besides its name – term meta `_host_{snake_case}` */
    public const FIELDS = [
        'server' => ['max' => 100],
        'serverUrl' => ['format' => 'uri'],
        'serverAccount' => ['max' => 100],
        // until when it is paid – YYYY-MM-DD; the list warns 30 days before
        'paidUntil' => ['format' => 'date'],
        // what the renewal costs – as typed, e.g. "299 zł brutto / rok"
        'renewalPrice' => ['max' => 50],
        'sshHost' => ['max' => 200],
        'sshPort' => ['type' => 'integer'],
        'sshUser' => ['max' => 100],
    ];

    private const META_PASSWORD = '_host_password';
    private const MIGRATED = 'wp_plugin_projects_hosts_migrated';

    public function __construct(private readonly Secrets $secrets = new Secrets())
    {
    }

    public static function metaKey(string $field): string
    {
        return '_host_' . strtolower((string) preg_replace('/[A-Z]/', '_$0', $field));
    }

    /** @return array<int, array<string, mixed>> by name */
    public function all(): array
    {
        $terms = get_terms(['taxonomy' => ContentTypes::HOST, 'hide_empty' => false]);
        $projects = $this->projectsByHost();
        $hosts = is_array($terms) ? array_map(fn (WP_Term $term): array => $this->toArray($term, $projects[$term->term_id] ?? []), $terms) : [];

        $collator = class_exists(\Collator::class) ? new \Collator(get_locale()) : null;
        usort($hosts, static fn (array $a, array $b): int => $collator ? (int) $collator->compare($a['name'], $b['name']) : strnatcasecmp($a['name'], $b['name']));

        return $hosts;
    }

    public function exists(int $id): bool
    {
        return get_term($id, ContentTypes::HOST) instanceof WP_Term;
    }

    /**
     * @param array<string, mixed> $data `name` and any of FIELDS
     * @return array<string, mixed>|WP_Error
     */
    public function create(array $data): array|WP_Error
    {
        $name = trim(sanitize_text_field((string) ($data['name'] ?? '')));

        if ($name === '') {
            return $this->invalid(__('Konto musi mieć nazwę.', 'wp-plugin-projects'));
        }

        $result = wp_insert_term(wp_slash($name), ContentTypes::HOST);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        $this->save($result['term_id'], $data);

        return $this->find($result['term_id']);
    }

    /**
     * @param array<string, mixed> $changes any of `name` and FIELDS; null clears a field
     * @return array<string, mixed>|WP_Error
     */
    public function update(int $id, array $changes): array|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        if (array_key_exists('name', $changes)) {
            $name = trim(sanitize_text_field((string) $changes['name']));

            if ($name === '') {
                return $this->invalid(__('Konto musi mieć nazwę.', 'wp-plugin-projects'));
            }

            $result = wp_update_term($id, ContentTypes::HOST, ['name' => wp_slash($name), 'slug' => sanitize_title($name)]);

            if (is_wp_error($result)) {
                return $this->error($result);
            }
        }

        $this->save($id, $changes);

        return $this->find($id);
    }

    /** Deletes the account; its projects stay, without one (their own hosting fields, if any, show again). */
    public function delete(int $id): true|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        $result = wp_delete_term($id, ContentTypes::HOST);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        delete_metadata('post', 0, Fields::metaKey('hostId'), (string) $id, true);

        return true;
    }

    /** @return array<string, mixed>|WP_Error */
    public function find(int $id): array|WP_Error
    {
        $term = get_term($id, ContentTypes::HOST);

        return $term instanceof WP_Term ? $this->toArray($term, $this->projectsByHost()[$id] ?? []) : $this->notFound();
    }

    /** The panel's password, decrypted; null – none (or no key opens it). */
    public function password(int $id): string|null|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        $stored = (string) get_term_meta($id, self::META_PASSWORD, true);

        return $stored === '' ? null : $this->secrets->decrypt($stored);
    }

    /** @return array<string, mixed>|WP_Error the account – empty or null removes the password */
    public function setPassword(int $id, ?string $password): array|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        if ($password === null || $password === '') {
            delete_term_meta($id, self::META_PASSWORD);
        } else {
            update_term_meta($id, self::META_PASSWORD, $this->secrets->encrypt(mb_substr($password, 0, 500)));
        }

        return $this->find($id);
    }

    /**
     * Once: the projects' own hosting fields become accounts – one per hosting and account name (the same pair on
     * several projects, one account) – and each project points to its own.
     */
    public function migrate(): void
    {
        if (get_option(self::MIGRATED)) {
            return;
        }

        $posts = get_posts(['post_type' => ContentTypes::PROJECT, 'post_status' => 'publish', 'numberposts' => -1, 'no_found_rows' => true]);
        $created = [];

        foreach ($posts as $post) {
            if ((int) get_post_meta($post->ID, Fields::metaKey('hostId'), true) > 0) {
                continue;
            }

            $server = trim((string) get_post_meta($post->ID, Fields::metaKey('server'), true));
            $account = trim((string) get_post_meta($post->ID, Fields::metaKey('serverAccount'), true));

            if ($server === '' && $account === '') {
                continue;
            }

            $key = strtolower($server) . '|' . $account;

            if (!isset($created[$key])) {
                $name = $account !== '' ? ($server !== '' ? "$server – $account" : $account) : $server;
                $host = $this->create([
                    'name' => $name,
                    'server' => $server,
                    'serverUrl' => (string) get_post_meta($post->ID, Fields::metaKey('serverUrl'), true),
                    'serverAccount' => $account,
                ]);

                if (is_wp_error($host)) {
                    continue;
                }

                $created[$key] = $host['id'];
            }

            update_post_meta($post->ID, Fields::metaKey('hostId'), $created[$key]);
        }

        update_option(self::MIGRATED, gmdate(DATE_ATOM), false);
    }

    /** @param array<string, mixed> $data */
    private function save(int $id, array $data): void
    {
        foreach (self::FIELDS as $field => $definition) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $this->clean($data[$field], $definition);
            $value === null ? delete_term_meta($id, self::metaKey($field)) : update_term_meta($id, self::metaKey($field), wp_slash($value));
        }
    }

    /** @param array{type?: string, format?: string, max?: int} $definition */
    private function clean(mixed $value, array $definition): string|int|null
    {
        if (($definition['type'] ?? '') === 'integer') {
            return (int) $value > 0 && (int) $value < 65536 ? (int) $value : null;
        }

        $value = trim(sanitize_text_field((string) ($value ?? '')));

        if ($value === '') {
            return null;
        }

        return match ($definition['format'] ?? '') {
            'uri' => esc_url_raw($value) ?: null,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null,
            default => mb_substr($value, 0, $definition['max'] ?? 200),
        };
    }

    /** @return array<int, int[]> the projects of each account */
    private function projectsByHost(): array
    {
        $ids = get_posts([
            'post_type' => ContentTypes::PROJECT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => Fields::metaKey('hostId'),
        ]);
        $map = [];

        foreach ($ids as $id) {
            $map[(int) get_post_meta($id, Fields::metaKey('hostId'), true)][] = (int) $id;
        }

        return $map;
    }

    /**
     * @param int[] $projects
     * @return array<string, mixed>
     */
    private function toArray(WP_Term $term, array $projects): array
    {
        $host = ['id' => $term->term_id, 'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8')];

        foreach (self::FIELDS as $field => $definition) {
            $value = get_term_meta($term->term_id, self::metaKey($field), true);
            $host[$field] = ($definition['type'] ?? '') === 'integer' ? ((int) $value ?: null) : ((string) $value === '' ? null : (string) $value);
        }

        $host['hasPassword'] = get_term_meta($term->term_id, self::META_PASSWORD, true) !== '';
        $host['projects'] = $projects;

        return $host;
    }

    private function notFound(): WP_Error
    {
        return new WP_Error('projects_host_not_found', __('Nie ma takiego konta hostingowego.', 'wp-plugin-projects'), ['status' => 404]);
    }

    private function invalid(string $message): WP_Error
    {
        return new WP_Error('projects_host_invalid', $message, ['status' => 400]);
    }

    private function error(WP_Error $error): WP_Error
    {
        return $this->invalid($error->get_error_code() === 'term_exists'
            ? __('Konto o tej nazwie już istnieje.', 'wp-plugin-projects')
            : $error->get_error_message());
    }
}
