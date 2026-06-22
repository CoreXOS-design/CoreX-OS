<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\ContactType;
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
