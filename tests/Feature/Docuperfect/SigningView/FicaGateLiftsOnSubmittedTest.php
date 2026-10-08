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
 * Johan's ruling, 2026-10-08 (relayed by the conductor): the FICA gate lifts on SUBMITTED, not only on approved. The external signer's own signing
 * page is the one place sales (and a lease agreement's tenant/landlord) genuinely stops on FICA — so it is held to
 * the ruling for every FICA state: closed (the FICA page) until the signer has SUBMITTED, open from then on.
 *
 * The request is wet_ink so that an OPEN gate resolves to the cheap wet-ink redirect — the assertion is only
 * "did the FICA gate page show", never a render of the whole signing surface.
 */
final class FicaGateLiftsOnSubmittedTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cr-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Ayanda', 'last_name' => 'Mtolo', 'email' => 'ayanda@example.co.za',
        ]);
    }

    private function signingRequest(): SignatureRequest
    {
        $doc = Document::create(['name' => 'Residential Lease', 'owner_id' => $this->agent->id, 'agency_id' => $this->agency->id]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $this->agent->id,
        ]);

        return SignatureRequest::create([
            'signature_template_id' => $tpl->id,
            'party_role' => 'lessee', 'role_index' => 1, 'signing_order' => 1,
            'signer_name' => 'Ayanda Mtolo', 'signer_email' => 'ayanda@example.co.za',
            'token' => Str::random(48), 'token_expires_at' => now()->addDays(14),
            'status' => SignatureRequest::STATUS_PENDING,
            'signing_method' => 'wet_ink',
            'fica_required' => true,
            'contact_id' => $this->contact->id,
        ]);
    }

    private function fica(string $status): void
    {
        FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id,
            'requested_by' => $this->agent->id, 'status' => $status,
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    /** @return array<string, array{0:?string,1:bool}> [fica status or null, gate page expected] */
    public static function stateProvider(): array
    {
        return [
            'no FICA started' => [null, true],
            'requested, not submitted (draft)' => ['draft', true],
            'sent back for corrections' => ['corrections_requested', true],
            'rejected' => ['rejected', true],
            'submitted' => ['submitted', false],
            'under review' => ['under_review', false],
            'agent approved' => ['agent_approved', false],
            'referred to the compliance officer' => ['referred_to_co', false],
            'approved' => ['approved', false],
        ];
    }

    /** @dataProvider stateProvider */
    public function test_the_signing_page_shows_the_fica_gate_until_fica_is_submitted(?string $ficaStatus, bool $gateExpected): void
    {
        if ($ficaStatus !== null) {
            $this->fica($ficaStatus);
        }
        $req = $this->signingRequest();

        $response = $this->get(route('signatures.external', $req->token));

        if ($gateExpected) {
            $response->assertOk();
            $response->assertViewIs('docuperfect.signatures.external.fica-gate');
        } else {
            $response->assertRedirect(route('signatures.external.wetInkPortal', $req->token));
        }
    }

    public function test_a_request_that_does_not_require_fica_is_never_stopped_by_it(): void
    {
        $req = $this->signingRequest();
        $req->update(['fica_required' => false]);

        $this->get(route('signatures.external', $req->token))
            ->assertRedirect(route('signatures.external.wetInkPortal', $req->token));
    }
}
