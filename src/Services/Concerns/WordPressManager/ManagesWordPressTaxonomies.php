<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_whm\Models\WhmServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait ManagesWordPressTaxonomies
{
    public function listAuthors(array $target, bool $forceRefresh = false): array
    {
        $target = $this->normalizeTarget($target);

        if ($this->usesWpToolkit($target)) {
            $result = $this->wptoolkit->wpCliListAdminUsers($target["server"], (int) $target["install_id"], $forceRefresh);
            $result["authors"] = array_values(array_filter((array) ($result["authors"] ?? []), static fn ($author) => is_array($author) && !empty($author["user_login"])));
            return $result;
        }

        $authors = [];
        $truncated = false;
        for ($page = 1; $page <= 100; $page++) {
            // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-077. REST collections are
            // capped at 100 rows, so every author page must be loaded before
            // a configured campaign author can be declared missing.
            $response = $this->restRequest($target, "get", "users", [], [
                "per_page" => 100,
                "page" => $page,
                "context" => "edit",
                "_fields" => "id,name,slug,email,roles",
            ]);

            if (!($response["success"] ?? false)) {
                return [
                    "success" => false,
                    "message" => (string) ($response["message"] ?? "Author lookup failed."),
                    "authors" => [],
                    "cache_hit" => null,
                    "cached_at" => null,
                    "expires_at" => null,
                ];
            }

            $pageRows = array_values(array_filter((array) ($response["data"] ?? []), "is_array"));
            foreach ($pageRows as $author) {
                $normalized = [
                    "id" => (int) ($author["id"] ?? 0),
                    "user_login" => (string) ($author["login"] ?? $author["user_login"] ?? $author["slug"] ?? ""),
                    "slug" => (string) ($author["slug"] ?? ""),
                    "display_name" => (string) ($author["name"] ?? $author["slug"] ?? ""),
                    "email" => (string) ($author["email"] ?? ""),
                    "roles" => array_values(array_map("strval", (array) ($author["roles"] ?? []))),
                ];
                $authors[$normalized["id"] > 0 ? (string) $normalized["id"] : "page-{$page}-".count($authors)] = $normalized;
            }

            if (count($pageRows) < 100) {
                break;
            }
            $truncated = $page === 100;
        }
        $authors = array_values($authors);

        return [
            "success" => true,
            "message" => count($authors) . " author(s) loaded via REST" . ($truncated ? " (bounded at 10,000)." : "."),
            "authors" => $authors,
            "truncated" => $truncated,
            "cache_hit" => null,
            "cached_at" => null,
            "expires_at" => null,
        ];
    }

    public function resolvePreferredTaxonomy(array $target, array $candidates = ["publication", "category"]): array
    {
        $target = $this->normalizeTarget($target);
        $fallback = [
            "success" => true,
            "taxonomy" => "category",
            "label" => "Categories",
            "hierarchical" => true,
            "message" => "Using category taxonomy fallback.",
        ];

        if ($this->usesWpToolkit($target)) {
            $result = $this->wptoolkit->wpCliResolvePreferredTaxonomy($target["server"], (int) $target["install_id"], $candidates);
            if ((bool) ($result["success"] ?? false)) {
                return [
                    "success" => true,
                    "taxonomy" => (string) ($result["taxonomy"] ?? "category"),
                    "label" => (string) ($result["label"] ?? "Categories"),
                    "hierarchical" => (bool) ($result["hierarchical"] ?? true),
                    "message" => (string) ($result["message"] ?? "Preferred taxonomy resolved."),
                ];
            }
        }

        if (in_array("publication", $candidates, true)) {
            $terms = $this->listTerms($target, "publication");
            if ((bool) ($terms["success"] ?? false) && !empty($terms["terms"])) {
                return [
                    "success" => true,
                    "taxonomy" => "publication",
                    "label" => "Publications",
                    "hierarchical" => true,
                    "message" => "Using publication taxonomy.",
                ];
            }
        }

        return $fallback;
    }

    public function listTerms(array $target, string $taxonomy = "category", bool $forceRefresh = false): array
    {
        $target = $this->normalizeTarget($target);
        $taxonomy = trim($taxonomy) !== "" ? trim($taxonomy) : "category";

        if ($this->usesWpToolkit($target)) {
            if ($taxonomy === "category") {
                $result = $this->wptoolkit->wpCliListCategories($target["server"], (int) $target["install_id"]);
                $terms = array_values(array_map(static fn ($term) => [
                    "id" => (int) ($term["id"] ?? 0),
                    "term_id" => (int) ($term["id"] ?? 0),
                    "parent" => (int) ($term["parent"] ?? 0),
                    "count" => (int) ($term["count"] ?? 0),
                    "name" => (string) ($term["name"] ?? ""),
                    "slug" => (string) ($term["slug"] ?? ""),
                ], array_values(array_filter((array) ($result["categories"] ?? []), "is_array"))));

                return [
                    "success" => (bool) ($result["success"] ?? false),
                    "message" => (string) ($result["message"] ?? ""),
                    "terms" => $terms,
                    "categories" => $terms,
                    "taxonomy" => "category",
                    "taxonomy_requested" => "category",
                    "taxonomy_label" => "Categories",
                    "hierarchical" => true,
                ];
            }

            if ($taxonomy === "publication") {
                $direct = $this->wptoolkit->wpCliListTaxonomyTerms($target["server"], (int) $target["install_id"], "publication");
                if ((bool) ($direct["success"] ?? false) && !empty($direct["terms"])) {
                    $terms = array_values(array_map([$this, "normalizeTermRow"], (array) ($direct["terms"] ?? [])));
                    return [
                        "success" => true,
                        "message" => (string) ($direct["message"] ?? count($terms) . " publication term(s) loaded."),
                        "terms" => $terms,
                        "categories" => $terms,
                        "taxonomy" => "publication",
                        "taxonomy_requested" => "publication",
                        "taxonomy_label" => "Publications",
                        "hierarchical" => true,
                    ];
                }

                $rows = $this->fetchPublicationTermsViaDb($target["server"], (int) $target["install_id"]);
                return [
                    "success" => !empty($rows),
                    "message" => !empty($rows) ? count($rows) . " publication term(s) loaded from database fallback." : "No publication terms found.",
                    "terms" => $rows,
                    "categories" => $rows,
                    "taxonomy" => "publication",
                    "taxonomy_requested" => "publication",
                    "taxonomy_label" => "Publications",
                    "hierarchical" => true,
                    "cache_hit" => !$forceRefresh ? null : null,
                ];
            }

            $result = $this->wptoolkit->wpCliListTaxonomyTerms($target["server"], (int) $target["install_id"], $taxonomy);
            $terms = array_values(array_map([$this, "normalizeTermRow"], (array) ($result["terms"] ?? [])));

            return [
                "success" => (bool) ($result["success"] ?? false),
                "message" => (string) ($result["message"] ?? ""),
                "terms" => $terms,
                "categories" => $terms,
                "taxonomy" => $taxonomy,
                "taxonomy_requested" => $taxonomy,
                "taxonomy_label" => ucfirst(str_replace(["_", "-"], " ", $taxonomy)),
                "hierarchical" => $taxonomy !== "post_tag",
            ];
        }

        $endpoint = $this->restTaxonomyEndpoint($taxonomy);
        $response = $this->restRequest($target, "get", $endpoint, [], ["per_page" => 100]);
        if (!($response["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($response["message"] ?? "Term lookup failed."),
                "terms" => [],
                "categories" => [],
                "taxonomy" => $taxonomy,
                "taxonomy_requested" => $taxonomy,
                "taxonomy_label" => ucfirst(str_replace(["_", "-"], " ", $taxonomy)),
                "hierarchical" => $taxonomy !== "post_tag",
            ];
        }

        $terms = array_values(array_map(static function (array $term): array {
            return [
                "id" => (int) ($term["id"] ?? 0),
                "term_id" => (int) ($term["id"] ?? 0),
                "parent" => (int) ($term["parent"] ?? 0),
                "count" => (int) ($term["count"] ?? 0),
                "name" => (string) ($term["name"] ?? ""),
                "slug" => (string) ($term["slug"] ?? ""),
            ];
        }, array_values(array_filter((array) ($response["data"] ?? []), "is_array"))));

        return [
            "success" => true,
            "message" => count($terms) . " term(s) loaded via REST.",
            "terms" => $terms,
            "categories" => $terms,
            "taxonomy" => $taxonomy,
            "taxonomy_requested" => $taxonomy,
            "taxonomy_label" => ucfirst(str_replace(["_", "-"], " ", $taxonomy)),
            "hierarchical" => $taxonomy !== "post_tag",
        ];
    }

    public function ensureTerms(array $target, array $names, string $taxonomy = "category"): array
    {
        $target = $this->normalizeTarget($target);
        $taxonomy = trim($taxonomy) !== "" ? trim($taxonomy) : "category";
        $names = array_values(array_unique(array_filter(array_map(static fn ($name) => trim((string) $name), $names))));

        if ($names === []) {
            return ["success" => true, "message" => "No terms to resolve.", "term_ids" => [], "term_details" => []];
        }

        if ($this->usesWpToolkit($target)) {
            if ($taxonomy === "category") {
                return $this->wptoolkit->wpCliBatchCategories($target["server"], (int) $target["install_id"], $names);
            }
            if ($taxonomy === "post_tag") {
                return $this->wptoolkit->wpCliBatchTags($target["server"], (int) $target["install_id"], $names);
            }
            return $this->ensureToolkitTerms($target, $names, $taxonomy);
        }

        $existing = $this->listTerms($target, $taxonomy);
        $map = [];
        foreach ((array) ($existing["terms"] ?? []) as $term) {
            if (is_array($term)) {
                $map[mb_strtolower(trim((string) ($term["name"] ?? "")))] = (int) ($term["id"] ?? $term["term_id"] ?? 0);
            }
        }

        $termIds = [];
        $details = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            if (isset($map[$key]) && $map[$key] > 0) {
                $termIds[] = $map[$key];
                $details[] = ["name" => $name, "id" => $map[$key], "existed" => true, "error" => null];
                continue;
            }

            // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-065.
            // The collection endpoint is paginated, so the first 100 terms are
            // only a cache warm-up. Search every unresolved requested name
            // directly before attempting creation.
            $existingTermId = $this->findRestTermIdByName($target, $taxonomy, $name);
            if ($existingTermId > 0) {
                $termIds[] = $existingTermId;
                $details[] = ["name" => $name, "id" => $existingTermId, "existed" => true, "error" => null];
                $map[$key] = $existingTermId;
                continue;
            }

            $created = $this->restRequest($target, "post", $this->restTaxonomyEndpoint($taxonomy), ["name" => $name]);
            if (($created["success"] ?? false) && is_array($created["data"] ?? null) && !empty($created["data"]["id"])) {
                $termId = (int) $created["data"]["id"];
                $termIds[] = $termId;
                $details[] = ["name" => $name, "id" => $termId, "existed" => false, "error" => null];
                $map[$key] = $termId;
                continue;
            }

            // WordPress returns the existing term ID in a term_exists
            // conflict. Treat that as a successful resolution, never as a
            // reason to discard an otherwise ready article.
            $conflictTermId = $this->restTermConflictId($created);
            if ($conflictTermId > 0) {
                $termIds[] = $conflictTermId;
                $details[] = ["name" => $name, "id" => $conflictTermId, "existed" => true, "error" => null];
                $map[$key] = $conflictTermId;
                continue;
            }

            $details[] = ["name" => $name, "id" => 0, "existed" => false, "error" => (string) ($created["message"] ?? "Term creation failed.")];
        }

        return [
            "success" => count($termIds) > 0,
            "message" => count($termIds) . "/" . count($names) . " term(s) resolved.",
            "term_ids" => array_values(array_unique(array_filter(array_map("intval", $termIds)))),
            "term_details" => $details,
        ];
    }

    private function findRestTermIdByName(array $target, string $taxonomy, string $name): int
    {
        $response = $this->restRequest($target, "get", $this->restTaxonomyEndpoint($taxonomy), [], [
            "search" => $name,
            "per_page" => 100,
        ]);
        if (!($response["success"] ?? false)) {
            return 0;
        }

        $expected = $this->termLookupKey($name);
        foreach ((array) ($response["data"] ?? []) as $term) {
            if (!is_array($term) || $this->termLookupKey((string) ($term["name"] ?? "")) !== $expected) {
                continue;
            }

            $termId = (int) ($term["id"] ?? $term["term_id"] ?? 0);
            if ($termId > 0) {
                return $termId;
            }
        }

        return 0;
    }

    private function restTermConflictId(array $response): int
    {
        $payload = is_array($response["data"] ?? null) ? $response["data"] : [];
        if (($payload["code"] ?? null) !== "term_exists") {
            return 0;
        }

        return max(0, (int) ($payload["data"]["term_id"] ?? $payload["term_id"] ?? 0));
    }

    private function termLookupKey(string $name): string
    {
        return mb_strtolower(trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, "UTF-8")));
    }

}
