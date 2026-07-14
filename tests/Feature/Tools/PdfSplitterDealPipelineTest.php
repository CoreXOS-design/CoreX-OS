<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\PdfSplitterController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealDocumentService;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-254 (decision B) — the PDF splitter now files through the canonical
 * document spine (DealDocumentService), so a split document of a type bound to
 * an active DealV2 pipeline step auto-completes that step EXACTLY as a DR2 /
 * e-sign filing of the same type does. One filing truth.
 *
 * This is the "fix-the-class" proof the AT-254 plan requires: splitter output
 * for a type enters the same pipeline as a DR2 filing of that type. We drive the
 * REAL splitter filing path (fileGroupsToDestinations, deal-anchored) and assert
 * the same completed-step + populated deal_step_documents.document_id that the
 * spine's DR2 path (createDealDocument + autoCompleteMatchingStep) produces.
 */
final class PdfSplitterDealPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private array $typeIds = [];
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Pipeline Agency', 'slug' => 'pipe-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'super_admin',
        ]);

        // Type catalogue (grouping drives the Save-To default): 'mandate' files to
        // the property, 'otp' is shared (property + optionally contacts).
        foreach ([
            'mandate' => ['Sole Mandate', 'property'],
            'otp'     => ['Offer to Purchase', 'shared'],
        ] as $slug => [$label, $grouping]) {
            $this->typeIds[$slug] = DB::table('document_types')->insertGetId([
                'slug' => $slug, 'label' => $label, 'sort_order' => 0,
                'is_active' => true, 'grouping' => $grouping,
                'contact_roles' => json_encode($grouping === 'shared' ? ['seller_owner', 'buyer'] : ['seller_owner']),
                'fica_slot' => 'none',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->tmpDir = sys_get_temp_dir() . '/at254b-' . uniqid();
        @mkdir($this->tmpDir, 0777, true);

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ── proof: splitter output completes the deal step (same as DR2) ──────

    public function test_split_document_auto_completes_the_matching_deal_step(): void
    {
        $property = $this->makeProperty();
        $deal     = $this->makeDealWithMandateStep($property);
        $step     = $deal->stepInstances()->where('name', 'Mandate Signed')->firstOrFail();
        $this->assertSame('active', $step->status, 'step starts active (on_creation)');

        // Split a 'mandate' page and file it against the deal (the real splitter path).
        $groups = [$this->group('mandate', [], $this->outFile('mandate'))];
        $res = $this->callFile($property, $groups, $this->agency->id, $this->attached($property), $deal);

        // Filed to the property (mandate default) — no orphan.
        $this->assertSame(1, $res['property'] + $res['fallback']);

        // THE PROOF: the split doc completed the pipeline step through the engine.
        $this->assertSame('completed', $step->fresh()->status, 'splitter output auto-completed the deal step');

        $doc = Document::where('source_type', 'pdf_splitter')->firstOrFail();
        $this->assertSame($deal->id, (int) $doc->deal_id, 'doc anchored to the deal');
        $this->assertDatabaseHas('deal_step_documents', [
            'deal_step_instance_id' => $step->id,
            'document_id'           => $doc->id, // populated, not an orphan file_path
        ]);
    }

    public function test_split_filing_matches_the_dr2_filing_outcome_for_the_same_type(): void
    {
        // Two identical deals on two properties; one filed by the SPLITTER, one by
        // the canonical DR2 path. The completed-step outcome must be identical.
        $splitProp = $this->makeProperty('11 Marine Drive');
        $splitDeal = $this->makeDealWithMandateStep($splitProp);
        $splitStep = $splitDeal->stepInstances()->where('name', 'Mandate Signed')->firstOrFail();

        $dr2Prop = $this->makeProperty('12 Marine Drive');
        $dr2Deal = $this->makeDealWithMandateStep($dr2Prop);
        $dr2Step = $dr2Deal->stepInstances()->where('name', 'Mandate Signed')->firstOrFail();

        // Splitter path.
        $this->callFile(
            $splitProp,
            [$this->group('mandate', [], $this->outFile('mandate'))],
            $this->agency->id,
            $this->attached($splitProp),
            $splitDeal
        );

        // Canonical DR2 path (the same spine, invoked as an upload-onto-deal would).
        $docs = app(DealDocumentService::class);
        $dr2Doc = $docs->createDealDocument($dr2Deal, [
            'original_name'    => 'mandate.pdf',
            'storage_path'     => 'deals/dr2/mandate.pdf',
            'document_type_id' => $this->typeIds['mandate'],
            'source_type'      => 'deal_upload',
        ], $this->user);
        $docs->autoCompleteMatchingStep($dr2Deal, $dr2Doc, $this->user);

        // Identical outcome: both steps completed, both have exactly one linked doc.
        $this->assertSame('completed', $splitStep->fresh()->status);
        $this->assertSame('completed', $dr2Step->fresh()->status);
        $this->assertSame(1, $splitStep->documents()->count(), 'splitter: one step-document row');
        $this->assertSame(1, $dr2Step->documents()->count(), 'DR2: one step-document row');
    }

    public function test_split_document_of_unmatched_type_completes_nothing_no_false_completion(): void
    {
        // A split 'otp' page filed against a deal whose only doc step expects a
        // MANDATE must NOT force-complete that step (config-driven; no guessing).
        (new \App\Services\Compliance\AgencyComplianceDocTypeService())
            ->setDestination($this->agency->id, $this->typeIds['otp'], true, false);

        $property = $this->makeProperty();
        $deal     = $this->makeDealWithMandateStep($property);
        $step     = $deal->stepInstances()->where('name', 'Mandate Signed')->firstOrFail();

        $groups = [$this->group('otp', [], $this->outFile('otp'))];
        $this->callFile($property, $groups, $this->agency->id, $this->attached($property), $deal);

        $this->assertSame('active', $step->fresh()->status, 'no false completion on a non-matching type');
        // The doc is still filed (to the property) and anchored to the deal.
        $doc = Document::where('source_type', 'pdf_splitter')->firstOrFail();
        $this->assertSame($deal->id, (int) $doc->deal_id);
    }

    public function test_deal_less_split_still_files_and_never_crashes(): void
    {
        // The common case: a split with NO deal. Must file to the property and be
        // a graceful no-op on the deal side (deal = null).
        $property = $this->makeProperty();
        $groups = [$this->group('mandate', [], $this->outFile('mandate'))];
        $res = $this->callFile($property, $groups, $this->agency->id, $this->attached($property), null);

        $this->assertSame(1, $res['property'] + $res['fallback']);
        $this->assertSame(1, $property->fresh()->documents()->count());
        $doc = Document::where('source_type', 'pdf_splitter')->firstOrFail();
        $this->assertNull($doc->deal_id, 'no deal anchor when none supplied');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeProperty(string $address = '8 Compensation Beach Rd'): Property
    {
        return Property::create([
            'title' => 'Split Target', 'agency_id' => $this->agency->id,
            'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'sale', 'address' => $address,
            'street_name' => 'Marine Drive', 'suburb' => 'Shelly Beach',
            'town' => 'Shelly Beach', 'province' => 'KwaZulu-Natal',
            'price' => 2950000, 'property_type' => 'House',
        ]);
    }

    /** A DealV2 on the property with an ACTIVE document step bound to the mandate type. */
    private function makeDealWithMandateStep(Property $property): DealV2
    {
        $template = DealPipelineTemplate::create([
            'name' => 'AT-254B Test', 'deal_type' => 'bond', 'agency_id' => $this->agency->id,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->user->id,
        ]);

        // on_creation → the step is ACTIVE immediately (isolates the spine logic).
        DealPipelineStep::create([
            'pipeline_template_id' => $template->id, 'agency_id' => $this->agency->id,
            'position' => 1, 'name' => 'Mandate Signed', 'completion_type' => 'document_upload',
            'completion_config' => ['document_type_id' => $this->typeIds['mandate']],
            'trigger_type' => 'on_creation', 'days_offset' => 0,
            'rag_green_days' => 14, 'rag_amber_days' => 7, 'rag_red_days' => 3,
            'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
            'requires_bm_approval' => false,
        ]);

        return app(DealPipelineService::class)->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id,
            'listing_agent_id' => $this->user->id, 'pipeline_template_id' => $template->id,
            'purchase_price' => 2_950_000, 'commission_amount' => 147_500, 'commission_vat' => 22_125,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branch->id, 'created_by_id' => $this->user->id,
            'agents' => [['side' => 'listing', 'user_id' => $this->user->id]],
            'contacts' => [],
        ]);
    }

    private function outFile(string $slug): string
    {
        $path = $this->tmpDir . "/pack__{$slug}_" . uniqid() . '.pdf';
        file_put_contents($path, "%PDF-1.4 fake {$slug}\n");
        return $path;
    }

    private function group(string $label, array $contactIds, string $file): array
    {
        return ['label' => $label, 'contact_ids' => $contactIds, 'pages' => [1], 'file' => $file];
    }

    private function attached(Property $p): Collection
    {
        return $p->fresh()->contacts()->get()->keyBy('id');
    }

    private function callFile(Property $p, array $groups, int $agencyId, Collection $attached, ?DealV2 $deal): array
    {
        $m = new ReflectionMethod(PdfSplitterController::class, 'fileGroupsToDestinations');
        $m->setAccessible(true);
        return $m->invoke(app(PdfSplitterController::class), $p, $groups, $agencyId, $attached, $deal);
    }
}
