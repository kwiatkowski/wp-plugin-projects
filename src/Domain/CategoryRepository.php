<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use MKProjects\ContentTypes;
use WP_Error;
use WP_Term;

/**
 * Categories of the list (terms of `mk_project_category`) as plain arrays: { id: int, name: string, slug: string, position: int|null }.
 * The user's order; categories never placed (null) come last, by name. Deleting one leaves its projects without a category.
 */
final class CategoryRepository
{
    /** @return array<int, array{id: int, name: string, slug: string, position: int|null}> */
    public function all(): array
    {
        $terms = get_terms(['taxonomy' => ContentTypes::CATEGORY, 'hide_empty' => false]);
        $categories = is_array($terms) ? array_map([$this, 'toArray'], $terms) : [];

        $collator = class_exists(\Collator::class) ? new \Collator(get_locale()) : null;
        usort($categories, static fn (array $a, array $b): int => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX)
            ?: ($collator ? (int) $collator->compare($a['name'], $b['name']) : strnatcasecmp($a['name'], $b['name'])));

        return $categories;
    }

    public function exists(int $id): bool
    {
        return get_term($id, ContentTypes::CATEGORY) instanceof WP_Term;
    }

    /** @return array{id: int, name: string, slug: string, position: int|null}|WP_Error */
    public function create(string $name): array|WP_Error
    {
        $result = wp_insert_term(wp_slash($name), ContentTypes::CATEGORY);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        // the current order becomes explicit, the new one goes last
        foreach ([...array_diff(array_column($this->all(), 'id'), [$result['term_id']]), $result['term_id']] as $position => $id) {
            update_term_meta($id, ContentTypes::META_POSITION, $position);
        }

        return $this->find($result['term_id']);
    }

    /** @return array{id: int, name: string, slug: string, position: int|null}|WP_Error */
    public function rename(int $id, string $name): array|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        // the slug follows the name – it is the address of the category's list (/projects/{slug})
        $result = wp_update_term($id, ContentTypes::CATEGORY, ['name' => wp_slash($name), 'slug' => sanitize_title($name)]);

        return is_wp_error($result) ? $this->error($result) : $this->find($id);
    }

    /**
     * @param int[] $ids every category exactly once, in the new order
     * @return array<int, array{id: int, name: string, slug: string, position: int|null}>|WP_Error
     */
    public function reorder(array $ids): array|WP_Error
    {
        $ids = array_map('intval', $ids);
        $all = array_column($this->all(), 'id');

        if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $all) || array_diff($all, $ids)) {
            return new WP_Error('projects_category_invalid_order', __('Nieprawidłowa kolejność kategorii.', 'wp-plugin-projects'), ['status' => 400]);
        }

        foreach ($ids as $position => $id) {
            update_term_meta($id, ContentTypes::META_POSITION, $position);
        }

        return $this->all();
    }

    /** Deletes the category; its projects stay, without one. */
    public function delete(int $id): true|WP_Error
    {
        if (!$this->exists($id)) {
            return $this->notFound();
        }

        $result = wp_delete_term($id, ContentTypes::CATEGORY);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        delete_metadata('post', 0, Fields::metaKey('categoryId'), (string) $id, true);

        return true;
    }

    /** @return array{id: int, name: string, slug: string, position: int|null}|WP_Error */
    public function find(int $id): array|WP_Error
    {
        $term = get_term($id, ContentTypes::CATEGORY);

        return $term instanceof WP_Term ? $this->toArray($term) : $this->notFound();
    }

    /** @return array{id: int, name: string, slug: string, position: int|null} */
    private function toArray(WP_Term $term): array
    {
        $position = get_term_meta($term->term_id, ContentTypes::META_POSITION, true);

        return [
            'id' => $term->term_id,
            'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
            'slug' => urldecode($term->slug),
            'position' => is_numeric($position) ? (int) $position : null,
        ];
    }

    private function notFound(): WP_Error
    {
        return new WP_Error('projects_category_not_found', __('Nie ma takiej kategorii.', 'wp-plugin-projects'), ['status' => 404]);
    }

    private function error(WP_Error $error): WP_Error
    {
        $message = $error->get_error_code() === 'term_exists'
            ? __('Kategoria o tej nazwie już istnieje.', 'wp-plugin-projects')
            : $error->get_error_message();

        return new WP_Error('projects_category_invalid', $message, ['status' => 400]);
    }
}
