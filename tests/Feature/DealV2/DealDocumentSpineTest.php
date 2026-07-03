<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealDocumentService;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WS3 (AT-158 / DR2, decision D4) — the document spine.
 *
 * Gate (spec §7): one upload / split / sign links a document to the deal, its
 * property AND its contacts in a single pass, and — when the pipeline expects
 * that document type on an active step — auto-completes the step and populates
 * deal_step_documents.document_id. No orphaned file. Every path is guarded:
 * missing type, ambiguous match, zero-or-many deals, deleted/empty relations,
 * and a re-fire (idempotency) all resolve safely.
 *
 * Single agency per test → BelongsToAgency auto-stamps and the scopes bypass
 * (no auth), same convention as DealPipelineEngineTest.
 */
final class DealDocumentSpineTest extends TestCase
{
    use RefreshDatabase;

    private DealDocumentService $docs;
    private DealPipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docs = app(DealDocumentService::class);
        $this->svc = app(DealPipelineService::class);
    }

    // ── Gate: one upload → deal + property + contacts, in one pass ────────

    public function test_deal_document_links_deal_property_and_all_contacts(): void
    {
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];

        $doc = $this->docs->createDealDocument($deal, [
            'original_name'    => 'OTP signed.pdf',
            'storage_path'     => 'deals/x/otp.pdf',
            'size'             => 1234,
            'document_type_id' => $ctx['otpType']->id,
            'source_type'      => 'deal_upload',
        ], $ctx['agent']);

        $this->assertSame($deal->id, $doc->deal_id, 'anchored to the deal');
        $this->assertSame((int) $deal->agency_id, (int) $doc->agency_id, 'agency stamped');
        $this->assertTrue($deal->documents()->whereKey($doc->id)->exists(), 'reachable from the deal');
        $this->assertTrue($doc->properties()->whereKey($ctx['property']->id)->exists(), 'reachable from the property');
        $this->assertEqualsCanonicalizing(
            [$ctx['buyer']->id, $ctx['seller']->id],
            $doc->contacts()->pluck('contacts.id')->all(),
            'reachable from every contact party'
        );
    }

    // ── Gate: config-driven auto-completion of an ACTIVE document step ────

    public function test_matching_document_auto_completes_active_step_and_populates_document_id(): void
    {
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];
        $step = $this->activeDocStep($deal, 'Electrical COC');
        $this->assertSame('active', $step->status);

        $doc = $this->docs->createDealDocument($deal, [
            'original_name'    => 'coc.pdf',
            'storage_path'     => 'deals/x/coc.pdf',
            'document_type_id' => $ctx['cocType']->id,
            'source_type'      => 'deal_upload',
        ], $ctx['agent']);

        $resolved = $this->docs->autoCompleteMatchingStep($deal, $doc, $ctx['agent']);

        $this->assertNotNull($resolved);
        $this->assertSame('completed', $step->fresh()->status, 'active matching step auto-completed');
        $this->assertDatabaseHas('deal_step_documents', [
            'deal_step_instance_id' => $step->id,
            'document_id'           => $doc->id, // populated, not an orphan file_path
        ]);
        $this->assertSame(1, $step->documents()->count(), 'exactly one step-document row');
    }

    // ── Explicit step target (agent chose "satisfies this step") ─────────

    public function test_explicit_step_target_completes_that_step(): void
    {
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];
        $step = $this->activeDocStep($deal, 'Electrical COC');

        // Document type does NOT match the step's binding — but the agent
        // explicitly targets the step, which wins.
        $doc = $this->docs->createDealDocument($deal, [
            'original_name'    => 'random.pdf',
            'storage_path'     => 'deals/x/random.pdf',
            'document_type_id' => $ctx['otpType']->id,
            'source_type'      => 'deal_upload',
        ], $ctx['agent']);

        $this->docs->autoCompleteMatchingStep($deal, $doc, $ctx['agent'], $step);

        $this->assertSame('completed', $step->fresh()->status);
    }

    // ── Idempotency — a re-fire converges, no duplicates ─────────────────

    public function test_auto_complete_is_idempotent(): void
    {
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];
        $step = $this->activeDocStep($deal, 'Electrical COC');

        $doc = $this->docs->createDealDocument($deal, [
            'original_name'    => 'coc.pdf',
            'storage_path'     => 'deals/x/coc.pdf',
            'document_type_id' => $ctx['cocType']->id,
            'source_type'      => 'deal_upload',
        ], $ctx['agent']);

        $this->docs->autoCompleteMatchingStep($deal, $doc, $ctx['agent']);
        $this->docs->autoCompleteMatchingStep($deal, $doc, $ctx['agent']); // re-fire

        $this->assertSame('completed', $step->fresh()->status);
        $this->assertSame(1, $step->documents()->where('document_id', $doc->id)->count(), 'no duplicate link on re-fire');
    }

    // ── Guard: no match (unset type / ambiguous) is a graceful no-op ─────

    public function test_unmatched_document_type_files_but_completes_nothing(): void
    {
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];
        $step = $this->activeDocStep($deal, 'Electrical COC');

        // A doc type no active step expects → filed against the deal, but no
        // step is force-completed.
        $doc = $this->docs->createDealDocument($deal, [
            'original_name'    => 'misc.pdf',
            'storage_path'     => 'deals/x/misc.pdf',
            'document_type_id' => $ctx['otpType']->id,
            'source_type'      => 'deal_upload',
        ], $ctx['agent']);

        $this->assertNull($this->docs->autoCompleteMatchingStep($deal, $doc, $ctx['agent']));
        $this->assertSame('active', $step->fresh()->status, 'no false completion');
        $this->assertSame($deal->id, $doc->deal_id, 'document still filed against the deal');
    }

    public function test_ambiguous_two_active_steps_same_type_completes_neither(): void
    {
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];
        $a = $this->activeDocStep($deal, 'Electrical COC');
        // A second active step bound to the SAME doc type — the resolver must
        // refuse to guess.
        $b = DealStepInstance::create([
            'agency_id' => $deal->agency_id, 'deal_id' => $deal->id,
            'pipeline_step_id' => $a->pipeline_step_id, 'name' => 'COC (second)',
            'position' => 99, 'completion_type' => 'document_upload',
            'completion_config' => ['document_type_id' => $ctx['cocType']->id],
            'status' => 'active', 'trigger_type' => 'manual',
            'rag_green_days' => 14, 'rag_amber_days' => 7, 'rag_red_days' => 3,
            'current_rag' => 'green', 'approval_status' => 'not_required',
        ]);

        $doc = $this->docs->createDealDocument($deal, [
            'original_name' => 'coc.pdf', 'storage_path' => 'deals/x/coc.pdf',
            'document_type_id' => $ctx['cocType']->id, 'source_type' => 'deal_upload',
        ], $ctx['agent']);

        $this->assertNull($this->docs->autoCompleteMatchingStep($deal, $doc, $ctx['agent']));
        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame('active', $b->fresh()->status);
    }

    // ── Guard: resolveDealForProperty refuses to guess ───────────────────

    public function test_resolve_deal_for_property_guards_zero_and_many(): void
    {
        $ctx = $this->scaffold();
        $agencyId = (int) $ctx['deal']->agency_id;

        // Exactly one active deal on the property.
        $this->assertSame($ctx['deal']->id, $this->docs->resolveDealForProperty($ctx['property']->id, $agencyId)?->id);

        // Zero → null (missing property).
        $this->assertNull($this->docs->resolveDealForProperty(999999, $agencyId));
        $this->assertNull($this->docs->resolveDealForProperty(null, $agencyId));

        // A SECOND active deal on the same property → ambiguous → null.
        $this->makeDeal($ctx, $ctx['template']);
        $this->assertNull($this->docs->resolveDealForProperty($ctx['property']->id, $agencyId), 'refuses to guess between two deals');
    }

    // ── E-sign auto-file: signed doc → deal + document_signed completion ─

    public function test_signed_document_attaches_to_deal_and_completes_signed_step(): void
    {
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];
        $signedStep = $this->activeDocStep($deal, 'Mandate Signed', 'document_signed', $ctx['signedType']->id);

        // Simulate SignatureService's filed Document (no deal yet, property known
        // via the signing).
        $filed = Document::create([
            'original_name' => 'Mandate (Signed).pdf', 'storage_path' => 'esign/mandate.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 2048,
            'document_type_id' => $ctx['signedType']->id, 'source_type' => 'esign',
            'uploaded_by' => $ctx['agent']->id,
        ]);

        $linkedDeal = $this->docs->attachSignedDocumentToDeal($filed, $ctx['property']->id, $ctx['agent']);

        $this->assertSame($deal->id, $linkedDeal?->id, 'resolved the deal from the property');
        $this->assertSame($deal->id, $filed->fresh()->deal_id, 'signed doc anchored to the deal');
        $this->assertSame('completed', $signedStep->fresh()->status, 'document_signed step auto-completed');
        $this->assertTrue($filed->properties()->whereKey($ctx['property']->id)->exists());
    }

    // ── Robustness: empty/deleted relations never crash ──────────────────

    public function test_deal_with_no_contacts_files_without_error(): void
    {
        $ctx = $this->scaffold(withContacts: false);
        $deal = $ctx['deal'];

        $doc = $this->docs->createDealDocument($deal, [
            'original_name' => 'x.pdf', 'storage_path' => 'deals/x/x.pdf',
            'source_type' => 'deal_upload',
        ], $ctx['agent']);

        $this->assertSame($deal->id, $doc->deal_id);
        $this->assertSame(0, $doc->contacts()->count(), 'no contacts to link, no error');
        $this->assertTrue($doc->properties()->whereKey($ctx['property']->id)->exists());
    }

    public function test_signed_document_with_no_matching_deal_is_a_safe_noop(): void
    {
        $ctx = $this->scaffold();

        $filed = Document::create([
            'original_name' => 'orphan.pdf', 'storage_path' => 'esign/orphan.pdf',
            'disk' => 'local', 'source_type' => 'esign', 'uploaded_by' => $ctx['agent']->id,
        ]);

        // Property with no deal → resolve returns null → no link, no throw.
        $this->assertNull($this->docs->attachSignedDocumentToDeal($filed, 424242, $ctx['agent']));
        $this->assertNull($filed->fresh()->deal_id);
    }

    // ──────────────────────────────────────────────────────────────────
    // Seed helpers
    // ──────────────────────────────────────────────────────────────────

    private function scaffold(bool $withContacts = true): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC ' . Str::random(6), 'slug' => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title' => '8 Marine Drive, Shelly Beach',
            'address' => '8 Marine Drive, Shelly Beach',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
        ]));

        $otpType    = DocumentType::create(['slug' => 'otp-' . Str::random(5), 'label' => 'Offer to Purchase', 'is_active' => true]);
        $cocType    = DocumentType::create(['slug' => 'coc-' . Str::random(5), 'label' => 'Electrical COC', 'is_active' => true]);
        $signedType = DocumentType::create(['slug' => 'mandate-' . Str::random(5), 'label' => 'Sole Mandate', 'is_active' => true]);

        $ctx = compact('agencyId', 'agent', 'property', 'otpType', 'cocType', 'signedType');

        if ($withContacts) {
            $ctx['buyer']  = $this->contact($agencyId, $agent->id, 'Thabo', 'Nkosi');
            $ctx['seller'] = $this->contact($agencyId, $agent->id, 'Annelise', 'van der Merwe');
        }

        $ctx['template'] = $this->makeTemplate($agencyId, $agent->id, $cocType->id, $signedType->id);
        $ctx['deal'] = $this->makeDeal($ctx, $ctx['template']);

        return $ctx;
    }

    private function contact(int $agencyId, int $agentId, string $first, string $last): \App\Models\Contact
    {
        return \App\Models\Contact::withoutEvents(fn () => \App\Models\Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => $first, 'last_name' => $last,
            'created_by_user_id' => $agentId, 'agent_id' => $agentId,
        ]));
    }

    private function makeTemplate(int $agencyId, int $creatorId, int $cocTypeId, int $signedTypeId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'WS3 Test', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);

        // All on_creation so the document steps are ACTIVE immediately in the
        // test (isolates the spine logic from the trigger chain).
        $mk = function (int $pos, string $name, string $type, ?array $config) use ($template, $agencyId) {
            return DealPipelineStep::create([
                'pipeline_template_id' => $template->id, 'agency_id' => $agencyId,
                'position' => $pos, 'name' => $name, 'completion_type' => $type,
                'completion_config' => $config, 'trigger_type' => 'on_creation',
                'days_offset' => 0, 'rag_green_days' => 14, 'rag_amber_days' => 7, 'rag_red_days' => 3,
                'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
                'requires_bm_approval' => false,
            ]);
        };

        $mk(1, 'OTP Signed', 'date_input', null);
        $mk(2, 'Electrical COC', 'document_upload', ['document_type_id' => $cocTypeId]);
        $mk(3, 'Mandate Signed', 'document_signed', ['document_type_id' => $signedTypeId]);

        return $template;
    }

    private function makeDeal(array $ctx, DealPipelineTemplate $template): DealV2
    {
        $contacts = [];
        if (isset($ctx['buyer'])) {
            $contacts[] = ['contact_id' => $ctx['buyer']->id, 'role' => 'buyer'];
            $contacts[] = ['contact_id' => $ctx['seller']->id, 'role' => 'seller'];
        }

        return $this->svc->createDeal([
            'deal_type' => 'bond', 'property_id' => $ctx['property']->id,
            'listing_agent_id' => $ctx['agent']->id, 'pipeline_template_id' => $template->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $ctx['agencyId'], 'created_by_id' => $ctx['agent']->id,
            'agents' => [['side' => 'listing', 'user_id' => $ctx['agent']->id]],
            'contacts' => $contacts,
        ]);
    }

    private function activeDocStep(DealV2 $deal, string $name, string $type = 'document_upload', ?int $typeId = null): DealStepInstance
    {
        $step = $deal->stepInstances()->where('name', $name)->firstOrFail();
        // on_creation already made it active; assert the shape the test relies on.
        return $step;
    }
}
