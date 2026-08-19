<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-360 — Fill & Review typed values must reach the signed document's web_template_data.
 *
 * WebTemplateDataService::resolve() rebuilds web_template_data from the Property / Contact / Deal
 * pillars only, so a field the agent hand-typed with no pillar source (lessee alternate address,
 * occupancy counts, escalation month, fee overrides, …) was written to Document.fields_json but
 * never reached web_template_data — and rendered BLANK on the agent signing view. The wizard preview
 * applied this overlay; the document-creation path (prepareSigning) did not. overlayFillReviewValues()
 * closes that asymmetry.
 */
final class FillReviewOverlayTest extends TestCase
{
    private function overlay(array $data, array $stepData, ?int $onlyPack = null): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'overlayFillReviewValues');
        $m->setAccessible(true);

        return $m->invoke(app(ESignWizardController::class), $data, $stepData, $onlyPack);
    }

    /** The exact fields dropped for Johan's lease (Template #75) now land under their blade vars. */
    public function test_typed_pillar_less_values_populate_blade_vars(): void
    {
        $stepData = [
            'fields' => [
                ['id' => 'tag-a', 'field_name' => 'lessee_address'],
                ['id' => 'tag-b', 'field_name' => 'adults'],
                ['id' => 'tag-c', 'field_name' => 'other_occupants'],
                ['id' => 'tag-d', 'field_name' => 'escalation_month'],
                ['id' => 'tag-e', 'field_name' => 'lets_assist_fee'],
                ['id' => 'tag-f', 'field_name' => 'net_amount_to_owner'],
            ],
            'fill_review' => ['fieldValues' => [
                'tag-a' => 'Office 5, The Emporium', 'tag-b' => '4', 'tag-c' => '2',
                'tag-d' => 'February', 'tag-e' => '250', 'tag-f' => '9000',
            ]],
        ];

        $out = $this->overlay([], $stepData);

        $this->assertSame('Office 5, The Emporium', $out['lessee_address']);
        $this->assertSame('4', $out['adults']);
        $this->assertSame('2', $out['other_occupants']);
        $this->assertSame('February', $out['escalation_month']);
        $this->assertSame('250', $out['lets_assist_fee']);
        $this->assertSame('9000', $out['net_amount_to_owner']);
    }

    /** A composite field_name is sanitised to the same identifier the blade emits (AT-359b parity). */
    public function test_composite_field_name_key_is_sanitised(): void
    {
        $stepData = [
            'fields' => [['id' => 'tag-x', 'field_name' => 'property_address+suburb']],
            'fill_review' => ['fieldValues' => ['tag-x' => '16, La Mer, Margate']],
        ];

        $out = $this->overlay([], $stepData);

        $this->assertSame('16, La Mer, Margate', $out['property_address_suburb']);
        $this->assertArrayNotHasKey('property_address+suburb', $out);
    }

    /** A typed value overrides the pillar-resolved value for the same field (agent input wins). */
    public function test_typed_value_overrides_resolved(): void
    {
        $stepData = [
            'fields' => [['id' => 'tag-a', 'field_name' => 'lessor_name']],
            'fill_review' => ['fieldValues' => ['tag-a' => 'Typed Name']],
        ];

        $out = $this->overlay(['lessor_name' => 'Pillar Name'], $stepData);

        $this->assertSame('Typed Name', $out['lessor_name']);
    }

    /** An empty typed value never clobbers an already-resolved value. */
    public function test_empty_typed_value_does_not_clobber(): void
    {
        $stepData = [
            'fields' => [['id' => 'tag-a', 'field_name' => 'lessor_name']],
            'fill_review' => ['fieldValues' => ['tag-a' => '']],
        ];

        $out = $this->overlay(['lessor_name' => 'Pillar Name'], $stepData);

        $this->assertSame('Pillar Name', $out['lessor_name']);
    }

    /** Pack scoping: a value only applies to the pack template it was typed for. */
    public function test_pack_template_scoping(): void
    {
        $stepData = [
            'fields' => [
                ['id' => 'tag-a', 'field_name' => 'adults', '_pack_template_id' => 75],
                ['id' => 'tag-b', 'field_name' => 'permitted_pets', '_pack_template_id' => 80],
            ],
            'fill_review' => ['fieldValues' => ['tag-a' => '4', 'tag-b' => 'Yes']],
        ];

        $out = $this->overlay([], $stepData, 75);

        $this->assertSame('4', $out['adults']);
        $this->assertArrayNotHasKey('permitted_pets', $out);
    }

    /** No fill_review values → data returned unchanged. */
    public function test_no_values_is_noop(): void
    {
        $data = ['lessor_name' => 'X'];
        $this->assertSame($data, $this->overlay($data, ['fields' => []]));
    }
}
