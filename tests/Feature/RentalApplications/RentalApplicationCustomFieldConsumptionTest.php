<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationCustomField;
use App\Models\RentalApplicationGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/rental-application-field-config.md §7, piece (c)(3) — the
 * consumption side: every downstream reader of an application's own custom
 * field answers. "A custom field that appears on the form and nowhere else
 * is a broken feature" (Johan). Covers the agent's own editable
 * pre-submission capture form, the review screen's curated summary, and
 * the generation-history screen (a specific past submission round).
 *
 * Also locks in a real, self-inflicted bug found and fixed while building
 * this piece: writing the literal one-liner @php(...) directive syntax
 * inside a Blade COMMENT gets matched by Blade's raw-PHP extraction regex
 * exactly as if it were real code — comment or not — silently swallowing
 * everything after it. Found because a manual, standalone BladeCompiler
 * instance (no app-registered custom directives like @permission) gave a
 * false "compiles fine" result; only the fully-bootstrapped app compiler,
 * or a real HTTP walk, caught it for real.
 */
final class RentalApplicationCustomFieldConsumptionTest extends TestCase
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

    private function customField(array $attrs = []): RentalApplicationCustomField
    {
        $label = $attrs['label'] ?? 'Pet Details';

        return RentalApplicationCustomField::create(array_merge([
            'agency_id' => $this->agency->id,
            'key' => RentalApplicationCustomField::generateKey($this->agency->id, $label),
            'label' => $label,
            'field_type' => 'text',
            'sort_order' => 0,
        ], $attrs));
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'token' => Str::random(64),
        ], $attrs));
    }

    // ── Real bug this piece found: a comment containing the literal
    // one-liner directive syntax breaks Blade compilation ─────────────

    public function test_the_agent_editable_form_still_compiles_and_renders(): void
    {
        // Regression guard — a self-inflicted Blade comment mistake in
        // this exact file swallowed the Send/Resend button entirely
        // (compiled output had zero occurrences of $canSend) while
        // reporting a misleading "compiles fine" from a standalone
        // BladeCompiler instance. This is the real, fully-bootstrapped
        // render path — the one that actually would have caught it.
        $agent = $this->agent();
        $application = $this->application(['status' => 'draft']);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $application));

        $response->assertOk();
        $response->assertSee('Save', false);
    }

    public function test_the_review_screen_still_compiles_and_renders(): void
    {
        $agent = $this->agent();
        $application = $this->application(['status' => 'returned', 'submitted_at' => now()]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));

        $response->assertOk();
    }

    // ── Agent editable capture form ─────────────────────────────────────

    public function test_editable_capture_form_renders_an_active_custom_field(): void
    {
        $agent = $this->agent();
        $field = $this->customField(['label' => 'Pet Deposit']);
        $application = $this->application(['status' => 'draft']);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $application));

        $response->assertOk();
        $response->assertSee('Pet Deposit');
        $response->assertSee('name="custom_field_values[' . $field->key . ']"', false);
    }

    public function test_editable_capture_form_saves_custom_field_values(): void
    {
        $agent = $this->agent();
        $field = $this->customField();
        $application = $this->application(['status' => 'draft']);

        $response = $this->actingAs($agent)->put(route('corex.rental-applications.update', $application), [
            'custom_field_values' => [$field->key => 'Agent-typed answer'],
        ]);

        $response->assertRedirect(route('corex.rental-applications.show', $application));
        $this->assertSame('Agent-typed answer', $application->fresh()->custom_field_values[$field->key]);
    }

    public function test_editable_capture_form_never_enforces_a_required_custom_field(): void
    {
        // This screen's whole posture (fieldValidationRules(), no
        // requiredKeys reconciliation) is nullable-only for every shipped
        // field too — the applicant's own signed submission is where
        // requiredness is actually enforced, not the agent's draft capture.
        $agent = $this->agent();
        $this->customField(['required' => true]);
        $application = $this->application(['status' => 'draft']);

        $response = $this->actingAs($agent)->put(route('corex.rental-applications.update', $application), []);

        $response->assertSessionDoesntHaveErrors();
    }

    public function test_editable_capture_form_update_merges_never_replaces_custom_field_values(): void
    {
        $agent = $this->agent();
        $activeField = $this->customField(['label' => 'Pet Details']);
        $retiredField = $this->customField(['label' => 'Old Question']);
        $application = $this->application([
            'status' => 'draft',
            'custom_field_values' => [$retiredField->key => 'An answer from before it was retired'],
        ]);
        $retiredField->delete();

        $this->actingAs($agent)->put(route('corex.rental-applications.update', $application), [
            'custom_field_values' => [$activeField->key => 'New answer'],
        ])->assertSessionDoesntHaveErrors();

        $fresh = $application->fresh();
        $this->assertSame('New answer', $fresh->custom_field_values[$activeField->key]);
        $this->assertSame('An answer from before it was retired', $fresh->custom_field_values[$retiredField->key]);
    }

    // ── Review screen summary ───────────────────────────────────────────

    public function test_review_summary_shows_a_custom_fields_real_answer(): void
    {
        $agent = $this->agent();
        $field = $this->customField(['label' => 'Pet Details']);
        $application = $this->application([
            'status' => 'returned', 'submitted_at' => now(),
            'custom_field_values' => [$field->key => 'One small dog'],
            'field_config_snapshot' => RentalApplication::resolvedFieldConfigFor($this->agency->id),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));

        $response->assertOk();
        $response->assertSee('Pet Details');
        $response->assertSee('One small dog');
    }

    public function test_review_summary_never_shows_a_row_for_an_empty_custom_field(): void
    {
        $agent = $this->agent();
        $field = $this->customField(['label' => 'Pet Details']);
        $application = $this->application([
            'status' => 'returned', 'submitted_at' => now(),
            'field_config_snapshot' => RentalApplication::resolvedFieldConfigFor($this->agency->id),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));

        $response->assertOk();
        $response->assertDontSee('Pet Details');
    }

    public function test_review_summary_renders_a_yes_no_field_as_yes_or_no_not_raw_1_or_0(): void
    {
        $agent = $this->agent();
        $field = $this->customField(['label' => 'Has a Pet', 'field_type' => 'yes_no']);
        $application = $this->application([
            'status' => 'returned', 'submitted_at' => now(),
            'custom_field_values' => [$field->key => '1'],
            'field_config_snapshot' => RentalApplication::resolvedFieldConfigFor($this->agency->id),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));

        $response->assertSee('Has a Pet');
        $response->assertSee('Yes');
    }

    // ── Generation history — the actual historical-record screen ──────

    public function test_generation_seal_merges_custom_field_values_into_the_hash_protected_snapshot(): void
    {
        $field = $this->customField();
        $application = $this->application([
            'status' => 'returned', 'submitted_at' => now(), 'current_generation' => 1,
            'custom_field_values' => [$field->key => 'One small dog'],
        ]);

        $gen = RentalApplicationGeneration::seal($application, \Illuminate\Http\Request::create('/fake', 'POST'));

        $this->assertSame('One small dog', $gen->snapshot_json[$field->key]);
        $result = RentalApplicationGeneration::verifyChain($application->id);
        $this->assertTrue($result['ok'], 'custom field values must be covered by the SAME hash-chain tamper-evidence as every shipped field');
    }

    public function test_generation_screen_shows_a_custom_fields_answer_with_its_frozen_label(): void
    {
        $agent = $this->agent();
        $field = $this->customField(['label' => 'Pet Details']);
        $application = $this->application([
            'status' => 'returned', 'submitted_at' => now(), 'current_generation' => 1,
            'custom_field_values' => [$field->key => 'One small dog'],
        ]);
        RentalApplicationGeneration::seal($application, \Illuminate\Http\Request::create('/fake', 'POST'));

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.generations.show', [$application, 1]));

        $response->assertOk();
        $response->assertSee('Pet Details');
        $response->assertSee('One small dog');
    }

    public function test_generation_screen_renders_a_yes_no_custom_field_as_yes_or_no(): void
    {
        $agent = $this->agent();
        $field = $this->customField(['label' => 'Has a Pet', 'field_type' => 'yes_no']);
        $application = $this->application([
            'status' => 'returned', 'submitted_at' => now(), 'current_generation' => 1,
            'custom_field_values' => [$field->key => '1'],
        ]);
        RentalApplicationGeneration::seal($application, \Illuminate\Http\Request::create('/fake', 'POST'));

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.generations.show', [$application, 1]));

        $response->assertSee('Has a Pet');
        $response->assertSee('Yes');
    }

    public function test_a_later_hidden_custom_field_still_shows_its_earlier_generations_answer(): void
    {
        $agent = $this->agent();
        $field = $this->customField(['label' => 'Pet Details']);
        $application = $this->application([
            'status' => 'returned', 'submitted_at' => now()->subDay(), 'current_generation' => 1,
            'custom_field_values' => [$field->key => 'One small dog'],
        ]);
        RentalApplicationGeneration::seal($application, \Illuminate\Http\Request::create('/fake', 'POST'));

        // Field hidden AFTER round 1 was sealed — round 1's own screen must
        // still show what it actually captured.
        $field->update(['shown' => false]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.generations.show', [$application, 1]));

        $response->assertSee('Pet Details');
        $response->assertSee('One small dog');
    }
}
