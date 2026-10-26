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
 * Fuller application (26 Oct 2026, spec rental-applications.md "Fuller application"): the facts and blocks a letting agency's own
 * paper application asks that the first form did not. Proves the applicant form, the autosave, the agent's capture form and the
 * printed application all carry them, that a block's blank rows are never stored, that an absent key never wipes a saved block,
 * and that the agency's own field settings (hidden / compulsory) govern the new fields like every other.
 */
final class RentalApplicationFullerFormTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'Mega Real Estate', 'slug' => 'mega-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Head Office']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'email' => 'thandi@example.co.za',
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

    public function test_the_applicant_form_asks_for_every_new_fact_and_block(): void
    {
        $app = $this->application();

        $html = $this->get(route('rental-applications.public.show', $app->token))->assertOk()->getContent();

        foreach (['income_tax_no', 'marriage_regime', 'years_employed', 'pay_date', 'previous_landlord_name', 'previous_landlord_tel',
                  'bank_name', 'bank_account_no', 'judgments', 'judgments_details', 'car_make', 'car_colour', 'car_year', 'car_reg'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html, "the form has no '{$name}' input");
        }
        foreach (['full_name', 'id_number', 'income_tax_no', 'bank_account_no', 'judgments', 'car_reg'] as $coKey) {
            $this->assertStringContainsString('name="co_applicant[' . $coKey . ']"', $html, "the co-applicant block has no '{$coKey}'");
        }
        $this->assertStringContainsString("'co_occupants[' + i + '][relationship]'", $html);
        $this->assertStringContainsString("'pets[' + i + '][breed]'", $html);
        $this->assertStringContainsString("'affordability[income][' + i + '][amount]'", $html);
        $this->assertStringContainsString("'affordability[expenses][' + i + '][label]'", $html);
        $this->assertStringContainsString('Add another person', $html);
    }

    public function test_autosave_stores_the_new_facts_and_blocks_and_drops_blank_rows(): void
    {
        $app = $this->application();

        $this->postJson(route('rental-applications.public.autosave', $app->token), [
            'income_tax_no' => '9012345678', 'marriage_regime' => 'Out of community of property, with accrual',
            'years_employed' => '4', 'pay_date' => 'the 25th', 'previous_landlord_name' => 'Mr Botha', 'previous_landlord_tel' => '0821234567',
            'bank_name' => 'ABSA', 'bank_account_no' => '4071234567', 'judgments' => 'no',
            'car_make' => 'Toyota', 'car_colour' => 'White', 'car_year' => '2019', 'car_reg' => 'CA 123 456',
            'co_applicant' => ['full_name' => 'Pieter Mokoena', 'id_number' => '8501015800083', 'monthly_salary' => 'R 18 500,50', 'judgments' => 'maybe', 'bank_name' => ''],
            'co_occupants' => [
                ['name' => 'Lerato', 'relationship' => 'Daughter', 'age' => '7'],
                ['name' => '', 'relationship' => '', 'age' => ''],
            ],
            'pets' => [['type' => 'Dog', 'breed' => 'Labrador', 'size' => 'Large'], ['type' => ' ', 'breed' => '', 'size' => '']],
            'affordability' => [
                'income' => [['label' => 'Salary', 'amount' => 'R 25 000'], ['label' => 'Other income', 'amount' => '']],
                'expenses' => [['label' => 'Groceries', 'amount' => '4 500,00'], ['label' => '', 'amount' => '999']],
            ],
        ])->assertOk()->assertJson(['saved' => true]);

        $app->refresh();
        $this->assertSame('9012345678', $app->income_tax_no);
        $this->assertSame('ABSA', $app->bank_name);
        $this->assertSame('no', $app->judgments);
        $this->assertSame('CA 123 456', $app->car_reg);
        $this->assertEquals(['full_name' => 'Pieter Mokoena', 'id_number' => '8501015800083', 'monthly_salary' => '18500.50'], $app->co_applicant, 'a bad yes/no answer and a blank cell are not stored (MySQL JSON does not keep key order)');
        $this->assertEquals([['name' => 'Lerato', 'relationship' => 'Daughter', 'age' => '7']], $app->co_occupants, 'the blank row is dropped');
        $this->assertEquals([['type' => 'Dog', 'breed' => 'Labrador', 'size' => 'Large']], $app->pets);
        $this->assertEquals(['income' => [['label' => 'Salary', 'amount' => '25000.00']], 'expenses' => [['label' => 'Groceries', 'amount' => '4500.00']]], $app->affordability);
        $this->assertSame(25000.0, $app->affordabilityTotal('income'));
        $this->assertSame(4500.0, $app->affordabilityTotal('expenses'));
    }

    public function test_a_later_autosave_that_does_not_carry_a_block_never_wipes_it(): void
    {
        $app = $this->application(['pets' => [['type' => 'Cat']], 'co_occupants' => [['name' => 'Lerato']]]);

        $this->postJson(route('rental-applications.public.autosave', $app->token), ['full_name' => 'Thandi Mokoena'])->assertOk();

        $app->refresh();
        $this->assertEquals([['type' => 'Cat']], $app->pets);
        $this->assertEquals([['name' => 'Lerato']], $app->co_occupants);
        $this->assertSame('Thandi Mokoena', $app->full_name);
    }

    public function test_a_block_posted_empty_clears_it(): void
    {
        $app = $this->application(['pets' => [['type' => 'Cat']]]);

        $this->postJson(route('rental-applications.public.autosave', $app->token), ['pets' => [['type' => '', 'breed' => '', 'size' => '']]])->assertOk();

        $this->assertNull($app->refresh()->pets);
    }

    public function test_a_malformed_block_is_not_stored(): void
    {
        $app = $this->application();

        $this->postJson(route('rental-applications.public.autosave', $app->token), [
            'co_occupants' => [['name' => ['nested' => 'x']]],
            'full_name' => 'Thandi Mokoena',
        ])->assertOk();

        $app->refresh();
        $this->assertNull($app->co_occupants);
        $this->assertSame('Thandi Mokoena', $app->full_name, 'the rest of the autosave still lands');
    }

    public function test_the_agency_can_hide_a_new_field_and_the_form_obeys(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], ['hidden_field_keys' => ['bank_name', 'pets']]);
        $app = $this->application();

        $html = $this->get(route('rental-applications.public.show', $app->token))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="bank_name"', $html);
        $this->assertStringNotContainsString("'pets[' + i + '][type]'", $html);
        $this->assertStringContainsString('name="bank_account_no"', $html, 'a field that is not hidden still shows');
    }

    public function test_a_compulsory_new_field_is_enforced_only_where_it_applies(): void
    {
        $required = ['bank_name', 'years_employed', 'marriage_regime', 'co_occupants'];

        [$rules] = RentalApplication::submissionValidationRules($required, ['employment_type' => 'business_owner_personal_account', 'marital_status' => 'Single'], $this->agency->id);
        $this->assertContains('required', $rules['bank_name']);
        $this->assertContains('required', $rules['co_occupants']);
        $this->assertNotContains('required', $rules['years_employed'], 'employment length is only asked of the permanently employed');
        $this->assertNotContains('required', $rules['marriage_regime'], 'how married is only asked when the marital status implies a spouse');

        [$rules] = RentalApplication::submissionValidationRules($required, ['employment_type' => 'permanently_employed', 'marital_status' => 'Married'], $this->agency->id);
        $this->assertContains('required', $rules['years_employed']);
        $this->assertContains('required', $rules['marriage_regime']);
    }

    public function test_the_agent_capture_form_carries_them_and_saves_them(): void
    {
        $app = $this->application(['status' => 'draft']);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app))->assertOk()->getContent();
        $this->assertStringContainsString('name="income_tax_no"', $html);
        $this->assertStringContainsString("'co_occupants[' + i + '][name]'", $html);

        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'expected_updated_at' => $app->updated_at?->timestamp,
            'bank_name' => 'FNB', 'car_make' => 'VW',
            'pets' => [['type' => 'Cat', 'breed' => '', 'size' => 'Small']],
        ])->assertRedirect(route('corex.rental-applications.show', $app));

        $app->refresh();
        $this->assertSame('FNB', $app->bank_name);
        $this->assertSame('VW', $app->car_make);
        $this->assertEquals([['type' => 'Cat', 'size' => 'Small']], $app->pets);
    }

    public function test_the_printed_application_carries_the_new_facts_and_blocks(): void
    {
        $app = $this->application([
            'full_name' => 'Thandi Mokoena', 'income_tax_no' => '9012345678', 'marriage_regime' => 'In community of property',
            'years_employed' => '4', 'pay_date' => 'the 25th', 'previous_landlord_name' => 'Mr Botha',
            'bank_name' => 'ABSA', 'bank_account_no' => '4071234567', 'judgments' => 'yes', 'judgments_details' => 'Settled 2023',
            'car_make' => 'Toyota', 'car_reg' => 'CA 123 456',
            'co_applicant' => ['full_name' => 'Pieter Mokoena', 'bank_name' => 'Nedbank'],
            'co_occupants' => [['name' => 'Lerato', 'relationship' => 'Daughter', 'age' => '7']],
            'pets' => [['type' => 'Dog', 'breed' => 'Labrador', 'size' => 'Large']],
            'affordability' => ['income' => [['label' => 'Salary', 'amount' => '25000.00']], 'expenses' => [['label' => 'Groceries', 'amount' => '4500.00']]],
        ]);

        $html = view('corex.rental-applications.pdf', ['application' => $app, 'agency' => $this->agency, 'branch' => $this->branch])->render();

        foreach (['9012345678', 'In community of property', 'the 25th', 'Mr Botha', 'ABSA', '4071234567', 'Settled 2023', 'Toyota', 'CA 123 456',
                  'Pieter Mokoena', 'Nedbank', 'Lerato', 'Daughter', 'Labrador', 'R 25,000.00', 'R 4,500.00', 'Total income', 'Banking and Vehicle',
                  'Other People Who Will Live in the Property', 'Monthly Income and Expenses'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_a_blank_printed_application_still_gives_room_to_write(): void
    {
        $app = $this->application(['status' => 'draft']);

        $html = view('corex.rental-applications.pdf', ['application' => $app, 'agency' => $this->agency, 'branch' => $this->branch])->render();

        $this->assertStringContainsString('Co-applicant / Spouse', $html);
        $this->assertGreaterThanOrEqual(3, preg_match_all('/<td>(?:&nbsp;|\\xC2\\xA0)<\/td>/', $html), 'three empty co-occupant rows to write in');
        $this->assertStringContainsString('Bond / current rent', $html, 'the standard expense lines print so the tenant can fill them in by hand');
    }
}
