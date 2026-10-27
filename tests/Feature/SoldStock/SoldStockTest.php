<?php

namespace Tests\Feature\SoldStock;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Deal;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\PropertyContactRoleHistory;
use App\Models\PropertyListingCycle;
use App\Models\PropertyWebsiteSyndication;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\SoldStock;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/sold-stock.md (AT-512): the sale is frozen when a property is marked Sold / its deal registers; a Sold
 * property that is re-prospected starts a NEW listing cycle and can never reach the for-sale feeds; the website Sold
 * showcase reads sold_stock only; the screen is own/branch/agency scoped; the backfill is idempotent.
 */
class SoldStockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private AgencyApiKey $key;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // no portal call ever leaves a test
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid(), 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Thandi Mbeki']);

        $minted = AgencyApiKey::mintSecret();
        $this->token = $minted['plaintext'];
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Site', 'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
    }

    private function property(string $status = 'active', array $extra = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create($extra + [
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => '3 bed home', 'suburb' => 'Uvongo', 'city' => 'Margate', 'address' => '12 Marine Drive',
            'property_type' => 'house', 'status' => $status, 'price' => 1800000, 'beds' => 3, 'baths' => 2, 'size_m2' => 180,
            'description' => 'Sea views', 'listed_date' => now()->subDays(40)->toDateString(),
            'gallery_images_json' => ['properties/1/a.jpg', 'properties/1/b.jpg'],
        ]);
    }

    private function contact(string $first): Contact
    {
        return Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => 'Test']);
    }

    private function syndicate(Property $p): void
    {
        PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'agency_api_key_id' => $this->key->id,
            'enabled' => true, 'status' => PropertyWebsiteSyndication::STATUS_ACTIVE,
        ]);
    }

    public function test_marking_a_property_sold_freezes_one_snapshot_and_never_two(): void
    {
        $p = $this->property();
        $p->update(['status' => 'sold']);

        $snap = SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->get();
        $this->assertCount(1, $snap);
        $s = $snap->first();
        $this->assertSame(1, (int) $s->cycle_no);
        $this->assertSame('3 bed home', $s->title);
        $this->assertSame('Sea views', $s->description);
        $this->assertSame(['properties/1/a.jpg', 'properties/1/b.jpg'], $s->photos);   // references, not copies
        $this->assertSame(1800000.0, (float) $s->asking_price);
        $this->assertSame($this->agent->id, (int) $s->agent_id);
        $this->assertSame($this->branch->id, (int) $s->branch_id);
        $this->assertSame(now()->toDateString(), $s->sale_date->toDateString());
        $this->assertSame(40, (int) $s->days_on_market);

        // Editing the live advert afterwards never touches the frozen one; a second save adds no row.
        $p->update(['description' => 'Rewritten after the sale']);
        $p->update(['status' => 'sold', 'price' => 1]);
        $this->assertSame(1, SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->count());
        $this->assertSame('Sea views', $s->fresh()->description);
    }

    public function test_grace_window_uses_the_agency_setting_with_14_days_as_default(): void
    {
        $a = $this->property();
        $a->update(['status' => 'sold']);
        $this->assertSame(now()->addDays(14)->toDateString(), $a->fresh()->portal_sold_until->toDateString());

        PerformanceSetting::set('sold_portal_show_days', 3, $this->agency->id);
        $b = $this->property();
        $b->update(['status' => 'sold']);
        $this->assertSame(now()->addDays(3)->toDateString(), $b->fresh()->portal_sold_until->toDateString());
    }

    public function test_deal_registration_fills_the_snapshot_without_a_second_row(): void
    {
        $p = $this->property();
        $p->update(['status' => 'sold']);
        $this->assertNull(SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->value('deal_id'));

        $buyer = $this->contact('Bianca');
        $deal = Deal::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'period' => '2026-10', 'deal_date' => now()->toDateString(),
            'property_value' => 1750000, 'total_commission' => 100000, 'accepted_status' => 'R', 'sale_price' => 1750000,
            'registration_date' => now()->subDay()->toDateString(),
        ]);
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);

        event(new \App\Events\Deal\DealStageAdvanced($deal, 'G', 'R'));

        $rows = SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->get();
        $this->assertCount(1, $rows, 'whichever trigger comes second only fills the gaps');
        $this->assertSame($deal->id, (int) $rows->first()->deal_id);
        $this->assertSame(1750000.0, (float) $rows->first()->sale_price);
        $this->assertSame([$buyer->id], $rows->first()->buyer_contact_ids);
        // A deal that registers before any status change snapshots on its own (property never marked Sold).
        $q = $this->property('under_offer');
        $deal2 = Deal::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'property_id' => $q->id, 'period' => '2026-10', 'deal_date' => now()->toDateString(),
            'property_value' => 900000, 'total_commission' => 50000, 'accepted_status' => 'R', 'sale_price' => 900000, 'registration_date' => now()->toDateString(),
        ]);
        event(new \App\Events\Deal\DealStageAdvanced($deal2, 'G', 'R'));
        $this->assertSame(1, SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $q->id)->count());
    }

    public function test_re_prospecting_a_sold_property_starts_a_new_cycle_and_keeps_the_history(): void
    {
        $p = $this->property();
        $owner = $this->contact('Olga');
        $buyer = $this->contact('Bianca');
        ContactPropertyLinker::link($owner->id, $p->id, 'owner');
        ContactPropertyLinker::link($buyer->id, $p->id, 'buyer');
        $p->update(['status' => 'sold']);

        $p->cycleStartReason = 'deeds';
        $p->update(['status' => Property::STATUS_PROSPECTING]);
        $p = $p->fresh();

        $this->assertSame(2, (int) $p->listing_cycle);
        $this->assertNull($p->portal_sold_until);
        $this->assertSame(1, SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->where('cycle_no', 1)->count(), 'the sale stays as history');

        $first = PropertyListingCycle::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->where('cycle_no', 1)->first();
        $this->assertSame('reprospected', $first->end_reason);
        $this->assertSame([$owner->id], $first->previous_owner_contact_ids);
        $this->assertContains($buyer->id, $first->previous_buyer_contact_ids);
        $second = PropertyListingCycle::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->where('cycle_no', 2)->first();
        $this->assertSame('deeds', $second->start_reason);
        $this->assertSame($buyer->id, (int) $second->suggested_seller_contact_id);
        $this->assertSame('pending', $second->suggestion_state);

        // Role history keeps who held what in cycle 1; the old owner leaves the live property, the buyer stays.
        $this->assertSame(2, PropertyContactRoleHistory::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->where('cycle_no', 1)->count());
        $live = ContactProperty::where('property_id', $p->id)->pluck('role', 'contact_id')->all();
        $this->assertArrayNotHasKey($owner->id, $live);
        $this->assertSame('buyer', $live[$buyer->id]);

        // The agent confirms the previous buyer as the new seller: ONE row, role changes, one role per contact.
        $this->assertTrue(app(\App\Services\SoldStock\SoldStockService::class)->confirmSuggestion($p));
        $this->assertSame(['owner'], ContactProperty::where('property_id', $p->id)->where('contact_id', $buyer->id)->pluck('role')->all());
        $this->assertSame('confirmed', $second->fresh()->suggestion_state);
    }

    public function test_a_sale_that_falls_through_is_not_history(): void
    {
        $p = $this->property();
        $p->update(['status' => 'sold']);
        $p->update(['status' => 'active']);
        $this->assertSame(0, SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->count());
        $this->assertNull($p->fresh()->portal_sold_until);
    }

    public function test_the_for_sale_feed_can_never_emit_prospecting_sold_or_other_off_market_stock(): void
    {
        $live = $this->property('active', ['title' => 'Live one']);
        $sold = $this->property('sold', ['title' => 'Sold one']);
        $pros = $this->property(Property::STATUS_PROSPECTING, ['title' => 'Prospect one']);
        $ns   = $this->property(Property::STATUS_NOT_SELLING, ['title' => 'Not selling one']);
        foreach ([$live, $sold, $pros, $ns] as $p) {
            $this->syndicate($p);   // every pivot row is ENABLED - the status alone must keep them out
        }

        $titles = collect($this->withToken($this->token)->getJson('/api/v1/website/listings?per_page=50')->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['Live one'], $titles);
        $this->withToken($this->token)->getJson('/api/v1/website/listings?status=sold,prospecting')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_regression_a_sold_property_that_is_re_prospected_never_shows_for_sale_but_stays_on_the_sold_feed(): void
    {
        $this->key->update(['scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ]]);
        $p = $this->property();
        $this->syndicate($p);
        $p->update(['status' => 'sold']);
        $p->cycleStartReason = 'deeds';
        $p->update(['status' => Property::STATUS_PROSPECTING]);

        $this->withToken($this->token)->getJson('/api/v1/website/listings')->assertOk()->assertJsonCount(0, 'data');
        $sold = $this->withToken($this->token)->getJson('/api/v1/website/sold')->assertOk();
        $sold->assertJsonCount(1, 'data');
        $sold->assertJsonPath('data.0.title', '3 bed home');
        $sold->assertJsonPath('data.0.cycle', 1);
        $sold->assertJsonStructure(['data' => [['id', 'title', 'description', 'sale_price', 'sold_at', 'images', 'suburb', 'agents']]]);
    }

    public function test_the_sold_feed_is_agency_confined_and_obeys_its_scope(): void
    {
        $p = $this->property();
        $p->update(['status' => 'sold']);

        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid(), 'website_enabled' => true]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'B']);
        $oa = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'role' => 'agent']);
        $op = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $other->id, 'agent_id' => $oa->id, 'branch_id' => $ob->id, 'external_id' => (string) Str::uuid(),
            'title' => 'Other agency sale', 'suburb' => 'X', 'property_type' => 'house', 'status' => 'draft', 'price' => 1,
        ]);
        SoldStock::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'property_id' => $op->id, 'cycle_no' => 1, 'title' => 'Other agency sale', 'sale_date' => now()]);

        $titles = collect($this->withToken($this->token)->getJson('/api/v1/website/sold')->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['3 bed home'], $titles);

    }

    public function test_a_key_without_the_listings_scope_cannot_read_the_sold_feed(): void
    {
        $this->key->forceFill(['scopes' => [AgencyApiKey::SCOPE_AGENTS_READ]])->save();
        $this->withToken($this->token)->getJson('/api/v1/website/sold')->assertForbidden();
    }

    public function test_p24_never_maps_prospecting_to_an_active_advert(): void
    {
        $this->assertSame('Withdrawn', Property24ListingMapper::getP24Status('prospecting', 'REF1'));
        $this->assertSame('Withdrawn', Property24ListingMapper::getP24Status('not_selling', 'REF1'));
        $this->assertSame('Sold', Property24ListingMapper::getP24Status('sold', 'REF1'));
        $this->assertSame('Active', Property24ListingMapper::getP24Status('active', 'REF1'));
    }

    public function test_the_sold_stock_screen_is_scoped_own_branch_agency_and_blocks_direct_urls(): void
    {
        $mine  = $this->property('active', ['title' => 'My sale', 'address' => '1 Mine Road']);
        $mine->update(['status' => 'sold']);
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Pieter']);
        $theirs = $this->property('active', ['title' => 'Their sale', 'address' => '2 Their Road', 'agent_id' => $other->id]);
        $theirs->update(['status' => 'sold']);

        foreach (['access_sold_stock', 'properties.view'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => 'own']);
        }
        PermissionService::clearCache();

        $html = $this->actingAs($this->agent)->get(route('corex.sold-stock.index'))->assertOk()->getContent();
        $this->assertStringContainsString('1 Mine Road', $html);
        $this->assertStringNotContainsString('2 Their Road', $html);

        $theirId = SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $theirs->id)->value('id');
        $this->actingAs($this->agent)->get(route('corex.sold-stock.show', $theirId))->assertNotFound();

        // Search, sort and filter behave.
        $this->actingAs($this->agent)->get(route('corex.sold-stock.index', ['q' => 'Nothing matches this', 'sort' => 'sale_price']))->assertOk()->assertSee('No sales match');

        // Agency scope sees both.
        RolePermission::where('role', 'agent')->where('permission_key', 'properties.view')->update(['scope' => 'all']);
        PermissionService::clearCache();
        $all = $this->actingAs($this->agent)->get(route('corex.sold-stock.index'))->assertOk()->getContent();
        $this->assertStringContainsString('2 Their Road', $all);
    }

    public function test_the_backfill_snapshots_existing_sold_stock_and_is_safe_to_re_run(): void
    {
        $p = $this->property();
        $this->syndicate($p);
        // A property that was Sold before this feature existed: status written without the observer.
        Property::withoutGlobalScopes()->whereKey($p->id)->update(['status' => 'sold', 'updated_at' => '2026-03-01 10:00:00']);
        // A re-prospected property whose website switch was never turned off - the bug's victim.
        $victim = $this->property(Property::STATUS_PROSPECTING);
        $this->syndicate($victim);
        $this->assertSame(0, SoldStock::withoutGlobalScope(AgencyScope::class)->count());

        $this->artisan('sold-stock:backfill', ['--agency' => $this->agency->id, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, SoldStock::withoutGlobalScope(AgencyScope::class)->count(), 'dry-run writes nothing');

        $this->artisan('sold-stock:backfill', ['--agency' => $this->agency->id])->assertSuccessful();
        $this->assertSame(1, SoldStock::withoutGlobalScope(AgencyScope::class)->count());
        $this->assertSame('2026-03-01', SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->first()->sale_date->toDateString(),
            'a historic sale with no deal / sold record is dated from when it became Sold, never today');
        $this->assertSame(0, PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->where('property_id', $victim->id)->where('enabled', true)->count());
        // Default run: the website goes off in the database, NO portal job leaves (a copy of live data holds real credentials).
        Queue::assertNotPushed(\App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob::class);
        $this->assertSame(0, PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->where('enabled', true)->count());
        $this->withToken($this->token)->getJson('/api/v1/website/listings')->assertOk()->assertJsonCount(0, 'data');
        // --portals is the production flag: a live PP listing is then taken down.
        Property::withoutGlobalScopes()->whereKey($p->id)->update(['pp_syndication_enabled' => true]);
        $this->artisan('sold-stock:backfill', ['--agency' => $this->agency->id, '--portals' => true])->assertSuccessful();
        Queue::assertPushed(\App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob::class);

        $this->artisan('sold-stock:backfill', ['--agency' => $this->agency->id])->assertSuccessful();
        $this->assertSame(1, SoldStock::withoutGlobalScope(AgencyScope::class)->count(), 'second run adds nothing');
    }

    public function test_the_property_page_shows_the_cycle_banner_and_the_sale_detail_opens(): void
    {
        foreach (['access_sold_stock', 'access_properties', 'properties.edit', 'properties.archive'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        $p = $this->property();
        $buyer = $this->contact('Bianca');
        ContactPropertyLinker::link($buyer->id, $p->id, 'buyer');
        $p->update(['status' => 'sold']);
        $p->update(['status' => Property::STATUS_PROSPECTING]);

        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $p))->assertOk()->getContent();
        $this->assertStringContainsString('data-listing-cycle-banner', $html);
        $this->assertStringContainsString('Listing cycle 2', $html);
        $this->assertStringContainsString('Suggested new seller', $html);
        $this->assertStringContainsString('Bianca Test', $html);
        $this->assertStringContainsString(route('corex.properties.cycle.confirm', $p->id), $html);

        // Confirm through the real route: the buyer becomes the owner, the banner stops asking.
        $this->actingAs($this->agent)->post(route('corex.properties.cycle.confirm', $p->id))->assertRedirect();
        $this->assertSame('owner', ContactProperty::where('property_id', $p->id)->where('contact_id', $buyer->id)->value('role'));
        $this->assertStringNotContainsString('Suggested new seller', $this->actingAs($this->agent)->get(route('corex.properties.show', $p))->getContent());

        // The frozen sale opens read-only, with its photos, and can be archived then restored.
        $sale = SoldStock::withoutGlobalScope(AgencyScope::class)->where('property_id', $p->id)->first();
        $page = $this->actingAs($this->agent)->get(route('corex.sold-stock.show', $sale->id))->assertOk()->getContent();
        $this->assertStringContainsString('12 Marine Drive', $page);
        $this->assertStringContainsString('a.jpg', $page);
        $this->actingAs($this->agent)->post(route('corex.sold-stock.archive', $sale->id))->assertRedirect();
        $this->assertSoftDeleted('sold_stock', ['id' => $sale->id]);
        $this->actingAs($this->agent)->post(route('corex.sold-stock.restore', $sale->id))->assertRedirect();
        $this->assertNotSoftDeleted('sold_stock', ['id' => $sale->id]);
    }
}
