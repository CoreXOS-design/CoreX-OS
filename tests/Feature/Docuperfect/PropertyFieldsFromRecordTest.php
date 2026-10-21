<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Property;

/**
 * HOTFIX 2026-10-21 — the wizard saves a SMALL property snapshot (address, suburb, erf, complex, type, id), so District /
 * Town / Street / Unit number were blank on every route, electronic included, even when the Property record has them.
 * WebTemplateDataService now fills any MISSING key from the linked Property record; a value the snapshot (or Fill &
 * Review, applied afterwards) already holds is never overwritten.
 */
final class PropertyFieldsFromRecordTest extends PaperRouteFixtureCase
{
    public function test_district_complex_town_and_erf_fill_from_the_property_record_on_both_paper_routes(): void
    {
        foreach (['docuperfect.esign.prepareDownload', 'docuperfect.esign.prepareWetInk'] as $route) {
            $flow = $this->flow($this->templates);
            $this->runRoute($route, $flow);
            foreach ($this->packDocuments($flow) as $d) {
                $p = $this->printed($d);
                $this->assertStringContainsString('Ray Nkonyeni', $p, "{$route}: District from the Property record");
                $this->assertStringContainsString('Natspat Door', $p, "{$route}: Complex");
                $this->assertStringContainsString('2398-3', $p, "{$route}: Erf");
            }
        }
    }

    public function test_a_value_the_snapshot_already_holds_is_never_overwritten_by_the_record(): void
    {
        $flow = $this->flow($this->templates, 2, true, [], ['complex_name' => 'Snapshot Complex', 'district' => 'Snapshot District']);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $p = $this->printed($this->packDocuments($flow)->first());

        $this->assertStringContainsString('Snapshot Complex', $p);
        $this->assertStringContainsString('Snapshot District', $p);
        $this->assertStringNotContainsString('Ray Nkonyeni', $p);
    }

    public function test_freehold_property_with_no_complex_or_district_prints_blank_and_never_another_propertys_values(): void
    {
        $freehold = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->agent->branch_id, 'agent_id' => $this->agent->id,
            'title' => 'House', 'status' => 'for_sale', 'listing_type' => 'sale', 'street_number' => '380',
            'street_name' => 'Wilfred Street', 'suburb' => 'Shelly Beach', 'property_number' => '380', 'title_type' => 'full_title',
        ]);
        $flow = $this->flow($this->templates, 2, true, [], [
            'property_id' => $freehold->id, 'complex_name' => '', 'erf' => '380', 'suburb' => 'Shelly Beach',
        ]);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $p = $this->printed($this->packDocuments($flow)->first());

        $this->assertStringNotContainsString('Natspat Door', $p, 'no other property\'s complex bleeds in');
        $this->assertStringNotContainsString('Ray Nkonyeni', $p);
        $this->assertStringContainsString('Erf: <span class="corex-field-value" data-field="property_erf_number">380', $p);
    }

    // ---- 4. electronic route: typed District + record fill (parity with the paper routes) ------------------------

    public function test_electronic_route_carries_district_typed_in_fill_and_review_and_fills_it_from_the_record(): void
    {
        // The electronic route (unchanged by this hotfix) renders the generated blade directly, so generate it first.
        app(\App\Services\Docuperfect\WebTemplateBladeEnsurer::class)->ensure($this->templates[0]);

        // typed
        $flow = $this->flow([$this->templates[0]], 2, false, ['district' => 'Typed District XYZ']);
        $res = $this->actingAs($this->agent)->post(route('docuperfect.esign.prepareSigning', $flow->id), ['_json' => true], ['Accept' => 'application/json']);
        $this->assertContains($res->getStatusCode(), [200, 201, 302], 'prepareSigning: ' . substr($res->getContent(), 0, 600));
        $doc = Document::latest('id')->first();
        $html = (string) ($doc->web_template_data['canonical_html'] ?? $doc->web_template_data['merged_html'] ?? '');
        $this->assertStringContainsString('Typed District XYZ', $html, 'electronic: typed District is on the finished document');

        // not typed -> from the Property record (was blank on the electronic route before this hotfix)
        $flow2 = $this->flow([$this->templates[0]], 2, false);
        $this->actingAs($this->agent)->post(route('docuperfect.esign.prepareSigning', $flow2->id), ['_json' => true], ['Accept' => 'application/json']);
        $doc2 = Document::latest('id')->first();
        $this->assertNotSame($doc->id, $doc2->id);
        $html2 = (string) ($doc2->web_template_data['canonical_html'] ?? $doc2->web_template_data['merged_html'] ?? '');
        $this->assertStringContainsString('Ray Nkonyeni', $html2, 'electronic: District filled from the Property record');
    }
}
