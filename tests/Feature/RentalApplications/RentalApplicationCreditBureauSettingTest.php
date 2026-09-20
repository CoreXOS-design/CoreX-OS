<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationGeneration;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Johan, 2026-09-20 — "hfc uses tpn so thats why we have that." The credit
 * bureau named throughout the rental application (heading, PDF signature
 * caption, field label, settings screen) was hardcoded to "TPN" — HFC's
 * own choice, not a universal one, and a real problem ahead of a second,
 * differently-bureaued (or bureau-less) agency signing in October.
 *
 * Every test here proves behaviour that did not exist before this change:
 * old code had no credit_bureau_name column, no creditBureauNameFor()
 * resolver, and every "TPN" string was a hardcoded literal — none of
 * these assertions could pass against the prior code.
 */
final class RentalApplicationCreditBureauSettingTest extends TestCase
{
    use RefreshDatabase;

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

    private function owner(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'token' => Str::random(64), 'status' => 'sent',
        ], $attrs));
    }

    private function renderPdf(RentalApplication $app): string
    {
        View::share('errors', new ViewErrorBag);

        return view('corex.rental-applications.pdf', [
            'application' => $app, 'agency' => $app->agency, 'branch' => $app->branch,
        ])->render();
    }

    // ── Resolver ─────────────────────────────────────────────────────────

    public function test_a_new_agency_with_no_setting_resolves_to_null_not_tpn(): void
    {
        $this->assertNull(RentalApplicationQualifyingSetting::creditBureauNameFor($this->agency->id));
    }

    public function test_an_agency_can_configure_its_own_bureau_name(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['credit_bureau_name' => 'XDS']
        );

        $this->assertSame('XDS', RentalApplicationQualifyingSetting::creditBureauNameFor($this->agency->id));
    }

    // ── Public applicant-facing form ────────────────────────────────────

    public function test_the_public_form_shows_generic_wording_when_no_bureau_is_configured(): void
    {
        $app = $this->application();

        $response = $this->get(route('rental-applications.public.show', $app->token));

        $response->assertOk();
        $response->assertSee('Credit Bureau Consent');
        $response->assertDontSee('TPN', false);
        $response->assertDontSee('Tenant Profile Network', false);
    }

    public function test_the_public_form_shows_the_agencys_configured_bureau_name(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['credit_bureau_name' => 'XDS']
        );
        $app = $this->application();

        $response = $this->get(route('rental-applications.public.show', $app->token));

        $response->assertOk();
        $response->assertSee('XDS Consent');
        $response->assertDontSee('TPN', false);
    }

    // ── PDF ──────────────────────────────────────────────────────────────

    public function test_the_pdf_shows_generic_wording_when_no_bureau_is_configured(): void
    {
        $app = $this->application();

        $html = $this->renderPdf($app);

        $this->assertStringContainsString('Credit Bureau Consent', $html);
        $this->assertStringContainsString('Applicant Signature — Credit Bureau Consent', $html);
        $this->assertStringNotContainsStringIgnoringCase('TPN', $html);
        $this->assertStringNotContainsStringIgnoringCase('Tenant Profile Network', $html);
    }

    public function test_the_pdf_shows_the_agencys_configured_bureau_name(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['credit_bureau_name' => 'XDS']
        );
        $app = $this->application();

        $html = $this->renderPdf($app);

        $this->assertStringContainsString('XDS Consent', $html);
        $this->assertStringContainsString('Applicant Signature — XDS Consent', $html);
    }

    public function test_a_submitted_applications_pdf_keeps_its_historical_bureau_name_after_the_setting_changes(): void
    {
        // Historical integrity, same rule as every other frozen field —
        // displayFieldConfig() (not today's live setting) is what the PDF
        // reads for a submitted application, via field_config_snapshot.
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['credit_bureau_name' => 'TPN']
        );
        $app = $this->application(['status' => 'returned', 'submitted_at' => now(), 'current_generation' => 1]);
        $app->snapshotFieldConfig();
        $app->save();

        // Agency switches bureau AFTER this application was submitted.
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['credit_bureau_name' => 'XDS']
        );

        $html = $this->renderPdf($app->fresh());

        $this->assertStringContainsString('TPN Consent', $html, 'the PDF must keep naming the bureau this application actually disclosed at submission time');
        $this->assertStringNotContainsString('XDS', $html, 'today\'s live setting must never reach backward into an already-submitted record');
    }

    // ── Settings screen ──────────────────────────────────────────────────

    public function test_settings_screen_shows_generic_wording_when_no_bureau_is_configured(): void
    {
        $response = $this->actingAs($this->owner())->get(route('corex.settings.rental-applications.edit'));

        $response->assertOk();
        $response->assertSee('Credit Bureau Consent');
        $response->assertDontSee('Tenant Profile Network', false);
    }

    public function test_settings_screen_shows_the_configured_bureau_name_in_its_field_label_and_section_heading(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['credit_bureau_name' => 'XDS']
        );

        $response = $this->actingAs($this->owner())->get(route('corex.settings.rental-applications.edit'));

        $response->assertOk();
        $response->assertSee('XDS Consent');
    }

    public function test_saving_a_bureau_name_persists_it(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.credit-bureau'), [
            'credit_bureau_name' => 'XDS',
        ])->assertRedirect(route('corex.settings.rental-applications.edit'));

        $this->assertSame('XDS', RentalApplicationQualifyingSetting::creditBureauNameFor($this->agency->id));
    }

    public function test_saving_a_blank_bureau_name_clears_it_to_null_not_an_empty_string(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['credit_bureau_name' => 'TPN']
        );
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.credit-bureau'), [
            'credit_bureau_name' => '',
        ])->assertRedirect(route('corex.settings.rental-applications.edit'));

        $this->assertNull(RentalApplicationQualifyingSetting::creditBureauNameFor($this->agency->id));
    }

    public function test_saving_credit_bureau_respects_agency_scoping(): void
    {
        $otherAgency = Agency::create(['name' => 'Another Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $otherOwner = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        $this->actingAs($otherOwner)->post(route('corex.settings.rental-applications.credit-bureau'), [
            'credit_bureau_name' => 'Experian',
        ]);

        $this->assertSame('Experian', RentalApplicationQualifyingSetting::creditBureauNameFor($otherAgency->id));
        $this->assertNull(RentalApplicationQualifyingSetting::creditBureauNameFor($this->agency->id), 'one agency saving its bureau must never leak into another');
    }
}
