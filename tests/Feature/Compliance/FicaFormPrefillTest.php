<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Services\Compliance\FicaFormPrefill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cape walk, 14 Oct 2026 - the public FICA form opened EMPTY for a tenant or landlord CoreX already knows. It now opens with their
 * name, ID, address, phone and email filled in from the contact record, every field still editable, and nothing from another
 * person or agency ever appears.
 */
final class FicaFormPrefillTest extends TestCase
{
    use RefreshDatabase;

    private function agency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
    }

    private function submissionFor(Agency $agency, array $contact): FicaSubmission
    {
        $branch = Branch::firstOrCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $c = Contact::create(array_merge(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Thandi', 'last_name' => 'Ngcobo'], $contact));

        return FicaSubmission::create([
            'contact_id' => $c->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id, 'requested_by' => $agent->id,
            'status' => 'draft', 'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    public function test_a_known_person_gets_their_details_filled_in(): void
    {
        $s = $this->submissionFor($this->agency('Cape Rentals'), [
            'id_number' => '8001015009087', 'id_type' => 'sa_id', 'phone' => '0821234567', 'email' => 'thandi@example.test',
            'address' => '12 Kloof Street, Gardens, Cape Town',
        ]);

        $p = FicaFormPrefill::for($s);

        $this->assertSame('natural', $p['entity_type']);
        $this->assertSame([
            'full_name' => 'Thandi Ngcobo', 'id_number' => '8001015009087', 'sa_citizen' => 'yes',
            'residential_address' => '12 Kloof Street, Gardens, Cape Town', 'phone' => '0821234567', 'email' => 'thandi@example.test', 'tax_number' => '',
        ], $p['personal']);
    }

    public function test_the_page_carries_the_filled_in_values_into_the_form_and_they_stay_editable(): void
    {
        $s = $this->submissionFor($this->agency('Cape Rentals'), ['id_number' => '8001015009087', 'id_type' => 'sa_id', 'email' => 'thandi@example.test', 'phone' => '0821234567', 'address' => '12 Kloof Street']);

        $html = $this->get(route('fica.form', $s->token))->assertOk()->getContent();

        $this->assertStringContainsString('Thandi Ngcobo', $html);
        $this->assertStringContainsString('8001015009087', $html);
        $this->assertStringContainsString('12 Kloof Street', $html);
        $this->assertMatchesRegularExpression('/entityType: [\'"]natural[\'"]/', $html);
        // Still ordinary editable inputs bound to that data - not read-only, not disabled.
        $this->assertStringContainsString('name="personal[full_name]" class="fica-input" required x-model="personal.full_name"', $html);
        $this->assertStringNotContainsString('readonly', substr($html, strpos($html, 'personal[full_name]') - 80, 400));
    }

    public function test_a_passport_holder_is_marked_not_an_sa_citizen(): void
    {
        $s = $this->submissionFor($this->agency('Cape Rentals'), ['passport_number' => 'A1234567']);

        $p = FicaFormPrefill::for($s)['personal'];

        $this->assertSame('A1234567', $p['id_number']);
        $this->assertSame('no', $p['sa_citizen']);
    }

    public function test_an_id_with_no_recorded_type_is_read_from_its_shape(): void
    {
        $sa = FicaFormPrefill::for($this->submissionFor($this->agency('A'), ['id_number' => '8001015009087']))['personal'];
        $odd = FicaFormPrefill::for($this->submissionFor($this->agency('B'), ['id_number' => 'XY99']))['personal'];

        $this->assertSame('yes', $sa['sa_citizen']);
        $this->assertSame('', $odd['sa_citizen'], 'not guessed when the shape is not an SA ID');
    }

    public function test_without_a_free_text_address_the_structured_address_is_used(): void
    {
        $s = $this->submissionFor($this->agency('Cape Rentals'), ['street_number' => '5', 'street_name' => 'Long Street', 'suburb' => 'Gardens', 'city' => 'Cape Town', 'province' => 'Western Cape']);

        $this->assertSame('5 Long Street, Gardens, Cape Town, Western Cape', FicaFormPrefill::for($s)['personal']['residential_address']);
    }

    public function test_a_contact_with_nothing_recorded_opens_blank_but_as_a_natural_person(): void
    {
        $s = $this->submissionFor($this->agency('Cape Rentals'), []);

        $p = FicaFormPrefill::for($s);

        $this->assertSame('natural', $p['entity_type']);
        $this->assertSame('', $p['personal']['id_number']);
        $this->assertSame('', $p['personal']['phone']);
        $this->assertSame('Thandi Ngcobo', $p['personal']['full_name']);
    }

    public function test_a_company_contact_is_not_presented_as_the_person_completing_the_form(): void
    {
        $s = $this->submissionFor($this->agency('Cape Rentals'), ['contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Table Bay Properties (Pty) Ltd', 'email' => 'accounts@tablebay.test', 'phone' => '0215550000']);

        $p = FicaFormPrefill::for($s);

        $this->assertSame('', $p['entity_type'], 'the entity type is left for them to choose');
        $this->assertSame('', $p['personal']['full_name']);
        $this->assertSame('accounts@tablebay.test', $p['personal']['email']);
        $this->assertSame('0215550000', $p['personal']['phone']);
    }

    public function test_one_persons_link_never_carries_another_persons_details(): void
    {
        $one = $this->submissionFor($this->agency('Cape Rentals'), ['first_name' => 'Anele', 'last_name' => 'Zulu', 'id_number' => '9001015009087', 'email' => 'anele@example.test']);
        $two = $this->submissionFor($this->agency('Karoo Lettings'), ['first_name' => 'Kobus', 'last_name' => 'Venter', 'id_number' => '7001015009087', 'email' => 'kobus@example.test']);

        $html = $this->get(route('fica.form', $two->token))->assertOk()->getContent();

        $this->assertStringContainsString('Kobus Venter', $html);
        $this->assertStringNotContainsString('Anele', $html);
        $this->assertStringNotContainsString('9001015009087', $html);
        $this->assertNotNull($one);
    }

    public function test_a_submission_with_no_contact_still_renders(): void
    {
        $agency = $this->agency('Cape Rentals');
        $branch = Branch::firstOrCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $s = FicaSubmission::create(['contact_id' => null, 'agency_id' => $agency->id, 'branch_id' => $branch->id, 'requested_by' => $agent->id, 'status' => 'draft', 'token' => Str::random(64), 'token_expires_at' => now()->addDays(14)]);

        $p = FicaFormPrefill::for($s);
        $this->assertSame('', $p['entity_type']);
        $this->assertSame('', $p['personal']['full_name']);
        $this->get(route('fica.form', $s->token))->assertOk();
    }
}
