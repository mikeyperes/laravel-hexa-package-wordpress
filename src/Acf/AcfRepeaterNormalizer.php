<?php

namespace hexa_package_wordpress\Acf;

class AcfRepeaterNormalizer
{
    public function __construct(
        protected AcfStructureRegistry $structures,
    ) {
    }

    public function normalize(mixed $value, array|string|null $structureOrField = null): mixed
    {
        $structure = $this->resolveStructure($structureOrField);

        if (($structure['type'] ?? '') === AcfStructureRegistry::TYPE_GROUP) {
            return $this->normalizeGroup($value, $structure);
        }

        return $this->normalizeRows($value, $structure);
    }

    public function normalizeRows(mixed $value, array|string|null $structureOrField = null): array
    {
        $structure = $this->resolveStructure($structureOrField);
        $value = $this->decodeValue($value);

        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            $rows = array_is_list($value) ? $value : [$value];
        } elseif (is_object($value)) {
            $rows = [(array) $value];
        } else {
            $rows = $this->rowsFromString((string) $value, $structure);
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $row = $this->rowFromScalar($row, $structure);
            }

            $row = $this->normalizeRow($row, $structure);
            if (!$this->isEmptyRow($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    public function normalizeGroup(mixed $value, array|string|null $structureOrField = null): array
    {
        $structure = $this->resolveStructure($structureOrField);
        $value = $this->decodeValue($value);

        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            $row = array_is_list($value) ? (array) ($value[0] ?? []) : $value;
        } elseif (is_object($value)) {
            $row = (array) $value;
        } else {
            $row = $this->rowFromScalar($value, $structure);
        }

        return $this->normalizeRow(is_array($row) ? $row : [], $structure);
    }

    public function decodeValue(mixed $value): mixed
    {
        if (is_object($value)) {
            return json_decode((string) json_encode($value), true);
        }

        if (!is_string($value)) {
            return $value;
        }

        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        $candidates = [$raw];
        $stripped = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5));
        if ($stripped !== '' && $stripped !== $raw) {
            $candidates[] = $stripped;
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        if (preg_match('/^(a|O|s|i|b|d):/', $raw) === 1) {
            $unserialized = @unserialize($raw, ['allowed_classes' => false]);
            if ($unserialized !== false || $raw === 'b:0;') {
                return $unserialized;
            }
        }

        return $stripped !== '' ? $stripped : $raw;
    }

    public function looksStructured(mixed $value): bool
    {
        $decoded = $this->decodeValue($value);

        if (!is_array($decoded)) {
            return false;
        }

        if (array_is_list($decoded)) {
            return isset($decoded[0]) && is_array($decoded[0]);
        }

        foreach ($decoded as $item) {
            if (is_array($item)) {
                return true;
            }
        }

        return count($decoded) > 1;
    }

    private function normalizeRow(array $row, ?array $structure): array
    {
        if ($structure === null) {
            return $this->normalizeScalarArray($row);
        }

        $aliasMap = $this->structures->fieldAliasMap($structure);
        $normalized = [];

        foreach ($row as $key => $value) {
            $key = (string) $key;
            $lookup = AcfStructureRegistry::normalizeIdentifier($key);
            $canonical = $aliasMap[$lookup] ?? $key;
            $normalized[$canonical] = $this->normalizeScalar($value);
        }

        foreach ((array) ($structure['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name !== '' && !array_key_exists($name, $normalized)) {
                $normalized[$name] = '';
            }
        }

        return $normalized;
    }

    private function normalizeScalarArray(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[(string) $key] = $this->normalizeScalar($value);
        }

        return $normalized;
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if (is_object($value)) {
            return json_decode((string) json_encode($value), true);
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    private function rowsFromString(string $value, ?array $structure): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $value) ?: [])));
        if ($lines === []) {
            return [];
        }

        $key = (string) ($structure['key'] ?? '');
        if (in_array($key, ['education', 'personal_education'], true)) {
            return array_map(fn (string $line): array => $this->educationRowFromLine($line, $structure), $this->educationLines($lines));
        }

        if ($key === 'articles') {
            return array_map(fn (string $line): array => $this->articleRowFromLine($line, $structure), $lines);
        }

        return array_map(fn (string $line): array => $this->rowFromScalar($line, $structure), $lines);
    }

    private function educationLines(array $lines): array
    {
        $expanded = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/https?:\/\//i', $line)) {
                $expanded[] = $line;
                continue;
            }

            foreach (preg_split('/\s*(?:;|\|)\s*/', $line) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part === '') {
                    continue;
                }

                $commaParts = array_values(array_filter(array_map('trim', explode(',', $part))));
                if (count($commaParts) > 1 && $this->allEducationInstitutionNames($commaParts)) {
                    array_push($expanded, ...$commaParts);
                    continue;
                }

                $expanded[] = $part;
            }
        }

        return $expanded;
    }

    private function allEducationInstitutionNames(array $parts): bool
    {
        foreach ($parts as $part) {
            if (! preg_match('/\b(university|college|institute|school|academy|seminary|polytechnic|conservatory|centre|center)\b/i', (string) $part)) {
                return false;
            }
        }

        return true;
    }

    private function educationRowFromLine(string $line, ?array $structure): array
    {
        $row = $this->blankStructuredRow($structure);
        preg_match_all('/https?:\/\/[^\s,]+/i', $line, $matches);
        $urls = array_values($matches[0] ?? []);
        $label = trim(preg_replace('/https?:\/\/[^\s,]+/i', '', $line) ?: $line, " -\t");
        $wikiUrl = '';
        foreach ($urls as $url) {
            if (str_contains(strtolower($url), 'wikipedia.org')) {
                $wikiUrl = $url;
                break;
            }
        }

        if (array_key_exists('school', $row)) {
            $row['school'] = $label !== '' ? $label : $line;
        } elseif (array_key_exists('college', $row)) {
            $row['college'] = $label !== '' ? $label : $line;
        } else {
            $firstKey = array_key_first($row);
            if ($firstKey !== null) {
                $row[$firstKey] = $label !== '' ? $label : $line;
            }
        }

        if (array_key_exists('url', $row)) {
            $row['url'] = $urls[0] ?? '';
        }
        if (array_key_exists('wikipedia_url', $row)) {
            $row['wikipedia_url'] = $wikiUrl;
        }
        if (array_key_exists('wiki_url', $row)) {
            $row['wiki_url'] = $wikiUrl;
        }
        if (array_key_exists('same_as', $row)) {
            $row['same_as'] = implode("\n", array_values(array_filter($urls, fn (string $url): bool => $url !== ($urls[0] ?? ''))));
        }

        return $row;
    }

    private function articleRowFromLine(string $line, ?array $structure): array
    {
        $row = $this->blankStructuredRow($structure);
        preg_match_all('/https?:\/\/[^\s,]+/i', $line, $matches);
        $urls = array_values($matches[0] ?? []);
        $url = $urls[0] ?? '';
        $title = trim(preg_replace('/https?:\/\/[^\s,]+/i', '', $line) ?: '', " -\t|");
        $host = $url !== '' ? strtolower((string) (parse_url($url, PHP_URL_HOST) ?: '')) : '';
        $source = preg_replace('/^www\./', '', $host) ?: '';

        if ($title === '' && $url !== '') {
            $path = trim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
            $slug = basename($path);
            $slug = preg_replace('/\.[a-z0-9]+$/i', '', $slug) ?: $slug;
            $title = trim(ucwords(str_replace(['-', '_'], ' ', $slug))) ?: $url;
        }

        if (array_key_exists('title', $row)) {
            $row['title'] = $title !== '' ? $title : $line;
        } else {
            $firstKey = array_key_first($row);
            if ($firstKey !== null) {
                $row[$firstKey] = $title !== '' ? $title : $line;
            }
        }
        if (array_key_exists('source', $row)) {
            $row['source'] = $source;
        }
        if (array_key_exists('url', $row)) {
            $row['url'] = $url;
        }

        return $row;
    }

    private function blankStructuredRow(?array $structure): array
    {
        $row = [];
        foreach ((array) ($structure['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name !== '') {
                $row[$name] = '';
            }
        }

        return $row !== [] ? $row : ['value' => ''];
    }

    private function rowFromScalar(mixed $value, ?array $structure): array
    {
        $field = (array) (($structure['fields'] ?? [])[0] ?? []);
        $name = (string) ($field['name'] ?? 'value');

        return [$name => is_scalar($value) ? trim((string) $value) : $value];
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (is_array($value) && !$this->isEmptyRow($value)) {
                return false;
            }

            if (is_array($value)) {
                continue;
            }

            if (is_bool($value)) {
                if ($value === true) {
                    return false;
                }
                continue;
            }

            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function resolveStructure(array|string|null $structureOrField): ?array
    {
        if (is_array($structureOrField) && isset($structureOrField['fields'], $structureOrField['type'])) {
            return $structureOrField;
        }

        if (is_array($structureOrField)) {
            return $this->structures->findByField($structureOrField);
        }

        if (is_string($structureOrField) && $structureOrField !== '') {
            return $this->structures->find($structureOrField);
        }

        return null;
    }
}
