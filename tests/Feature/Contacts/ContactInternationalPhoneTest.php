<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contact-details Phase 1 — end-to-end proof, through the REAL HTTP form
 * submission (corex.contacts.store), that an agent can now save a USA number
 * (previously reported as "could not load a USA number") and that a ZA number
 * still saves exactly as before.
 */
final class ContactInternationalPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        return [$agencyId, $agent];
    }

    public function test_a_usa_number_can_be_created_via_the_real_form_submission(): void
    {
        [, $agent] = $this->seedFixture();

        $this->actingAs($agent)->post(route('corex.contacts.store'), [
            'first_name' => 'Jane',
            'last_name'  => 'American',
            'phones'     => [
                ['value' => '+1 415 555 2671', 'country_iso' => 'US', 'is_primary' => true],
            ],
        ])->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('first_name', 'Jane')->where('last_name', 'American')->firstOrFail();
        $this->assertSame('+1 415 555 2671', $contact->phone, 'raw US number saved to the mirror unmangled');

        $phone = $contact->phones()->firstOrFail();
        $this->assertSame('US', $phone->country_iso);
        $this->assertSame('+1', $phone->dial_code);
        $this->assertSame('14155552671', $phone->phone_normalised, 'full digits kept — no last-9 collapse');
    }

    public function test_a_za_number_still_defaults_and_saves_exactly_as_before(): void
    {
        [, $agent] = $this->seedFixture();

        $this->actingAs($agent)->post(route('corex.contacts.store'), [
            'first_name' => 'Sipho',
            'last_name'  => 'Local',
            'phone'      => '0825559999',
        ])->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('phone', '0825559999')->firstOrFail();
        $phone = $contact->phones()->firstOrFail();
        $this->assertSame('ZA', $phone->country_iso);
        $this->assertSame('+27', $phone->dial_code);
        $this->assertSame('825559999', $phone->phone_normalised, 'ZA dedup key unchanged');
    }

    /** A tampered/unrecognised country_iso falls back to ZA rather than being trusted. */
    public function test_unrecognised_country_iso_falls_back_to_za(): void
    {
        [, $agent] = $this->seedFixture();

        $this->actingAs($agent)->post(route('corex.contacts.store'), [
            'first_name' => 'Tam',
            'last_name'  => 'Pered',
            'phones'     => [
                ['value' => '0825551234', 'country_iso' => 'XX', 'is_primary' => true], // not a real country
            ],
        ])->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('first_name', 'Tam')->firstOrFail();
        $phone = $contact->phones()->firstOrFail();
        $this->assertSame('ZA', $phone->country_iso);
        $this->assertSame('+27', $phone->dial_code);
    }
}
