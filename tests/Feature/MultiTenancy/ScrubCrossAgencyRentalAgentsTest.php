<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenancy;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QA2 audit follow-up to AT-424 — proves the one-off scrub migration
 * (2026_09_26_000000_scrub_cross_agency_rental_agents.php) removes
 * `rental_agents` rows left over from before RentalsController started
 * filtering posted agent ids to the rental's own agency, without touching
 * any genuine same-agency link.
 */
final class ScrubCrossAgencyRentalAgentsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        $agency = Agency::create(['name' => 'Scrub Co ' . Str::random(6), 'slug' => 'scrub-' . Str::random(8)]);
        Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return $agency;
    }

    private function makeRental(Agency $agency): Rental
    {
        $branch = Branch::where('agency_id', $agency->id)->firstOrFail();

        return Rental::withoutGlobalScopes()->create([
            'agency_id'        => $agency->id,
            'branch_id'        => $branch->id,
            'lease_address'    => '1 Scrub Road',
            'lease_start_date' => now()->subYear(),
            'is_active'        => true,
        ]);
    }

    private function attachAgent(Rental $rental, User $agent): void
    {
        DB::table('rental_agents')->insert([
            'rental_id' => $rental->id, 'user_id' => $agent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function runScrub(): void
    {
        $migration = require base_path('database/migrations/2026_09_26_000000_scrub_cross_agency_rental_agents.php');
        $migration->up();
    }

    public function test_a_pre_fix_cross_agency_agent_link_is_removed(): void
    {
        $agencyA = $this->makeAgency();
        $agencyB = $this->makeAgency();
        $rental = $this->makeRental($agencyA);
        $foreignAgent = User::factory()->create(['agency_id' => $agencyB->id, 'branch_id' => Branch::where('agency_id', $agencyB->id)->value('id'), 'role' => 'agent']);
        $this->attachAgent($rental, $foreignAgent);

        $this->runScrub();

        $this->assertSame(0, DB::table('rental_agents')->where('rental_id', $rental->id)->where('user_id', $foreignAgent->id)->count());
    }

    public function test_a_genuine_same_agency_agent_link_survives(): void
    {
        $agency = $this->makeAgency();
        $rental = $this->makeRental($agency);
        $branch = Branch::where('agency_id', $agency->id)->firstOrFail();
        $ownAgent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->attachAgent($rental, $ownAgent);

        $this->runScrub();

        $this->assertSame(1, DB::table('rental_agents')->where('rental_id', $rental->id)->where('user_id', $ownAgent->id)->count());
    }

    public function test_the_scrub_is_idempotent(): void
    {
        $agencyA = $this->makeAgency();
        $agencyB = $this->makeAgency();
        $rental = $this->makeRental($agencyA);
        $foreignAgent = User::factory()->create(['agency_id' => $agencyB->id, 'branch_id' => Branch::where('agency_id', $agencyB->id)->value('id'), 'role' => 'agent']);
        $this->attachAgent($rental, $foreignAgent);

        $this->runScrub();
        $this->runScrub(); // second pass — nothing left to remove, must not error

        $this->assertSame(0, DB::table('rental_agents')->where('rental_id', $rental->id)->count());
    }
}
