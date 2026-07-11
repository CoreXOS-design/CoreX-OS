<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Agency;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WebPack;
use App\Models\Docuperfect\WebPackItem;
use App\Models\LegalBlockAuditLog;
use App\Models\User;
use App\Services\Docuperfect\EsignEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-1 — an Offer To Purchase inside a PACK must never reach the e-sign pipeline.
 *
 * The legal block (Template::isEsignBlocked() — Alienation of Land Act / ECTA
 * s13(1)) was only ever applied to the SINGLE-template path. Both pack paths
 * bypassed it, so a sale agreement riding inside a pack could be e-signed. Three
 * separate holes, all covered here:
 *
 *   1. Flow creation (store) never checked pack members at all.
 *   2. Dispatch (prepareSigning) checked `$flow->template` — the pack's FIRST
 *      document — so a blocked document in position 2+ was dispatched.
 *   3. The wizard auto-stamped is_esign=true on any template it carried, which
 *      laundered a wet-ink-only document into an "eligible" one and permanently
 *      poisoned the flag that pack eligibility reads.
 *
 * Plus: `resolved_template_ids` was unvalidated client input fed to a bare
 * Template::find(), so ANY template id — including a blocked one that is not a
 * member of the pack — could be injected into a pack flow.
 */
final class PackEsignLegalBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc']);
        $this->agent  = User::factory()->create([
            'agency_id' => $this->agency->id,
            'role'      => 'super_admin',
        ]);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** A legally-blocked document. Real HFC naming — this is the live OTP. */
    private function offerToPurchase(array $overrides = []): Template
    {
        $type = DocumentType::create(['label' => 'Offer to Purchase', 'slug' => 'otp']);

        return Template::create(array_merge([
            'name'             => 'Shelly HFC OTP (V13) - Enviro Clause',
            'document_type_id' => $type->id,
            'render_type'      => 'web',
            'blade_view'       => 'docuperfect.web-templates.cds.template-111',
            'is_esign'         => true, // deliberately TRUE — the weak flag must not save us
            'owner_id'         => $this->agent->id,
        ], $overrides));
    }

    /** An ordinary, lawfully e-signable document. */
    private function mandate(string $name = 'Shelly EATS (V10)'): Template
    {
        $type = DocumentType::firstOrCreate(['slug' => 'mandate'], ['label' => 'Mandate']);

        return Template::create([
            'name'             => $name,
            'document_type_id' => $type->id,
            'render_type'      => 'web',
            'blade_view'       => 'docuperfect.web-templates.cds.template-116',
            'is_esign'         => true,
            'owner_id'         => $this->agent->id,
        ]);
    }

    private function webPackOf(Template ...$templates): WebPack
    {
        $pack = WebPack::create([
            'name'       => 'Sales Mandate Pack',
            'agency_id'  => $this->agency->id,
            'created_by' => $this->agent->id,
        ]);

        foreach ($templates as $i => $template) {
            WebPackItem::create([
                'web_pack_id' => $pack->id,
                'template_id' => $template->id,
                'sort_order'  => $i,
            ]);
        }

        return $pack;
    }

    // ── the hole: a blocked document inside a web pack ──────────────────────

    public function test_web_pack_containing_an_otp_is_refused_at_flow_creation(): void
    {
        $pack = $this->webPackOf($this->mandate(), $this->offerToPurchase());

        $response = $this->actingAs($this->agent)->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow' => true,
            'pack_id'      => $pack->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['esign_blocked' => true]);
        $this->assertStringContainsString(
            'Shelly HFC OTP (V13) - Enviro Clause',
            $response->json('error'),
            'the refusal must NAME the offending document so the agent knows which one to pull out'
        );
    }

    /**
     * The precise shape of the bug: the OTP is NOT first. Dispatch used to test
     * only $flow->template (the pack's first document), so this walked through.
     */
    public function test_blocked_document_in_second_position_is_still_refused(): void
    {
        $pack = $this->webPackOf($this->mandate(), $this->mandate('FICA'), $this->offerToPurchase());

        $response = $this->actingAs($this->agent)->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow' => true,
            'pack_id'      => $pack->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['esign_blocked' => true]);
    }

    /** The block is legal, not cosmetic — every trigger is audited (ES-1). */
    public function test_the_refusal_is_written_to_the_legal_block_audit_log(): void
    {
        $pack = $this->webPackOf($this->mandate(), $this->offerToPurchase());

        $this->actingAs($this->agent)->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow' => true,
            'pack_id'      => $pack->id,
        ])->assertStatus(422);

        $this->assertTrue(
            LegalBlockAuditLog::query()->exists(),
            'every legal-block trigger writes an insert-only audit row'
        );
    }

    // ── the injection: resolved_template_ids was unvalidated ────────────────

    public function test_a_blocked_template_cannot_be_injected_via_resolved_template_ids(): void
    {
        $pack = $this->webPackOf($this->mandate());
        $otp  = $this->offerToPurchase(); // NOT a member of the pack

        $response = $this->actingAs($this->agent)->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow'          => true,
            'pack_id'               => $pack->id,
            'resolved_template_ids' => [$otp->id], // client-supplied, previously trusted
        ]);

        // The OTP is not a pack member, so it is discarded rather than merged.
        // The flow must NOT be created carrying it.
        $this->assertDatabaseMissing('flows', [
            'template_id' => $otp->id,
        ]);

        $this->assertNotEquals(
            200,
            $response->status(),
            'a non-member template id must never be silently merged into a pack flow'
        );
    }

    // ── the laundering: is_esign auto-flag ──────────────────────────────────

    public function test_a_blocked_template_is_never_auto_flagged_as_esign_capable(): void
    {
        $otp = $this->offerToPurchase(['is_esign' => false]);

        $eligibility = app(EsignEligibilityService::class);

        $this->assertFalse(
            $eligibility->mayAutoFlagEsign($otp),
            'the wizard must never launder a wet-ink-only document into an e-signable one'
        );

        $otp->refresh();
        $this->assertFalse((bool) $otp->is_esign, 'is_esign must remain false');
    }

    // ── the happy path: a clean pack still works (no false positives) ───────

    public function test_a_clean_pack_is_accepted(): void
    {
        $pack = $this->webPackOf($this->mandate(), $this->mandate('Seller Mandatory Disclosure (V7)'));

        $response = $this->actingAs($this->agent)->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow' => true,
            'pack_id'      => $pack->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('flows', ['type' => 'esign']);
    }

    // ── the empty path: nothing to sign is not a green light ────────────────

    public function test_an_empty_pack_is_not_eligible(): void
    {
        $eligibility = app(EsignEligibilityService::class);

        $this->assertFalse(
            $eligibility->isEligible(collect()),
            '"nothing to sign" must never present as a green light'
        );
    }

    // ── the predicate itself, across the block layers ───────────────────────

    public function test_blocked_by_name_pattern_even_without_a_blocking_document_type(): void
    {
        $type = DocumentType::firstOrCreate(['slug' => 'other'], ['label' => 'Other']);

        $sneaky = Template::create([
            'name'             => 'SB 2026 Agreement of Sale',
            'document_type_id' => $type->id, // type does NOT flag it
            'render_type'      => 'web',
            'is_esign'         => true,
            'owner_id'         => $this->agent->id,
        ]);

        $eligibility = app(EsignEligibilityService::class);

        $this->assertNotNull(
            $eligibility->blockReason(collect([$sneaky])),
            'the name-pattern layer must still catch a sale agreement wearing a benign type'
        );
    }

    public function test_an_ordinary_document_whose_name_merely_contains_a_substring_is_not_blocked(): void
    {
        $type = DocumentType::firstOrCreate(['slug' => 'other'], ['label' => 'Other']);

        $innocent = Template::create([
            'name'             => 'Photoshop Workflow Guide', // contains "otp" as a substring
            'document_type_id' => $type->id,
            'render_type'      => 'web',
            'is_esign'         => true,
            'owner_id'         => $this->agent->id,
        ]);

        $eligibility = app(EsignEligibilityService::class);

        $this->assertNull(
            $eligibility->blockReason(collect([$innocent])),
            'word-boundary matching must not refuse an innocent document (false positives cost trust)'
        );
    }
}
