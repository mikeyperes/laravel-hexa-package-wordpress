<?php

namespace hexa_package_wordpress\Acf;

class AcfSmartTypeResolver
{
    public const TYPE_REPEATER = 'acf_repeater';
    public const TYPE_GROUP = 'acf_group';
    public const TYPE_WYSIWYG = 'acf_wysiwyg';
    public const TYPE_TEXTAREA = 'acf_textarea';
    public const TYPE_URL = 'acf_url';
    public const TYPE_EMAIL = 'acf_email';
    public const TYPE_NUMBER = 'acf_number';
    public const TYPE_BOOLEAN = 'acf_boolean';
    public const TYPE_DATE = 'acf_date';
    public const TYPE_DATETIME = 'acf_datetime';
    public const TYPE_IMAGE = 'acf_image';
    public const TYPE_GALLERY = 'acf_gallery';
    public const TYPE_FILE = 'acf_file';
    public const TYPE_RELATIONSHIP = 'acf_relationship';
    public const TYPE_SELECT = 'acf_select';
    public const TYPE_JSON = 'acf_json';
    public const TYPE_TEXT = 'acf_text';

    public function __construct(
        protected AcfStructureRegistry $structures,
        protected AcfRepeaterNormalizer $normalizer,
    ) {
    }

    public function smartTypes(): array
    {
        return [
            self::TYPE_REPEATER,
            self::TYPE_GROUP,
            self::TYPE_WYSIWYG,
            self::TYPE_TEXTAREA,
            self::TYPE_URL,
            self::TYPE_EMAIL,
            self::TYPE_NUMBER,
            self::TYPE_BOOLEAN,
            self::TYPE_DATE,
            self::TYPE_DATETIME,
            self::TYPE_IMAGE,
            self::TYPE_GALLERY,
            self::TYPE_FILE,
            self::TYPE_RELATIONSHIP,
            self::TYPE_SELECT,
            self::TYPE_JSON,
            self::TYPE_TEXT,
        ];
    }

    public function structures(): array
    {
        return $this->structures->all();
    }

    public function annotateField(array $field, mixed $value = null): array
    {
        return array_merge($field, $this->resolve($field, $value));
    }

    public function annotateFields(array $fields): array
    {
        return array_map(function (array $field): array {
            return $this->annotateField($field);
        }, $fields);
    }

    public function typedValue(string $fieldName, mixed $value, array $field = []): array
    {
        $field = array_merge([
            'field_name' => $fieldName,
            'name' => $fieldName,
        ], $field);

        $meta = $this->resolve($field, $value);

        return [
            'field_name' => $fieldName,
            'field_label' => (string) ($field['field_label'] ?? $field['label'] ?? $fieldName),
            'field_key' => (string) ($field['field_key'] ?? $field['key'] ?? ''),
            'acf_type' => (string) ($field['field_type'] ?? $field['type'] ?? ''),
            'smart_type' => $meta['smart_type'],
            'control' => $meta['control'],
            'structure_key' => $meta['structure_key'],
            'structure' => $meta['structure'],
            'raw_value' => $value,
            'fill_value' => $meta['fill_value'],
        ];
    }

    public function resolve(array|string $field, mixed $value = null): array
    {
        $field = is_string($field) ? ['field_name' => $field, 'name' => $field] : $field;
        $acfType = $this->normalizeType((string) ($field['field_type'] ?? $field['type'] ?? ''));
        $structure = $this->structures->findByField($field);

        if ($structure !== null) {
            return $this->forStructure($structure, $value, $acfType);
        }

        if ($acfType === AcfStructureRegistry::TYPE_REPEATER) {
            return $this->result(self::TYPE_REPEATER, 'repeater', null, $this->normalizer->normalizeRows($value));
        }

        if ($acfType === AcfStructureRegistry::TYPE_GROUP) {
            return $this->result(self::TYPE_GROUP, 'group', null, $this->normalizer->normalizeGroup($value));
        }

        [$smartType, $control] = $this->mapAcfType($acfType, $value);

        return $this->result($smartType, $control, null, $this->coerceValue($smartType, $value));
    }

    private function forStructure(array $structure, mixed $value, string $acfType): array
    {
        $structureType = (string) ($structure['type'] ?? '');

        if ($structureType === AcfStructureRegistry::TYPE_REPEATER) {
            return $this->result(
                self::TYPE_REPEATER,
                'repeater',
                $structure,
                $this->normalizer->normalizeRows($value, $structure),
                $acfType
            );
        }

        return $this->result(
            self::TYPE_GROUP,
            'group',
            $structure,
            $this->normalizer->normalizeGroup($value, $structure),
            $acfType
        );
    }

    private function result(string $smartType, string $control, ?array $structure, mixed $fillValue, string $acfType = ''): array
    {
        return [
            'smart_type' => $smartType,
            'control' => $control,
            'acf_type' => $acfType,
            'structure_key' => (string) ($structure['key'] ?? ''),
            'structure' => $structure,
            'fill_value' => $fillValue,
        ];
    }

    private function mapAcfType(string $acfType, mixed $value): array
    {
        return match ($acfType) {
            'repeater', 'flexible_content' => [self::TYPE_REPEATER, 'repeater'],
            'group' => [self::TYPE_GROUP, 'group'],
            'wysiwyg' => [self::TYPE_WYSIWYG, 'tinymce'],
            'textarea' => [self::TYPE_TEXTAREA, 'textarea'],
            'url', 'oembed', 'link' => [self::TYPE_URL, 'url'],
            'email' => [self::TYPE_EMAIL, 'email'],
            'number', 'range' => [self::TYPE_NUMBER, 'number'],
            'true_false' => [self::TYPE_BOOLEAN, 'boolean'],
            'date_picker' => [self::TYPE_DATE, 'date'],
            'date_time_picker', 'time_picker' => [self::TYPE_DATETIME, 'datetime'],
            'image' => [self::TYPE_IMAGE, 'image'],
            'gallery' => [self::TYPE_GALLERY, 'gallery'],
            'file' => [self::TYPE_FILE, 'file'],
            'relationship', 'post_object', 'page_link', 'user', 'taxonomy' => [self::TYPE_RELATIONSHIP, 'relationship'],
            'select', 'checkbox', 'radio', 'button_group' => [self::TYPE_SELECT, 'select'],
            'json' => [self::TYPE_JSON, 'json'],
            default => $this->mapUnknownValue($value),
        };
    }

    private function mapUnknownValue(mixed $value): array
    {
        $decoded = $this->normalizer->decodeValue($value);

        if (is_array($decoded)) {
            if (array_is_list($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
                return [self::TYPE_REPEATER, 'repeater'];
            }

            return [self::TYPE_JSON, 'json'];
        }

        return [self::TYPE_TEXT, 'text'];
    }

    private function coerceValue(string $smartType, mixed $value): mixed
    {
        return match ($smartType) {
            self::TYPE_REPEATER => $this->normalizer->normalizeRows($value),
            self::TYPE_GROUP => $this->normalizer->normalizeGroup($value),
            self::TYPE_BOOLEAN => $this->coerceBoolean($value),
            self::TYPE_NUMBER => $this->coerceNumber($value),
            self::TYPE_JSON => $this->normalizer->decodeValue($value),
            self::TYPE_URL,
            self::TYPE_EMAIL,
            self::TYPE_DATE,
            self::TYPE_DATETIME,
            self::TYPE_TEXTAREA,
            self::TYPE_WYSIWYG,
            self::TYPE_TEXT => is_string($value) ? trim($value) : $value,
            default => $value,
        };
    }

    private function coerceBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    private function coerceNumber(mixed $value): int|float|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function normalizeType(string $type): string
    {
        return AcfStructureRegistry::normalizeIdentifier($type);
    }
}
