<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-10-11 hotfix (Johan, live) — a client opened a signing link sent an hour
 * earlier, was sent on to FICA, and got "This link has expired". The FICA gate
 * reused the contact's unfinished FICA draft from weeks before, and only ever
 * minted a token when it was EMPTY — a draft whose 14-day link had lapsed kept
 * its dead expiry. Rule now (FicaSubmission::keepLinkAliveUntil): a reused FICA
 * link never dies before the link that leads to it.
 */
final class FicaLinkRenewedOnReuseTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Second Agency', 'slug' => 'second-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => 'Nomsa', 'last_name' => 'Dlamini', 'email' => 'nomsa@example.co.za',
        ]);
    }

    private function draft(?string $token, $expiresAt): FicaSubmission
    {
        return FicaSubmission::create([
            'contact_id'       => $this->contact->id,
            'agency_id'        => $this->agency->id,
            'requested_by'     => $this->agent->id,
            'token'            => $token,
            'token_expires_at' => $expiresAt,
            'status'           => 'draft',
        ]);
    }

    private function signingRequest(FicaSubmission $fica): SignatureRequest
    {
        $doc = Document::create(['name' => 'Sole Mandate', 'owner_id' => $this->agent->id, 'agency_id' => $this->agency->id]);
        $t = SignatureTemplate::create([
            'document_id'   => $doc->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_SIGNING,
            'created_by'    => $this->agent->id,
        ]);

        return SignatureRequest::create([
            'signature_template_id' => $t->id,
            'party_role'            => 'seller',
            'role_index'            => 1,
            'signing_order'         => 1,
            'signer_name'           => 'Nomsa Dlamini',
            'signer_email'          => 'nomsa@example.co.za',
            'contact_id'            => $this->contact->id,
            'fica_required'         => true,
            'fica_submission_id'    => $fica->id,
            'token'                 => Str::random(48),
            'token_expires_at'      => now()->addDays(14),
            'status'                => SignatureRequest::STATUS_PENDING,
        ]);
    }

    // ── The model rule ───────────────────────────────────────────────────

    public function test_a_lapsed_link_is_renewed_and_keeps_its_token(): void
    {
        $fica = $this->draft('old-token-' . Str::random(40), now()->subDays(10));
        $until = now()->addDays(14);

        $fica->keepLinkAliveUntil($until);

        $this->assertSame($until->toDateTimeString(), $fica->token_expires_at->toDateTimeString());
        $this->assertStringStartsWith('old-token-', $fica->token, 'A link the client already holds must work again.');
    }

    public function test_a_missing_token_is_minted(): void
    {
        $fica = $this->draft(null, null);

        $fica->keepLinkAliveUntil(now()->addDays(14));

        $this->assertNotEmpty($fica->token);
        $this->assertFalse($fica->isExpired());
    }

    public function test_a_longer_living_link_is_never_shortened(): void
    {
        $later = now()->addDays(30);
        $fica = $this->draft(Str::random(64), $later);

        $fica->keepLinkAliveUntil(now()->addDays(14));

        $this->assertFalse($fica->isDirty());
    }

    public function test_a_token_with_no_expiry_is_left_alone(): void
    {
        $fica = $this->draft(Str::random(64), null);

        $fica->keepLinkAliveUntil(now()->addDays(14));

        $this->assertNull($fica->token_expires_at);
        $this->assertFalse($fica->isDirty());
    }

    // ── The real symptom, over HTTP ──────────────────────────────────────

    public function test_opening_a_fresh_signing_link_revives_a_lapsed_fica_draft_link(): void
    {
        $fica = $this->draft(Str::random(64), now()->subDays(10)); // made weeks ago, lapsed
        $req = $this->signingRequest($fica);

        // Before the fix this is the page the client hit: "This link has expired".
        $this->get(route('fica.form', $fica->token))->assertStatus(410);

        $this->get(route('signatures.external', $req->token))->assertOk();

        $fica->refresh();
        $this->assertFalse($fica->isExpired());
        $this->assertTrue($fica->token_expires_at->gte($req->token_expires_at),
            'The FICA link must live at least as long as the signing link that leads to it.');
        $this->get(route('fica.form', $fica->token))->assertOk();
    }
}
