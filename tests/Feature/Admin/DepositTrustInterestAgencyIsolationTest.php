<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\DepositTrustInterest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-15 (Johan, HFC tenant-isolation fix, Wave 2, #8) — locks in the
 * Trust Interest Register fix: agency_id + BelongsToAgency, composite
 * (agency_id, interest_date) unique replacing the old global unique on
 * interest_date alone.
 */
class DepositTrustInterestAgencyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function agency(string $name): Agency
    {
        return Model::withoutEvents(fn () => Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]));
    }

    public function test_records_are_agency_scoped(): void
    {
        $agencyA = $this->agency('Agency A');
        $agencyB = $this->agency('Agency B');
        $userA = User::factory()->create(['agency_id' => $agencyA->id]);
        $userB = User::factory()->create(['agency_id' => $agencyB->id]);

        $this->actingAs($userA);
        $record = DepositTrustInterest::create([
            'interest_date' => '2026-08-01',
            'total_invested_funds' => 100000,
            'interest_earned' => 500,
        ]);
        $this->assertSame($agencyA->id, $record->agency_id);

        $this->actingAs($userB);
        $this->assertNull(DepositTrustInterest::find($record->id), 'AgencyScope must hide another agency\'s trust-interest row');
    }

    public function test_two_agencies_can_both_have_an_entry_for_the_same_month(): void
    {
        $agencyA = $this->agency('Agency A');
        $agencyB = $this->agency('Agency B');
        $userA = User::factory()->create(['agency_id' => $agencyA->id]);
        $userB = User::factory()->create(['agency_id' => $agencyB->id]);

        $this->actingAs($userA);
        DepositTrustInterest::create(['interest_date' => '2026-08-01', 'total_invested_funds' => 100000, 'interest_earned' => 500]);

        $this->actingAs($userB);
        // Must NOT throw a duplicate-key error — the old global unique on
        // interest_date alone would have rejected this.
        $recordB = DepositTrustInterest::create(['interest_date' => '2026-08-01', 'total_invested_funds' => 200000, 'interest_earned' => 900]);

        $this->assertSame($agencyB->id, $recordB->agency_id);
        $this->assertDatabaseHas('deposit_trust_interest', ['agency_id' => $agencyA->id, 'interest_date' => '2026-08-01']);
        $this->assertDatabaseHas('deposit_trust_interest', ['agency_id' => $agencyB->id, 'interest_date' => '2026-08-01']);
    }

    public function test_same_agency_still_cannot_duplicate_a_date(): void
    {
        $agency = $this->agency('Agency A');
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $this->actingAs($user);

        DepositTrustInterest::create(['interest_date' => '2026-08-01', 'total_invested_funds' => 100000, 'interest_earned' => 500]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DepositTrustInterest::create(['interest_date' => '2026-08-01', 'total_invested_funds' => 999, 'interest_earned' => 1]);
    }
}
