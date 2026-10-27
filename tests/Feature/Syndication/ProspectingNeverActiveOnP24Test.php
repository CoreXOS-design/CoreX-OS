<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sold-stock fix (AT-512): a Sold property that is re-prospected must leave every portal. P24's status mapper used to fall
 * through to 'Active' for prospecting, and the observer did not take the website pivot down for it.
 */
class ProspectingNeverActiveOnP24Test extends TestCase
{
    use RefreshDatabase;

    public function test_p24_maps_prospecting_and_not_selling_to_withdrawn_and_keeps_sold_as_sold(): void
    {
        $this->assertSame('Withdrawn', Property24ListingMapper::getP24Status('prospecting', 'REF1'));
        $this->assertSame('Withdrawn', Property24ListingMapper::getP24Status('not_selling', 'REF1'));
        $this->assertSame('Withdrawn', Property24ListingMapper::getP24Status('Not selling', null));
        $this->assertSame('Sold', Property24ListingMapper::getP24Status('sold', 'REF1'));
        $this->assertSame('Active', Property24ListingMapper::getP24Status('active', 'REF1'));
    }

    public function test_sold_to_prospecting_dispatches_a_full_desyndication_but_a_plain_sold_keeps_p24(): void
    {
        Queue::fake();
        $agency = Agency::create(['name' => 'C', 'slug' => 'c-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'M']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id, 'external_id' => (string) Str::uuid(),
            'title' => 't', 'suburb' => 's', 'property_type' => 'house', 'status' => 'active', 'price' => 1, 'pp_syndication_enabled' => true,
        ]);

        $p->update(['status' => 'sold']);
        Queue::assertPushed(\App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob::class,
            fn ($j) => $j->keepP24ForSold === true && $j->removeFromWebsite === true);

        $p->update(['status' => Property::STATUS_PROSPECTING]);
        Queue::assertPushed(\App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob::class,
            fn ($j) => $j->keepP24ForSold === false && $j->removeFromWebsite === true);
    }
}
