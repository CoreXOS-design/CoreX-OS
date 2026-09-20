<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Conductor, 2026-09-20 — the public form's client-side beforeSubmit()
 * unconditionally required BOTH signatures, ignoring 'shown' and the
 * agency's own required-field tick — the ONE place on this form that did
 * not drive its requiredness from $requiredFieldKeys the way every other
 * field already does. "It is exactly what happens the first time an
 * agency turns that field off... A prospective tenant fills in the whole
 * application, cannot submit it, and nobody at the agency ever finds
 * out." Fixed for BOTH signature fields (declaration + credit bureau
 * consent) — the same defect, found on both, not just the one that was
 * already being touched for the TPN wording fix.
 *
 * PHPUnit cannot execute the page's real client-side JS, so the "client"
 * half of these tests proves what got BAKED into the rendered page's JS
 * at render time (declRequired/tpnRequired booleans) — the same class of
 * proof this whole session's Blade-rendering tests already rely on. The
 * "server" half proves the actual HTTP submission outcome, which IS
 * exercised for real. Together: server was already correct
 * (effectiveRequiredFieldKeysFor() already reconciles hidden vs required)
 * — only the client was wrong.
 */
final class RentalApplicationSignatureRequirementClientServerAgreementTest extends TestCase
{
    use RefreshDatabase;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'HQ']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'status' => 'sent', 'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    private function completePayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Jane Applicant',
            'id_number' => '9001015800083',
            'email' => 'jane@example.com',
            'current_residential_address' => '1 Example Road, Ramsgate',
            'monthly_salary' => 20000,
            'rental_term_months' => 12,
            'declaration_signature' => self::SIG,
            'tpn_consent_signature' => self::SIG,
        ], $overrides);
    }

    // ── Client: what got baked into the rendered page's JS ──────────────

    public function test_the_client_still_requires_both_signatures_by_default_shown_and_required(): void
    {
        // Regression proof: fixing the bug must not silently stop
        // requiring the signatures for the unconfigured (HFC-shaped)
        // default, where both are shown and required.
        $app = $this->application();

        $html = $this->get(route('rental-applications.public.show', $app->token))->getContent();

        $this->assertStringContainsString('const declRequired = true;', $html);
        $this->assertStringContainsString('const tpnRequired = true;', $html);
    }

    public function test_the_client_does_not_require_a_hidden_declaration_signature(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['hidden_field_keys' => ['declaration_signature']]
        );
        $app = $this->application();

        $html = $this->get(route('rental-applications.public.show', $app->token))->getContent();

        $this->assertStringContainsString('const declRequired = false;', $html);
    }

    public function test_the_client_does_not_require_a_hidden_tpn_consent_signature(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['hidden_field_keys' => ['tpn_consent_signature']]
        );
        $app = $this->application();

        $html = $this->get(route('rental-applications.public.show', $app->token))->getContent();

        $this->assertStringContainsString('const tpnRequired = false;', $html);
    }

    public function test_the_client_does_not_require_a_shown_but_unticked_signature(): void
    {
        // Hidden and required are two separate settings — an agency can
        // leave a field visible but make it optional. The old code
        // ignored this dimension too, not just 'shown'.
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['required_field_keys' => array_values(array_diff(
                RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS,
                ['declaration_signature']
            ))]
        );
        $app = $this->application();

        $html = $this->get(route('rental-applications.public.show', $app->token))->getContent();

        $this->assertStringContainsString('const declRequired = false;', $html);
        // Still shown — the section itself must still render.
        $this->assertStringContainsString('Declaration', $html);
    }

    // ── Server: the actual HTTP submission outcome ───────────────────────

    public function test_server_accepts_submission_with_a_blank_hidden_declaration_signature(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['hidden_field_keys' => ['declaration_signature']]
        );
        $app = $this->application();

        $response = $this->post(
            route('rental-applications.public.submit', $app->token),
            $this->completePayload(['declaration_signature' => ''])
        );

        $response->assertSessionDoesntHaveErrors('declaration_signature');
        $this->assertNotNull($app->fresh()->submitted_at, 'a hidden, non-required signature must never block a genuine submission server-side');
    }

    public function test_server_accepts_submission_with_a_blank_shown_but_unticked_signature(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['required_field_keys' => array_values(array_diff(
                RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS,
                ['tpn_consent_signature']
            ))]
        );
        $app = $this->application();

        $response = $this->post(
            route('rental-applications.public.submit', $app->token),
            $this->completePayload(['tpn_consent_signature' => ''])
        );

        $response->assertSessionDoesntHaveErrors('tpn_consent_signature');
        $this->assertNotNull($app->fresh()->submitted_at);
    }

    public function test_server_still_refuses_submission_with_a_blank_shown_and_required_signature(): void
    {
        // The default (HFC-shaped) case, unchanged — this proves the
        // server-side floor was already correct and stays correct.
        $app = $this->application();

        $response = $this->post(
            route('rental-applications.public.submit', $app->token),
            $this->completePayload(['tpn_consent_signature' => ''])
        );

        $response->assertSessionHasErrors('tpn_consent_signature');
        $this->assertNull($app->fresh()->submitted_at);
    }
}
