<?php

namespace Tests\Feature\Syndication;

use App\Http\Concerns\AppliesP24Location;
use App\Http\Controllers\Api\V1\P24LocationController;
use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AT-104 follow-up — the existence guard.
 *
 * A suburb whose p24_id P24 never returned (a phantom, e.g. Addington/5997)
 * must be unselectable AND unsaveable, even though its suburb→city→province
 * chain is internally consistent. The single signal is `p24_verified_at`,
 * stamped only by the location sync / reconcile.
 */
class P24SuburbVerifiedGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;
    private int $verifiedSuburbId;
    private int $phantomSuburbId;

    protected function setUp(): void
    {
        parent::setUp();

        $kzn = P24Province::create(['p24_id' => 4, 'p24_country_id' => 1, 'name' => 'KwaZulu Natal']);
        $durban = P24City::create(['p24_id' => 169, 'p24_province_id' => $kzn->id, 'name' => 'Durban']);
        $this->cityId = $durban->id;

        // Verified: P24 returned this in the live sync.
        $this->verifiedSuburbId = P24Suburb::create([
            'name' => 'Glenwood', 'slug' => 'glenwood', 'p24_id' => 5969,
            'p24_city_id' => $durban->id, 'region' => 'durban',
            'p24_verified_at' => now(),
        ])->id;

        // Phantom: internally consistent chain, but NEVER returned by P24.
        $this->phantomSuburbId = P24Suburb::create([
            'name' => 'Addington', 'slug' => 'addington', 'p24_id' => 5997,
            'p24_city_id' => $durban->id, 'region' => 'kzn-south-coast',
            'p24_verified_at' => null,
        ])->id;
    }

    /** A harness exposing the protected trait method. */
    private function applier(): object
    {
        return new class {
            use AppliesP24Location;
            public function run(array $data): array
            {
                return $this->applyP24Location($data, true);
            }
        };
    }

    public function test_verified_suburb_is_accepted_and_canonicalised(): void
    {
        $out = $this->applier()->run([
            'p24_suburb_id' => $this->verifiedSuburbId,
            'p24_city_id'   => $this->cityId,
        ]);

        $this->assertSame('Glenwood', $out['suburb']);
        $this->assertSame('Durban', $out['city']);
        $this->assertFalse($out['p24_suburb_mismatch']);
    }

    public function test_phantom_unverified_suburb_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->applier()->run([
            'p24_suburb_id' => $this->phantomSuburbId,
            'p24_city_id'   => $this->cityId,
        ]);
    }

    public function test_suburbs_endpoint_only_offers_verified_rows(): void
    {
        $request = Request::create('/api/v1/p24/suburbs', 'GET', ['city_id' => $this->cityId]);
        $json = (new P24LocationController())->suburbs($request)->getData(true);

        $ids = array_column($json['data'], 'id');
        $this->assertContains($this->verifiedSuburbId, $ids, 'Verified suburb must be offered.');
        $this->assertNotContains($this->phantomSuburbId, $ids, 'Phantom suburb must never be offered.');
    }
}
