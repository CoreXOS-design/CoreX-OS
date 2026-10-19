<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalInspections\Concerns\BuildsSigningFixture;
use Tests\TestCase;

/**
 * AT-463 / .ai/specs/rental-inspections.md §61 - on-screen signing in the inspection's Signing block:
 *  - the AGENT's "Sign now": the logged-in agent draws their signature on screen; it is recorded with their identity, the
 *    time, and the device (IP + user agent), through the existing signature storage - the agent still signs last;
 *  - a PARTY who is with the agent signs on the agent's device ("Sign now on this device"): recorded as signed on the
 *    agent's device, in that agent's presence;
 *  - the PIN signature is untouched.
 * (The pad itself - one shared component - is exercised by tests/js/inspection-sign-now.mjs.)
 */
final class RentalInspectionSignNowTest extends TestCase
{
    use BuildsSigningFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    /** Everyone except the agent has an outcome: tenant by link, tenant 2 on the agent's device, landlord in CoreX. */
    private function everyoneButTheAgentSigns(RentalInspection $inspection): void
    {
        $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini');
        $link2 = $this->issueLink($inspection, 'tenant', $this->tenant2->id);
        $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link2]), $this->signPayload(['typed_name' => 'Sipho Khumalo']))->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG,
        ])->assertStatus(201);
    }

    private function agentSignature(RentalInspection $inspection): ?RentalInspectionSignature
    {
        return RentalInspectionSignature::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)
            ->where('party_role', 'agent')->whereNull('superseded_at')->first();
    }

    // ── The agent's "Sign now" ───────────────────────────────────────────────────────────────────────────────

    public function test_agent_sign_now_records_the_drawn_signature_with_identity_time_and_device(): void
    {
        $inspection = $this->ready();
        $this->everyoneButTheAgentSigns($inspection);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
                'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG, 'signing_method' => 'drawn',
            ], ['User-Agent' => 'AgentTablet/2.0'])
            ->assertStatus(201);

        $sig = $this->agentSignature($inspection);
        $this->assertNotNull($sig, 'the agent signature was not stored');
        $this->assertSame('signed', $sig->disposition);
        $this->assertSame(RentalInspectionSignature::SIGNED_VIA_AGENT_SCREEN, $sig->signed_via);
        $this->assertSame($this->admin->id, $sig->recorded_by_user_id, 'identity: the logged-in agent');
        $this->assertSame($this->admin->id, $sig->signed_on_device_by_user_id);
        $this->assertSame('Aileen Agent', $sig->signed_typed_name);
        $this->assertNotNull($sig->disposition_recorded_at, 'the time it was signed');
        $this->assertSame('198.51.100.9', $sig->signed_ip);
        $this->assertSame('AgentTablet/2.0', $sig->signed_user_agent);
        $this->assertSame($inspection->fresh()->reportFingerprint(), $sig->signed_report_fingerprint, 'the report content it was signed against');
        $this->assertTrue(Storage::disk('local')->exists(substr((string) $sig->party_signature_path, strlen(RentalInspectionSignature::PRIVATE_PREFIX))), 'the drawn image was not stored in the existing signature storage');
        $this->assertSame('on screen by the agent (Aileen Agent)', $sig->signedViaPhrase());

        // ...and the Signing panel says so beside the agent's row.
        $agentRow = collect($this->getJson(route('corex.rental-inspections.signing-links.index', $inspection))->assertOk()->json('rows'))->firstWhere('is_agent', true);
        $this->assertSame('Signed', $agentRow['recorded']['label']);
        $this->assertSame('on screen by the agent (Aileen Agent)', $agentRow['recorded']['via']);
    }

    public function test_the_agent_still_signs_last_nothing_is_stored_while_someone_is_outstanding(): void
    {
        $inspection = $this->ready();
        $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini'); // tenant 2 and the landlord are still outstanding

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG, 'signing_method' => 'drawn',
        ])->assertStatus(422)->assertJsonFragment(['message' => "Cannot record the agent's signature until every tenant and the landlord has a disposition recorded — the agent's signature attests to the complete record, not a partial one."]);

        $this->assertNull($this->agentSignature($inspection));
        $this->assertSame([], Storage::disk('local')->allFiles("rental-inspections/signatures/agent"), 'a refused signature left its image behind');
    }

    public function test_the_pin_signature_is_unchanged_and_carries_no_screen_evidence(): void
    {
        $inspection = $this->ready();
        $this->everyoneButTheAgentSigns($inspection);

        // audit B23: the saved-signature route needs the PIN unlock for THIS inspection and the user's own saved image
        $inspection->update(['inspector_user_id' => $this->admin->id]);
        app(\App\Services\AgentSignatureService::class)->save($this->admin, self::PNG, self::PNG, '4321');
        $this->withSession(['sig_unlock.' . $this->admin->id . '.' . sha1('rental-inspection:' . $inspection->id) => now()->timestamp]);
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG,
        ])->assertStatus(201);

        $sig = $this->agentSignature($inspection);
        $this->assertNull($sig->signed_via, 'the saved-PIN signature must record exactly what it always did');
        $this->assertNull($sig->signed_ip);
        $this->assertSame($this->admin->id, $sig->recorded_by_user_id);
    }

    public function test_only_a_drawn_signing_method_is_accepted_and_it_cannot_dress_a_refusal_up_as_a_signature(): void
    {
        $inspection = $this->ready();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG, 'signing_method' => 'telepathy',
        ])->assertStatus(422);

        // a party's refusal carries no screen evidence even if the field is sent
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'tenant', 'disposition' => 'refused', 'party_contact_id' => $this->tenant->id, 'refusal_reason_preset' => 'other',
            'refusal_reason_note' => 'Disputes the damp', 'signing_method' => 'drawn',
        ])->assertStatus(201);
        $refusal = RentalInspectionSignature::withoutGlobalScopes()->where('party_role', 'tenant')->firstOrFail();
        $this->assertNull($refusal->signed_via);
    }

    // ── A party who is with the agent: "Sign now on this device" ─────────────────────────────────────────────

    public function test_a_party_signing_on_the_agents_device_is_recorded_in_the_agents_presence(): void
    {
        $inspection = $this->ready();
        $link = $this->issueLink($inspection, 'tenant', $this->tenant->id);

        $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link]), $this->signPayload(['typed_name' => 'Naledi Dlamini']))->assertStatus(201);

        $sig = RentalInspectionSignature::withoutGlobalScopes()->where('party_role', 'tenant')->firstOrFail();
        $this->assertSame(RentalInspectionSignature::SIGNED_VIA_AGENT_DEVICE, $sig->signed_via);
        $this->assertSame($this->admin->id, $sig->signed_on_device_by_user_id, 'which agent was present');
        $this->assertSame('Naledi Dlamini', $sig->signed_typed_name, 'who signed');
        $this->assertSame("on the agent's device, in Aileen Agent's presence", $sig->signedViaPhrase());

        $row = collect($this->getJson(route('corex.rental-inspections.signing-links.index', $inspection))->json('rows'))->firstWhere('contact_id', $this->tenant->id);
        $this->assertSame("on the agent's device, in Aileen Agent's presence", $row['recorded']['via']);

        $log = RentalInspectionAuditLog::where('event', 'signed_by_link')->firstOrFail();
        $this->assertStringContainsString("on the agent's device, in Aileen Agent's presence", (string) $log->summary);
        $this->assertStringContainsString('"Naledi Dlamini"', (string) $log->summary);
    }

    public function test_a_party_cannot_be_signed_for_twice_on_the_device(): void
    {
        $inspection = $this->ready();
        $link = $this->issueLink($inspection, 'tenant', $this->tenant->id);
        $url = route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link]);

        $this->postJson($url, $this->signPayload())->assertStatus(201);
        $this->postJson($url, $this->signPayload())->assertStatus(409);
        $this->assertSame(1, RentalInspectionSignature::withoutGlobalScopes()->where('party_role', 'tenant')->count());
    }

    // ── The pad and the overlay behaviour (JS) ─────────────────────────────────────────────────────────────

    public function test_the_shared_pad_and_the_sign_now_overlay_behave(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $out = [];
        exec(escapeshellarg($node) . ' ' . escapeshellarg(base_path('tests/js/inspection-sign-now.mjs')) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringNotContainsString('FAIL', implode("\n", $out));
    }

    public function test_the_signing_page_and_the_agent_screens_use_the_one_pad(): void
    {
        $page = (string) file_get_contents(resource_path('views/rental-inspections/public/partials/sign-section.blade.php'));
        $panel = (string) file_get_contents(resource_path('js/rental-inspection-signing.js'));
        $mixin = (string) file_get_contents(resource_path('js/corex-signature-pad-mixin.js'));

        $this->assertStringContainsString('window.corexSignaturePadMixin()', $page);
        $this->assertStringContainsString('window.corexAttachSignaturePad(', $panel);
        $this->assertSame(1, substr_count($mixin . $page . $panel, 'new window.SignaturePad('), 'there must be exactly one place a pad is constructed');
    }

    // ── The screen ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_signing_block_carries_the_sign_now_buttons_and_the_overlay(): void
    {
        $inspection = $this->ready();

        foreach ([
            route('corex.rental-inspections.show', $inspection),
            route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'inspections']),
        ] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-qa="signing-row-sign-now"', false)
                ->assertSee('Sign now on this device')
                ->assertSee('>Sign now<', false)
                ->assertSee('data-qa="sign-now-overlay"', false)
                ->assertSee('data-qa="sign-now-pad"', false)
                ->assertSee('Read report first, then sign')
                ->assertSee('Email everyone their link'); // the fallbacks are still there
        }
    }
}
