<?php

namespace hexa_package_wordpress\Data;

use Stringable;

final readonly class WordPressPostMutation
{
    private const STANDARD_KEYS = [
        'title',
        'content',
        'status',
        'excerpt',
        'date',
        'featured_media',
        'featured_media_id',
        'author',
        'categories',
        'category_ids',
        'tags',
        'tag_ids',
        'taxonomies',
        'post_type',
        'slug',
        'post_name',
    ];

    /**
     * @param  list<int>  $categories
     * @param  list<int>  $tags
     * @param  array<string, list<int>>  $taxonomies
     * @param  array<string, bool>  $provided
     * @param  list<string>  $providedTaxonomies
     * @param  list<string>  $validationErrors
     */
    private function __construct(
        public ?string $title,
        public ?string $content,
        public ?string $status,
        public string $postType,
        public ?string $slug,
        public ?string $excerpt,
        public ?string $date,
        public ?int $featuredMedia,
        public ?string $author,
        public array $categories,
        public array $tags,
        public array $taxonomies,
        public array $provided,
        public array $providedTaxonomies,
        public array $validationErrors,
    ) {}

    public static function fromArray(array $payload): self
    {
        $errors = [];
        $taxonomies = [];
        $providedTaxonomies = [];

        if (array_key_exists('taxonomies', $payload) && ! is_array($payload['taxonomies'])) {
            $errors[] = 'Taxonomies must be supplied as a taxonomy-to-term-ID map.';
        }

        foreach ((array) ($payload['taxonomies'] ?? []) as $taxonomy => $termIds) {
            self::addTaxonomy(
                $taxonomies,
                $providedTaxonomies,
                $errors,
                $taxonomy,
                $termIds,
            );
        }

        foreach ($payload as $key => $value) {
            if (in_array($key, self::STANDARD_KEYS, true) || ! is_array($value) || $value === []) {
                continue;
            }

            if (self::looksLikeIntegerList($value)) {
                self::addTaxonomy(
                    $taxonomies,
                    $providedTaxonomies,
                    $errors,
                    $key,
                    $value,
                );
            }
        }

        $provided = [
            'title' => array_key_exists('title', $payload),
            'content' => array_key_exists('content', $payload),
            'status' => array_key_exists('status', $payload),
            'post_type' => array_key_exists('post_type', $payload),
            'slug' => array_key_exists('slug', $payload) || array_key_exists('post_name', $payload),
            'excerpt' => array_key_exists('excerpt', $payload),
            'date' => array_key_exists('date', $payload),
            'featured_media' => array_key_exists('featured_media', $payload) || array_key_exists('featured_media_id', $payload),
            'author' => array_key_exists('author', $payload),
            'categories' => array_key_exists('categories', $payload) || array_key_exists('category_ids', $payload),
            'tags' => array_key_exists('tags', $payload) || array_key_exists('tag_ids', $payload),
        ];

        $postType = trim(self::nullableString($payload['post_type'] ?? 'post', 'Post type', $errors) ?? 'post') ?: 'post';
        if (preg_match('/^[a-z0-9_-]{1,20}$/', $postType) !== 1) {
            $errors[] = 'Post type must be a valid WordPress post-type key of at most 20 characters.';
        }

        $featuredMedia = self::nullableIntegerAlias($payload, 'featured_media', 'featured_media_id', 'Featured media', $errors);
        if ($featuredMedia !== null && $featuredMedia < 0) {
            $errors[] = 'Featured media must be zero or a positive integer.';
        }

        return new self(
            title: array_key_exists('title', $payload) ? self::nullableString($payload['title'] ?? '', 'Title', $errors) : null,
            content: array_key_exists('content', $payload) ? self::nullableString($payload['content'] ?? '', 'Content', $errors) : null,
            status: array_key_exists('status', $payload) ? self::nullableString($payload['status'] ?? 'draft', 'Status', $errors) : null,
            postType: $postType,
            slug: self::nullableStringAlias($payload, 'slug', 'post_name', 'Slug', $errors),
            excerpt: array_key_exists('excerpt', $payload) ? self::nullableString($payload['excerpt'] ?? '', 'Excerpt', $errors) : null,
            date: array_key_exists('date', $payload) ? self::nullableString($payload['date'], 'Date', $errors) : null,
            featuredMedia: $featuredMedia,
            author: array_key_exists('author', $payload) ? self::nullableString($payload['author'], 'Author', $errors) : null,
            categories: self::integerIds($payload['categories'] ?? $payload['category_ids'] ?? [], 'Categories', $errors),
            tags: self::integerIds($payload['tags'] ?? $payload['tag_ids'] ?? [], 'Tags', $errors),
            taxonomies: $taxonomies,
            provided: $provided,
            providedTaxonomies: array_values(array_unique($providedTaxonomies)),
            validationErrors: array_values(array_unique($errors)),
        );
    }

    public function withDefaultAuthor(string $author): self
    {
        if ($this->provided['author'] ?? false) {
            return $this;
        }

        $author = trim($author);
        if ($author === '') {
            return $this;
        }

        return new self(
            title: $this->title,
            content: $this->content,
            status: $this->status,
            postType: $this->postType,
            slug: $this->slug,
            excerpt: $this->excerpt,
            date: $this->date,
            featuredMedia: $this->featuredMedia,
            author: $author,
            categories: $this->categories,
            tags: $this->tags,
            taxonomies: $this->taxonomies,
            provided: array_replace($this->provided, ['author' => true]),
            providedTaxonomies: $this->providedTaxonomies,
            validationErrors: $this->validationErrors,
        );
    }

    public function isValid(): bool
    {
        return $this->validationErrors === [];
    }

    /**
     * Backward-compatible adapter for existing package internals and callers
     * that still consume the normalized mutation as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'status' => $this->status,
            'post_type' => $this->postType,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'date' => $this->date,
            'featured_media' => $this->featuredMedia,
            'author' => $this->author,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'taxonomies' => $this->taxonomies,
            '_provided' => $this->provided,
            '_provided_taxonomies' => $this->providedTaxonomies,
        ];
    }

    private static function nullableStringAlias(array $payload, string $primary, string $alias, string $label, array &$errors): ?string
    {
        if (array_key_exists($primary, $payload)) {
            return self::nullableString($payload[$primary] ?? '', $label, $errors);
        }

        return array_key_exists($alias, $payload)
            ? self::nullableString($payload[$alias] ?? '', $label, $errors)
            : null;
    }

    private static function nullableString(mixed $value, string $label, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        $errors[] = $label.' must be a scalar string value.';

        return null;
    }

    private static function nullableIntegerAlias(array $payload, string $primary, string $alias, string $label, array &$errors): ?int
    {
        if (! array_key_exists($primary, $payload) && ! array_key_exists($alias, $payload)) {
            return null;
        }

        $value = array_key_exists($primary, $payload) ? $payload[$primary] : $payload[$alias];
        if ($value === null) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            $errors[] = $label.' must be an integer.';

            return null;
        }

        return $integer;
    }

    private static function addTaxonomy(array &$taxonomies, array &$providedTaxonomies, array &$errors, mixed $taxonomy, mixed $termIds): void
    {
        $taxonomy = trim((string) $taxonomy);
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $taxonomy) !== 1) {
            $errors[] = 'Taxonomy keys must use valid lowercase WordPress taxonomy names of at most 32 characters.';

            return;
        }

        if (! is_array($termIds)) {
            $errors[] = 'Taxonomy '.$taxonomy.' term IDs must be supplied as an array.';

            return;
        }

        $taxonomies[$taxonomy] = self::integerIds($termIds, 'Taxonomy '.$taxonomy, $errors);
        $providedTaxonomies[] = $taxonomy;
    }

    /** @return list<int> */
    private static function integerIds(mixed $values, string $label, array &$errors): array
    {
        if (! is_array($values)) {
            $values = $values === null ? [] : [$values];
        }

        $ids = [];
        foreach ($values as $value) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer <= 0) {
                $errors[] = $label.' must contain only positive integer IDs.';

                continue;
            }

            $ids[] = $integer;
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private static function looksLikeIntegerList(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                return false;
            }
        }

        return true;
    }
}
