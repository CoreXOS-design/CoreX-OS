<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * HOTFIX 2026-10-21 — a stand-alone Mandatory Disclosure (the CDS import) had no property address anywhere, while the
 * sales Mandatory Disclosure opens with "…the immovable property situated at <address>". `esign:add-property-header`
 * adds that block as a BOUND Property Address tag; the address then shows on every route, stand-alone and in a pack.
 */
final class MandatoryDisclosurePropertyHeaderTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Template $mdf;
    private string $bladeFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Queue::fake();

        $this->agency = Agency::create(['name' => 'Cape Sales', 'slug' => 'cs-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_docuperfect', 'agency_id' => $this->agency->id], []);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        PermissionService::clearCache();

        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'agent_id' => $this->agent->id,
            'title' => 'House', 'status' => 'for_sale', 'listing_type' => 'sale', 'street_number' => '380',
            'street_name' => 'Wilfred Street', 'suburb' => 'Shelly Beach', 'property_number' => '380',
        ]);

        NamedField::create(['name' => 'Property Address', 'field_type' => 'text', 'source_type' => 'property', 'source_column' => 'address+suburb']);

        // The shape of QA1 template #74: a title, a checklist, no property field at all.
        // High, fixed id — the generated blade must not collide with blade files tracked in the repo.
        $this->mdf = new Template([
            'name' => 'Mandatory Disclosure', 'owner_id' => $this->agent->id, 'agency_id' => $this->agency->id, 'is_global' => false,
            'page_count' => 1, 'render_type' => 'web', 'template_type' => 'cds', 'is_esign' => true,
            'signing_parties' => ['owner_party', 'agent'],
            'blade_view' => 'docuperfect.web-templates.cds.template-pending',
            'editor_state' => [
                'tagged_html' => '<div class="corex-h1">IMMOVABLE PROPERTY CONDITION REPORT</div><p>MDFBODY checklist</p>',
                'tags' => [], 'mappings' => [],
            ],
            'cds_json' => ['sections' => []], 'field_mappings' => [], 'fields_json' => [],
        ]);
        $this->mdf->id = 880100;
        $this->mdf->save();
        $this->mdf->update(['blade_view' => 'docuperfect.web-templates.cds.template-' . $this->mdf->id]);
        $this->bladeFile = resource_path('views/docuperfect/web-templates/cds/template-' . $this->mdf->id . '.blade.php');
        @unlink($this->bladeFile);
        $this->actingAs($this->agent);
    }

    protected function tearDown(): void
    {
        @unlink($this->bladeFile);
        parent::tearDown();
    }

    private function flow(): Flow
    {
        return Flow::create([
            'type' => 'esign', 'template_id' => $this->mdf->id, 'user_id' => $this->agent->id, 'current_step' => 6, 'status' => 'active',
            'step_data' => [
                'property' => ['address' => '380 Wilfred Street', 'title' => '380 Wilfred Street', 'suburb' => 'Shelly Beach',
                    'erf' => '380', 'property_id' => $this->property->id, '_property_source' => 'properties'],
                'recipients' => ['recipients' => [[
                    'role' => 'seller', 'name' => 'Seller One', 'email' => 's1@example.test', 'cell' => '0820000011',
                    'address' => '1 Road', 'id_number' => '8001015009081',
                ]]],
                'fields' => [],
                'fill_review' => ['fieldValues' => []],
            ],
        ]);
    }

    private function printedAfterDownload(): string
    {
        $flow = $this->flow();
        $this->post(route('docuperfect.esign.prepareDownload', $flow->id))->assertRedirect();
        $d = Document::find($flow->fresh()->step_data['document_id']);

        return (string) $d->web_template_data['merged_html'];
    }

    public function test_without_the_block_the_stand_alone_mdf_has_no_address(): void
    {
        $this->assertStringNotContainsString('Wilfred Street', $this->printedAfterDownload());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->artisan('esign:add-property-header', ['template' => $this->mdf->id])->assertSuccessful();

        $this->assertStringNotContainsString('data-corex-property-header', json_encode($this->mdf->fresh()->editor_state));
    }

    public function test_applied_block_is_bound_and_shows_the_property_address_at_the_top_on_download_and_wet_ink(): void
    {
        $this->artisan('esign:add-property-header', ['template' => $this->mdf->id, '--apply' => true])->assertSuccessful();

        $mdf = $this->mdf->fresh();
        $mappings = array_values(array_filter($mdf->field_mappings, fn ($m) => ($m['sourceType'] ?? null) === 'property'));
        $this->assertCount(1, $mappings, 'one bound Property Address mapping');
        $this->assertSame('named_field', $mappings[0]['mappingType']);

        $printed = $this->printedAfterDownload();
        $this->assertStringContainsString('380 Wilfred Street', $printed, 'download-and-sign: bound address in the header block');
        $this->assertLessThan(strpos($printed, 'MDFBODY'), strpos($printed, '380 Wilfred Street'), 'at the top, before the body');

        $flow = $this->flow();
        $this->post(route('docuperfect.esign.prepareWetInk', $flow->id))->assertRedirect();
        $doc = Document::find($flow->fresh()->step_data['document_id']);
        $wet = (string) ($doc->web_template_data['canonical_html'] ?? $doc->web_template_data['merged_html']);
        $this->assertStringContainsString('380 Wilfred Street', $wet, 'wet-ink');
    }

    public function test_applying_twice_adds_the_block_once(): void
    {
        $this->artisan('esign:add-property-header', ['template' => $this->mdf->id, '--apply' => true])->assertSuccessful();
        $this->artisan('esign:add-property-header', ['template' => $this->mdf->id, '--apply' => true])->assertSuccessful();

        $this->assertSame(1, substr_count(json_encode($this->mdf->fresh()->editor_state), 'data-corex-property-header'));
        $this->assertCount(1, $this->mdf->fresh()->field_mappings);
    }

    public function test_refuses_a_template_that_is_not_a_mandatory_disclosure(): void
    {
        $this->mdf->update(['name' => 'Exclusive Authority']);

        $this->artisan('esign:add-property-header', ['template' => $this->mdf->id, '--apply' => true])->assertFailed();
    }
}
