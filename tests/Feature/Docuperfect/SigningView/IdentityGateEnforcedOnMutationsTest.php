<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Mail\Signatures\SigningRequestMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Signature;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prod-promotion audit 2026-09-16.
 *
 * HIGH — show() gated a recipient with an ID/passport on file behind
 * identity verification + e-sign consent, but the token-only mutation
 * endpoints the signing page drives by fetch() (chooseMethod, saveFields,
 * saveWebFields, completeWeb, complete, decline) never asked. Anyone holding
 * a forwarded link could finish — or decline — a signer's turn without ever
 * typing the ID number. Every one of those six now answers 403
 * `identity_verification_required` with nothing written, and passes once the
 * SAME two session keys show() relies on are present.
 *
 * MEDIUM — the agent-side invitation resend mailed a dead link on an
 * expired / cancelled / declined request and re-stamped sent_at as if it were
 * a healthy fresh send. It now refuses before any send or stamp.
 */
final class IdentityGateEnforcedOnMutationsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;

    /** route name => POST payload — the six token-only mutation endpoints. */
    private const MUTATIONS = [
        'signatures.external.chooseMethod'  => ['method' => 'wet_ink'],
        'signatures.external.saveFields'    => ['fields' => []],
        'signatures.external.saveWebFields' => ['fields' => []],
        'signatures.external.completeWeb'   => ['consented' => true],
        'signatures.external.complete'      => [],
        'signatures.external.decline'       => ['reason' => 'Changed my mind'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    private function request(
        ?string $idNumber = '8501015800083',
        ?string $passportNumber = null,
        string $templateStatus = SignatureTemplate::STATUS_SIGNING,
        array $overrides = [],
    ): SignatureRequest {
        $doc = Document::create(['name' => 'Offer to Purchase — Jan de Vries', 'owner_id' => $this->agent->id, 'agency_id' => $this->agency->id]);
        // agency_id is required here: SignatureTemplate is BelongsToAgency, so once
        // an agent is authenticated (the resend tests) an unstamped template is
        // hidden by AgencyScope and the controller would see template === null.
        $t = SignatureTemplate::create([
            'document_id'   => $doc->id,
            'agency_id'     => $this->agency->id,
            'document_hash' => Str::random(64),
            'status'        => $templateStatus,
            'created_by'    => $this->agent->id,
        ]);

        return SignatureRequest::create(array_merge([
            'signature_template_id'  => $t->id,
            'party_role'             => 'buyer',
            'role_index'             => 1,
            'signing_order'          => 1,
            'signer_name'            => 'Jan de Vries',
            'signer_email'           => 'jan@example.co.za',
            'signer_id_number'       => $idNumber,
            'signer_passport_number' => $passportNumber,
            'token'                  => Str::random(48),
            'token_expires_at'       => now()->addDays(14),
            'status'                 => SignatureRequest::STATUS_PENDING,
        ], $overrides));
    }

    /** The exact two keys show() requires before it will render the document. */
    private function passedGate(SignatureRequest $req): array
    {
        return [
            "signing_verified_{$req->token}" => true,
            "esign_consent_{$req->id}"       => true,
        ];
    }

    private function assertUntouched(SignatureRequest $req, string $endpoint): void
    {
        $fresh = $req->fresh();
        $this->assertSame(SignatureRequest::STATUS_PENDING, $fresh->status, "$endpoint must not change the request status");
        $this->assertNull($fresh->completed_at, "$endpoint must not complete the request");
        $this->assertNull($fresh->signing_method, "$endpoint must not record a signing method");
        $this->assertNull($fresh->wet_ink_status, "$endpoint must not start a wet-ink flow");
        $this->assertSame(SignatureTemplate::STATUS_SIGNING, $fresh->template->fresh()->status, "$endpoint must not change the ceremony status");
        $this->assertSame(0, Signature::where('signature_request_id', $req->id)->count(), "$endpoint must not store a signature");
        $this->assertSame(0, SignatureAuditLog::where('signature_request_id', $req->id)->count(), "$endpoint must not write an audit row");
    }

    // ── (a) every mutation endpoint refuses an unverified session ────────

    public function test_every_mutation_endpoint_is_refused_with_403_json_when_identity_is_unverified(): void
    {
        foreach (self::MUTATIONS as $routeName => $payload) {
            $req = $this->request(); // fresh request per endpoint — no cross-contamination

            $response = $this->postJson(route($routeName, $req->token), $payload);

            $response->assertStatus(403);
            $response->assertJson(['ok' => false, 'error' => 'identity_verification_required']);
            $response->assertJsonPath('redirect', route('signatures.external.gateway', ['token' => $req->token]));

            $this->assertUntouched($req, $routeName);
        }
    }

    public function test_verified_but_unconsented_session_is_refused_and_pointed_at_the_consent_page(): void
    {
        foreach (self::MUTATIONS as $routeName => $payload) {
            $req = $this->request();

            $response = $this->withSession(["signing_verified_{$req->token}" => true])
                ->postJson(route($routeName, $req->token), $payload);

            $response->assertStatus(403);
            $response->assertJson(['ok' => false, 'error' => 'identity_verification_required']);
            $response->assertJsonPath('redirect', route('signatures.external.showConsent', ['token' => $req->token]));

            $this->assertUntouched($req, $routeName);
        }
    }

    public function test_a_passport_only_signer_is_gated_on_the_mutation_endpoints_too(): void
    {
        $req = $this->request(idNumber: null, passportNumber: 'P1234567');

        $response = $this->postJson(route('signatures.external.decline', $req->token), ['reason' => 'x']);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'identity_verification_required']);
        $this->assertUntouched($req, 'decline (passport-only)');
    }

    /**
     * Ordering guard — a dead link still says "expired" (410) before the
     * identity gate is consulted, exactly as show() checks isSigningBlocked()
     * before its own gateway redirect.
     */
    public function test_a_blocked_link_still_answers_410_ahead_of_the_identity_gate(): void
    {
        $req = $this->request(overrides: ['token_expires_at' => now()->subDay()]);

        $response = $this->postJson(route('signatures.external.completeWeb', $req->token), ['consented' => true]);

        $response->assertStatus(410);
    }

    // ── (b) the same session keys show() relies on open the gate ─────────

    public function test_choose_method_succeeds_once_verified_and_consented(): void
    {
        $req = $this->request();

        $response = $this->withSession($this->passedGate($req))
            ->postJson(route('signatures.external.chooseMethod', $req->token), ['method' => 'wet_ink']);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'method' => 'wet_ink']);
        $fresh = $req->fresh();
        $this->assertSame('wet_ink', $fresh->signing_method);
        $this->assertSame(SignatureRequest::WET_INK_PENDING_UPLOAD, $fresh->wet_ink_status);
    }

    public function test_decline_succeeds_once_verified_and_consented(): void
    {
        $req = $this->request();

        $response = $this->withSession($this->passedGate($req))
            ->postJson(route('signatures.external.decline', $req->token), ['reason' => 'Changed my mind']);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'declined' => true]);
        $this->assertSame(SignatureRequest::STATUS_DECLINED, $req->fresh()->status);
    }

    /**
     * Regression guard — the gate's semantics are unchanged for a request
     * with neither an ID nor a passport on file: show() never gated those
     * and the mutation endpoints must not start to.
     */
    public function test_a_request_with_no_id_and_no_passport_is_not_gated(): void
    {
        $req = $this->request(idNumber: null, passportNumber: null);

        $response = $this->postJson(route('signatures.external.chooseMethod', $req->token), ['method' => 'electronic']);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $this->assertSame('electronic', $req->fresh()->signing_method);
    }

    // ── (c) agent-side resend refuses a dead link, stamps nothing ─────────

    public function test_resend_invitation_on_an_expired_link_is_refused_and_sent_at_is_untouched(): void
    {
        Mail::fake();
        $stamp = now()->subDays(3)->startOfSecond();
        $req = $this->request(overrides: [
            'token_expires_at'   => now()->subDay(),
            'sent_at'            => $stamp,
            'invite_send_status' => 'sent',
        ]);
        $document = $req->template->document;

        $response = $this->actingAs($this->agent)
            ->from(route('docuperfect.esign.myDocuments'))
            ->post(route('docuperfect.signatures.resendEmail', ['document' => $document->id, 'signatureRequest' => $req->id]));

        $response->assertRedirect(route('docuperfect.esign.myDocuments'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('expired', (string) session('error'));

        Mail::assertNothingSent();
        $fresh = $req->fresh();
        $this->assertSame($stamp->toDateTimeString(), $fresh->sent_at?->toDateTimeString(), 'sent_at must not be re-stamped');
        $this->assertSame('sent', $fresh->invite_send_status, 'invite_send_status must not be re-written');
    }

    public function test_resend_invitation_on_a_cancelled_ceremony_is_refused(): void
    {
        Mail::fake();
        $req = $this->request(templateStatus: SignatureTemplate::STATUS_CANCELLED);
        $document = $req->template->document;

        $response = $this->actingAs($this->agent)
            ->post(route('docuperfect.signatures.resendEmail', ['document' => $document->id, 'signatureRequest' => $req->id]));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('cancelled', (string) session('error'));
        Mail::assertNothingSent();
        $this->assertNull($req->fresh()->sent_at);
    }

    public function test_resend_invitation_on_a_declined_request_is_refused(): void
    {
        Mail::fake();
        $req = $this->request(overrides: ['status' => SignatureRequest::STATUS_DECLINED]);
        $document = $req->template->document;

        $response = $this->actingAs($this->agent)
            ->post(route('docuperfect.signatures.resendEmail', ['document' => $document->id, 'signatureRequest' => $req->id]));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('declined', (string) session('error'));
        Mail::assertNothingSent();
        $this->assertNull($req->fresh()->sent_at);
    }

    /** The happy path must be exactly as before: a live link still resends. */
    public function test_resend_invitation_on_a_live_link_still_sends_and_stamps(): void
    {
        Mail::fake();
        $req = $this->request();
        $document = $req->template->document;

        $response = $this->actingAs($this->agent)
            ->post(route('docuperfect.signatures.resendEmail', ['document' => $document->id, 'signatureRequest' => $req->id]));

        $response->assertSessionHas('status');
        $response->assertSessionMissing('error');
        Mail::assertSent(SigningRequestMail::class);
        $fresh = $req->fresh();
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame('sent', $fresh->invite_send_status);
    }
}
