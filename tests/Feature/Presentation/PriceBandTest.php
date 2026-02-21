<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\MarketAnalyticsRun;
use App\Models\Presentation;
use App\Models\SaleProbabilityRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C2: Price Band endpoint acceptance tests.
 *
 * Validates optimal price band scan returns deterministic results with no DB writes.
 */
class PriceBandTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private Branch       $branch;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.price_band_v1', true);

        $this->branch = Branch::create([
            'name'      => 'Test Branch',
            'code'      => 'TEST',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $this->branch->id,
        ]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'Price Band Test',
            'property_address'   => '1 Test Street',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'suburb'        => 'Claremont',
            'type'          => 'house',
            'price'         => 2_000_000,
            'size_m2'       => 120,
            'bedrooms'      => 3,
            'period_months' => 12,
        ], $overrides);
    }

    // ── Contract shape ───────────────────────────────────────────────────

    public function test_returns_correct_contract_shape(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $this->basePayload(),
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('aggressive', $json);
        $this->assertArrayHasKey('balanced', $json);
        $this->assertArrayHasKey('defensive', $json);
        $this->assertArrayHasKey('scan', $json);
    }

    public function test_scan_has_correct_number_of_steps(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $this->basePayload(['steps' => 5]),
        );

        $response->assertOk();
        $this->assertCount(5, $response->json('scan'));
    }

    public function test_scan_row_contains_expected_keys(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $this->basePayload(['steps' => 3]),
        );

        $response->assertOk();
        $row = $response->json('scan.0');

        $this->assertArrayHasKey('price', $row);
        $this->assertArrayHasKey('p60', $row);
        $this->assertArrayHasKey('p90', $row);
        $this->assertArrayHasKey('confidence', $row);
        $this->assertArrayHasKey('ppi', $row);
    }

    // ── Scan prices are within range ─────────────────────────────────────

    public function test_scan_prices_within_range(): void
    {
        $this->actingAs($this->user);
        $price = 2_000_000;

        $response = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $this->basePayload(['price' => $price, 'range_percent' => 0.08]),
        );

        $response->assertOk();
        $scan = $response->json('scan');

        $lowerBound = $price * 0.92;
        $upperBound = $price * 1.08;

        foreach ($scan as $row) {
            $this->assertGreaterThanOrEqual($lowerBound, $row['price']);
            $this->assertLessThanOrEqual($upperBound, $row['price']);
        }
    }

    // ── No DB writes ─────────────────────────────────────────────────────

    public function test_no_ma_runs_persisted(): void
    {
        $this->actingAs($this->user);
        $countBefore = MarketAnalyticsRun::count();

        $this->postJson(
            route('presentations.price-band', $this->presentation),
            $this->basePayload(['steps' => 3]),
        )->assertOk();

        $this->assertSame($countBefore, MarketAnalyticsRun::count());
    }

    public function test_no_sp_runs_persisted(): void
    {
        $this->actingAs($this->user);
        $countBefore = SaleProbabilityRun::count();

        $this->postJson(
            route('presentations.price-band', $this->presentation),
            $this->basePayload(['steps' => 3]),
        )->assertOk();

        $this->assertSame($countBefore, SaleProbabilityRun::count());
    }

    // ── Deterministic ────────────────────────────────────────────────────

    public function test_deterministic_output(): void
    {
        $this->actingAs($this->user);
        $payload = $this->basePayload(['steps' => 3]);

        $response1 = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $payload,
        );

        $response2 = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $payload,
        );

        $response1->assertOk();
        $response2->assertOk();

        $this->assertEquals($response1->json(), $response2->json());
    }

    // ── Feature flag ─────────────────────────────────────────────────────

    public function test_feature_flag_off_returns_404(): void
    {
        config()->set('features.price_band_v1', false);
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $this->basePayload(),
        );

        $response->assertNotFound();
    }

    // ── Auth required ────────────────────────────────────────────────────

    public function test_requires_auth(): void
    {
        $response = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $this->basePayload(),
        );

        $response->assertUnauthorized();
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_price_required(): void
    {
        $this->actingAs($this->user);

        $payload = $this->basePayload();
        unset($payload['price']);

        $response = $this->postJson(
            route('presentations.price-band', $this->presentation),
            $payload,
        );

        $response->assertUnprocessable();
    }
}
