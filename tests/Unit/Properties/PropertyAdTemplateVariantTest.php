<?php

namespace Tests\Unit\Properties;

use App\Models\PropertyAdTemplate;
use PHPUnit\Framework\TestCase;

/**
 * §18 — property-type template variants. `resolvedLayoutFor()` is the PHP
 * mirror of the kernel's `CoreXAd.resolveTemplateLayout()`
 * (public/js/corex-ad-render.js, covered by tests/js/ad-render-kernel.mjs) —
 * used where a layout must be resolved server-side, before reaching the
 * browser: the bulk Ad Manager's per-property payload
 * (AdManagerController::generate()). Both must behave identically; these
 * assert the same cases the JS test does.
 */
class PropertyAdTemplateVariantTest extends TestCase
{
    private function templateWith(array $layoutJson): PropertyAdTemplate
    {
        $tpl = new PropertyAdTemplate();
        $tpl->layout_json = $layoutJson;

        return $tpl;
    }

    public function test_a_matching_property_type_resolves_to_that_variant(): void
    {
        $tpl = $this->templateWith([
            'canvasW' => 1200, 'canvasH' => 628, 'canvasBg' => '#071325',
            'elements' => [['field' => 'beds']],
            'variants' => [
                'Vacant Land / Plot' => [
                    'canvasW' => 1200, 'canvasH' => 628, 'canvasBg' => '#123456',
                    'elements' => [['field' => 'custom_text', 'text' => 'Land size']],
                ],
            ],
        ]);

        $resolved = $tpl->resolvedLayoutFor('Vacant Land / Plot');

        $this->assertSame('#123456', $resolved['canvasBg']);
        $this->assertSame('Land size', $resolved['elements'][0]['text'] ?? null);
    }

    public function test_matching_is_case_insensitive_and_trims_whitespace(): void
    {
        $tpl = $this->templateWith([
            'elements' => [['field' => 'beds']],
            'variants' => ['Vacant Land / Plot' => ['elements' => [['field' => 'custom_text']]]],
        ]);

        $resolved = $tpl->resolvedLayoutFor('  vacant land / plot  ');

        $this->assertSame('custom_text', $resolved['elements'][0]['field']);
    }

    public function test_a_property_type_with_no_variant_falls_back_to_default(): void
    {
        $tpl = $this->templateWith([
            'elements' => [['field' => 'beds']],
            'variants' => ['Vacant Land / Plot' => ['elements' => [['field' => 'custom_text']]]],
        ]);

        $resolved = $tpl->resolvedLayoutFor('House');

        $this->assertSame('beds', $resolved['elements'][0]['field']);
    }

    public function test_a_blank_or_missing_property_type_falls_back_to_default(): void
    {
        $tpl = $this->templateWith([
            'elements' => [['field' => 'beds']],
            'variants' => ['Vacant Land / Plot' => ['elements' => [['field' => 'custom_text']]]],
        ]);

        $this->assertSame('beds', $tpl->resolvedLayoutFor('')['elements'][0]['field']);
        $this->assertSame('beds', $tpl->resolvedLayoutFor(null)['elements'][0]['field']);
    }

    public function test_a_template_with_no_variants_key_at_all_resolves_to_its_own_design_unchanged(): void
    {
        $tpl = $this->templateWith(['canvasW' => 900, 'elements' => [['field' => 'price']]]);

        $resolved = $tpl->resolvedLayoutFor('Vacant Land / Plot');

        $this->assertSame(900, $resolved['canvasW']);
        $this->assertSame('price', $resolved['elements'][0]['field']);
    }
}
