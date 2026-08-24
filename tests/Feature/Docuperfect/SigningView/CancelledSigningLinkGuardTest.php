<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * cc6's public-link audit, escalated by Johan 2026-08-24 — a cancelled
 * ceremony was NOT one of isSigningBlocked()'s two clocks (link TTL /
 * legal deadline), so a recipient could be walked through ID verification
 * and consent and actually sign a document the agency had already
 * withdrawn. Mirrors LapseGuardTest's shape for the same predicate, this
 * time for the cancelled clock, plus the HTTP-level proof that every entry
 * point (show, a direct bookmarked gateway URL, the actual completion
 * endpoint) is blocked — not just the page a recipient normally lands on
 * first — and that the block-page leaks no document identity.
 */
final class CancelledSigningLinkGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    private function template(string $status, string $documentName = 'Sole Mandate — Nomsa Dlamini'): SignatureTemplate
    {
        $doc = Document::create(['name' => $documentName, 'owner_id' => $this->agent->id, 'agency_id' => $this->agency->id]);

        return SignatureTemplate::create([
            'document_id'   => $doc->id,
            'document_hash' => Str::random(64),
            'status'        => $status,
            'created_by'    => $this->agent->id,
        ]);
    }

    private function request(SignatureTemplate $t, string $status = SignatureRequest::STATUS_PENDING): SignatureRequest
    {
        return SignatureRequest::create([
            'signature_template_id' => $t->id,
            'party_role'            => 'seller',
            'role_index'            => 1,
            'signing_order'         => 1,
            'signer_name'           => 'Nomsa Dlamini',
            'signer_email'          => 'nomsa@example.co.za',
            'token'                 => Str::random(48),
            'token_expires_at'      => now()->addDays(14), // fresh — the bug is that this alone let a cancelled ceremony through
            'status'                => $status,
        ]);
    }

    // ── isSigningBlocked() — the cancelled clock ─────────────────────────

    public function test_a_cancelled_template_blocks_signing_even_with_a_fresh_token(): void
    {
        $t = $this->template(SignatureTemplate::STATUS_CANCELLED);
        $req = $this->request($t);

        $this->assertTrue($req->fresh()->isSigningBlocked(),
            'A cancelled ceremony must stop the pen even though the 14-day link is still fresh.');
    }

    public function test_a_live_ceremony_with_a_fresh_token_is_not_blocked(): void
    {
        $t = $this->template(SignatureTemplate::STATUS_SIGNING);
        $req = $this->request($t);

        $this->assertFalse($req->fresh()->isSigningBlocked());
    }

    // ── HTTP — every entry point, not just show() ────────────────────────

    public function test_show_does_not_reveal_the_document_and_does_not_redirect_into_the_live_flow(): void
    {
        $t = $this->template(SignatureTemplate::STATUS_CANCELLED, 'Sole Mandate — Nomsa Dlamini');
        $req = $this->request($t);

        $response = $this->get(route('signatures.external', $req->token));

        $response->assertStatus(410); // renders the unavailable page directly, not a redirect into gateway
        $response->assertDontSee('Nomsa Dlamini', false);
        $response->assertDontSee('Sole Mandate', false);
        $response->assertSee('No longer available', false);
    }

    public function test_a_bookmarked_gateway_url_is_also_blocked_not_just_show(): void
    {
        $t = $this->template(SignatureTemplate::STATUS_CANCELLED, 'Sole Mandate — Nomsa Dlamini');
        $req = $this->request($t);

        // Simulates a recipient who verified earlier, bookmarked the gateway
        // URL, and returns after the agent cancels — must not fall through
        // to the live gateway page just because show() wasn't visited again.
        $response = $this->get(route('signatures.external.gateway', $req->token));

        $response->assertStatus(410);
        $response->assertDontSee('Nomsa Dlamini', false);
        $response->assertDontSee('Sole Mandate', false);
        $response->assertDontSee('Enter your full ID or passport number', false); // the live gateway page's own ID form
    }

    public function test_completing_a_cancelled_document_is_refused_server_side(): void
    {
        $t = $this->template(SignatureTemplate::STATUS_CANCELLED);
        $req = $this->request($t);

        $response = $this->postJson(route('signatures.external.completeWeb', $req->token), [
            'consented' => true,
        ]);

        $response->assertStatus(410);
        $this->assertNull($req->fresh()->completed_at, 'No signature may be recorded for a cancelled ceremony.');
        $this->assertNotSame(SignatureRequest::STATUS_COMPLETED, $req->fresh()->status);
    }

    // ── Declined routes to the same non-leaking page ─────────────────────

    public function test_a_declined_request_does_not_reveal_the_document(): void
    {
        $t = $this->template(SignatureTemplate::STATUS_SIGNING, 'Sole Mandate — Nomsa Dlamini');
        $req = $this->request($t, SignatureRequest::STATUS_DECLINED);

        $response = $this->get(route('signatures.external', $req->token));

        $response->assertStatus(410);
        $response->assertDontSee('Nomsa Dlamini', false);
        $response->assertDontSee('Sole Mandate', false);
        $response->assertSee('Signing declined', false);
    }

    // ── Already-completed keeps working, minus the identity line ─────────

    public function test_an_already_completed_request_still_shows_its_own_summary_but_not_the_document_name(): void
    {
        $t = $this->template(SignatureTemplate::STATUS_COMPLETED, 'Sole Mandate — Nomsa Dlamini');
        $req = $this->request($t, SignatureRequest::STATUS_COMPLETED);
        $req->forceFill(['completed_at' => now()])->save();

        $response = $this->get(route('signatures.external', $req->token));

        $response->assertOk();
        $response->assertSee('Already Signed', false);
        $response->assertSee('Nomsa Dlamini', false); // signer name — legitimately shown, unchanged behaviour
        $response->assertDontSee('Sole Mandate', false); // the document-name box is what was removed
    }

    /**
     * A request individually completed BEFORE the wider ceremony was later
     * cancelled (cancelDocument() only cancels the still-pending requests)
     * must still say "already signed" — never "no longer available", which
     * would read as an invitation the recipient no longer has any reason to
     * act on, or worse, cast doubt on a signature that is already valid.
     */
    public function test_an_individually_completed_request_says_already_signed_even_after_the_ceremony_is_later_cancelled(): void
    {
        $t = $this->template(SignatureTemplate::STATUS_CANCELLED);
        $req = $this->request($t, SignatureRequest::STATUS_COMPLETED);
        $req->forceFill(['completed_at' => now()])->save();

        $response = $this->get(route('signatures.external', $req->token));

        $response->assertOk();
        $response->assertSee('Already Signed', false);
        $response->assertDontSee('No longer available', false);
    }
}
