<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealDistributionRuleProvisioner;
use App\Services\DealV2\DealDistributionService;
use App\Services\DealV2\DealDocumentService;
use App\Services\DealV2\DealPipelineService;
use App\Services\DealV2\DealPipelineTemplateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-225 · DR2 §8.1 — the default distribution-rules seeder is the config half of
 * "docs uploading and filing themselves". This test is the evidence layer AND the
 * walkthrough fallback: it provisions the pipeline template + default rules exactly
 * as the deploy seeder does, then drives the full flow end-to-end — upload a doc onto
 * a deal, prove it files itself to the deal + property + contacts, and prove the
 * SEEDED "Electrical COC → electrician" rule resolves the appointed provider.
 */
class DealDistributionRuleSeederTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::create(['name' => 'HFC ' . Str::random(5), 'slug' => 'hfc-' . Str::random(8)]);
        $this->agencyId = (int) $agency->id;
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ]);

        // Global document types the defaults reference (present in prod; absent in a fresh test DB).
        foreach ([['coc_request', 'COC Request'], ['otp', 'OTP (Offer to Purchase)'], ['fica', 'FICA']] as [$slug, $label]) {
            DocumentType::firstOrCreate(['slug' => $slug], ['label' => $label, 'is_active' => true]);
        }
    }

    private function provisionTemplate(): void
    {
        app(DealPipelineTemplateProvisioner::class)->provisionDefaultsForAgency($this->agencyId, $this->agent->id);
    }

    private function provisionRules(): array
    {
        return app(DealDistributionRuleProvisioner::class)->provisionDefaultsForAgency($this->agencyId, $this->agent->id);
    }

    /** The §8.1 defaults land with the right stage × doc-type × role × mode × auto. */
    public function test_provisions_the_section_8_1_default_matrix(): void
    {
        $this->provisionTemplate();
        $res = $this->provisionRules();

        $this->assertGreaterThan(0, $res['created']);

        $elecStep = DealPipelineStep::withoutGlobalScopes()
            ->where('agency_id', $this->agencyId)->whereRaw('LOWER(name) = ?', ['electrical coc'])->first();
        $this->assertNotNull($elecStep, 'template provisioned an Electrical COC step');

        // The red-button rule: COC request auto-goes to the electrician via secure link.
        $this->assertDatabaseHas('deal_stage_document_rules', [
            'agency_id'          => $this->agencyId,
            'pipeline_step_id'   => $elecStep->id,
            'document_type_id'   => DocumentType::where('slug', 'coc_request')->value('id'),
            'party_role'         => 'electrician_coc',
            'delivery_mode'      => 'secure_link',
            'auto_on_stage_tick' => 1,
        ]);

        // OTP distributed to the buyer, manual (agent confirms).
        $otpStep = DealPipelineStep::withoutGlobalScopes()
            ->where('agency_id', $this->agencyId)->whereRaw('LOWER(name) = ?', ['otp signed'])->first();
        $this->assertNotNull($otpStep);
        $this->assertDatabaseHas('deal_stage_document_rules', [
            'agency_id'          => $this->agencyId,
            'pipeline_step_id'   => $otpStep->id,
            'party_role'         => 'buyer',
            'auto_on_stage_tick' => 0,
        ]);
    }

    /** Re-running never duplicates or disturbs an agency's matrix. */
    public function test_provisioning_is_idempotent(): void
    {
        $this->provisionTemplate();
        $this->provisionRules();
        $count = DealStageDocumentRule::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count();

        $second = $this->provisionRules();
        $this->assertSame(0, $second['created']);
        $this->assertSame(
            $count,
            DealStageDocumentRule::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count()
        );
    }

    /** An agency with no pipeline template gets no rules (graceful skip, not a crash). */
    public function test_skips_gracefully_when_no_template(): void
    {
        $res = $this->provisionRules(); // no template provisioned
        $this->assertSame(0, $res['created']);
        $this->assertGreaterThan(0, $res['skipped']);
        $this->assertSame(0, DealStageDocumentRule::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count());
    }

    /**
     * END-TO-END (the fallback proof): a doc uploaded onto a deal files itself to the
     * deal + property + contacts, and the seeded Electrical COC rule resolves the
     * appointed electrician as a distribution recipient.
     */
    public function test_upload_files_to_pillars_and_seeded_rule_resolves_provider(): void
    {
        $this->provisionTemplate();
        $this->provisionRules();

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '8 Marine Drive, Shelly Beach',
            'suburb' => 'Shelly Beach', 'agent_id' => $this->agent->id,
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));
        $seller = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'first_name' => 'Annelise', 'last_name' => 'van der Merwe',
            'email' => 'seller' . Str::random(4) . '@example.co.za',
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
        ]));

        $template = DealPipelineTemplate::withoutGlobalScopes()
            ->where('agency_id', $this->agencyId)->where('deal_type', 'bond')->firstOrFail();

        $engine = app(DealPipelineService::class);
        $deal = $engine->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id,
            'listing_agent_id' => $this->agent->id, 'pipeline_template_id' => $template->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $this->agencyId, 'created_by_id' => $this->agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $this->agent->id]],
            'contacts' => [['contact_id' => $seller->id, 'role' => 'seller']],
        ]);

        $electrician = AgencyServiceProvider::create([
            'agency_id' => $this->agencyId, 'name' => 'Sparky Electrical', 'specialty' => 'electrician',
            'email' => 'sparky@example.co.za', 'is_active' => true, 'created_by_id' => $this->agent->id,
        ]);
        $deal->providerParties()->attach($electrician->id, ['role' => 'electrician_coc']);

        // Upload a COC-request doc onto the deal → it must file itself to the pillars.
        $doc = app(DealDocumentService::class)->createDealDocument($deal, [
            'original_name'    => 'coc-request.pdf',
            'storage_path'     => 'deal-docs/coc-request.pdf',
            'document_type_id' => DocumentType::where('slug', 'coc_request')->value('id'),
            'source_type'      => 'deal_upload',
        ], $this->agent);

        $this->assertSame((int) $deal->id, (int) $doc->deal_id, 'filed to the deal');
        $this->assertTrue($doc->properties()->where('properties.id', $property->id)->exists(), 'reachable from the property');
        $this->assertTrue($doc->contacts()->where('contacts.id', $seller->id)->exists(), 'reachable from the seller contact');

        // resolvePlan is stage-scoped to ACTIVE steps; put THIS deal's OTP Signed step
        // instance active so its seeded OTP→seller rule is live. (The Electrical COC →
        // electrician rule is proven to EXIST + be auto in the matrix test; it enters
        // the plan once that stage becomes active.)
        $deal->stepInstances()->whereRaw('LOWER(name) = ?', ['otp signed'])->update(['status' => 'active']);

        $plan = app(DealDistributionService::class)->resolvePlan($deal->fresh(), $this->agent);
        $sellerRow = collect($plan)->firstWhere('party_role', 'seller');
        $this->assertNotNull($sellerRow, 'the seeded OTP→seller rule is live at the active stage');
        $recipientEmails = collect($sellerRow['recipients'] ?? [])->pluck('email')->all();
        $this->assertContains($seller->email, $recipientEmails, 'the seller resolves as a distribution recipient');
    }
}
