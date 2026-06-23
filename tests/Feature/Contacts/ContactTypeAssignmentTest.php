<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\ContactType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-79 — contact types collapse to 4 fixed e-sign parents with nested sub-tags,
 * and a contact may hold MULTIPLE (parent + sub-tag) assignments.
 *
 * The invariant the whole feature (and the e-sign wizard) leans on:
 * Contact::syncTypeAssignments() keeps the multi-parent pivot, the sub-tag pivot
 * and the denormalised primary-type mirror (contacts.contact_type_id) consistent
 * on every write.
 */
final class ContactTypeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_four_canonical_parents_exist_and_are_locked(): void
    {
        $parents = ContactType::query()->canonical()->get();

        $this->assertSame(['seller', 'buyer', 'lessor', 'lessee'], $parents->pluck('esign_role')->all());
        foreach ($parents as $p) {
            $this->assertTrue($p->isLocked(), "{$p->name} must be a locked parent");
        }
    }

    public function test_parents_includes_owner_and_other_without_esign_role(): void
    {
        $parents = ContactType::query()->parents()->get();

        $this->assertEqualsCanonicalizing(
            ['Seller', 'Buyer', 'Lessor', 'Lessee', 'Owner', 'Other'],
            $parents->pluck('name')->all()
        );

        $owner = $parents->firstWhere('name', 'Owner');
        $other = $parents->firstWhere('name', 'Other');
        $this->assertNull($owner->esign_role, 'Owner does not map to e-sign');
        $this->assertNull($other->esign_role, 'Other does not map to e-sign');
        $this->assertTrue($owner->isLocked());
        $this->assertTrue($other->isLocked());
    }

    public function test_store_adds_multiple_subtags_under_owner_and_skips_case_insensitive_dupes(): void
    {
        $agencyId = $this->seedAgency();
        $admin = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        $owner = ContactType::where('name', 'Owner')->whereNull('esign_role')->firstOrFail();

        // Pre-existing sub-tag under the (non-e-sign) Owner parent.
        $this->subTag($agencyId, $owner->id, 'Investor');

        $this->actingAs($admin)
            ->post(route('corex.settings.contact-tags.store'), [
                'contact_type_id' => $owner->id,
                // 'investor' collides with existing (case); 'Cash Buyer' collides
                // with 'Cash buyer' within the same input.
                'name' => 'Cash buyer, First-timer, investor, Cash Buyer',
            ])
            ->assertRedirect();

        $names = DB::table('contact_tags')
            ->where('contact_type_id', $owner->id)
            ->whereNull('deleted_at')
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['Cash buyer', 'First-timer', 'Investor'], $names);
    }

    public function test_canonical_excludes_rogue_types_sharing_an_esign_role(): void
    {
        // Un-normalised installs carry legacy types the old name-pattern migration
        // stamped with a canonical esign_role (e.g. "Buyer, Lead", "Tenant").
        // canonical() must still return ONLY the 4 true parents — never these.
        DB::table('contact_types')->insert([
            ['name' => 'Buyer, Lead',   'esign_role' => 'buyer',  'color' => '#fff', 'sort_order' => 9, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Seller, Owner', 'esign_role' => 'seller', 'color' => '#fff', 'sort_order' => 9, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tenant',        'esign_role' => 'lessee', 'color' => '#fff', 'sort_order' => 9, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $parents = ContactType::query()->canonical()->get();

        $this->assertCount(4, $parents);
        $this->assertEqualsCanonicalizing(['Seller', 'Buyer', 'Lessor', 'Lessee'], $parents->pluck('name')->all());
    }

    public function test_sync_assigns_multiple_parents_and_derives_primary_mirror(): void
    {
        $agencyId = $this->seedAgency();
        [$seller, $buyer] = $this->parents();
        $tag = $this->subTag($agencyId, $seller->id, 'Cash seller');

        $contact = $this->makeContact($agencyId);

        $newly = $contact->syncTypeAssignments([$seller->id, $buyer->id], [$tag->id]);
        $contact->refresh();

        $this->assertEqualsCanonicalizing(
            [$seller->id, $buyer->id],
            $contact->parentTypes()->pluck('contact_types.id')->all(),
            'both parents assigned'
        );
        $this->assertSame([$tag->id], $contact->tags()->pluck('contact_tags.id')->all());
        // Primary mirror = lowest-sort parent (Seller sorts before Buyer).
        $this->assertSame($seller->id, (int) $contact->contact_type_id);
        $this->assertSame([$tag->id], $newly, 'newly-attached tag reported for ContactTagged event');
    }

    public function test_reassign_updates_mirror_and_clears_dropped_tags(): void
    {
        $agencyId = $this->seedAgency();
        [$seller, $buyer] = $this->parents();
        $tag = $this->subTag($agencyId, $seller->id, 'Cash seller');

        $contact = $this->makeContact($agencyId);
        $contact->syncTypeAssignments([$seller->id], [$tag->id]);

        // Reassign to Buyer only, no tags.
        $contact->syncTypeAssignments([$buyer->id], []);
        $contact->refresh();

        $this->assertSame([$buyer->id], $contact->parentTypes()->pluck('contact_types.id')->all());
        $this->assertSame($buyer->id, (int) $contact->contact_type_id);
        $this->assertSame([], $contact->tags()->pluck('contact_tags.id')->all(), 'dropped tag detached');
    }

    public function test_assigning_a_subtag_folds_in_its_parent(): void
    {
        $agencyId = $this->seedAgency();
        [$seller] = $this->parents();
        $tag = $this->subTag($agencyId, $seller->id, 'Cash seller');

        $contact = $this->makeContact($agencyId);

        // Pass NO explicit parent — only the sub-tag. The parent must be folded in.
        $contact->syncTypeAssignments([], [$tag->id]);
        $contact->refresh();

        $this->assertSame([$seller->id], $contact->parentTypes()->pluck('contact_types.id')->all());
        $this->assertSame($seller->id, (int) $contact->contact_type_id);
    }

    public function test_synctags_path_preserves_existing_parents_and_folds_tag_parent(): void
    {
        // Guards the syncTags endpoint fix: it calls syncTypeAssignments with the
        // contact's EXISTING parents + the new tag set, so a tag-only update never
        // drops an existing parent, and a tag under another parent folds it in.
        $agencyId = $this->seedAgency();
        [$seller, $buyer] = $this->parents();
        $sellerTag = $this->subTag($agencyId, $seller->id, 'Cash seller');
        $buyerTag  = $this->subTag($agencyId, $buyer->id, 'First-time buyer');

        $contact = $this->makeContact($agencyId);
        $contact->syncTypeAssignments([$seller->id], [$sellerTag->id]);

        $existing = $contact->parentTypes()->pluck('contact_types.id')->all();
        $contact->syncTypeAssignments($existing, [$sellerTag->id, $buyerTag->id]);
        $contact->refresh();

        $this->assertEqualsCanonicalizing(
            [$seller->id, $buyer->id],
            $contact->parentTypes()->pluck('contact_types.id')->all(),
            'existing Seller kept; Buyer folded in from its sub-tag'
        );
        $this->assertSame($seller->id, (int) $contact->contact_type_id);
    }

    public function test_normalise_protects_the_owner_parent(): void
    {
        $agencyId = $this->seedAgency();
        $owner = ContactType::where('name', 'Owner')->whereNull('esign_role')->firstOrFail();

        $contact = $this->makeContact($agencyId);
        DB::table('contacts')->where('id', $contact->id)->update(['contact_type_id' => $owner->id]);

        $this->artisan('contacts:normalise-types', ['--force' => true, '--preserve-unmappable' => true])->assertSuccessful();

        $this->assertNull(DB::table('contact_types')->where('id', $owner->id)->value('deleted_at'), 'Owner parent must NOT be deleted');
        $contact->refresh();
        $this->assertSame($owner->id, (int) $contact->contact_type_id, 'contact keeps its Owner type');
    }

    public function test_normalise_maps_landlord_to_lessor_keeping_name_as_subtag(): void
    {
        $agencyId = $this->seedAgency();
        $lessor   = ContactType::where('esign_role', 'lessor')->firstOrFail();

        // "Landlord" — a synonym for Lessor; not a parent name, no esign_role.
        $landlordId = (int) DB::table('contact_types')->insertGetId([
            'name' => 'Landlord', 'esign_role' => null, 'color' => '#fff',
            'sort_order' => 9, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contact = $this->makeContact($agencyId);
        DB::table('contacts')->where('id', $contact->id)->update(['contact_type_id' => $landlordId]);

        $this->artisan('contacts:normalise-types', ['--force' => true])->assertSuccessful();

        $contact->refresh();
        $this->assertSame($lessor->id, (int) $contact->contact_type_id, 'mirror remapped to Lessor');
        $this->assertSame([$lessor->id], $contact->parentTypes()->pluck('contact_types.id')->all());
        $this->assertNotNull(DB::table('contact_types')->where('id', $landlordId)->value('deleted_at'), 'Landlord type soft-deleted');

        $tag = DB::table('contact_tag')
            ->join('contact_tags', 'contact_tags.id', '=', 'contact_tag.contact_tag_id')
            ->where('contact_tag.contact_id', $contact->id)
            ->first(['contact_tags.name', 'contact_tags.contact_type_id']);
        $this->assertSame('Landlord', $tag->name);
        $this->assertSame($lessor->id, (int) $tag->contact_type_id, 'name kept as sub-tag under Lessor');
    }

    public function test_normalise_preserves_non_transaction_type_as_unsorted_tag(): void
    {
        $agencyId = $this->seedAgency();

        $attorneyId = (int) DB::table('contact_types')->insertGetId([
            'name' => 'Attorney', 'esign_role' => null, 'color' => '#fff',
            'sort_order' => 9, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contact = $this->makeContact($agencyId);
        DB::table('contacts')->where('id', $contact->id)->update(['contact_type_id' => $attorneyId]);

        // Without the flag it must ABORT and change nothing.
        $this->artisan('contacts:normalise-types', ['--force' => true])->assertFailed();
        $contact->refresh();
        $this->assertSame($attorneyId, (int) $contact->contact_type_id, 'aborted — unchanged');

        // With the flag: kept as an unsorted (parent-less) tag, transaction type cleared.
        $this->artisan('contacts:normalise-types', ['--force' => true, '--preserve-unmappable' => true])->assertSuccessful();

        $contact->refresh();
        $this->assertNull($contact->contact_type_id, 'transaction type cleared');
        $this->assertSame([], $contact->parentTypes()->pluck('contact_types.id')->all());
        $this->assertNotNull(DB::table('contact_types')->where('id', $attorneyId)->value('deleted_at'), 'Attorney type soft-deleted');

        $tag = DB::table('contact_tag')
            ->join('contact_tags', 'contact_tags.id', '=', 'contact_tag.contact_tag_id')
            ->where('contact_tag.contact_id', $contact->id)
            ->first(['contact_tags.name', 'contact_tags.contact_type_id']);
        $this->assertSame('Attorney', $tag->name);
        $this->assertNull($tag->contact_type_id, 'kept as an unsorted (parent-less) tag');
    }

    public function test_clearing_all_assignments_nulls_the_mirror(): void
    {
        $agencyId = $this->seedAgency();
        [$seller] = $this->parents();

        $contact = $this->makeContact($agencyId);
        $contact->syncTypeAssignments([$seller->id], []);
        $contact->syncTypeAssignments([], []);
        $contact->refresh();

        $this->assertNull($contact->contact_type_id);
        $this->assertSame([], $contact->parentTypes()->pluck('contact_types.id')->all());
    }

    public function test_bulk_destroy_deletes_only_the_selected_subtags(): void
    {
        $agencyId = $this->seedAgency();
        $admin = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        $seller = ContactType::where('esign_role', 'seller')->firstOrFail();

        $a = $this->subTag($agencyId, $seller->id, 'AAA');
        $b = $this->subTag($agencyId, $seller->id, 'BBB');
        $c = $this->subTag($agencyId, $seller->id, 'CCC');

        $this->actingAs($admin)
            ->delete(route('corex.settings.contact-tags.bulk-destroy'), ['tag_ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertNotNull(DB::table('contact_tags')->where('id', $a->id)->value('deleted_at'));
        $this->assertNotNull(DB::table('contact_tags')->where('id', $b->id)->value('deleted_at'));
        $this->assertNull(DB::table('contact_tags')->where('id', $c->id)->value('deleted_at'), 'unselected sub-tag kept');
    }

    public function test_owner_contact_is_promoted_to_seller_when_linked_to_a_property(): void
    {
        $agencyId = $this->seedAgency();
        $owner  = ContactType::where('name', 'Owner')->whereNull('esign_role')->firstOrFail();
        $seller = ContactType::where('esign_role', 'seller')->firstOrFail();

        $contact = $this->makeContact($agencyId);
        $contact->syncTypeAssignments([$owner->id], []);
        $contact->refresh();
        $this->assertSame([$owner->id], $contact->parentTypes()->pluck('contact_types.id')->all());

        $event = new \App\Events\Contact\ContactLinkedToProperty(
            $contact, new \App\Models\Property(['agency_id' => $agencyId]), 'owner'
        );
        (new \App\Listeners\Contact\PromoteOwnerToSellerOnPropertyLink())->handle($event);

        $contact->refresh();
        $this->assertSame([$seller->id], $contact->parentTypes()->pluck('contact_types.id')->all(), 'Owner promoted to Seller');
        $this->assertSame($seller->id, (int) $contact->contact_type_id, 'mirror moved to Seller');
    }

    public function test_owner_contact_is_not_promoted_when_linked_as_a_buyer(): void
    {
        $agencyId = $this->seedAgency();
        $owner = ContactType::where('name', 'Owner')->whereNull('esign_role')->firstOrFail();

        $contact = $this->makeContact($agencyId);
        $contact->syncTypeAssignments([$owner->id], []);

        $event = new \App\Events\Contact\ContactLinkedToProperty(
            $contact, new \App\Models\Property(['agency_id' => $agencyId]), 'buyer'
        );
        (new \App\Listeners\Contact\PromoteOwnerToSellerOnPropertyLink())->handle($event);

        $contact->refresh();
        $this->assertSame([$owner->id], $contact->parentTypes()->pluck('contact_types.id')->all(), 'buyer link must not promote');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }

    /** @return ContactType[] [seller, buyer] */
    private function parents(): array
    {
        return [
            ContactType::where('esign_role', 'seller')->firstOrFail(),
            ContactType::where('esign_role', 'buyer')->firstOrFail(),
        ];
    }

    private function subTag(int $agencyId, int $parentId, string $name): ContactTag
    {
        $id = (int) DB::table('contact_tags')->insertGetId([
            'agency_id' => $agencyId,
            'contact_type_id' => $parentId,
            'name' => $name,
            'color' => '#6366f1',
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ContactTag::withoutGlobalScopes()->findOrFail($id);
    }

    private function makeContact(int $agencyId): Contact
    {
        $id = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'first_name' => 'AT79',
            'last_name'  => Str::random(5),
            'phone'      => '08' . random_int(10000000, 99999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Contact::withoutGlobalScopes()->findOrFail($id);
    }
}
