<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-293 — server-side mandatory FLOOR on completeWeb().
 *
 * The client (canSubmitWeb / webIncompleteCount) is the full required-item
 * enforcer, but it is DOM-derived and bypassable (crafted POST / JS failure
 * after consent). A web/CDS template carries no structured per-field `required`
 * flag, so the exact client count can't be faithfully reproduced server-side.
 * The gate enforces the FLOOR that closes the real hole — a completion POST
 * that submits none of the statutory work:
 *   (b) at least one signature/initial captured, and
 *   (c) if the signer has recipient-editable fields, at least one filled.
 * The floor sits beneath the client contract (which requires ALL such items),
 * so it only rejects the empty/crafted POST — never a client-legitimate one.
 */
final class WebCompletionRequiredGateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    /** (b) A completion with no signature and no initial is rejected 422. */
    public function test_complete_web_rejected_when_no_signature_captured(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $response = $this->postJson('/sign/' . $seller1->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => [],
            'initials'     => [],
            'field_values' => [],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('sign the document', (string) $response->json('error'));
    }

    /** (c) A signer with editable fields who submits none is rejected 422. */
    public function test_complete_web_rejected_when_editable_fields_all_blank(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $response = $this->postJson('/sign/' . $seller1->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => ['owner_party-sig-0' => 'data:image/png;base64,iVBORw0KGgo='],
            'initials'     => [],
            'field_values' => [],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('complete the fields', (string) $response->json('error'));
    }

    /** The floor passes when a signature and a field value are present. */
    public function test_complete_web_gate_passes_with_signature_and_field(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $response = $this->postJson('/sign/' . $seller1->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => ['owner_party-sig-0' => 'data:image/png;base64,iVBORw0KGgo='],
            'initials'     => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);

        // The mandatory floor must NOT fire (downstream completion side-effects
        // are out of scope for this gate boundary test).
        $this->assertNotSame(422, $response->getStatusCode(), 'Mandatory floor must pass when signature + field present.');
    }

    /**
     * P0 (Johan 2026-08-07) — a recipient whose signatures are positioned DB MARKERS captures each one
     * through a SEPARATE earlier request (POST /capture/{id} → a persisted Signature row). Those marks
     * never enter the completeWeb POST body, so the floor used to false-positive 422 even though the
     * document is fully signed. With a persisted signature for this signing request, the floor must PASS
     * on an otherwise-empty completeWeb body.
     */
    public function test_complete_web_passes_when_marker_signature_already_persisted(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        // Persist a marker signature for this signing request (mirrors POST /capture/{id}).
        $marker = \App\Models\Docuperfect\SignatureMarker::create([
            'signature_template_id' => $session['signatureTemplate']->id,
            'assigned_party'        => $seller1->party_role,
            'type'                  => 'signature',
            'page_number'           => 1,
            'x_position'            => 10, 'y_position' => 10, 'width' => 20, 'height' => 8,
        ]);
        \App\Models\Docuperfect\Signature::create([
            'signature_template_id' => $session['signatureTemplate']->id,
            'signature_marker_id'   => $marker->id,
            'signature_request_id'  => $seller1->id,
            'signer_name'           => $seller1->signer_name,
            'signer_email'          => $seller1->signer_email,
            'signer_ip_address'     => '127.0.0.1',
            'signature_data'        => 'data:image/png;base64,iVBORw0KGgo=',
            'signature_type'        => 'drawn',
            'signed_at'             => now(),
        ]);

        $response = $this->postJson('/sign/' . $seller1->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => [],   // empty body — the marks live server-side, not in this POST
            'initials'     => [],
            'field_values' => [],
        ]);

        // The no-signature floor must NOT fire (it now sees the persisted marker signature). Any later
        // gate is out of scope — we assert only that the false-positive "no signature" 422 is gone.
        $error = (string) $response->json('error');
        $this->assertStringNotContainsString('no signature was captured', $error,
            'The floor must accept a recipient whose signature is already persisted via the marker path.');
    }

    /** Consent remains enforced (unchanged, but pinned alongside the new floor). */
    public function test_complete_web_still_requires_consent(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $response = $this->postJson('/sign/' . $seller1->token . '/complete-web', [
            'consented'  => false,
            'signatures' => ['owner_party-sig-0' => 'data:image/png;base64,iVBORw0KGgo='],
        ]);

        $response->assertStatus(422);
    }
}
