<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Shared fixture for the paper-route hotfix tests (2026-10-21): an agency + agent, a sectional-title Property whose
 * complex and district live on the RECORD (not in the wizard's snapshot), three web templates rendered from their
 * stored editor HTML (their generated blade files do not exist until first use), and a paper-route Flow builder.
 */
abstract class PaperRouteFixtureCase extends TestCase
{
    use RefreshDatabase;

    protected Agency $agency;
    protected User $agent;
    protected Property $property;
    /** @var list<Template> */
    protected array $templates = [];
    /** @var list<string> */
    protected array $bladeFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Queue::fake();

        $this->agency = Agency::create(['name' => 'Cape Sales', 'slug' => 'cs-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        foreach (['access_docuperfect'] as $key) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], []);
        }
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true,
        ]);
        PermissionService::clearCache();

        // Sectional title: complex + district live on the record, NOT in the wizard snapshot.
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'agent_id' => $this->agent->id,
            'title' => '2 Bed Flat', 'status' => 'for_sale', 'listing_type' => 'sale',
            'street_number' => '60', 'street_name' => 'Lilliecrona Boulevard', 'complex_name' => 'Natspat Door',
            'unit_number' => '11', 'suburb' => 'Manaba Beach', 'town' => 'Margate', 'district' => 'Ray Nkonyeni',
            'property_number' => '2398-3', 'title_type' => 'sectional_title',
        ]);

        foreach ([1, 2, 3] as $n) {
            $this->templates[] = $this->makeTemplate($n);
        }
        $this->actingAs($this->agent);
    }

    protected function tearDown(): void
    {
        foreach ($this->bladeFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    protected function makeTemplate(int $n): Template
    {
        $sellerBlocks = $n === 1
            ? '<div class="corex-clause" data-role-block="seller" data-role-block-segment="address">'
              . 'Seller address: <span class="corex-field-value" data-field="seller_address"></span></div>'
            : '';
        $tagged = '<div class="corex-h1">PACKDOC' . $n . ' TITLE</div>'
            . '<p>District: <span class="corex-field-value" data-field="property_district">{{ $property_district ?? \'\' }}</span>'
            . ' | Complex: <span class="corex-field-value" data-field="property_complex_name">{{ $property_complex_name ?? \'\' }}</span>'
            . ' | Town: <span class="corex-field-value" data-field="property_town">{{ $property_town ?? \'\' }}</span>'
            . ' | Erf: <span class="corex-field-value" data-field="property_erf_number">{{ $property_erf_number ?? \'\' }}</span></p>'
            . $sellerBlocks
            . '@include("docuperfect.web-templates.components.signature-block", ["parties" => ["Seller", "Agent"]])';

        // High, fixed ids: a generated blade is written to resources/views/.../cds/template-<id>.blade.php, and low ids
        // would collide with blade files that are tracked in the repo.
        $t = new Template([
            'name' => "Pack Doc {$n}",
            'owner_id' => $this->agent->id,
            'agency_id' => $this->agency->id,
            'is_global' => false,
            'page_count' => 1,
            'render_type' => 'web',
            'template_type' => 'sales',
            'is_esign' => true,
            'signing_parties' => ['owner_party', 'agent'],
            // blade_view names a file that does NOT exist — the paper route must regenerate it from the stored HTML.
            'blade_view' => 'docuperfect.web-templates.cds.template-9' . $n . '000' . random_int(100, 999),
            'editor_state' => ['tagged_html' => $tagged, 'tags' => [], 'mappings' => []],
            'cds_json' => ['sections' => []],
            'field_mappings' => [],
            'fields_json' => [],
        ]);
        $t->id = 880000 + $n;
        $t->save();
        $t->update(['blade_view' => 'docuperfect.web-templates.cds.template-' . $t->id]);
        $this->bladeFiles[] = resource_path('views/docuperfect/web-templates/cds/template-' . $t->id . '.blade.php');
        @unlink(end($this->bladeFiles));

        return $t->fresh();
    }

    /** @return list<array<string, mixed>> */
    protected function sellers(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'role' => 'seller', 'name' => "Seller{$i} Person", 'email' => "seller{$i}@example.test",
                'cell' => '08200000' . $i . $i, 'address' => "{$i} Domicilium Road", 'id_number' => '800101500908' . $i,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<Template>  $templates
     */
    protected function flow(array $templates, int $sellers = 2, bool $pack = true, array $typed = [], array $propertySnapshot = []): Flow
    {
        $fields = [];
        $values = [];
        foreach ($templates as $t) {
            $id = 'tag-district-' . $t->id;
            $fields[] = ['id' => $id, 'type' => 'placeholder', 'tag_type' => 'input', 'field_name' => 'property_district',
                'label' => 'District', '_pack_template_id' => $t->id];
            if (isset($typed['district'])) {
                $values[$id] = $typed['district'];
            }
        }

        $step = [
            // exactly the keys the wizard's getStepData() persists for step 2 — NO district / town / unit.
            'property' => $propertySnapshot + [
                'address' => '60 Lilliecrona Boulevard', 'title' => '60 Lilliecrona Boulevard', 'suburb' => 'Manaba Beach',
                'erf' => '2398-3', 'complex_name' => 'Natspat Door', 'property_type' => 'Apartment / Flat',
                'property_id' => $this->property->id, '_property_source' => 'properties',
            ],
            'recipients' => ['recipients' => $this->sellers($sellers)],
            'fields' => $fields,
            'fill_review' => ['fieldValues' => $values],
        ];
        if ($pack) {
            $step['is_pack_flow'] = true;
            $step['pack_name'] = 'Test Pack';
            $step['template_ids'] = array_map(fn (Template $t) => $t->id, $templates);
        }

        return Flow::create([
            'type' => 'esign', 'template_id' => $templates[0]->id, 'user_id' => $this->agent->id,
            'current_step' => 6, 'step_data' => $step, 'status' => 'active',
        ]);
    }

    /** What a stored document prints: the composed canonical (wet-ink first doc) else merged_html. */
    protected function printed(Document $d): string
    {
        $canonical = trim((string) ($d->web_template_data['canonical_html'] ?? ''));

        return $canonical !== '' ? $canonical : (string) ($d->web_template_data['merged_html'] ?? '');
    }

    /** @return \Illuminate\Support\Collection<int, Document> */
    protected function packDocuments(Flow $flow)
    {
        $ids = $flow->fresh()->step_data['pack_document_ids'] ?? [$flow->fresh()->step_data['document_id']];

        return Document::whereIn('id', $ids)->orderBy('id')->get();
    }

    protected function runRoute(string $route, Flow $flow): void
    {
        $r = $this->actingAs($this->agent)->post(route($route, $flow->id));
        $r->assertRedirect();
        $this->assertSame([], session('errors')?->all() ?? [], $route . ' redirected with errors: ' . json_encode(session('errors')?->all()) . ' ' . json_encode(session('error')));
    }

}
