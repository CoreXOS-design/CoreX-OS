<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\User;
use App\Services\BuyerStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-500 — a buyer who is Won (linked to a DR2 deal) stays Won.
 *
 * The bug: the nightly `buyers:recompute-states` re-derived New/Warm/Cold/Lost for EVERY buyer and had
 * no Won check (the guard lived only in BuyerStateService::recomputeState(), which the command never
 * called), so every Won buyer went back onto the active board at 04:00 the next morning. Every Won
 * buyer on Staging had been reversed that way. Spec: .ai/specs/buyer-pipeline.md "Won stays Won".
 */
final class BuyerWonStaysWonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_a_won_buyer_stays_won_when_the_nightly_recompute_runs(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $won = $this->buyer($agencyId, $agent->id, 'new');
        $won->updateQuietly(['last_activity_at' => now()->subDays(90)]); // stale: the recompute WOULD move a non-Won buyer

        // Won by hand (AT-500: with no live deal, only a manual Won has a basis the nightly will keep).
        app(BuyerStateService::class)->transitionTo($won->fresh(), 'won', 'manual_override', $agent->id);
        $this->assertSame('won', $won->fresh()->buyer_state);

        $stale = $this->buyer($agencyId, $agent->id, 'warm');
        $stale->updateQuietly(['last_activity_at' => now()->subDays(90)]);

        Artisan::call('buyers:recompute-states');

        $this->assertSame('won', $won->fresh()->buyer_state, 'Won must survive buyers:recompute-states');
        $this->assertSame('lost', $stale->fresh()->buyer_state, 'control: a genuinely stale non-Won buyer still decays');
        $this->assertSame(0, DB::table('buyer_state_transitions')
            ->where('contact_id', $won->id)->where('reason', 'auto_recompute')->count(), 'no auto_recompute row for a Won buyer');
    }

    public function test_the_dry_run_report_does_not_list_a_won_buyer(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $won = $this->buyer($agencyId, $agent->id, 'new');
        $won->updateQuietly(['last_activity_at' => now()->subDays(90)]);
        app(BuyerStateService::class)->transitionTo($won->fresh(), 'won', 'manual_override', $agent->id); // Won by hand

        Artisan::call('buyers:recompute-states', ['--dry-run' => true]);

        $this->assertStringNotContainsString($won->full_name, Artisan::output());
        $this->assertSame('won', $won->fresh()->buyer_state);
    }

    public function test_the_service_recompute_leaves_a_won_buyer_alone(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $won = $this->buyer($agencyId, $agent->id, 'new');
        $won->updateQuietly(['last_activity_at' => now()->subDays(90)]);
        $svc = app(BuyerStateService::class);
        $svc->markWon($won->fresh(), $agent->id);

        $this->assertNull($svc->nextAutoState($won->fresh()));
        $svc->recomputeState($won->fresh());

        $this->assertSame('won', $won->fresh()->buyer_state);
    }

    public function test_activity_on_a_won_buyer_is_logged_but_does_not_move_them(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $won = $this->buyer($agencyId, $agent->id, 'new');
        $svc = app(BuyerStateService::class);
        $svc->markWon($won->fresh(), $agent->id);

        $svc->markActivity($won->fresh(), 'manual', null, null, null, $agent->id);

        $fresh = $won->fresh();
        $this->assertSame('won', $fresh->buyer_state, 'markActivity must not pull a Won buyer back to warm');
        $this->assertNotNull($fresh->last_activity_at, 'the activity itself is still recorded');
    }

    // ── fixtures ──

    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);

        return [$agencyId, $agent];
    }

    private function buyer(int $agencyId, int $agentId, string $state): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $agentId, 'agent_id' => $agentId,
            'is_buyer' => true, 'buyer_state' => $state,
            'first_name' => 'Wendy', 'last_name' => 'Won ' . Str::random(4),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'wendy-' . Str::random(5) . '@example.co.za',
        ]);
    }
}
