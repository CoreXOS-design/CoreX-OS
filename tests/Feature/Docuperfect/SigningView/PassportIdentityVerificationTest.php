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
 * AT-385 — Johan: "no id is a massive problem... ACCEPTS IDENTITY NUMBER OR
 * PASSPORT." Before this fix, show()'s gateway-trigger only checked
 * signer_id_number, and verify() only compared against signer_id_number —
 * a passport-only signer (no SA ID, e.g. a foreign national buyer on the
 * KZN coast) either skipped the ID-verify second factor entirely (if the
 * gate never triggered) or could NEVER pass verify() (since the expected
 * value was always empty). Both are now checked against EITHER field.
 */
final class PassportIdentityVerificationTest extends TestCase
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

    private function requestWith(?string $idNumber, ?string $passportNumber): SignatureRequest
    {
        $doc = Document::create(['name' => 'Offer to Purchase — Jan de Vries', 'owner_id' => $this->agent->id, 'agency_id' => $this->agency->id]);
        $t = SignatureTemplate::create([
            'document_id'   => $doc->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_SIGNING,
            'created_by'    => $this->agent->id,
        ]);

        return SignatureRequest::create([
            'signature_template_id'   => $t->id,
            'party_role'              => 'buyer',
            'role_index'              => 1,
            'signing_order'           => 1,
            'signer_name'             => 'Jan de Vries',
            'signer_email'            => 'jan@example.co.za',
            'signer_id_number'        => $idNumber,
            'signer_passport_number'  => $passportNumber,
            'token'                   => Str::random(48),
            'token_expires_at'        => now()->addDays(14),
            'status'                  => SignatureRequest::STATUS_PENDING,
        ]);
    }

    public function test_show_triggers_the_gateway_for_a_passport_only_signer(): void
    {
        $req = $this->requestWith(idNumber: null, passportNumber: 'P1234567');

        $response = $this->get(route('signatures.external', $req->token));

        $response->assertRedirect(route('signatures.external.gateway', $req->token));
    }

    public function test_verify_succeeds_against_the_passport_number_case_insensitively(): void
    {
        $req = $this->requestWith(idNumber: null, passportNumber: 'P1234567');

        $response = $this->post(route('signatures.external.verify', $req->token), [
            'id_number' => 'p1234567',
        ]);

        $response->assertRedirect(route('signatures.external.showConsent', $req->token));
        $this->assertTrue(session("signing_verified_{$req->token}"));
    }

    public function test_verify_still_rejects_a_wrong_value_for_a_passport_only_signer(): void
    {
        $req = $this->requestWith(idNumber: null, passportNumber: 'P1234567');

        $response = $this->post(route('signatures.external.verify', $req->token), [
            'id_number' => 'WRONGVALUE',
        ]);

        $response->assertRedirect(route('signatures.external.gateway', $req->token));
        $response->assertSessionHas('error');
        $this->assertNull(session("signing_verified_{$req->token}"));
    }

    /**
     * Regression guard — the ID-only path (the pre-existing, already-working
     * behaviour) must be completely unaffected by the passport fallback.
     */
    public function test_verify_still_succeeds_against_the_id_number_when_no_passport_is_on_file(): void
    {
        $req = $this->requestWith(idNumber: '8501015800083', passportNumber: null);

        $response = $this->post(route('signatures.external.verify', $req->token), [
            'id_number' => '8501015800083',
        ]);

        $response->assertRedirect(route('signatures.external.showConsent', $req->token));
        $this->assertTrue(session("signing_verified_{$req->token}"));
    }

}
