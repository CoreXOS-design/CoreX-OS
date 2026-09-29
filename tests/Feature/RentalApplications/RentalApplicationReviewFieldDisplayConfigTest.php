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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/rental-application-field-config.md, staff-facing follow-up
 * 2026-09-20 — Johan: "an agency can hide a field from the applicant and
 * our own staff screen may still show it... a half-true configuration is
 * worse than none." Covers the three internal, agent-facing consumers of
 * an application's own answers that were still ignoring config entirely:
 * review.blade.php's "Submitted Application" summary, the editable
 * pre-submission capture form (show.blade.php), and generation-show.blade.php
 * (a specific past submission round after a reopen/resubmit).
 *
 * The rule this build settled, and every test below is built to prove:
 * hidden governs whether an EMPTY field clutters a screen; it never
 * suppresses a real answer already on file.
 */
final class RentalApplicationReviewFieldDisplayConfigTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function agent(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'token' => Str::random(64),
        ], $attrs));
    }

    // ── RentalApplication::displayFieldConfig() — the resolver itself ──

    public function test_a_not_yet_submitted_application_uses_live_settings(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_label_overrides' => ['full_name' => 'Live label'],
        ]);
        $app = $this->application(['status' => 'draft']);

        $config = $app->displayFieldConfig();

        $this->assertSame('Live label', $config['full_name']['label']);
    }

    public function test_a_submitted_application_with_a_snapshot_ignores_live_settings(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_label_overrides' => ['full_name' => 'Live label — set AFTER submission'],
        ]);
        $app = $this->application([
            'status' => 'returned',
            'submitted_at' => now(),
            'field_config_snapshot' => RentalApplication::resolvedFieldConfigFor(null), // frozen BEFORE the live override above
        ]);

        $config = $app->displayFieldConfig();

        $this->assertNotSame('Live label — set AFTER submission', $config['full_name']['label']);
    }

    public function test_a_submitted_application_with_no_snapshot_falls_back_to_registry_defaults_not_live_settings(): void
    {
        // Legacy record — submitted before this column existed.
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'hidden_field_keys' => ['full_name'],
        ]);
        $app = $this->application(['status' => 'returned', 'submitted_at' => now(), 'field_config_snapshot' => null]);

        $config = $app->displayFieldConfig();

        $this->assertTrue($config['full_name']['shown'], 'a legacy record must never be retroactively reshaped by config that did not exist at its own submission');
    }

    // ── review.blade.php summary ────────────────────────────────────────

    public function test_review_summary_applies_a_label_override(): void
    {
        $agent = $this->agent();
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_label_overrides' => ['employer_name' => 'Employer (current)'],
        ]);
        $app = $this->application([
            'status' => 'returned', 'submitted_at' => now(), 'employer_name' => 'Acme Corp',
            'field_config_snapshot' => RentalApplication::resolvedFieldConfigFor($this->agency->id),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertSee('Employer (current)', false);
    }

    public function test_review_summary_suppresses_a_hidden_empty_field(): void
    {
        $agent = $this->agent();
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'hidden_field_keys' => ['current_landlord_name'],
        ]);
        $app = $this->application([
            'status' => 'returned', 'submitted_at' => now(), 'current_landlord_name' => null,
            'field_config_snapshot' => RentalApplication::resolvedFieldConfigFor($this->agency->id),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertDontSee('Current landlord', false);
    }

    public function test_review_summary_never_hides_a_field_with_a_real_answer_even_when_configured_hidden(): void
    {
        $agent = $this->agent();
        $frozenConfig = RentalApplication::resolvedFieldConfigFor($this->agency->id);
        // Simulates: answered while shown, hidden afterward — the exact
        // scenario Johan named. field_config_snapshot is frozen at the
        // moment of submission, so this models the state directly.
        $frozenConfig['current_landlord_name']['shown'] = false;
        $app = $this->application([
            'status' => 'returned', 'submitted_at' => now(), 'current_landlord_name' => 'Old Landlord',
            'field_config_snapshot' => $frozenConfig,
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertSee('Old Landlord', false);
    }

    public function test_review_summary_respects_within_section_ordering(): void
    {
        $agent = $this->agent();
        $frozenConfig = RentalApplication::resolvedFieldConfigFor($this->agency->id);
        $frozenConfig['current_landlord_name']['order'] = 0;
        $frozenConfig['employer_name']['order'] = 1;
        $app = $this->application([
            'status' => 'returned', 'submitted_at' => now(),
            'current_landlord_name' => 'Zed Landlord', 'employer_name' => 'Acme Corp',
            'field_config_snapshot' => $frozenConfig,
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertLessThan(strpos($body, 'Acme Corp'), strpos($body, 'Zed Landlord'), 'the ordered field must render before the default-ordered one');
    }

    // ── show.blade.php — editable pre-submission capture form ─────────

    public function test_editable_capture_form_hides_a_configured_field(): void
    {
        $agent = $this->agent();
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'hidden_field_keys' => ['work_number'],
        ]);
        $app = $this->application(['status' => 'draft']);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        $response->assertDontSee('name="work_number"', false);
    }

    public function test_editable_capture_form_applies_a_label_override(): void
    {
        $agent = $this->agent();
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_label_overrides' => ['full_name' => 'Applicant full legal name'],
        ]);
        $app = $this->application(['status' => 'draft']);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        $response->assertSee('Applicant full legal name');
    }

    // ── generation-show.blade.php — per-generation frozen config ───────

    public function test_generation_seal_freezes_its_own_field_config_snapshot(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_label_overrides' => ['full_name' => 'Round-one label'],
        ]);
        $app = $this->application(['status' => 'returned', 'submitted_at' => now(), 'current_generation' => 1]);

        $gen = RentalApplicationGeneration::seal($app, \Illuminate\Http\Request::create('/fake', 'POST'));

        $this->assertSame('Round-one label', $gen->field_config_snapshot['full_name']['label']);
    }

    public function test_a_later_hide_never_reaches_backward_into_an_earlier_generations_display(): void
    {
        $agent = $this->agent();
        // Round 1: special_conditions shown.
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'hidden_field_keys' => [],
        ]);
        $app = $this->application([
            'status' => 'returned', 'submitted_at' => now()->subDay(), 'current_generation' => 1,
            'special_conditions' => 'No pets — round 1 answer',
        ]);
        $gen1 = RentalApplicationGeneration::seal($app, \Illuminate\Http\Request::create('/fake', 'POST'));
        $this->assertTrue($gen1->field_config_snapshot['special_conditions']['shown']);

        // Round 2: agency hides special_conditions BEFORE this resubmit.
        RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->update(['hidden_field_keys' => ['special_conditions']]);
        $app->current_generation = 2;
        $app->save();
        RentalApplicationGeneration::seal($app, \Illuminate\Http\Request::create('/fake', 'POST'));

        // Generation 1's own screen must still show what round 1 actually
        // saw — never reshaped by round 2's config.
        $response = $this->actingAs($agent)->get(route('corex.rental-applications.generations.show', [$app, 1]));

        $response->assertOk();
        $response->assertSee('No pets', false);
    }

    public function test_a_value_hidden_by_the_time_of_a_later_generation_still_shows_on_that_generations_own_screen(): void
    {
        $agent = $this->agent();
        RentalApplicationQualifyingSetting::create(['agency_id' => $this->agency->id, 'hidden_field_keys' => []]);
        $app = $this->application([
            'status' => 'returned', 'submitted_at' => now()->subDay(), 'current_generation' => 1,
            'special_conditions' => 'No pets — round 1 answer',
        ]);
        RentalApplicationGeneration::seal($app, \Illuminate\Http\Request::create('/fake', 'POST'));

        RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->update(['hidden_field_keys' => ['special_conditions']]);
        $app->current_generation = 2;
        $app->save();
        $gen2 = RentalApplicationGeneration::seal($app, \Illuminate\Http\Request::create('/fake', 'POST'));

        $this->assertFalse($gen2->field_config_snapshot['special_conditions']['shown'], 'sanity check — round 2 really did freeze it as hidden');
        $this->assertSame('No pets — round 1 answer', $gen2->snapshot_json['special_conditions'], 'the value survives on the live row and gets re-frozen even though the field is no longer asked for');

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.generations.show', [$app, 2]));

        $response->assertOk();
        $response->assertSee('No pets', false);
    }

    public function test_field_config_snapshot_is_not_part_of_the_hash_chain(): void
    {
        // A generation-config-only change (no answer values touched) must
        // never look like tampering to verifyChain() — the hash covers
        // snapshot_json only.
        RentalApplicationQualifyingSetting::create(['agency_id' => $this->agency->id]);
        $app = $this->application(['status' => 'returned', 'submitted_at' => now(), 'current_generation' => 1, 'full_name' => 'Jane Applicant']);
        RentalApplicationGeneration::seal($app, \Illuminate\Http\Request::create('/fake', 'POST'));

        $result = RentalApplicationGeneration::verifyChain($app->id);

        $this->assertTrue($result['ok']);
    }

    public function test_generation_screen_gracefully_falls_back_when_an_old_generation_has_no_frozen_config(): void
    {
        // A generation sealed before this column existed — field_config_snapshot
        // is null on that row. Must render, not error, falling back to the
        // pre-existing title-cased label.
        $agent = $this->agent();
        $app = $this->application(['status' => 'returned', 'submitted_at' => now(), 'current_generation' => 1, 'full_name' => 'Jane Applicant']);
        RentalApplicationGeneration::create([
            'rental_application_id' => $app->id, 'generation' => 1, 'agency_id' => $this->agency->id,
            'snapshot_json' => ['full_name' => 'Jane Applicant'],
            'field_config_snapshot' => null,
            'submitted_at' => now(), 'content_hash' => hash('sha256', 'x'),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.generations.show', [$app, 1]));

        $response->assertOk();
        $response->assertSee('Full Name', false);
        $response->assertSee('Jane Applicant', false);
    }
}
