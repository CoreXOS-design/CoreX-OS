<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\Rentals\PropertyStatusFollowsLeaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An agency with Rentals gets the built-in To Let / Let Out / Rented in Settings > Properties & Listings > Property Statuses (default on, toggleable like
 * the others), so the property page's Status dropdown - which reads only the agency's own active list - can show them. The same slugs
 * LeaseActivationService::flipPropertyToLeasedOut() writes.
 */
final class RentalStatusesInSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function agency(bool $rentals = true): Agency
    {
        $a = Agency::create(['name' => 'Rent ' . Str::random(5), 'slug' => 'rent-' . Str::random(8)]);
        AgencyFeature::withTrashed()->updateOrCreate(['agency_id' => $a->id, 'feature_key' => 'rentals'], ['enabled' => $rentals, 'deleted_at' => null]);
        app(AgencyFeatureService::class)->forget($a->id);

        return $a;
    }

    private function statuses(int $agencyId, bool $activeOnly = false)
    {
        return DB::table('property_setting_items')->where('agency_id', $agencyId)->where('group', 'property_status')->whereNull('deleted_at')
            ->when($activeOnly, fn ($q) => $q->where('active', 1))->orderBy('sort_order')->get();
    }

    public function test_a_rental_agency_gets_the_three_after_its_existing_statuses_once(): void
    {
        $a = $this->agency();
        DB::table('property_setting_items')->where('agency_id', $a->id)->where('group', 'property_status')->whereIn('name', PropertySettingItem::RENTAL_STATUS_NAMES)->delete();
        $before = $this->statuses($a->id)->count();

        $this->assertSame(3, PropertySettingItem::provisionRentalStatusesFor($a->id));
        $list = $this->statuses($a->id);
        $this->assertSame($before + 3, $list->count());
        $this->assertSame(['To Let', 'Let Out', 'Rented'], $list->slice($before)->pluck('name')->all(), 'after the existing rows, in this order');
        $this->assertSame([1, 1, 1], $list->slice($before)->pluck('active')->map(fn ($v) => (int) $v)->all(), 'on by default');
        $this->assertSame(0, PropertySettingItem::provisionRentalStatusesFor($a->id), 'idempotent');
    }

    public function test_a_new_agency_has_them_from_the_start_and_an_agency_without_rentals_has_none(): void
    {
        $rent = $this->agency(true);
        $this->assertSame(['To Let', 'Let Out', 'Rented'], $this->statuses($rent->id)->whereIn('name', PropertySettingItem::RENTAL_STATUS_NAMES)->pluck('name')->sortBy(fn ($n) => array_search($n, PropertySettingItem::RENTAL_STATUS_NAMES))->values()->all());

        $sales = $this->agency(false);
        DB::table('property_setting_items')->where('agency_id', $sales->id)->where('group', 'property_status')->whereIn('name', PropertySettingItem::RENTAL_STATUS_NAMES)->delete();
        $this->assertSame(0, PropertySettingItem::provisionRentalStatusesFor($sales->id));
        $this->assertSame(0, $this->statuses($sales->id)->whereIn('name', PropertySettingItem::RENTAL_STATUS_NAMES)->count(), 'a sales-only agency is not offered rental statuses');
    }

    public function test_an_agencys_own_choice_is_never_undone(): void
    {
        $a = $this->agency();
        DB::table('property_setting_items')->where('agency_id', $a->id)->where('group', 'property_status')->where('name', 'Rented')->update(['active' => 0]);
        DB::table('property_setting_items')->where('agency_id', $a->id)->where('group', 'property_status')->where('name', 'To Let')->update(['deleted_at' => now()]);
        DB::table('property_setting_items')->where('agency_id', $a->id)->where('group', 'property_status')->where('name', 'Let Out')->update(['name' => 'let out']);   // renamed spelling still counts

        $this->assertSame(0, PropertySettingItem::provisionRentalStatusesFor($a->id));
        $this->assertSame(0, (int) DB::table('property_setting_items')->where('agency_id', $a->id)->where('name', 'Rented')->value('active'), 'switched off stays off');
        $this->assertSame(0, DB::table('property_setting_items')->where('agency_id', $a->id)->where('name', 'To Let')->whereNull('deleted_at')->count(), 'removed stays removed');
    }

    public function test_the_dropdown_source_offers_them_and_their_slugs_are_the_ones_the_lease_flip_writes(): void
    {
        $a = $this->agency();
        $names = $this->statuses($a->id, true)->pluck('name')->all();
        foreach (['To Let', 'Let Out', 'Rented'] as $n) {
            $this->assertContains($n, $names);
        }
        // the picker's name -> stored status rule (lower case, spaces to underscores) lands on real system statuses
        $slugs = array_map(fn ($n) => str_replace(' ', '_', strtolower($n)), PropertySettingItem::RENTAL_STATUS_NAMES);
        $this->assertSame(['to_let', 'let_out', 'rented'], $slugs);
        foreach ($slugs as $slug) {
            $this->assertTrue(Property::isAllowedStatus($slug, $a->id));
        }
        $this->assertContains(app(PropertyStatusFollowsLeaseService::class)->leasedOutStatusFor($a->id), $slugs);
    }

    public function test_the_settings_page_lists_them_and_opens_for_an_existing_agency_that_never_had_them(): void
    {
        $a = $this->agency();
        DB::table('property_setting_items')->where('agency_id', $a->id)->where('group', 'property_status')->whereIn('name', PropertySettingItem::RENTAL_STATUS_NAMES)->delete();
        $admin = User::factory()->create(['agency_id' => $a->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);

        $html = $this->actingAs($admin)->get(route('corex.settings'))->assertOk()->getContent();

        $this->assertSame(3, $this->statuses($a->id)->whereIn('name', PropertySettingItem::RENTAL_STATUS_NAMES)->count(), 'opening Settings provisions them for an agency that predates this');
        foreach (['To Let', 'Let Out', 'Rented'] as $n) {
            $this->assertStringContainsString($n, $html);
        }
    }
}
