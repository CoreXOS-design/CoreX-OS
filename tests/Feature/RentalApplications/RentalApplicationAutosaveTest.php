<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Applicant-side autosave, 2026-09-12 — Johan: "a member of the public
 * part-way through a rental application... losing everything they have
 * typed is a defect on a public form." No new data model: autosave fills
 * and saves the SAME row RentalApplication::fieldValidationRules() already
 * validates for submit(). Signatures are never autosaved. A malformed or
 * still-mid-edit field must never block every OTHER field from saving —
 * that tolerance is what makes this genuinely safe to fire on a debounce
 * against a form a real person is actively typing into.
 */
final class RentalApplicationAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    public function test_autosave_saves_typed_fields_without_requiring_signatures(): void
    {
        $application = $this->application();

        $this->postJson(route('rental-applications.public.autosave', $application->token), [
            'full_name' => 'Sipho Ndlovu',
            'id_number' => '8001015800083',
            'monthly_salary' => '25000',
        ])->assertOk()->assertJson(['saved' => true]);

        $application->refresh();
        $this->assertSame('Sipho Ndlovu', $application->full_name);
        $this->assertSame('8001015800083', $application->id_number);
        $this->assertSame('25000.00', (string) $application->monthly_salary);
        $this->assertNotNull($application->draft_saved_at, 'draft_saved_at must be stamped on a successful autosave');
    }

    public function test_autosave_never_persists_a_signature_even_if_posted(): void
    {
        $application = $this->application();
        $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $this->postJson(route('rental-applications.public.autosave', $application->token), [
            'full_name' => 'Sipho Ndlovu',
            'declaration_signature' => $sig,
            'tpn_consent_signature' => $sig,
        ])->assertOk();

        $this->assertSame(0, RentalApplicationSignature::where('rental_application_id', $application->id)->count(), 'autosave must never create a signature row');
        $application->refresh();
        $this->assertSame('Sipho Ndlovu', $application->full_name, 'a real field in the same request must still save');
    }

    public function test_autosave_flips_sent_to_in_progress_and_reopened_status_is_never_touched(): void
    {
        $sent = $this->application(['status' => 'sent']);
        $this->postJson(route('rental-applications.public.autosave', $sent->token), ['full_name' => 'A'])->assertOk();
        $sent->refresh();
        $this->assertSame('in_progress', $sent->status);

        $reopened = $this->application(['status' => 'reopened', 'submitted_at' => now()->subDay(), 'reopened_at' => now()]);
        $this->postJson(route('rental-applications.public.autosave', $reopened->token), ['full_name' => 'B'])->assertOk();
        $reopened->refresh();
        $this->assertSame('reopened', $reopened->status, 'autosave must never overwrite the distinct reopened status');
        $this->assertSame('B', $reopened->full_name);
    }

    public function test_autosave_is_a_silent_noop_on_a_locked_terminal_application(): void
    {
        $approved = $this->application(['status' => 'approved', 'full_name' => 'Original Name']);

        $this->postJson(route('rental-applications.public.autosave', $approved->token), [
            'full_name' => 'Tampered Name',
        ])->assertOk()->assertJson(['saved' => false]);

        $approved->refresh();
        $this->assertSame('Original Name', $approved->full_name, 'a locked/terminal application must never be mutated by autosave');
    }

    public function test_autosave_is_a_silent_noop_on_a_not_yet_sent_draft(): void
    {
        $draft = $this->application(['status' => 'draft', 'full_name' => null]);

        $this->postJson(route('rental-applications.public.autosave', $draft->token), [
            'full_name' => 'Should Not Save',
        ])->assertOk()->assertJson(['saved' => false]);

        $draft->refresh();
        $this->assertNull($draft->full_name);
    }

    public function test_autosave_skips_only_the_invalid_field_and_keeps_saving_the_rest(): void
    {
        $application = $this->application();

        // current_rental_to before current_rental_from fails after_or_equal
        // — exactly the transient state a real applicant passes through
        // mid-edit. full_name must still save.
        $this->postJson(route('rental-applications.public.autosave', $application->token), [
            'full_name' => 'Sipho Ndlovu',
            'current_rental_from' => '2026-08-15',
            'current_rental_to' => '2026-01-01',
        ])->assertOk()->assertJson(['saved' => true]);

        $application->refresh();
        $this->assertSame('Sipho Ndlovu', $application->full_name, 'a valid field must save even when a sibling field is transiently invalid');
        $this->assertNull($application->current_rental_to, 'the invalid field itself must not be persisted');
    }

    public function test_autosave_on_an_expired_link_is_a_silent_noop(): void
    {
        $expired = $this->application(['token_expires_at' => now()->subDay(), 'full_name' => 'Original']);

        $this->postJson(route('rental-applications.public.autosave', $expired->token), [
            'full_name' => 'Tampered',
        ])->assertOk()->assertJson(['saved' => false]);

        $expired->refresh();
        $this->assertSame('Original', $expired->full_name);
    }

    public function test_show_page_displays_a_draft_restored_banner_once_in_progress(): void
    {
        $inProgress = $this->application(['status' => 'in_progress', 'full_name' => 'Sipho Ndlovu', 'draft_saved_at' => now()->subMinutes(3)]);

        $this->get(route('rental-applications.public.show', $inProgress->token))
            ->assertOk()
            ->assertSee('restored', false);

        $freshlySent = $this->application(['status' => 'sent']);
        $this->get(route('rental-applications.public.show', $freshlySent->token))
            ->assertOk()
            ->assertDontSee('restored', false);
    }

    public function test_autosave_debounce_setting_has_a_sensible_default_and_is_agency_configurable(): void
    {
        $this->assertSame(
            RentalApplicationQualifyingSetting::DEFAULT_AUTOSAVE_DEBOUNCE_SECONDS,
            RentalApplicationQualifyingSetting::autosaveDebounceSecondsFor($this->agency->id),
            'an agency that has never configured this gets the sensible default'
        );

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['autosave_debounce_seconds' => 12],
        );

        $this->assertSame(12, RentalApplicationQualifyingSetting::autosaveDebounceSecondsFor($this->agency->id));
    }

    public function test_autosave_rate_limit_setting_has_a_sensible_default_and_is_agency_configurable(): void
    {
        $this->assertSame(
            RentalApplicationQualifyingSetting::DEFAULT_AUTOSAVE_RATE_LIMIT_MAX,
            RentalApplicationQualifyingSetting::autosaveRateLimitMaxFor($this->agency->id)
        );
        $this->assertSame(
            RentalApplicationQualifyingSetting::DEFAULT_AUTOSAVE_RATE_LIMIT_WINDOW_MINUTES,
            RentalApplicationQualifyingSetting::autosaveRateLimitWindowMinutesFor($this->agency->id)
        );

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['autosave_rate_limit_max' => 5, 'autosave_rate_limit_window_minutes' => 10],
        );

        $this->assertSame(5, RentalApplicationQualifyingSetting::autosaveRateLimitMaxFor($this->agency->id));
        $this->assertSame(10, RentalApplicationQualifyingSetting::autosaveRateLimitWindowMinutesFor($this->agency->id));
    }

    /**
     * Johan, 2026-09-12 (following his own audit questions on the public
     * autosave endpoint): "what stops a script from sending fifty
     * thousand?" — proves the per-APPLICATION volume cap actually trips,
     * that tripping it signals distinctly (`rate_limited: true`, not just
     * `saved: false` indistinguishable from every other silent-degrade
     * case), and — critically — that the draft already saved before the
     * cap was hit is completely undisturbed by hitting it. Configures a
     * tiny cap (3 per 10 minutes) for this one test rather than actually
     * firing thousands of requests — the mechanism is identical regardless
     * of the configured number.
     */
    public function test_autosave_rate_limit_trips_signals_distinctly_and_never_damages_the_existing_draft(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['autosave_rate_limit_max' => 3, 'autosave_rate_limit_window_minutes' => 10],
        );
        $application = $this->application();

        // First 3 saves succeed normally and land in the database.
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson(route('rental-applications.public.autosave', $application->token), [
                'full_name' => "Saved Before Cap {$i}",
            ])->assertOk()->assertJson(['saved' => true]);
        }
        $application->refresh();
        $this->assertSame('Saved Before Cap 3', $application->full_name, 'the last successful save before the cap landed correctly');
        $savedDraftAt = $application->draft_saved_at;

        // The 4th trips the cap — distinct signal, not just saved:false.
        $this->postJson(route('rental-applications.public.autosave', $application->token), [
            'full_name' => 'Should Never Be Saved',
        ])->assertOk()->assertJson(['saved' => false, 'rate_limited' => true]);

        // The already-saved draft is completely untouched — same value,
        // same timestamp, as before the capped attempt.
        $application->refresh();
        $this->assertSame('Saved Before Cap 3', $application->full_name, 'a rate-limited attempt must never overwrite the existing draft');
        $this->assertTrue($savedDraftAt->equalTo($application->draft_saved_at), 'draft_saved_at must not move on a rate-limited (non-)save');
    }

    /**
     * The rate-limit key must be the application, resolved from the TOKEN
     * in the URL — never anything the request body controls. Proves two
     * DIFFERENT applications (different tokens) each get their own,
     * independent budget: exhausting one's cap must never affect the
     * other's, which it would if the key were something shared/guessable
     * (e.g. IP, or a global bucket) rather than the token-resolved id.
     */
    public function test_autosave_rate_limit_is_keyed_per_application_not_shared(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['autosave_rate_limit_max' => 1, 'autosave_rate_limit_window_minutes' => 10],
        );
        $applicationA = $this->application();
        $otherContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Other', 'last_name' => 'Applicant', 'email' => 'other@example.co.za',
        ]);
        $applicationB = $this->application(['contact_id' => $otherContact->id, 'token' => Str::random(64)]);

        // Exhaust A's single-attempt budget.
        $this->postJson(route('rental-applications.public.autosave', $applicationA->token), ['full_name' => 'A'])
            ->assertJson(['saved' => true]);
        $this->postJson(route('rental-applications.public.autosave', $applicationA->token), ['full_name' => 'A again'])
            ->assertJson(['saved' => false, 'rate_limited' => true]);

        // B, a completely different application/token, is unaffected.
        $this->postJson(route('rental-applications.public.autosave', $applicationB->token), ['full_name' => 'B'])
            ->assertJson(['saved' => true]);
        $applicationB->refresh();
        $this->assertSame('B', $applicationB->full_name);
    }
}
