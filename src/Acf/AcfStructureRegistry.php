<?php

namespace hexa_package_wordpress\Acf;

class AcfStructureRegistry
{
    public const TYPE_GROUP = 'group';
    public const TYPE_REPEATER = 'repeater';

    public function all(): array
    {
        return self::definitions();
    }

    public function names(): array
    {
        return array_keys(self::definitions());
    }

    public function get(string $key): ?array
    {
        $key = self::normalizeIdentifier($key);

        return self::definitions()[$key] ?? null;
    }

    public function find(null|string $fieldName = null, null|string $fieldKey = null, null|string $path = null): ?array
    {
        $tokens = array_values(array_filter([
            self::normalizeIdentifier($fieldName),
            self::normalizeIdentifier($fieldKey),
            self::normalizeIdentifier($path),
            self::normalizeIdentifier($this->lastPathSegment((string) $path)),
        ]));

        if ($tokens === []) {
            return null;
        }

        foreach (self::definitions() as $key => $structure) {
            $matches = array_map([self::class, 'normalizeIdentifier'], array_merge(
                [$key, (string) ($structure['field_key'] ?? '')],
                (array) ($structure['aliases'] ?? [])
            ));

            foreach ($tokens as $token) {
                if ($token !== '' && in_array($token, $matches, true)) {
                    return $structure;
                }
            }
        }

        return null;
    }

    public function findByField(array|string $field): ?array
    {
        if (is_string($field)) {
            return $this->find($field);
        }

        return $this->find(
            (string) ($field['field_name'] ?? $field['name'] ?? ''),
            (string) ($field['field_key'] ?? $field['key'] ?? ''),
            (string) ($field['path'] ?? '')
        );
    }

    public function fieldAliasMap(array $structure): array
    {
        $map = [];
        foreach ((array) ($structure['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $aliases = array_merge([$name, (string) ($field['field_key'] ?? '')], (array) ($field['aliases'] ?? []));
            foreach ($aliases as $alias) {
                $alias = self::normalizeIdentifier((string) $alias);
                if ($alias !== '') {
                    $map[$alias] = $name;
                }
            }
        }

        return $map;
    }

    public static function normalizeIdentifier(null|string $identifier): string
    {
        $identifier = strtolower(trim((string) $identifier));
        $identifier = str_replace([' ', '-', '[', ']'], ['_', '_', '_', ''], $identifier);
        $identifier = preg_replace('/_+/', '_', $identifier) ?: '';

        return trim($identifier, '_');
    }

    private function lastPathSegment(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $parts = explode('.', $path);

        return (string) end($parts);
    }

    private static function definitions(): array
    {
        static $definitions = null;

        if ($definitions !== null) {
            return $definitions;
        }

        $definitions = [
            'alternate_names' => self::repeater('alternate_names', 'Alternate Names', [
                self::field('name', 'Name'),
            ], ['aliases' => ['alternate_name', 'also_known_as', 'aka']]),

            'nationality' => self::repeater('nationality', 'Nationality', [
                self::field('value', 'Value'),
            ], ['aliases' => ['nationalities', 'citizenship']]),

            'knows_language' => self::repeater('knows_language', 'Knows Language', [
                self::field('value', 'Value'),
            ], ['aliases' => ['knows_languages', 'languages']]),

            'awards' => self::repeater('awards', 'Awards', [
                self::field('value', 'Value'),
            ], ['aliases' => ['award']]),

            'professions' => self::repeater('professions', 'Professions', [
                self::field('name', 'Name'),
                self::field('page', 'Page', 'url'),
                self::field('summary', 'Summary', 'textarea'),
            ], ['aliases' => ['profession']]),

            'education' => self::repeater('education', 'Education', [
                self::field('college', 'College'),
                self::field('wiki_url', 'Wikipedia URL', 'url', ['wikipedia_url']),
                self::field('year', 'Year'),
                self::field('designation', 'Designation'),
                self::field('major', 'Major'),
            ], ['aliases' => ['schools', 'academic_background']]),

            'personal_education' => self::repeater('personal_education', 'Personal Education', [
                self::field('school', 'School', 'text', ['field_smp_vp_personal_education_school']),
                self::field('degree', 'Degree', 'text', ['field_smp_vp_personal_education_degree']),
                self::field('field_of_study', 'Field Of Study', 'text', ['field_smp_vp_personal_education_field_of_study']),
                self::field('start_date', 'Start Date', 'date', ['field_smp_vp_personal_education_start_date']),
                self::field('end_date', 'End Date', 'date', ['field_smp_vp_personal_education_end_date']),
                self::field('url', 'URL', 'url', ['field_smp_vp_personal_education_url']),
                self::field('wikipedia_url', 'Wikipedia URL', 'url', ['field_smp_vp_personal_education_wikipedia_url']),
                self::field('same_as', 'Same As', 'url', ['field_smp_vp_personal_education_same_as']),
                self::field('description', 'Description', 'textarea', ['field_smp_vp_personal_education_description']),
            ], [
                'aliases' => ['field_smp_vp_personal_education'],
                'field_key' => 'field_smp_vp_personal_education',
            ]),

            'articles' => self::repeater('articles', 'Articles', [
                self::field('title', 'Title'),
                self::field('source', 'Source'),
                self::field('url', 'URL', 'url'),
            ], ['aliases' => ['article']]),

            'faq' => self::repeater('faq', 'FAQs', [
                self::field('question', 'Question', 'textarea'),
                self::field('answer', 'Answer', 'wysiwyg'),
            ], ['aliases' => ['faqs', 'frequently_asked_questions']]),

            'post_faq_items' => self::repeater('post_faq_items', 'Post FAQ Items', [
                self::field('question', 'Question', 'textarea'),
                self::field('answer', 'Answer', 'wysiwyg'),
                self::field('enabled_for_schema', 'Enabled For Schema', 'boolean'),
            ], ['aliases' => ['post_faqs', 'faq_items']]),

            'profiles' => self::repeater('profiles', 'Profiles', [
                self::field('profile', 'Profile'),
            ], ['aliases' => ['profile_list']]),

            'pending_profiles' => self::repeater('pending_profiles', 'Pending Profiles', [
                self::field('name', 'Name'),
                self::field('type', 'Type'),
                self::field('url', 'URL', 'url'),
            ]),

            'organizations_founded' => self::repeater('organizations_founded', 'Organizations Founded', [
                self::field('organization', 'Organization'),
            ], ['aliases' => ['founded_organizations']]),

            'books' => self::repeater('books', 'Books', [
                self::field('title', 'Title'),
                self::field('cover', 'Cover', 'image', ['cover_image', 'featured_image']),
            ], ['aliases' => ['book_list']]),

            'book' => self::group('book', 'Book', [
                self::field('title', 'Title'),
                self::field('cover', 'Cover', 'image', ['cover_image', 'featured_image']),
                self::field('description', 'Description', 'textarea'),
            ]),

            'notification_emails' => self::repeater('notification_emails', 'Notification Emails', [
                self::field('email', 'Email', 'email'),
            ]),

            'unclaimed_profiles' => self::repeater('unclaimed_profiles', 'Unclaimed Profiles', [
                self::field('profile', 'Profile'),
            ]),

            'recent_media' => self::repeater('recent_media', 'Recent Media', [
                self::field('title', 'Title'),
                self::field('url', 'URL', 'url'),
            ]),

            'quotes' => self::repeater('quotes', 'Quotes', [
                self::field('quote', 'Quote', 'textarea'),
                self::field('name', 'Name'),
                self::field('title', 'Title'),
            ]),

            'contact_points' => self::repeater('contact_points', 'Contact Points', [
                self::field('contact_type', 'Contact Type'),
                self::field('email', 'Email', 'email'),
                self::field('telephone', 'Telephone'),
                self::field('url', 'URL', 'url'),
            ], ['aliases' => ['contact_point']]),

            'rss_post_type' => self::repeater('rss_post_type', 'RSS Post Type', [
                self::field('slug', 'Slug'),
                self::field('rss_id', 'RSS ID'),
            ]),

            'rss_post_category' => self::repeater('rss_post_category', 'RSS Post Category', [
                self::field('slug', 'Slug'),
                self::field('rss_id', 'RSS ID'),
            ]),

            'same_as_urls' => self::repeater('same_as_urls', 'SameAs URLs', [
                self::field('url', 'URL', 'url'),
            ], ['aliases' => ['sameas', 'same_as']]),

            'social_urls' => self::group('social_urls', 'Social URLs', [
                self::field('wikipedia', 'Wikipedia', 'url'),
                self::field('facebook', 'Facebook', 'url'),
                self::field('instagram', 'Instagram', 'url'),
                self::field('linkedin', 'LinkedIn', 'url'),
                self::field('website', 'Website', 'url'),
                self::field('soundcloud', 'SoundCloud', 'url'),
                self::field('imdb', 'IMDb', 'url'),
                self::field('tiktok', 'TikTok', 'url'),
                self::field('youtube', 'YouTube', 'url'),
                self::field('amazon', 'Amazon', 'url'),
                self::field('x', 'X', 'url', ['twitter']),
                self::field('audible', 'Audible', 'url'),
                self::field('github', 'GitHub', 'url'),
                self::field('f6s', 'F6S', 'url'),
                self::field('crunchbase', 'Crunchbase', 'url'),
                self::field('muckrack', 'MuckRack', 'url'),
                self::field('angellist', 'AngelList', 'url', ['wellfound', 'well_found']),
            ], ['aliases' => ['url', 'urls', 'social_media', 'social_links']]),

            'parent_organization' => self::group('parent_organization', 'Parent Organization', [
                self::field('name', 'Name'),
                self::field('url', 'URL', 'url'),
            ]),

            'postal_address' => self::group('postal_address', 'Postal Address', [
                self::field('street_address', 'Street Address'),
                self::field('address_locality', 'Address Locality'),
                self::field('address_region', 'Address Region'),
                self::field('postal_code', 'Postal Code'),
                self::field('address_country', 'Address Country'),
            ], ['aliases' => ['address']]),

            'location_born' => self::group('location_born', 'Location Born', [
                self::field('name', 'Name'),
                self::field('wikipedia_url', 'Wikipedia URL', 'url'),
            ], ['aliases' => ['birth_place', 'place_of_birth']]),

            'current_residence' => self::group('current_residence', 'Current Residence', [
                self::field('name', 'Name'),
                self::field('wikipedia_url', 'Wikipedia URL', 'url'),
            ], ['aliases' => ['residence']]),

            'new_entity_email' => self::group('new_entity_email', 'New Entity Email', [
                self::field('subject', 'Subject'),
                self::field('message', 'Message', 'wysiwyg'),
            ]),

            'entity_summary_email' => self::group('entity_summary_email', 'Entity Summary Email', [
                self::field('subject', 'Subject'),
                self::field('message', 'Message', 'wysiwyg'),
            ]),

            'welcome_email' => self::group('welcome_email', 'Welcome Email', [
                self::field('subject', 'Subject'),
                self::field('message', 'Message', 'wysiwyg'),
            ]),
        ];

        return $definitions;
    }

    private static function repeater(string $key, string $label, array $fields, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_REPEATER,
            'fields' => $fields,
        ], $extra);
    }

    private static function group(string $key, string $label, array $fields, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => self::TYPE_GROUP,
            'fields' => $fields,
        ], $extra);
    }

    private static function field(string $name, string $label, string $type = 'text', array $aliases = []): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'aliases' => $aliases,
        ];
    }
}
