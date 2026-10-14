<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rental re-walk, 14 Oct 2026: typing a NEW contact on a new property whose phone already belonged to someone silently attached the
 * existing contact to the property - no warning, no choice. A phone / email match is now put to the agent, and nothing is saved until
 * they answer ("use that contact" = queue the existing one; "create a new one" = `confirmed_new`).
 */
final class NewPropertyContactMatchAsksTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private int $suburbId;
    private Contact $existing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Match Agency ' . Str::random(4), 'slug' => 'ma-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);

        $countryId = (int) DB::table('p24_countries')->insertGetId(['p24_id' => 90000, 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now()]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId(['p24_id' => 90001, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal', 'created_at' => now(), 'updated_at' => now()]);
        $cityId = (int) DB::table('p24_cities')->insertGetId(['p24_id' => 90002, 'p24_province_id' => $provinceId, 'name' => 'Hibiscus Coast', 'created_at' => now(), 'updated_at' => now()]);
        $this->suburbId = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(4), 'p24_id' => 90003, 'p24_city_id' => $cityId, 'confirmed' => 1,
            'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $c = Contact::create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'first_name' => 'Existing', 'last_name' => 'Owner', 'phone' => '0821110001', 'email' => 'existing.owner@example.test']);
        DB::table('contacts')->where('id', $c->id)->update(['agency_id' => $this->agencyId]);
        $this->existing = $c->fresh();
    }

    private function mode(string $mode): void
    {
        AgencyContactSettings::forAgency($this->agencyId)->forceFill(['duplicate_mode' => $mode])->save();
    }

    private function save(array $newContact, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->agent)->post(route('corex.properties.store'), [
            'title' => 'Match Listing ' . Str::random(4), 'price' => 9000, 'beds' => 2, 'baths' => 1, 'garages' => 0,
            'suburb' => 'Uvongo', 'listing_type' => 'sale', 'agent_id' => ($as ?? $this->agent)->id, 'p24_suburb_id' => $this->suburbId,
            'pending_new_contacts' => [$newContact],
        ]);
    }

    private function fresh(array $over = []): array
    {
        return array_merge(['first_name' => 'Brand', 'last_name' => 'New', 'phone' => '0821110001'], $over);
    }

    private function propertyCount(): int
    {
        return Property::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count();
    }

    private function contactCount(): int
    {
        return Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count();
    }

    public function test_a_new_contact_with_a_known_phone_is_asked_about_and_nothing_is_saved_or_attached(): void
    {
        foreach (['soft_warn', 'auto_link', 'hard_block_override', 'hard_block_request'] as $mode) {
            $this->mode($mode);
            $before = $this->existing->fresh()->only(['first_name', 'last_name', 'phone', 'email', 'updated_at']);

            $res = $this->save($this->fresh());

            $res->assertSessionHasErrors('contacts');
            self::assertStringContainsString('Existing Owner', session('errors')->first('contacts'), "mode {$mode}");
            self::assertStringContainsString('Use that contact, or create a new one', session('errors')->first('contacts'));
            self::assertSame(0, $this->propertyCount(), "mode {$mode}: no property was created behind the question");
            self::assertSame(1, $this->contactCount(), "mode {$mode}: no contact was created");
            self::assertSame(0, DB::table('contact_property')->where('contact_id', $this->existing->id)->count(), "mode {$mode}: the existing contact was not attached");
            self::assertEquals($before, $this->existing->fresh()->only(['first_name', 'last_name', 'phone', 'email', 'updated_at']), "mode {$mode}: the existing contact is untouched");
        }
    }

    public function test_an_email_match_is_asked_about_too(): void
    {
        $res = $this->save($this->fresh(['phone' => '0839998888', 'email' => 'EXISTING.owner@example.test']));

        $res->assertSessionHasErrors('contacts');
        self::assertStringContainsString('email address', session('errors')->first('contacts'));
        self::assertSame(0, $this->propertyCount());
    }

    public function test_choosing_create_new_makes_a_separate_contact_and_leaves_the_existing_one_alone(): void
    {
        $this->mode('soft_warn');

        $this->save($this->fresh(['confirmed_new' => '1']))->assertSessionHasNoErrors();

        self::assertSame(1, $this->propertyCount());
        self::assertSame(2, $this->contactCount(), 'the agent chose a new contact, so a new one exists');
        $new = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('first_name', 'Brand')->firstOrFail();
        self::assertNotSame($this->existing->id, $new->id);
        $property = Property::withoutGlobalScopes()->where('agency_id', $this->agencyId)->firstOrFail();
        self::assertSame([$new->id], DB::table('contact_property')->where('property_id', $property->id)->pluck('contact_id')->map(fn ($v) => (int) $v)->all(), 'only the NEW contact is on the property');
        self::assertSame(0, DB::table('contact_property')->where('contact_id', $this->existing->id)->count());
        self::assertSame('0821110001', $this->existing->fresh()->phone);
    }

    public function test_the_agencys_duplicate_mode_still_limits_who_may_create_a_second_one(): void
    {
        $this->mode('hard_block_request');
        $this->save($this->fresh(['confirmed_new' => '1']))->assertSessionHasErrors('contacts');
        self::assertSame(0, $this->propertyCount(), 'request-access agencies never get a second contact with that number');

        $this->mode('hard_block_override');
        $this->save($this->fresh(['confirmed_new' => '1']))->assertSessionHasErrors('contacts');
        self::assertSame(0, $this->propertyCount(), 'an ordinary agent may not override');

        $admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->save($this->fresh(['confirmed_new' => '1']), $admin)->assertSessionHasNoErrors();
        self::assertSame(1, $this->propertyCount(), 'an admin may');
    }

    public function test_a_phone_nobody_has_saves_straight_through(): void
    {
        $this->save($this->fresh(['phone' => '0840000000']))->assertSessionHasNoErrors();

        self::assertSame(1, $this->propertyCount());
        self::assertSame(2, $this->contactCount());
    }

    public function test_the_precheck_gives_the_form_what_it_needs_to_ask(): void
    {
        $this->mode('soft_warn');
        $json = $this->actingAs($this->agent)->postJson(route('corex.contacts.check-duplicate'), ['phone' => '0821110001', 'email' => null])->assertOk()->json();

        self::assertTrue($json['found']);
        self::assertSame($this->existing->id, $json['id']);
        self::assertSame('Existing Owner', $json['name']);
        self::assertTrue($json['can_create_anyway']);

        $this->mode('hard_block_request');
        self::assertFalse($this->actingAs($this->agent)->postJson(route('corex.contacts.check-duplicate'), ['phone' => '0821110001'])->json('can_create_anyway'));
    }

    public function test_the_new_property_form_asks_instead_of_adding_straight_away(): void
    {
        $html = file_get_contents(resource_path('views/corex/properties/show.blade.php'));

        self::assertStringContainsString('data-new-contact-duplicate', $html);
        self::assertStringContainsString('Use that contact, or create a new one?', $html);
        self::assertStringContainsString("pending_new_contacts['+idx+'][confirmed_new]", $html);
        self::assertStringContainsString("route('corex.contacts.check-duplicate')", $html);
    }
}
