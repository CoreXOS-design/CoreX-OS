<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "Delete All" contacts action is a hard purge — it deliberately breaks
 * the no-hard-deletes rule as a super-admin-only maintenance escape hatch.
 *
 * It must:
 *   - purge every contact in the active agency, INCLUDING soft-deleted ones;
 *   - clean up all contact-owned related records so nothing is orphaned;
 *   - never cross tenant boundaries (other agencies untouched);
 *   - be reachable only by super admins (403 otherwise).
 */
final class ContactDestroyAllTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_purges_active_and_soft_deleted_contacts_with_related_records(): void
    {
        [$agencyId, $superAdmin] = $this->seedAgencyUser('super_admin');

        $live    = $this->makeContact($agencyId, $superAdmin->id);
        $trashed = $this->makeContact($agencyId, $superAdmin->id);
        DB::table('contacts')->where('id', $trashed->id)->update(['deleted_at' => now()]);

        // Related records on the live contact.
        $this->seedRelated($agencyId, $superAdmin->id, $live->id);
        // ...and on the soft-deleted one — these must be purged too.
        $this->seedRelated($agencyId, $superAdmin->id, $trashed->id);

        // A second agency with its own contact + related records — must survive.
        [$otherAgencyId, $otherAdmin] = $this->seedAgencyUser('super_admin');
        $foreign = $this->makeContact($otherAgencyId, $otherAdmin->id);
        $this->seedRelated($otherAgencyId, $otherAdmin->id, $foreign->id);

        $this->actingAs($superAdmin)
            ->delete(route('corex.contacts.destroy-all'))
            ->assertRedirect(route('corex.contacts.index'))
            ->assertSessionHas('success');

        // Active agency: every contact gone, including the soft-deleted one.
        $this->assertSame(0, Contact::withoutGlobalScopes()->withTrashed()
            ->where('agency_id', $agencyId)->count(), 'all agency contacts purged');

        // No orphaned related rows for the purged contacts.
        foreach (['contact_notes', 'contact_tag', 'contact_consent_records'] as $table) {
            $this->assertSame(0, DB::table($table)->whereIn('contact_id', [$live->id, $trashed->id])
                ->count(), "{$table} cleaned for purged contacts");
        }
        $this->assertSame(0, DB::table('calendar_event_links')
            ->whereIn('linkable_id', [$live->id, $trashed->id])
            ->where('linkable_type', Contact::class)->count(), 'calendar links cleaned');

        // Tenant isolation: the other agency is untouched.
        $this->assertSame(1, Contact::withoutGlobalScopes()->withTrashed()
            ->where('agency_id', $otherAgencyId)->count(), 'other agency contact survives');
        $this->assertSame(1, DB::table('contact_notes')->where('contact_id', $foreign->id)->count());
        $this->assertSame(1, DB::table('calendar_event_links')->where('linkable_id', $foreign->id)->count());
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        [$agencyId, $agent] = $this->seedAgencyUser('agent');
        $contact = $this->makeContact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->delete(route('corex.contacts.destroy-all'))
            ->assertForbidden();

        $this->assertSame(1, Contact::withoutGlobalScopes()->where('id', $contact->id)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgencyUser(string $role): array
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
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $role,
        ]);

        return [$agencyId, $user];
    }

    private function makeContact(int $agencyId, int $userId): Contact
    {
        $id = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $userId,
            'first_name' => 'Purge',
            'last_name'  => Str::random(5),
            'phone'      => '0821234567',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Contact::withoutGlobalScopes()->findOrFail($id);
    }

    private function seedRelated(int $agencyId, int $userId, int $contactId): void
    {
        DB::table('contact_notes')->insert([
            'contact_id' => $contactId, 'agency_id' => $agencyId, 'user_id' => $userId,
            'body' => 'note', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $tagId = (int) DB::table('contact_tags')->insertGetId([
            'name' => 'Tag ' . Str::random(4), 'agency_id' => $agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contact_tag')->insert([
            'contact_id' => $contactId, 'contact_tag_id' => $tagId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('contact_consent_records')->insert([
            'contact_id' => $contactId, 'agency_id' => $agencyId,
            'consent_type' => 'marketing_communications', 'given_at' => now(),
            'given_by_user_id' => $userId, 'method' => 'electronic',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $eventId = (int) DB::table('calendar_events')->insertGetId([
            'event_type' => 'manual', 'title' => 'Event', 'event_date' => now(),
            'agency_id' => $agencyId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('calendar_event_links')->insert([
            'calendar_event_id' => $eventId, 'agency_id' => $agencyId,
            'linkable_type' => Contact::class, 'linkable_id' => $contactId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
