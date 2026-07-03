<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Mail\DealV2\DealDocumentDeliveryMail;
use App\Mail\DealV2\DealSecureLinkMail;
use App\Mail\OtpMail;
use App\Models\Communications\Communication;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\DealDocumentAccessLog;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealDistributionService;
use App\Services\DealV2\DealDocumentService;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WS4 (AT-158 / DR2, §8, §10) — the distribution matrix, both delivery modes,
 * auto-trigger on stage tick, the auto-generated COC, and the immutable
 * secure-link access log.
 *
 * Gate (§8.3): a configured rule fires on a stage tick → a distribution +
 * outbound Communication + generated COC Document exist; the secure link
 * demands an OTP before serving; every access is written to the immutable log.
 */
final class DealDistributionTest extends TestCase
{
    use RefreshDatabase;

    private DealDistributionService $dist;
    private DealPipelineService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dist = app(DealDistributionService::class);
        $this->engine = app(DealPipelineService::class);
    }

    // ── Gate: auto-distribute on stage tick generates the COC + secure link ─

    public function test_auto_distribute_on_tick_generates_coc_and_sends_secure_link(): void
    {
        Mail::fake();
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];

        // Rule: at the "OTP Signed" stage, the electrician (electrician_coc)
        // gets the COC request by secure link, automatically.
        $step = $deal->stepInstances()->where('name', 'OTP Signed')->firstOrFail();
        $this->rule($ctx, $step->pipeline_step_id, $ctx['cocType']->id, 'electrician_coc', 'secure_link', true);

        // Tick the stage.
        $this->engine->completeStep($step->fresh(), $ctx['agent'], ['outcome' => 'positive', 'value' => '2026-03-01']);

        // A COC document was generated + filed on the deal.
        $coc = Document::where('deal_id', $deal->id)->where('document_type_id', $ctx['cocType']->id)->first();
        $this->assertNotNull($coc, 'COC request generated + filed');
        $this->assertTrue(Storage::disk('local')->exists($coc->storage_path), 'COC PDF on disk');

        // A distribution row exists — secure link, tokened, sent, to the electrician.
        $d = DealDocumentDistribution::where('deal_id', $deal->id)->first();
        $this->assertNotNull($d);
        $this->assertSame('secure_link', $d->delivery_mode);
        $this->assertNotEmpty($d->secure_token);
        $this->assertSame('sent', $d->status);
        $this->assertSame($ctx['electrician']->id, $d->recipient_provider_id);

        // The outbound was archived on the deal + property pillars, owner stamped.
        $comm = Communication::find($d->communication_id);
        $this->assertNotNull($comm);
        $this->assertSame($ctx['agent']->id, $comm->owner_user_id);
        $morphs = $comm->links()->pluck('linkable_type')->all();
        $this->assertContains(DealV2::class, $morphs, 'deal pillar linked');
        $this->assertContains(Property::class, $morphs, 'property pillar linked');

        Mail::assertSent(DealSecureLinkMail::class);
    }

    // ── Secure link demands OTP before serving; every step logged ──────────

    public function test_secure_link_requires_otp_and_writes_immutable_log(): void
    {
        Mail::fake();
        $ctx = $this->scaffold();
        $deal = $ctx['deal'];
        $doc = $this->docOnDeal($ctx, $ctx['otpType']->id, 'OTP.pdf');

        $recipient = ['type' => 'contact', 'model' => $ctx['seller'], 'name' => $ctx['seller']->full_name, 'email' => $ctx['seller']->email];
        $d = $this->dist->send($deal, $doc, 'seller', 'secure_link', $recipient, $ctx['agent'], true);

        $token = $d->secure_token;

        // Open the link — logged, status → opened.
        $this->get(route('deals-v2.secure-doc.show', $token))->assertOk();
        $this->assertLog($d, DealDocumentAccessLog::EVENT_LINK_CLICKED);
        $this->assertSame('opened', $d->fresh()->status);

        // Download BEFORE verifying → refused (redirect, not the file).
        $this->get(route('deals-v2.secure-doc.download', $token))->assertRedirect();
        $this->assertNull($d->accessLog()->where('event', DealDocumentAccessLog::EVENT_DOWNLOADED)->first());

        // Request a PIN → logged + emailed; capture the code.
        $code = null;
        $this->post(route('deals-v2.secure-doc.otp', $token))->assertRedirect();
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;
            return true;
        });
        $this->assertLog($d, DealDocumentAccessLog::EVENT_OTP_SENT);
        $this->assertNotNull($code);

        // Wrong PIN → failure logged, still not verified.
        $this->post(route('deals-v2.secure-doc.verify', $token), ['code' => '000000']);
        $this->assertLog($d, DealDocumentAccessLog::EVENT_OTP_FAILED);

        // Correct PIN → verified.
        $this->post(route('deals-v2.secure-doc.verify', $token), ['code' => $code])->assertRedirect();
        $this->assertLog($d, DealDocumentAccessLog::EVENT_OTP_VERIFIED);

        // Now the document streams + is logged + status downloaded.
        $resp = $this->get(route('deals-v2.secure-doc.download', $token));
        $resp->assertOk();
        $this->assertLog($d, DealDocumentAccessLog::EVENT_DOWNLOADED);
        $this->assertSame('downloaded', $d->fresh()->status);
    }

    // ── The access log is append-only ──────────────────────────────────────

    public function test_access_log_is_immutable(): void
    {
        $ctx = $this->scaffold();
        $doc = $this->docOnDeal($ctx, $ctx['otpType']->id, 'x.pdf');
        $recipient = ['type' => 'contact', 'model' => $ctx['seller'], 'name' => 'x', 'email' => $ctx['seller']->email];
        Mail::fake();
        $d = $this->dist->send($ctx['deal'], $doc, 'seller', 'secure_link', $recipient, $ctx['agent'], true);

        $row = DealDocumentAccessLog::record($d, DealDocumentAccessLog::EVENT_LINK_CLICKED);

        try {
            $row->update(['event' => DealDocumentAccessLog::EVENT_DOWNLOADED]);
            $this->fail('access log must be immutable');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            $row->delete();
            $this->fail('access log must not be deletable');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    // ── Direct-attachment mode attaches the file + archives it ─────────────

    public function test_direct_attachment_mode_sends_attachment_and_archives_it(): void
    {
        Mail::fake();
        $ctx = $this->scaffold();
        $doc = $this->docOnDeal($ctx, $ctx['otpType']->id, 'pack.pdf');
        $recipient = ['type' => 'contact', 'model' => $ctx['seller'], 'name' => $ctx['seller']->full_name, 'email' => $ctx['seller']->email];

        $d = $this->dist->send($ctx['deal'], $doc, 'seller', 'direct_attachment', $recipient, $ctx['agent']);

        $this->assertSame('direct_attachment', $d->delivery_mode);
        $this->assertNull($d->secure_token);
        Mail::assertSent(DealDocumentDeliveryMail::class);

        $comm = Communication::find($d->communication_id);
        $this->assertTrue((bool) $comm->has_attachments, 'attachment flagged');
        $this->assertSame(1, $comm->attachments()->count(), 'attachment row written');
    }

    // ── Prevent-or-absorb: a rule for an absent party is skipped ────────────

    public function test_rule_for_absent_party_is_skipped_not_crashed(): void
    {
        $ctx = $this->scaffold();
        // A rule targeting a bond attorney — the deal has none.
        $this->rule($ctx, null, $ctx['otpType']->id, 'bond_attorney', 'secure_link', false);

        $plan = $this->dist->resolvePlan($ctx['deal'], $ctx['agent']);
        $row = collect($plan)->firstWhere('party_role', 'bond_attorney');
        $this->assertNotNull($row);
        $this->assertFalse($row['sendable']);
        $this->assertNotNull($row['skip_reason']);

        // Executing it creates nothing (no recipient) — no crash.
        $created = $this->dist->distributeRules($ctx['deal'], [$row['rule_id']], $ctx['agent']);
        $this->assertCount(0, $created);
    }

    // ── Revoke blocks the secure link ──────────────────────────────────────

    public function test_revoke_blocks_the_secure_link(): void
    {
        Mail::fake();
        $ctx = $this->scaffold();
        $doc = $this->docOnDeal($ctx, $ctx['otpType']->id, 'r.pdf');
        $recipient = ['type' => 'contact', 'model' => $ctx['seller'], 'name' => 'x', 'email' => $ctx['seller']->email];
        $d = $this->dist->send($ctx['deal'], $doc, 'seller', 'secure_link', $recipient, $ctx['agent'], true);

        $this->dist->revoke($d->fresh(), $ctx['agent']);
        $this->assertSame('revoked', $d->fresh()->status);
        $this->assertLog($d, DealDocumentAccessLog::EVENT_REVOKED);

        // A revoked link shows the friendly unavailable page (410).
        $this->get(route('deals-v2.secure-doc.show', $d->secure_token))->assertStatus(410);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function assertLog(DealDocumentDistribution $d, string $event): void
    {
        $this->assertTrue(
            $d->accessLog()->where('event', $event)->exists(),
            "expected access-log event: {$event}"
        );
    }

    private function docOnDeal(array $ctx, int $typeId, string $name): Document
    {
        Storage::disk('local')->put($p = "deals/{$ctx['deal']->id}/{$name}", '%PDF-1.4 test');
        return app(DealDocumentService::class)->createDealDocument($ctx['deal'], [
            'original_name'    => $name,
            'storage_path'     => $p,
            'disk'             => 'local',
            'mime_type'        => 'application/pdf',
            'size'             => 13,
            'document_type_id' => $typeId,
            'source_type'      => 'deal_upload',
        ], $ctx['agent']);
    }

    private function rule(array $ctx, ?int $stepId, int $typeId, string $role, string $mode, bool $auto): DealStageDocumentRule
    {
        return DealStageDocumentRule::create([
            'agency_id'          => $ctx['agencyId'],
            'pipeline_step_id'   => $stepId,
            'document_type_id'   => $typeId,
            'party_role'         => $role,
            'delivery_mode'      => $mode,
            'auto_on_stage_tick' => $auto,
            'is_active'          => true,
            'created_by_id'      => $ctx['agent']->id,
        ]);
    }

    private function scaffold(): array
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
            'external_id' => 'T-' . Str::random(8), 'title' => '8 Marine Drive, Shelly Beach',
            'address' => '8 Marine Drive, Shelly Beach', 'suburb' => 'Shelly Beach', 'erf_number' => '1234',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
        ]));

        $seller = \App\Models\Contact::withoutEvents(fn () => \App\Models\Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'Annelise', 'last_name' => 'van der Merwe',
            'email' => 'seller' . Str::random(4) . '@example.co.za',
            'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
        ]));

        $cocType = DocumentType::where('slug', 'coc_request')->first()
            ?? DocumentType::create(['slug' => 'coc_request', 'label' => 'COC Request', 'is_active' => true]);
        $otpType = DocumentType::create(['slug' => 'otp-' . Str::random(5), 'label' => 'Offer to Purchase', 'is_active' => true]);

        $template = $this->makeTemplate($agencyId, $agent->id);

        $deal = $this->engine->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id,
            'listing_agent_id' => $agent->id, 'pipeline_template_id' => $template->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $agencyId, 'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
            'contacts' => [['contact_id' => $seller->id, 'role' => 'seller']],
        ]);

        // The electrician we always use → attached to the deal as a provider party.
        $electrician = AgencyServiceProvider::create([
            'agency_id' => $agencyId, 'name' => 'Sparky Electrical', 'specialty' => 'electrician',
            'email' => 'sparky@example.co.za', 'is_active' => true, 'created_by_id' => $agent->id,
        ]);
        $deal->providerParties()->attach($electrician->id, ['role' => 'electrician_coc']);

        return compact('agencyId', 'agent', 'property', 'seller', 'electrician', 'cocType', 'otpType', 'template', 'deal');
    }

    private function makeTemplate(int $agencyId, int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'WS4 Test', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);

        DealPipelineStep::create([
            'pipeline_template_id' => $template->id, 'agency_id' => $agencyId,
            'position' => 1, 'name' => 'OTP Signed', 'completion_type' => 'date_input',
            'trigger_type' => 'on_creation', 'days_offset' => 0,
            'rag_green_days' => 14, 'rag_amber_days' => 7, 'rag_red_days' => 3,
            'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
            'requires_bm_approval' => false,
        ]);

        return $template;
    }
}
