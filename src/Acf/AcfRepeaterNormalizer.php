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

        return array_map(fn (string $line): array => $this->rowFromScalar($line, $structure), $lines);
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
