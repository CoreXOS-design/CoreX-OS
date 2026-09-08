<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-392 — Johan, QA1, end of day: "no user action on either form may
 * EVER discard typed input." Three real defects — the agent form
 * reverting four fields on validation failure, the applicant's email not
 * persisting, and the public form wiping everything on a document
 * upload — were each found and fixed as an instance. This file locks in
 * the CLASS: every route that posts to either the agent-side or public
 * applicant-side rental application form, proven not to discard input on
 * every failure path a full sweep identified.
 *
 * Two more real defects found DURING this sweep (not previously known):
 * 1. create.blade.php's contact/property picker was Alpine state seeded
 *    from nothing — a failed store() wiped the agent's search-and-select
 *    work even though old() had the ids the whole time.
 * 2. The two signature canvases had no old() on their hidden inputs and
 *    nothing redrew them — a validation failure on an unrelated field
 *    wiped both hand-drawn signatures, forcing the applicant to re-sign.
 *
 * A third gap — already-submitted.blade.php still used the OLD
 * synchronous form-POST-and-reload upload mechanism the main form was
 * already fixed away from — is covered in RentalApplicationAsyncUploadTest
 * alongside the rest of that page's behaviour.
 */
final class RentalApplicationInputPreservationTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
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
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    // ── Agent-side create() — new defect found this sweep ────────────────

    public function test_create_form_redisplays_the_selected_contact_and_property_after_a_validation_failure(): void
    {
        $property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Flat to let in Ramsgate', 'status' => 'active', 'property_type' => 'flat', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '9 Beach Road',
        ]);

        // A stale property_id (deleted between search and submit) is the
        // realistic way this fails validation in practice.
        $response = $this->actingAs($this->agent)->from(route('corex.rental-applications.create'))
            ->post(route('corex.rental-applications.store'), [
                'contact_id' => $this->contact->id,
                'property_id' => 999999,
            ]);

        $response->assertRedirect(route('corex.rental-applications.create'));
        $response->assertSessionHasErrors('property_id');

        $create = $this->actingAs($this->agent)->get(route('corex.rental-applications.create'));
        $create->assertOk();
        $create->assertSee('Sipho Ndlovu');
        $create->assertSee((string) $this->contact->id, false);

        // A valid property_id must still redisplay too, not just a contact.
        $response2 = $this->actingAs($this->agent)->from(route('corex.rental-applications.create'))
            ->post(route('corex.rental-applications.store'), [
                'contact_id' => 999999,
                'property_id' => $property->id,
            ]);
        $response2->assertSessionHasErrors('contact_id');

        $create2 = $this->actingAs($this->agent)->get(route('corex.rental-applications.create'));
        $create2->assertSee('Flat to let in Ramsgate');
        $create2->assertSee((string) $property->id, false);
    }

    // ── Public applicant form — signature preservation (new defect) ──────

    public function test_a_validation_failure_on_an_unrelated_field_preserves_both_signatures(): void
    {
        $app = $this->application(['status' => 'sent']);
        $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $response = $this->from(route('rental-applications.public.show', $app->token))
            ->post(route('rental-applications.public.submit', $app->token), [
                'declaration_signature' => $sig,
                'tpn_consent_signature' => $sig,
                'current_rental_amount' => 'not-a-number', // forces the failure
            ]);

        $response->assertRedirect(route('rental-applications.public.show', $app->token));
        $response->assertSessionHasErrors('current_rental_amount');
        $this->assertSame('sent', $app->fresh()->status, 'Nothing was saved — the whole submission failed together.');

        $show = $this->get(route('rental-applications.public.show', $app->token));
        $show->assertOk();
        // Both hidden signature inputs must still carry the drawn data —
        // not blank, not requiring the applicant to sign again.
        $show->assertSee('value="' . $sig . '"', false);
    }

    public function test_every_typed_field_on_the_public_form_survives_a_validation_failure(): void
    {
        $app = $this->application(['status' => 'sent']);
        $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $payload = [
            'full_name' => 'Jane Applicant', 'id_number' => '9001015800083',
            'marital_status' => 'Single', 'citizenship' => 'South African',
            'spouse_name' => '', 'spouse_id' => '',
            'email' => 'jane@example.com', 'cell' => '0821234567', 'work_number' => '0399121111',
            'current_residential_address' => '1 Old Road, Ramsgate',
            'emergency_contact_name' => 'John Doe', 'emergency_contact_cell' => '0827654321', 'emergency_contact_work' => '',
            'current_landlord_name' => 'ABC Rentals', 'current_landlord_tel' => '0399123456',
            'current_rental_amount' => 7500,
            'employment_type' => 'permanently_employed',
            'employer_name' => 'Acme Corp', 'employer_position' => 'Manager', 'employer_tel' => '0399111222',
            'monthly_salary' => 25000, 'employer_address' => '2 Main Road, Margate',
            'rental_term_months' => 12, 'adults' => 'not-a-number', // forces the failure
            'children' => 1, 'special_conditions' => 'Has a small dog',
            'declaration_signature' => $sig, 'tpn_consent_signature' => $sig,
        ];

        $this->from(route('rental-applications.public.show', $app->token))
            ->post(route('rental-applications.public.submit', $app->token), $payload)
            ->assertSessionHasErrors('adults');

        $show = $this->get(route('rental-applications.public.show', $app->token));
        $show->assertOk();
        foreach ([
            'Jane Applicant', '9001015800083', 'Single', 'South African', 'jane@example.com',
            '0821234567', '0399121111', '1 Old Road, Ramsgate', 'John Doe', '0827654321',
            'ABC Rentals', '0399123456', 'Acme Corp', 'Manager', '0399111222',
            '2 Main Road, Margate', 'Has a small dog',
        ] as $typed) {
            $show->assertSee($typed);
        }
        $show->assertSee('permanently_employed', false);
        // rental_term_months is a quick-button choice (6/12/24), not free
        // text — preserved via the Alpine x-data seed, not an old() echo
        // inside a visible field.
        $show->assertSee('months: 12', false);
    }

    // ── Round 4's own status form — note preservation ─────────────────────

    public function test_the_status_note_survives_a_rejected_status_change(): void
    {
        $app = $this->application(['status' => 'returned']);

        $this->actingAs($this->agent)->from(route('corex.rental-applications.show', $app))
            ->post(route('corex.rental-applications.update-status', $app), [
                'status' => 'sent', // rejected — system-owned
                'note' => 'Checked the payslips, all good.',
            ]);

        $show = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));
        $show->assertOk();
        $show->assertSee('Checked the payslips, all good.');
    }

    // ── RA-02 (cc5, independent testing): "a real person types 15,000
    // because that is how South Africans write money, and gets 'must be a
    // number'." Fixed the class — every numeric field, both forms. ───────

    public function test_agent_side_numeric_fields_accept_comma_formatted_south_african_money(): void
    {
        $app = $this->application();

        $response = $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'monthly_salary' => '15,000',
            'current_rental_amount' => 'R 8,500.50',
            'adults' => '2',
            'children' => '1',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $app->refresh();
        $this->assertSame('15000.00', $app->monthly_salary);
        $this->assertSame('8500.50', $app->current_rental_amount);
    }

    public function test_public_side_numeric_fields_accept_comma_formatted_south_african_money(): void
    {
        $app = $this->application(['status' => 'sent']);
        $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $response = $this->post(route('rental-applications.public.submit', $app->token), [
            'monthly_salary' => '25 000',
            'current_rental_amount' => '7,500',
            'declaration_signature' => $sig,
            'tpn_consent_signature' => $sig,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $app->refresh();
        $this->assertSame('returned', $app->status);
        $this->assertSame('25000.00', $app->monthly_salary);
        $this->assertSame('7500.00', $app->current_rental_amount);
    }

    public function test_a_genuinely_invalid_numeric_value_is_still_rejected_after_sanitizing(): void
    {
        $app = $this->application();

        $response = $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'monthly_salary' => 'not money at all',
        ]);

        $response->assertSessionHasErrors('monthly_salary');
        $this->assertNull($app->fresh()->monthly_salary);
    }
}
