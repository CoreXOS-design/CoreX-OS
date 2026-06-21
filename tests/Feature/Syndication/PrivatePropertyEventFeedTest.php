<?php

namespace Tests\Feature\Syndication;

use App\Jobs\ProcessPrivatePropertyEventFeed;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * PP event feed fixes:
 *  - PP-4: first poll sends an EMPTY continuation key (not '0') + startDateTime.
 *  - PP-3: a page is processed even when the continuation key does not advance
 *          (the old code dropped the terminal page's events).
 *  - PP-2: every enabled PP agency is polled (forAgency per agency).
 *
 * Audit: .ai/audits/syndication-bug-sweep-2026-06-20.md (PP-2/3/4)
 */
class PrivatePropertyEventFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_terminal_page_and_uses_empty_first_key(): void
    {
        $agency = $this->ppAgency('coastal', 'GUID-A');
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'L', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
            'pp_syndication_enabled' => true, 'pp_syndication_status' => 'submitted',
        ]);

        // One Activated event on a page with NO advancing continuation key.
        $response = [
            'GetListingEventFeedByBranchResult' => [
                'ContinuationKey' => null,
                'FeedData' => [
                    'LisitngEventFeedData' => [
                        ['ListingFeedEventType' => 'Activated', 'ListingFeedRef' => (string) $p->id,
                         'OfficeFeedRef' => 'GUID-A', 'EventDescription' => 'T999'],
                    ],
                ],
            ],
        ];

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        // PP-4: first call must use an empty key (not '0').
        $client->shouldReceive('getListingEventFeed')->once()
            ->with('', Mockery::type('string'))
            ->andReturn($response);

        (new ProcessPrivatePropertyEventFeed())->handle($client);

        // PP-3: the terminal page's event was processed despite the key not advancing.
        $p->refresh();
        $this->assertSame('active', $p->pp_syndication_status);
        $this->assertSame('T999', $p->pp_ref);
    }

    public function test_iterates_every_enabled_pp_agency(): void
    {
        $this->ppAgency('a', 'GUID-A');
        $this->ppAgency('b', 'GUID-B');
        $this->ppAgency('c-disabled', 'GUID-C', enabled: false); // must be skipped

        $seen = [];
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnUsing(function ($agency) use (&$seen, $client) {
            $seen[] = $agency?->pp_branch_guid;
            return $client;
        });
        $client->shouldReceive('getListingEventFeed')->andReturn([
            'GetListingEventFeedByBranchResult' => ['ContinuationKey' => null, 'FeedData' => []],
        ]);

        (new ProcessPrivatePropertyEventFeed())->handle($client);

        $this->assertContains('GUID-A', $seen);
        $this->assertContains('GUID-B', $seen);
        $this->assertNotContains('GUID-C', $seen, 'Disabled PP agency must not be polled.');
    }

    private function ppAgency(string $slug, string $guid, bool $enabled = true): Agency
    {
        return Agency::create([
            'name' => ucfirst($slug), 'slug' => $slug,
            'pp_enabled' => $enabled, 'pp_branch_guid' => $guid,
        ]);
    }
}
