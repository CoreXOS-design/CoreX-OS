<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Duplicate a property — POST /corex/properties/{property}/duplicate.
 *
 * Regression guard. duplicate() set `$clone->price = null` so the agent would be
 * forced to re-enter it, but `properties.price` is `bigint unsigned NOT NULL
 * DEFAULT 0` — an explicit NULL into a NOT NULL column throws 1048 no matter what
 * the default is. So duplicate 500'd for EVERY property, always; it was not a
 * quirk of one listing's data (reported 2026-07-13, property 6088, whose own
 * price was a perfectly good 4710).
 *
 * The copy still starts with no usable price — 0 is this schema's "unset", and
 * empty(0) is true, so publishToggle()'s readiness gate keeps demanding a real
 * Price before the copy can go live. Same intent, a value the column accepts.
 */
final class PropertyDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create([
            'name' => 'Duplicate Test Agency',
            'slug' => 'dup-test-' . uniqid(),
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function makeProperty(array $attrs = []): Property
    {
        return Property::create(array_merge([
            'title'     => 'Seaside Cottage',
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->user->id,
            'branch_id' => $this->branch->id,
            'price'     => 1950000,
            'status'    => 'active',
        ], $attrs));
    }

    /** THE bug: this was a hard 500 (1048 Column 'price' cannot be null). */
    public function test_duplicating_a_property_succeeds_and_does_not_500(): void
    {
        $p = $this->makeProperty();

        $clone = Property::where('id', '>', $p->id);

        $this->actingAs($this->user)
            ->post("/corex/properties/{$p->id}/duplicate")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, $clone->count(), 'the duplicate was not created');
    }

    /** The copy is an unpublished draft with no usable price, carrying the source's detail. */
    public function test_the_copy_is_a_draft_with_no_usable_price(): void
    {
        $p = $this->makeProperty([
            'published_at'             => now(),
            'p24_syndication_enabled'  => true,
            'pp_syndication_enabled'   => true,
        ]);

        $this->actingAs($this->user)->post("/corex/properties/{$p->id}/duplicate")->assertRedirect();

        $clone = Property::where('id', '>', $p->id)->firstOrFail();

        $this->assertSame('Seaside Cottage (Copy)', $clone->title);
        $this->assertSame('draft', $clone->status);
        $this->assertNull($clone->published_at);
        $this->assertFalse((bool) $clone->p24_syndication_enabled);
        $this->assertFalse((bool) $clone->pp_syndication_enabled);
        $this->assertSame($this->agency->id, $clone->agency_id);

        // Price is cleared but WRITEABLE — 0, not null. empty(0) is true, so the
        // publish gate still lists "Price" as missing until the agent sets one.
        $this->assertSame(0, (int) $clone->price);
        $this->assertEmpty($clone->price);
        $this->assertNotNull($clone->price, 'price must never be NULL — the column is NOT NULL');
    }

    /**
     * A NULL price is rejected by the schema itself. This pins the constraint that
     * made the bug, so nobody "helpfully" restores `$clone->price = null`.
     */
    public function test_the_price_column_rejects_null_at_the_database(): void
    {
        $p = $this->makeProperty();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('properties')->where('id', $p->id)->update(['price' => null]);
    }

    public function test_contact_links_are_copied_to_the_duplicate(): void
    {
        $p = $this->makeProperty();

        $contact = Contact::create([
            'agency_id'  => $this->agency->id,
            'first_name' => 'Retha',
            'last_name'  => 'Kelly',
        ]);
        $p->contacts()->attach($contact->id, ['role' => 'seller']);

        $this->actingAs($this->user)->post("/corex/properties/{$p->id}/duplicate")->assertRedirect();

        $clone = Property::where('id', '>', $p->id)->firstOrFail();

        $this->assertSame(1, $clone->contacts()->count());
        $this->assertSame('seller', $clone->contacts()->first()->pivot->role);
    }

    /* ───────────────────────── AT-262 — duplicate/change listing type ─────────────────────────
     * Andre's design + Johan's extension: duplicate optionally AS the other type; same type = full
     * copy, cross type = matching fields only; the clone opens in a completable draft where the
     * listing type is NOT locked (listing_type_pending) until the first real save. "Change type" =
     * duplicate to the other type + archive (soft-delete + de-list) the original.
     */

    /** Duplicate AS rental from a sale: the clone carries the target type, in an unlocked draft. */
    public function test_duplicate_as_the_other_type_sets_target_and_leaves_type_unlocked(): void
    {
        $p = $this->makeProperty(['listing_type' => 'sale']);

        $this->actingAs($this->user)
            ->post("/corex/properties/{$p->id}/duplicate", ['target_type' => 'rental'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $clone = Property::where('id', '>', $p->id)->firstOrFail();

        $this->assertSame('rental', $clone->listing_type);
        $this->assertTrue((bool) $clone->listing_type_pending, 'a fresh duplicate opens with the type unlocked');
        $this->assertSame('draft', $clone->status);
    }

    /** Cross-type rental→sale carries the matching fields only — the rental-only fields are cleared. */
    public function test_cross_type_duplicate_clears_the_other_types_fields(): void
    {
        $p = $this->makeProperty([
            'listing_type'   => 'rental',
            'rental_amount'  => 12000,
            'deposit_amount' => 24000,
        ]);

        $this->actingAs($this->user)
            ->post("/corex/properties/{$p->id}/duplicate", ['target_type' => 'sale'])
            ->assertRedirect();

        $clone = Property::where('id', '>', $p->id)->firstOrFail();

        $this->assertSame('sale', $clone->listing_type);
        $this->assertNull($clone->rental_amount, 'sale copy must not carry the source rental amount');
        $this->assertNull($clone->deposit_amount, 'sale copy must not carry the source rental deposit');
    }

    /** Same-type duplicate is a full copy and stays unlocked until completed. */
    public function test_same_type_duplicate_is_a_full_copy(): void
    {
        $p = $this->makeProperty([
            'listing_type'  => 'rental',
            'rental_amount' => 8500,
        ]);

        $this->actingAs($this->user)
            ->post("/corex/properties/{$p->id}/duplicate", ['target_type' => 'rental'])
            ->assertRedirect();

        $clone = Property::where('id', '>', $p->id)->firstOrFail();

        $this->assertSame('rental', $clone->listing_type);
        $this->assertSame(8500, (int) $clone->rental_amount, 'same-type copy carries the rental fields');
        $this->assertTrue((bool) $clone->listing_type_pending);
    }

    /** Change type = new other-type draft AND the original is archived (soft-deleted) + de-listed. */
    public function test_change_type_creates_other_type_draft_and_archives_the_original(): void
    {
        // AT-262 gate: change-type is ONLY for a draft that has never been advertised.
        $p = $this->makeProperty([
            'listing_type' => 'sale',
            'status'       => 'draft',   // never activated, never syndicated → convertible
        ]);
        $this->assertTrue($p->canChangeType(), 'a never-advertised draft must be convertible');

        $this->actingAs($this->user)
            ->post("/corex/properties/{$p->id}/change-type")
            ->assertRedirect()
            ->assertSessionHas('success');

        // Original archived — soft-deleted, de-listed, status archived (history preserved).
        $original = Property::withTrashed()->findOrFail($p->id);
        $this->assertNotNull($original->deleted_at, 'the original must be soft-deleted, never hard-deleted');
        $this->assertSame('archived', $original->status);
        $this->assertFalse((bool) $original->p24_syndication_enabled);
        $this->assertFalse((bool) $original->pp_syndication_enabled);

        // New draft is the OTHER type, unlocked, ready to complete.
        $clone = Property::where('id', '>', $p->id)->firstOrFail();
        $this->assertSame('rental', $clone->listing_type);
        $this->assertTrue((bool) $clone->listing_type_pending);
        $this->assertSame('draft', $clone->status);
    }

    /** AT-262 gate: an advertised property CANNOT change type — it's pointed to Duplicate. */
    public function test_change_type_is_blocked_on_an_advertised_property(): void
    {
        $p = $this->makeProperty([
            'listing_type' => 'sale',
            'status'       => 'active',
            'published_at' => now(),   // advertised → NOT convertible
        ]);
        $this->assertFalse($p->canChangeType());

        $this->actingAs($this->user)
            ->post("/corex/properties/{$p->id}/change-type")
            ->assertRedirect()
            ->assertSessionHas('error');

        // Nothing happened: original untouched, no new draft minted.
        $p->refresh();
        $this->assertNull($p->deleted_at, 'an advertised property must NOT be archived by change-type');
        $this->assertSame('active', $p->status);
        $this->assertSame(0, Property::where('id', '>', $p->id)->count(), 'no clone created');
    }

    /** A draft that WAS advertised then reverted is not convertible (history is durable). */
    public function test_a_draft_that_was_previously_advertised_cannot_change_type(): void
    {
        $p = $this->makeProperty([
            'listing_type'    => 'sale',
            'status'          => 'draft',
            'p24_activated_at' => now()->subDays(5),   // durable "was on a portal" marker
        ]);
        $this->assertFalse($p->canChangeType(), 'a once-advertised draft is not convertible');
    }

    /** Completing the draft (a normal save) commits the chosen type and clears the pending flag. */
    public function test_saving_a_pending_draft_locks_the_type(): void
    {
        $p = $this->makeProperty([
            'listing_type'         => 'rental',
            'listing_type_pending' => true,
        ]);
        // Stamp ownership + branch so the linked contact is visible under any
        // ContactScope data-scope (own/branch/all) when the update guard counts it.
        $contact = Contact::create([
            'agency_id'           => $this->agency->id,
            'branch_id'           => $this->branch->id,
            'created_by_user_id'  => $this->user->id,
            'first_name'          => 'Seller',
            'last_name'           => 'One',
        ]);
        $p->contacts()->attach($contact->id, ['role' => 'seller']);

        $this->actingAs($this->user)->put("/corex/properties/{$p->id}", [
            'title'        => 'Seaside Cottage',
            'price'        => 2100000,
            'suburb'       => 'Uvongo',
            'beds'         => 3,
            'baths'        => 2,
            'garages'      => 1,
            'listing_type' => 'sale',
            'agent_id'     => $this->user->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $p->refresh();
        $this->assertFalse((bool) $p->listing_type_pending, 'the first real save clears the pending window');
        $this->assertSame('sale', $p->listing_type, 'the chosen type is committed on save');
    }

    /** A stamped, in-scope seller contact (visible under any ContactScope). */
    private function linkSeller(Property $p): void
    {
        $contact = Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'first_name'         => 'Seller',
            'last_name'          => 'One',
        ]);
        $p->contacts()->attach($contact->id, ['role' => 'seller']);
    }

    /* ─── AT-262 draft-lenient + category-aware save (Johan's repro: change-type→save 500'd) ─── */

    /** The handed-over draft saves PARTIALLY — no price/suburb/beds/baths/garages/agent required. */
    public function test_a_pending_draft_saves_partially_without_full_requirements(): void
    {
        $p = $this->makeProperty([
            'listing_type'         => 'rental',
            'listing_type_pending' => true,
            'status'               => 'draft',
            'property_type'        => 'House',
        ]);

        // Only a title — everything else left for completion. No contact linked either:
        // a draft in progress must not be blocked on the completion requirements.
        $this->actingAs($this->user)->put("/corex/properties/{$p->id}", [
            'title' => 'Half-filled switched draft',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $p->refresh();
        $this->assertSame('Half-filled switched draft', $p->title);
        $this->assertSame('rental', $p->listing_type);
    }

    /** A completed (active) RESIDENTIAL listing still enforces beds — leniency is draft-only. */
    public function test_active_residential_listing_still_requires_beds(): void
    {
        $p = $this->makeProperty(['listing_type' => 'sale', 'status' => 'active', 'property_type' => 'House']);
        $this->linkSeller($p);

        $this->actingAs($this->user)->put("/corex/properties/{$p->id}", [
            'title' => 'Home', 'price' => 1_000_000, 'suburb' => 'Uvongo',
            'baths' => 2, 'garages' => 1, 'agent_id' => $this->user->id, // beds omitted
        ])->assertSessionHasErrors('beds');
    }

    /** A COMMERCIAL listing never requires beds/baths/garages, even when active (m3 portal finding). */
    public function test_active_commercial_listing_does_not_require_beds(): void
    {
        $p = $this->makeProperty(['listing_type' => 'sale', 'status' => 'active', 'property_type' => 'Commercial Office']);
        $this->linkSeller($p);

        $this->actingAs($this->user)->put("/corex/properties/{$p->id}", [
            'title' => 'Shop', 'price' => 2_000_000, 'suburb' => 'Margate',
            'agent_id' => $this->user->id, // beds/baths/garages omitted — commercial
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    /** A RENTAL never requires the sale `price` field (it prices via rental_amount). */
    public function test_active_rental_does_not_require_sale_price(): void
    {
        $p = $this->makeProperty(['listing_type' => 'rental', 'status' => 'active', 'property_type' => 'Flat']);
        $this->linkSeller($p);

        $this->actingAs($this->user)->put("/corex/properties/{$p->id}", [
            'title' => 'To let', 'suburb' => 'Shelly Beach',
            'beds' => 2, 'baths' => 1, 'garages' => 1, 'agent_id' => $this->user->id, // price omitted
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_cannot_duplicate_another_agencys_property(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Main']);
        $otherAgent  = User::factory()->create([
            'agency_id' => $otherAgency->id,
            'branch_id' => $otherBranch->id,
        ]);
        $foreign = Property::create([
            'title'     => 'Foreign',
            'agency_id' => $otherAgency->id,
            'agent_id'  => $otherAgent->id,
            'branch_id' => $otherBranch->id,
            'price'     => 100000,
        ]);

        // AgencyScope blocks route-model binding across agencies → 404.
        $this->actingAs($this->user)
            ->post("/corex/properties/{$foreign->id}/duplicate")
            ->assertNotFound();
    }
}
