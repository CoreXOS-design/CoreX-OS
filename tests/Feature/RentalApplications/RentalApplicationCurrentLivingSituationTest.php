<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, verbatim: "rental application - current landlord - we need to
 * include a part here for a person who has sold his house and is going to
 * rent for the first time now. and a free text section where the
 * applicant can capture their own explanation of where they live / lived."
 *
 * The "Current Landlord" section assumed a landlord always exists. Fixed by
 * adding current_living_situation (which of the recognised situations
 * applies — renting/owns_or_selling/living_with_family/other) alongside the
 * existing landlord fields, which now only apply/show when the answer is
 * 'renting'. current_living_situation_notes is a genuinely optional free
 * text field, always available regardless of the answer above.
 *
 * Both fields are nullable server-side (BUILD_STANDARD §2 — nothing on
 * this form may block a save), same posture as every other field here —
 * the landlord fields were never actually required before this change
 * either; what changes is the UI no longer visually demands them of
 * someone they don't apply to.
 */
final class RentalApplicationCurrentLivingSituationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Contact $contact;
    private $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho-' . uniqid() . '@example.co.za',
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    // ── The core gap Johan named ──────────────────────────────────────────

    public function test_an_applicant_who_just_sold_completes_the_form_without_any_landlord_fields(): void
    {
        $app = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $app->token), [
            'current_living_situation' => 'owns_or_selling',
            'current_living_situation_notes' => 'Sold my house in Ramsgate, need to move out by end of month.',
            'declaration_signature' => $this->sig,
            'tpn_consent_signature' => $this->sig,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $app->refresh();
        $this->assertSame('owns_or_selling', $app->current_living_situation);
        $this->assertSame('Sold my house in Ramsgate, need to move out by end of month.', $app->current_living_situation_notes);
        $this->assertNull($app->current_landlord_name, 'no landlord field was ever demanded of them');
        $this->assertSame('returned', $app->status);
    }

    public function test_an_applicant_currently_renting_still_completes_the_old_path_unchanged(): void
    {
        $app = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $app->token), [
            'current_living_situation' => 'renting',
            'current_landlord_name' => 'ABC Rentals',
            'current_landlord_tel' => '0399123456',
            'current_rental_amount' => '8500',
            'declaration_signature' => $this->sig,
            'tpn_consent_signature' => $this->sig,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $app->refresh();
        $this->assertSame('renting', $app->current_living_situation);
        $this->assertSame('ABC Rentals', $app->current_landlord_name);
        $this->assertSame('8500.00', $app->current_rental_amount);
    }

    public function test_living_with_family_also_completes_without_landlord_fields(): void
    {
        $app = $this->application();

        $this->post(route('rental-applications.public.submit', $app->token), [
            'current_living_situation' => 'living_with_family',
            'declaration_signature' => $this->sig,
            'tpn_consent_signature' => $this->sig,
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('living_with_family', $app->fresh()->current_living_situation);
    }

    public function test_an_invalid_situation_value_is_rejected_with_a_plain_message(): void
    {
        $app = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $app->token), [
            'current_living_situation' => 'squatting_in_a_castle',
            'declaration_signature' => $this->sig,
            'tpn_consent_signature' => $this->sig,
        ]);

        $response->assertSessionHasErrors('current_living_situation');
        $this->assertSame('sent', $app->fresh()->status, 'nothing was saved — the whole submission failed together');
    }

    public function test_the_free_text_notes_are_never_required(): void
    {
        $app = $this->application();

        $this->post(route('rental-applications.public.submit', $app->token), [
            'current_living_situation' => 'other',
            'declaration_signature' => $this->sig,
            'tpn_consent_signature' => $this->sig,
        ])->assertSessionDoesntHaveErrors();

        $this->assertNull($app->fresh()->current_living_situation_notes);
    }

    public function test_no_answer_at_all_still_does_not_block_submission(): void
    {
        // BUILD_STANDARD §2 — nothing on this form may block a save on its
        // own absence, including the new field itself.
        $app = $this->application();

        $this->post(route('rental-applications.public.submit', $app->token), [
            'declaration_signature' => $this->sig,
            'tpn_consent_signature' => $this->sig,
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('returned', $app->fresh()->status);
    }

    // ── Nothing typed is lost on a validation failure ─────────────────────

    public function test_a_validation_failure_on_an_unrelated_field_preserves_the_situation_answer_and_notes(): void
    {
        $app = $this->application();

        $this->post(route('rental-applications.public.submit', $app->token), [
            'current_living_situation' => 'owns_or_selling',
            'current_living_situation_notes' => 'Selling unit A, need to move by January.',
            'current_rental_amount' => 'not-a-number', // forces the failure
            'declaration_signature' => $this->sig,
            'tpn_consent_signature' => $this->sig,
        ])->assertSessionHasErrors('current_rental_amount');

        $show = $this->get(route('rental-applications.public.show', $app->token));
        $show->assertOk();
        $show->assertSee('Selling unit A, need to move by January.');
    }

    // ── Agent-side update() accepts the same fields ────────────────────────

    public function test_agent_side_update_accepts_the_new_fields(): void
    {
        $app = $this->application();

        $response = $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'current_living_situation' => 'owns_or_selling',
            'current_living_situation_notes' => 'Client sold their home, first-time renter.',
        ]);

        $response->assertRedirect();
        $app->refresh();
        $this->assertSame('owns_or_selling', $app->current_living_situation);
        $this->assertSame('Client sold their home, first-time renter.', $app->current_living_situation_notes);
    }

    // ── Existing (pre-feature) applications must not break ────────────────

    public function test_an_existing_application_with_landlord_data_but_no_situation_answer_still_renders_everywhere(): void
    {
        // Simulates a real pre-feature record: landlord fields filled in,
        // current_living_situation genuinely never asked (null).
        $app = $this->application([
            'status' => 'returned', 'submitted_at' => now(),
            'current_landlord_name' => 'Pre-Existing Landlord CC',
            'current_landlord_tel' => '0399991111',
            'current_rental_amount' => 7500,
        ]);
        $this->assertNull($app->current_living_situation);

        // Public link — already-submitted page, not the editable form, but
        // must not 500.
        $this->get(route('rental-applications.public.show', $app->token))->assertOk();

        // Agent-side editable/read-only view.
        $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app))->assertOk();

        // The PDF's own Blade content — direct render, not the full HTTP
        // route (RentalApplicationPdfService shells out to Node/Puppeteer
        // to rasterise the HTML into an actual PDF file, an environment
        // dependency this test has no need to pull in just to prove the
        // TEMPLATE itself renders correctly for a pre-feature record).
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = view('corex.rental-applications.pdf', [
            'application' => $app, 'agency' => $app->agency, 'branch' => $app->branch,
        ])->render();
        $this->assertStringContainsString('Currently renting', $html, 'falls back to the old landlord data when the new field was never answered');
        $this->assertStringContainsString('Pre-Existing Landlord CC', $html);
    }
}
