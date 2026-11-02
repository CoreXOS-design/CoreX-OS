<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Property;
use App\Services\SellerOutreach\ListingPropertyPromoter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Pitch now on an advert whose prospecting property was archived must not 500 on the unique source ref (properties_agency_ext_uq): the archived one is
 * restored and reused (history kept); when it cannot be restored the key is freed and a new one is made.
 */
final class ListingPropertyPromoterArchivedTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
    }

    private function listingRow(): object
    {
        $id = $this->listing(['address' => '7 Archive Road', 'suburb' => 'Margate'], $this->tp(['street_name' => 'Archive Road', 'street_number' => '7']));

        return DB::table('prospecting_listings')->find($id);
    }

    private function promote(object $listing): Property
    {
        return app(ListingPropertyPromoter::class)->promote($this->agency->id, $listing, $this->agent);
    }

    public function test_an_archived_prospecting_property_is_restored_and_reused(): void
    {
        $listing = $this->listingRow();
        $first = $this->promote($listing);
        DB::table('contact_property')->insert(['contact_id' => $this->makeContact(), 'property_id' => $first->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $first->delete();
        $this->assertSoftDeleted('properties', ['id' => $first->id]);

        $again = $this->promote($listing);

        $this->assertSame((int) $first->id, (int) $again->id, 'the same property, not a second one');
        $this->assertNull($again->deleted_at);
        $this->assertSame(1, Property::withoutGlobalScopes()->withTrashed()->where('external_id', 'prospecting:' . $listing->id)->count());
        $this->assertSame(1, DB::table('contact_property')->where('property_id', $first->id)->count(), 'its seller link is still there');
    }

    public function test_an_archived_property_that_is_no_longer_a_stub_is_not_revived_and_its_key_is_freed(): void
    {
        $listing = $this->listingRow();
        $first = $this->promote($listing);
        DB::table('properties')->where('id', $first->id)->update(['status' => 'active']);
        Property::withoutGlobalScopes()->find($first->id)->delete();

        $new = $this->promote($listing);

        $this->assertNotSame((int) $first->id, (int) $new->id);
        $this->assertNull($new->deleted_at);
        $this->assertSame('prospecting:' . $listing->id, $new->external_id);
        $old = Property::withoutGlobalScopes()->withTrashed()->find($first->id);
        $this->assertNotNull($old->deleted_at, 'the old one stays archived');
        $this->assertSame('released:' . $first->id, $old->external_id);
    }

    public function test_when_the_restore_does_not_take_the_key_is_freed_and_a_new_property_is_made(): void
    {
        $listing = $this->listingRow();
        $first = $this->promote($listing);
        $first->delete();
        Event::listen('eloquent.restoring: ' . Property::class, fn () => false);   // something vetoes the restore

        $new = $this->promote($listing);

        $this->assertNotSame((int) $first->id, (int) $new->id);
        $this->assertNull($new->deleted_at);
        $this->assertSame('released:' . $first->id, Property::withoutGlobalScopes()->withTrashed()->find($first->id)->external_id);
    }

    public function test_a_listing_with_no_archived_property_is_unchanged(): void
    {
        $listing = $this->listingRow();
        $a = $this->promote($listing);
        $b = $this->promote($listing);

        $this->assertSame((int) $a->id, (int) $b->id);
        $this->assertSame('prospecting:' . $listing->id, $a->external_id);
    }

    private function makeContact(): int
    {
        return (int) DB::table('contacts')->insertGetId(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Old', 'last_name' => 'Seller', 'created_at' => now(), 'updated_at' => now()]);
    }
}
