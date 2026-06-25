<?php

namespace Tests\Feature\Syndication;

use App\Jobs\SubmitListingToProperty24;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A listing must never sit frozen at 'submitting' forever. A queued
 * SubmitListingToProperty24 that is SIGKILL'd on timeout or exhausts its tries
 * can't run its own ->update(['error']), so two safety nets resolve the row:
 *   1. Job::failed() — fires after tries exhausted.
 *   2. reapStuckSubmits() — catches a HARD worker kill where failed() never ran.
 */
class Property24StuckSubmitReaperTest extends TestCase
{
    use RefreshDatabase;

    private function makeProperty(string $status): Property
    {
        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'L', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
        ]);
        $p->forceFill(['p24_syndication_enabled' => true, 'p24_syndication_status' => $status])->save();

        return $p;
    }

    public function test_reaper_flips_stale_submitting_to_error(): void
    {
        Http::fake(); // reconcile makes no real calls for a 'submitting' row

        $stale = $this->makeProperty('submitting');
        // Backdate updated_at past the 15-minute staleness threshold.
        Property::withoutGlobalScope(AgencyScope::class)->whereKey($stale->id)
            ->update(['updated_at' => now()->subMinutes(20)]);

        $reaped = app(Property24SyndicationService::class)->reapStuckSubmits();

        $this->assertSame(1, $reaped);
        $this->assertSame('error', $stale->fresh()->p24_syndication_status);
        $this->assertStringContainsString('timed out', (string) $stale->fresh()->p24_last_error);
    }

    public function test_reaper_leaves_a_fresh_submitting_row_alone(): void
    {
        Http::fake();

        $fresh = $this->makeProperty('submitting'); // updated_at = now()

        $reaped = app(Property24SyndicationService::class)->reapStuckSubmits();

        $this->assertSame(0, $reaped);
        $this->assertSame('submitting', $fresh->fresh()->p24_syndication_status);
    }

    public function test_failed_handler_resolves_a_submitting_row_to_error(): void
    {
        $p = $this->makeProperty('submitting');

        (new SubmitListingToProperty24($p))->failed(new \RuntimeException('boom'));

        $this->assertSame('error', $p->fresh()->p24_syndication_status);
        $this->assertStringContainsString('boom', (string) $p->fresh()->p24_last_error);
    }

    public function test_failed_handler_does_not_clobber_an_already_active_row(): void
    {
        $p = $this->makeProperty('active');

        (new SubmitListingToProperty24($p))->failed(new \RuntimeException('boom'));

        $this->assertSame('active', $p->fresh()->p24_syndication_status);
    }
}
