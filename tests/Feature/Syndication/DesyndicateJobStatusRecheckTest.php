<?php

namespace Tests\Feature\Syndication;

use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\Property24\Property24SyndicationService;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The queued withdraw job re-checks the property's CURRENT status when it runs
 * (Johan, 2026-10-06 — portal presence follows property status). A late job must
 * never withdraw a listing that has been flipped back on market since dispatch.
 *
 * FAKE portal clients only — the P24/PP syndication services are mocked, no HTTP/SOAP.
 */
class DesyndicateJobStatusRecheckTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // observer side-jobs (PP status sync etc.) must not run

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid(), 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);
    }

    private function liveOnBoth(string $status): Property
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'listing_type' => 'rental', 'status' => $status, 'price' => 9500,
        ]);
        $p->forceFill([
            'pp_syndication_enabled'  => true, 'pp_syndication_status' => 'active', 'pp_ref' => 'PP-1',
            'p24_syndication_enabled' => true, 'p24_syndication_status' => 'active', 'p24_ref' => '99887766',
        ])->saveQuietly();

        return $p->fresh();
    }

    /** Flip status the way a later agent edit would, without firing the observer. */
    private function flipStatus(Property $p, string $status): void
    {
        DB::table('properties')->where('id', $p->id)->update(['status' => $status]);
    }

    private function portals(int $p24Calls, int $ppCalls): void
    {
        $this->mock(Property24SyndicationService::class, function ($m) use ($p24Calls) {
            $e = $m->shouldReceive('deactivateListing')->times($p24Calls);
            if ($p24Calls > 0) {
                $e->andReturn(['success' => true]);
            }
        });
        $this->mock(PrivatePropertySyndicationService::class, function ($m) use ($ppCalls) {
            $e = $m->shouldReceive('deactivateListing')->times($ppCalls);
            if ($ppCalls > 0) {
                $e->andReturn(['success' => true]);
            }
        });
    }

    public function test_let_out_then_back_to_active_before_job_runs_withdraws_nothing(): void
    {
        $p = $this->liveOnBoth('let_out');
        $job = new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: false, keepP24ForSold: true);

        $this->flipStatus($p, 'active'); // flipped back before the worker picks the job up
        Log::spy();
        $this->portals(p24Calls: 0, ppCalls: 0);

        $job->handle(); // $job->property is still the stale let_out copy

        Log::shouldHaveReceived('info')->withArgs(
            fn ($msg) => str_contains($msg, 'skipped for property #' . $p->id) && str_contains($msg, 'back on market')
        )->once();
    }

    public function test_back_on_market_leaves_website_listing_untouched_too(): void
    {
        $p = $this->liveOnBoth('withdrawn');
        $minted = AgencyApiKey::mintSecret();
        $key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Main Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
        app(WebsiteSyndicationService::class)->setEnabled($p, $key, true);

        $job = new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: true);
        $this->flipStatus($p, 'active');
        $this->portals(p24Calls: 0, ppCalls: 0);

        $job->handle();

        $row = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $p->id)->where('agency_api_key_id', $key->id)->first();
        $this->assertTrue((bool) $row->enabled);
    }

    public function test_still_let_out_withdraws_from_both_portals(): void
    {
        $p = $this->liveOnBoth('let_out');
        $job = new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: false, keepP24ForSold: true);
        $this->portals(p24Calls: 1, ppCalls: 1);

        $job->handle();
        $this->addToAssertionCount(1); // Mockery ->times() is the assertion
    }

    public function test_withdrawn_unchanged_still_withdraws_everywhere(): void
    {
        $p = $this->liveOnBoth('withdrawn');
        $job = new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: true, keepP24ForSold: true);
        $this->portals(p24Calls: 1, ppCalls: 1);

        $job->handle();
        $this->addToAssertionCount(1);
    }

    public function test_sold_unchanged_keeps_p24_sold_but_still_delists_pp(): void
    {
        $p = $this->liveOnBoth('sold');
        $job = new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: false, keepP24ForSold: true);
        $this->portals(p24Calls: 0, ppCalls: 1);

        $job->handle();
        $this->addToAssertionCount(1);
    }

    public function test_soft_deleted_property_is_still_withdrawn_even_if_status_was_on_market(): void
    {
        $p = $this->liveOnBoth('active');
        $job = new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: false);
        $p->delete(); // trashed — never "back on market"
        $this->portals(p24Calls: 1, ppCalls: 1);

        $job->handle();
        $this->addToAssertionCount(1);
    }

    public function test_matches_off_market_status_helper(): void
    {
        foreach (['let_out', 'let out', 'Let Out', 'sold', 'sold • cash', 'withdrawn', 'rented'] as $s) {
            $this->assertTrue(Property::matchesOffMarketStatus($s), $s);
        }
        foreach (['active', 'for_sale', 'to_let', 'under_offer', ''] as $s) {
            $this->assertFalse(Property::matchesOffMarketStatus($s), $s);
        }
    }
}
