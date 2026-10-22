<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-487 - "Currently renting": the date pair reads From / To (in that order), "Still living here" means no end date,
 * and switching the living situation away from renting clears the renting-only answers on the record.
 * Spec: .ai/specs/rental-applications.md (AT-487).
 */
final class RentalApplicationRentingFieldsAt487Test extends TestCase
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
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'ta-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'in_progress',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    private function rentingAnswers(): array
    {
        return [
            'current_living_situation' => 'renting', 'current_landlord_name' => 'Mrs Smith', 'current_landlord_tel' => '0821112222',
            'current_rental_amount' => '6500', 'current_rental_due_day' => 1, 'current_rental_from' => '2024-02-01',
            'current_rental_to' => '2026-09-30',
        ];
    }

    public function test_the_applicant_form_shows_from_then_to_in_that_order(): void
    {
        $app = $this->application(['current_living_situation' => 'renting']);

        $html = $this->get(route('rental-applications.public.show', $app->token))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="current_rental_from"/', $html);
        // The end-date wrapper carries the same CSS order mechanism as its neighbours, after "From"'s.
        $this->assertMatchesRegularExpression('/x-data="\{ stillLiving[^"]*"\s+style="order: \d+"/', $html);
        $config = RentalApplication::resolvedFieldConfigFor($this->agency->id);
        $this->assertSame('From', $config['current_rental_from']['label']);
        $this->assertSame('To', $config['current_rental_to']['label']);
        $this->assertGreaterThan($config['current_rental_from']['order'], $config['current_rental_to']['order']);
        $this->assertLessThan($config['current_living_situation_notes']['order'], $config['current_rental_to']['order']);
    }

    public function test_the_agent_copy_has_the_same_order_and_label(): void
    {
        $app = $this->application(['current_living_situation' => 'renting']);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/x-data="\{ stillLiving[^"]*"\s+style="order: \d+"/', $html);
        $this->assertGreaterThan(strpos($html, 'name="current_rental_from"'), strpos($html, 'name="current_rental_to"'));
    }

    public function test_the_end_date_can_be_relabelled_and_hidden_like_its_neighbours(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['hidden_field_keys' => ['current_rental_to'], 'field_label_overrides' => ['current_rental_from' => 'Renting since']],
        );
        $app = $this->application(['current_living_situation' => 'renting']);

        $html = $this->get(route('rental-applications.public.show', $app->token))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="current_rental_to"', $html);
        $this->assertStringContainsString('Renting since', $html);
    }

    public function test_switching_away_from_renting_clears_the_renting_answers_on_autosave(): void
    {
        $app = $this->application($this->rentingAnswers() + ['current_rental_still_living' => false]);

        $this->postJson(route('rental-applications.public.autosave', $app->token), [
            'current_living_situation' => 'owns_or_selling',
            'current_landlord_name' => 'Mrs Smith', 'current_rental_amount' => '6500', 'current_rental_from' => '2024-02-01',
        ])->assertOk();

        $app->refresh();
        $this->assertSame('owns_or_selling', $app->current_living_situation);
        $this->assertNull($app->current_landlord_name);
        $this->assertNull($app->current_landlord_tel);
        $this->assertNull($app->current_rental_amount);
        $this->assertNull($app->current_rental_from);
        $this->assertNull($app->current_rental_to);
        $this->assertNull($app->current_rental_due_day);
    }

    public function test_a_partial_autosave_without_the_situation_key_never_wipes_the_renting_answers(): void
    {
        $app = $this->application($this->rentingAnswers());

        $this->postJson(route('rental-applications.public.autosave', $app->token), ['full_name' => 'Sipho Ndlovu'])->assertOk();

        $this->assertSame('Mrs Smith', $app->fresh()->current_landlord_name);
    }

    public function test_staying_on_renting_keeps_everything(): void
    {
        $app = $this->application($this->rentingAnswers());

        $this->postJson(route('rental-applications.public.autosave', $app->token), $this->rentingAnswers())->assertOk();

        $app->refresh();
        $this->assertSame('Mrs Smith', $app->current_landlord_name);
        $this->assertSame('2024-02-01', $app->current_rental_from->format('Y-m-d'));
    }

    public function test_the_agents_edit_clears_them_too(): void
    {
        $app = $this->application($this->rentingAnswers());

        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'current_living_situation' => 'living_with_family',
            'current_landlord_name' => 'Mrs Smith',
        ])->assertRedirect();

        $this->assertNull($app->fresh()->current_landlord_name);
        $this->assertNull($app->fresh()->current_rental_from);
    }

    public function test_still_living_here_means_no_end_date(): void
    {
        $app = $this->application($this->rentingAnswers());

        $this->postJson(route('rental-applications.public.autosave', $app->token), $this->rentingAnswers() + ['current_rental_still_living' => 1])->assertOk();

        $app->refresh();
        $this->assertNull($app->current_rental_to);
        $this->assertTrue((bool) $app->current_rental_still_living);
    }

    public function test_a_compulsory_end_date_is_not_demanded_when_still_living_here_is_ticked(): void
    {
        [$rules] = RentalApplication::submissionValidationRules(['current_rental_to'], ['current_living_situation' => 'renting', 'current_rental_still_living' => 1], $this->agency->id);
        $this->assertNotContains('required', $rules['current_rental_to']);

        [$rules] = RentalApplication::submissionValidationRules(['current_rental_to'], ['current_living_situation' => 'renting'], $this->agency->id);
        $this->assertContains('required', $rules['current_rental_to']);

        [$rules] = RentalApplication::submissionValidationRules(['current_rental_to'], ['current_living_situation' => 'owns_or_selling'], $this->agency->id);
        $this->assertNotContains('required', $rules['current_rental_to']);
    }
}
