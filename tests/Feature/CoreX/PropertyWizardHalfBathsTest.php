<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Property upload wizard (step 1 / createDraft): the ½ Bath stepper must
 * persist `half_baths` onto the draft property alongside the whole bath count.
 */
final class PropertyWizardHalfBathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_draft_persists_half_baths(): void
    {
        [$agent, $suburbId] = $this->seedAgencyAgent();

        $this->actingAs($agent)
            ->postJson(route('corex.properties.wizard.draft'), [
                'listing_type'    => 'sale',
                'property_type'   => 'House',
                'title'           => 'Wizard Half Bath ' . Str::random(4),
                'price'           => 2_100_000,
                'beds'            => 3,
                'baths'           => 2,
                'half_baths'      => 1,
                'garages'         => 2,
                'suburb'          => 'Uvongo',
                'p24_province_id' => $this->provinceId,
                'p24_city_id'     => $this->cityId,
                'p24_suburb_id'   => $suburbId,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $property = Property::withoutGlobalScopes()->where('agent_id', $agent->id)->latest('id')->firstOrFail();

        $this->assertSame(2, (int) $property->baths);
        $this->assertSame(1, $property->half_baths);
    }

    private int $provinceId = 0;
    private int $cityId = 0;

    /** @return array{0:User,1:int} [agent, p24SuburbId] */
    private function seedAgencyAgent(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => 90000, 'name' => 'South Africa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => 90001, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 90002, 'p24_province_id' => $this->provinceId, 'name' => 'Hibiscus Coast',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $suburbId = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(4),
            'p24_id' => 90003, 'p24_city_id' => $this->cityId, 'confirmed' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agent, $suburbId];
    }
}
