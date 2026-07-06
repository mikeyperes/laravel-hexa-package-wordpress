<?php

namespace Tests\Unit;

use hexa_package_wordpress\Acf\AcfRepeaterNormalizer;
use hexa_package_wordpress\Acf\AcfSmartTypeResolver;
use hexa_package_wordpress\Acf\AcfStructureRegistry;
use Tests\TestCase;

class AcfSmartStructureTest extends TestCase
{
    public function test_personal_education_resolves_to_canonical_repeater_structure(): void
    {
        $registry = new AcfStructureRegistry();
        $structure = $registry->find('personal_education');

        $this->assertIsArray($structure);
        $this->assertSame('personal_education', $structure['key']);
        $this->assertSame('education', $structure['canonical_key'] ?? null);
    }

    public function test_repeater_normalizer_maps_education_aliases_to_registered_fields(): void
    {
        $registry = new AcfStructureRegistry();
        $normalizer = new AcfRepeaterNormalizer($registry);

        $rows = $normalizer->normalizeRows([
            [
                'school' => 'Yeshiva University',
                'wikipedia_url' => 'https://en.wikipedia.org/wiki/Yeshiva_University',
                'degree' => 'BS',
                'field_of_study' => 'Computer Science',
            ],
        ], 'personal_education');

        $this->assertSame('Yeshiva University', $rows[0]['college'] ?? null);
        $this->assertSame('https://en.wikipedia.org/wiki/Yeshiva_University', $rows[0]['wiki_url'] ?? null);
        $this->assertSame('BS', $rows[0]['designation'] ?? null);
        $this->assertSame('Computer Science', $rows[0]['major'] ?? null);
    }

    public function test_smart_type_resolver_exposes_repeater_control_and_fill_value(): void
    {
        $registry = new AcfStructureRegistry();
        $resolver = new AcfSmartTypeResolver($registry, new AcfRepeaterNormalizer($registry));

        $typed = $resolver->typedValue('personal_education', [
            ['school' => 'Dawson College'],
        ], [
            'field_type' => 'repeater',
            'field_key' => 'field_smp_vp_personal_education',
        ]);

        $this->assertSame('acf_repeater', $typed['smart_type']);
        $this->assertSame('repeater', $typed['control']);
        $this->assertSame('personal_education', $typed['structure_key']);
        $this->assertSame('Dawson College', $typed['fill_value'][0]['college'] ?? null);
    }
}
