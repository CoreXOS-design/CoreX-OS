<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-3 — the per-page initial consent gate must be REAL, not UI-deep.
 *
 * An initial is legally meaningful because it is an individual affirmation of THAT page
 * (informed consent). That rule was enforced only by hiding the "Apply to All" button in
 * Alpine: `isAgent` withheld the affordance, but completeWeb() had no server-side
 * rejection, so a crafted client on a recipient token could still post every initial in
 * one go and the server would file them all.
 *
 * ApplyToAllConsentGateTest (the sibling) asserts the BUTTON IS ABSENT FROM THE HTML.
 * That is not the same thing as refusing the write — it is exactly the gap this closes.
 * These tests assert the REJECTION.
 *
 * The agent's apply-all is preserved on purpose: professional profile, adopt-once /
 * apply-all (ceremony §2). That is doctrine, not a bug.
 */
final class InitialConsentServerGateTest extends TestCase
{
    use RefreshDatabase;

    /** THE ATTACK: a recipient token posts N initials it never individually consented to. */
    public function test_bulk_initial_write_on_a_recipient_token_is_refused(): void
    {
        [$seller] = $this->seedSession();
        $this->verifySession($seller->token);

        $response = $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented' => true,
            'signatures' => [
                'seller-init-0' => $this->initialImage(),
                'seller-init-1' => $this->initialImage(),
                'seller-init-2' => $this->initialImage(),
                'seller-init-3' => $this->initialImage(),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $this->assertStringContainsString(
            'Each page has to be initialled on its own',
            (string) $response->json('error'),
            'The refusal must tell the signer plainly what to do — no error codes at agents.'
        );
        $this->assertEqualsCanonicalizing(
            ['seller-init-0', 'seller-init-1', 'seller-init-2', 'seller-init-3'],
            $response->json('unconsented_surfaces'),
        );
    }

    /** The refusal is evidence: it lands in the append-only audit trail. */
    public function test_a_refused_bulk_write_is_recorded_in_the_audit_trail(): void
    {
        [$seller] = $this->seedSession();
        $this->verifySession($seller->token);

        $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented' => true,
            'signatures' => ['seller-init-0' => $this->initialImage()],
        ])->assertStatus(422);

        $this->assertDatabaseHas('signature_audit_log', [
            'signature_request_id' => $seller->id,
            'action' => 'initial_consent_denied',
        ]);
    }

    /** Nothing is written when the gate refuses — no partial state, no half-signed document. */
    public function test_a_refused_bulk_write_persists_nothing(): void
    {
        [$seller, $document] = $this->seedSession();
        $this->verifySession($seller->token);

        $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented' => true,
            'signatures' => ['seller-init-0' => $this->initialImage()],
        ])->assertStatus(422);

        $webData = $document->fresh()->web_template_data ?? [];
        $this->assertArrayNotHasKey('signed_initials', $webData, 'A refused submission must not file any initial.');
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller->fresh()->status);
    }

    /** THE LEGITIMATE FLOW: initial each page individually, then submit — accepted. */
    public function test_individually_consented_initials_are_accepted(): void
    {
        [$seller] = $this->seedSession();
        $this->verifySession($seller->token);

        // The signer places each initial, one page at a time. Each placement records its
        // own timestamped consent event — this is what the browser now does.
        foreach (['seller-init-0', 'seller-init-1', 'seller-init-2'] as $surface) {
            $this->postJson('/sign/' . $seller->token . '/initial-consent', [
                'surface_key' => $surface,
            ])->assertOk();
        }

        $response = $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented' => true,
            'signatures' => [
                'seller-init-0' => $this->initialImage(),
                'seller-init-1' => $this->initialImage(),
                'seller-init-2' => $this->initialImage(),
            ],
        ]);

        $response->assertOk();
    }

    /** Consent for SOME pages does not license the rest — the partial bulk is still refused. */
    public function test_partially_consented_bulk_is_refused_for_the_uncosented_pages(): void
    {
        [$seller] = $this->seedSession();
        $this->verifySession($seller->token);

        $this->postJson('/sign/' . $seller->token . '/initial-consent', [
            'surface_key' => 'seller-init-0',
        ])->assertOk();

        $response = $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented' => true,
            'signatures' => [
                'seller-init-0' => $this->initialImage(),   // consented
                'seller-init-1' => $this->initialImage(),   // NOT consented
                'seller-init-2' => $this->initialImage(),   // NOT consented
            ],
        ]);

        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(
            ['seller-init-1', 'seller-init-2'],
            $response->json('unconsented_surfaces'),
        );
    }

    /**
     * The gate cannot be walked around by sending the initials through the OTHER input.
     * completeWeb() persists initials from `signatures` (keys carrying -init-) AND from a
     * separate `initials` input — so the gate must read both.
     */
    public function test_the_gate_cannot_be_bypassed_via_the_initials_input(): void
    {
        [$seller] = $this->seedSession();
        $this->verifySession($seller->token);

        $response = $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented' => true,
            'initials' => [
                'seller-init-0' => $this->initialImage(),
                'seller-init-1' => $this->initialImage(),
            ],
        ]);

        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(
            ['seller-init-0', 'seller-init-1'],
            $response->json('unconsented_surfaces'),
        );
    }

    /**
     * DOCTRINE, NOT A BUG (ceremony §2): the agent signs on the professional profile —
     * adopt once, apply to every surface they own. The gate must NOT touch them.
     */
    public function test_agent_apply_all_is_still_allowed(): void
    {
        [, , $agent] = $this->seedSession(includeAgent: true);
        $this->verifySession($agent->token);

        $response = $this->postJson('/sign/' . $agent->token . '/complete-web', [
            'consented' => true,
            'signatures' => [
                'agent-init-0' => $this->initialImage(),
                'agent-init-1' => $this->initialImage(),
                'agent-init-2' => $this->initialImage(),
            ],
        ]);

        $response->assertOk();
    }

    /** A submission carrying no initials at all is unaffected by the gate. */
    public function test_a_submission_with_no_initials_is_unaffected(): void
    {
        [$seller] = $this->seedSession();
        $this->verifySession($seller->token);

        $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented' => true,
            'signatures' => ['seller-sig-0' => $this->initialImage()],
        ])->assertOk();
    }

    /** The consent endpoint is itself gated — an unverified session cannot mint consent. */
    public function test_initial_consent_requires_a_verified_session(): void
    {
        [$seller] = $this->seedSession();

        $this->postJson('/sign/' . $seller->token . '/initial-consent', [
            'surface_key' => 'seller-init-0',
        ])->assertStatus(403);
    }

    // ── Helpers ──

    private function initialImage(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    private function verifySession(string $token): void
    {
        $this->withSession(["signing_verified_{$token}" => true]);
    }

    /** @return array{0: SignatureRequest, 1: Document, 2?: SignatureRequest} */
    private function seedSession(bool $includeAgent = false): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Elize van Wyk', 'email' => 'elize-' . Str::random(6) . '@hfcoastal.co.za',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Exclusive Authority To Sell (V10)',
            'render_type' => 'web',
            'template_type' => 'cds',
            'category' => 'sales',
            'signing_parties' => ['owner_party', 'agent'],
            'field_mappings' => [],
            'owner_id' => $userId,
        ]);

        $doc = Document::create([
            'name' => 'EATS — 14 Marine Drive, Shelly Beach',
            'document_type' => 'agreement',
            'owner_id' => $userId,
            'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div>body</div>'],
        ]);

        $sigTmpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SELLER,
            'created_by' => $userId,
        ]);

        $seller = SignatureRequest::create([
            'signature_template_id' => $sigTmpl->id,
            'party_role'    => 'seller',
            'role_index'    => 1,
            'signer_name'   => 'Thandeka Mkhize',
            'signer_email'  => 'thandeka.mkhize@gmail.com',
            'token'         => Str::random(48),
            'token_expires_at' => now()->addDays(30),
            'status'        => SignatureRequest::STATUS_PENDING,
            'signing_order' => 1,
            'sent_at'       => now()->subDay(),
        ]);

        $agent = null;
        if ($includeAgent) {
            $agent = SignatureRequest::create([
                'signature_template_id' => $sigTmpl->id,
                'party_role'    => 'agent',
                'role_index'    => 1,
                'signer_name'   => 'Elize van Wyk',
                'signer_email'  => 'elize@hfcoastal.co.za',
                'token'         => Str::random(48),
                'token_expires_at' => now()->addDays(30),
                'status'        => SignatureRequest::STATUS_PENDING,
                'signing_order' => 2,
                'sent_at'       => now()->subDay(),
            ]);
        }

        return $includeAgent ? [$seller, $doc, $agent] : [$seller, $doc];
    }
}
