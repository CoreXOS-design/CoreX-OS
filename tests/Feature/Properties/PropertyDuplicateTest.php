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
