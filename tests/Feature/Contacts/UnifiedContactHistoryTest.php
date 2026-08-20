<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Contacts\ContactHistoryService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CX-110 (Johan, 2026-08-20) — the contact History tab used to read ONLY contact_audit_log.
 * Real history was sitting, correctly written, in buyer_activity_log, calendar_event_feedback,
 * calendar_events, and contact_access_log the whole time. ContactHistoryService merges all
 * five; this covers the default (human-only) vs system-trail toggle split, dedup between
 * buyer_activity_log's feedback_captured rows and the raw calendar_event_feedback row they
 * mirror, cross-agency scope safety, and that the tab badge count never disagrees with the
 * list beneath it (Johan's standing rule — this has bitten three times today).
 */
final class UnifiedContactHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_contacts', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'first_name' => 'RC', 'last_name' => 'TestBrewer',
            'phone' => '+2782' . random_int(1000000, 9999999),
            'is_buyer' => 1, 'buyer_state' => 'warm',
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** Seeds one real row + one telemetry/system row per source, for our contact AND a
     * different agency's contact (scope-leak canary). Returns the calendar_event_feedback id
     * that gets deduped away by a matching buyer_activity_log row. */
    private function seedAllSources(): array
    {
        $otherAgencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(6), 'slug' => 'other-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherBranchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $otherAgencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherContact = Contact::create([
            'agency_id' => $otherAgencyId, 'branch_id' => $otherBranchId,
            'first_name' => 'Other', 'last_name' => 'AgencyContact',
            'phone' => '+2783' . random_int(1000000, 9999999),
            'is_buyer' => 1, 'buyer_state' => 'warm',
        ]);

        // contact_audit_log — one real (actor_type=user), one machine (actor_type=system).
        // Every row needs the SAME key set — DB::table()->insert() builds the column list
        // from the first row only; a missing key on a later row shifts every value after it.
        DB::table('contact_audit_log')->insert([
            ['contact_id' => $this->contact->id, 'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
                'user_id' => $this->agent->id, 'actor_type' => 'user', 'actor_label' => null, 'event_category' => 'contact', 'event_type' => 'contact_updated',
                'human_summary' => 'MARKER_AUDIT_HUMAN', 'created_at' => now()->subDays(1)],
            ['contact_id' => $this->contact->id, 'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
                'user_id' => null, 'actor_type' => 'system', 'actor_label' => 'job:TestJob', 'event_category' => 'system', 'event_type' => 'contact_created',
                'human_summary' => 'MARKER_AUDIT_SYSTEM', 'created_at' => now()->subDays(10)],
            ['contact_id' => $otherContact->id, 'agency_id' => $otherAgencyId, 'branch_id' => $otherBranchId,
                'user_id' => $this->agent->id, 'actor_type' => 'user', 'actor_label' => null, 'event_category' => 'contact', 'event_type' => 'contact_updated',
                'human_summary' => 'MARKER_AUDIT_OTHER_AGENCY', 'created_at' => now()],
        ]);

        // calendar_event_feedback.calendar_event_id FK-references calendar_events — three
        // bare support rows (not asserted on directly) just to satisfy the constraint.
        $ce = fn (int $contactId, int $agencyId, int $branchId) => DB::table('calendar_events')->insertGetId([
            'user_id' => $this->agent->id, 'created_by_id' => $this->agent->id, 'created_by_ai' => 0, 'event_type' => 'manual',
            'category' => 'viewing', 'title' => 'FK support event', 'event_date' => now(), 'all_day' => 0,
            'priority' => 'normal', 'send_reminder' => 0, 'status' => 'completed', 'is_recurring' => 0,
            'contact_id' => $contactId, 'branch_id' => $branchId, 'agency_id' => $agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ceId1 = $ce($this->contact->id, $this->agencyId, $this->branchId);
        $ceId2 = $ce($this->contact->id, $this->agencyId, $this->branchId);
        $ceId3 = $ce($otherContact->id, $otherAgencyId, $otherBranchId);

        // calendar_event_feedback — the row a buyer_activity_log row below will reference (deduped away).
        $feedbackId = DB::table('calendar_event_feedback')->insertGetId([
            'calendar_event_id' => $ceId1, 'contact_id' => $this->contact->id, 'feedback_kind' => 'viewing',
            'internal_notes' => 'MARKER_FEEDBACK_DEDUPED', 'captured_by_user_id' => $this->agent->id,
            'captured_at' => now()->subDays(2), 'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'visibility' => 'internal', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        // An un-referenced feedback row — nothing dedupes this one away, must show directly.
        DB::table('calendar_event_feedback')->insert([
            'calendar_event_id' => $ceId2, 'contact_id' => $this->contact->id, 'feedback_kind' => 'viewing',
            'internal_notes' => 'MARKER_FEEDBACK_STANDALONE', 'captured_by_user_id' => $this->agent->id,
            'captured_at' => now()->subDays(3), 'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'visibility' => 'internal', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);
        DB::table('calendar_event_feedback')->insert([
            'calendar_event_id' => $ceId3, 'contact_id' => $otherContact->id, 'feedback_kind' => 'viewing',
            'internal_notes' => 'MARKER_FEEDBACK_OTHER_AGENCY', 'captured_by_user_id' => $this->agent->id,
            'captured_at' => now(), 'agency_id' => $otherAgencyId, 'branch_id' => $otherBranchId,
            'visibility' => 'internal', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // buyer_activity_log — one real (feedback_captured, referencing the dedup target),
        // one telemetry (contact_access).
        DB::table('buyer_activity_log')->insert([
            ['contact_id' => $this->contact->id, 'agency_id' => $this->agencyId, 'activity_type' => 'feedback_captured',
                'activity_date' => now()->subDays(2), 'related_feedback_id' => $feedbackId,
                'metadata' => json_encode(['event_title' => 'MARKER_ACTIVITY_FEEDBACK']), 'logged_by_user_id' => $this->agent->id,
                'created_at' => now()->subDays(2)],
            ['contact_id' => $this->contact->id, 'agency_id' => $this->agencyId, 'activity_type' => 'contact_access',
                'activity_date' => now()->subHours(1), 'related_feedback_id' => null,
                'metadata' => null, 'logged_by_user_id' => $this->agent->id, 'created_at' => now()->subHours(1)],
            ['contact_id' => $otherContact->id, 'agency_id' => $otherAgencyId, 'activity_type' => 'feedback_captured',
                'activity_date' => now(), 'related_feedback_id' => null,
                'metadata' => json_encode(['event_title' => 'MARKER_ACTIVITY_OTHER_AGENCY']),
                'logged_by_user_id' => $this->agent->id, 'created_at' => now()],
        ]);

        // calendar_events — always real.
        DB::table('calendar_events')->insert([
            ['user_id' => $this->agent->id, 'created_by_id' => $this->agent->id, 'created_by_ai' => 0, 'event_type' => 'manual',
                'category' => 'viewing', 'title' => 'MARKER_EVENT_SCHEDULED', 'event_date' => now()->subDays(5), 'all_day' => 0,
                'priority' => 'normal', 'send_reminder' => 0, 'status' => 'completed', 'is_recurring' => 0,
                'contact_id' => $this->contact->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
                'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)],
            ['user_id' => $this->agent->id, 'created_by_id' => $this->agent->id, 'created_by_ai' => 0, 'event_type' => 'manual',
                'category' => 'viewing', 'title' => 'MARKER_EVENT_OTHER_AGENCY', 'event_date' => now(), 'all_day' => 0,
                'priority' => 'normal', 'send_reminder' => 0, 'status' => 'completed', 'is_recurring' => 0,
                'contact_id' => $otherContact->id, 'branch_id' => $otherBranchId, 'agency_id' => $otherAgencyId,
                'created_at' => now(), 'updated_at' => now()],
        ]);

        // contact_access_log — always telemetry.
        DB::table('contact_access_log')->insert([
            ['agency_id' => $this->agencyId, 'contact_id' => $this->contact->id, 'user_id' => $this->agent->id,
                'action_type' => 'view', 'accessed_at' => now()->subMinutes(10), 'created_at' => now()->subMinutes(10)],
            ['agency_id' => $otherAgencyId, 'contact_id' => $otherContact->id, 'user_id' => $this->agent->id,
                'action_type' => 'view', 'accessed_at' => now(), 'created_at' => now()],
        ]);

        return ['feedback_id' => $feedbackId, 'other_contact' => $otherContact];
    }

    public function test_default_view_shows_only_real_human_activity(): void
    {
        $this->seedAllSources();

        $resp = $this->actingAs($this->agent)
            ->get(route('corex.contacts.show', $this->contact->id) . '?tab=history')
            ->assertOk();

        $resp->assertSee('MARKER_AUDIT_HUMAN');
        $resp->assertSee('MARKER_ACTIVITY_FEEDBACK');   // buyer_activity_log's feedback_captured row
        $resp->assertSee('MARKER_FEEDBACK_STANDALONE');  // the un-referenced calendar_event_feedback row
        $resp->assertSee('MARKER_EVENT_SCHEDULED');

        // System trail, off by default.
        $resp->assertDontSee('MARKER_AUDIT_SYSTEM');

        // Deduped — the raw calendar_event_feedback row a buyer_activity_log row already represents.
        $resp->assertDontSee('MARKER_FEEDBACK_DEDUPED');

        // Cross-agency leak canary — must never appear regardless of toggle state.
        $resp->assertDontSee('MARKER_AUDIT_OTHER_AGENCY');
        $resp->assertDontSee('MARKER_ACTIVITY_OTHER_AGENCY');
        $resp->assertDontSee('MARKER_FEEDBACK_OTHER_AGENCY');
        $resp->assertDontSee('MARKER_EVENT_OTHER_AGENCY');
    }

    public function test_contact_access_log_is_system_trail_only_and_excluded_by_default(): void
    {
        $this->seedAllSources();

        $this->actingAs($this->agent)
            ->get(route('corex.contacts.show', $this->contact->id) . '?tab=history')
            ->assertOk()
            ->assertDontSee('Viewed this record');
    }

    public function test_system_trail_toggle_reveals_the_machine_rows_and_never_leaks_other_agencies(): void
    {
        $this->seedAllSources();

        $resp = $this->actingAs($this->agent)
            ->get(route('corex.contacts.show', $this->contact->id) . '?tab=history&include_system=1')
            ->assertOk();

        $resp->assertSee('MARKER_AUDIT_SYSTEM');
        $resp->assertSee('Viewed this record'); // contact_access_log row, now visible

        $resp->assertDontSee('MARKER_AUDIT_OTHER_AGENCY');
        $resp->assertDontSee('MARKER_EVENT_OTHER_AGENCY');
    }

    public function test_the_tab_badge_count_always_matches_the_list_total(): void
    {
        $this->seedAllSources();
        $service = app(ContactHistoryService::class);

        $offCount = $service->count($this->contact, false);
        $offList  = $service->paginate($this->contact, false);
        $this->assertSame($offCount, $offList->total(), 'default-view badge must equal the default-view list total');

        $onCount = $service->count($this->contact, true);
        $onList  = $service->paginate($this->contact, true);
        $this->assertSame($onCount, $onList->total(), 'system-trail badge must equal the system-trail list total');

        $this->assertGreaterThan($offCount, $onCount, 'toggling system trail on must add rows, not just relabel them');
    }

    public function test_the_query_count_stays_flat_regardless_of_row_count(): void
    {
        $this->seedAllSources();
        $service = app(ContactHistoryService::class);

        DB::enableQueryLog();
        $service->paginate($this->contact, false);
        $service->count($this->contact, false); // must reuse the memoized rows(), not re-query
        $offQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $service2 = app(ContactHistoryService::class); // fresh instance, same as a fresh request
        $service2->paginate($this->contact, true);
        $service2->count($this->contact, true);
        $onQueries = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(5, $offQueries, 'default view: 4 source tables + 1 actor lookup, memoized across paginate()+count()');
        $this->assertLessThanOrEqual(6, $onQueries, 'system trail on: 5 source tables + 1 actor lookup, memoized across paginate()+count()');
    }

    public function test_csv_export_uses_the_same_unified_source_and_toggle(): void
    {
        $this->seedAllSources();

        $resp = $this->actingAs($this->agent)
            ->get(route('corex.contacts.show', $this->contact->id) . '?tab=history&export=csv')
            ->assertOk();
        $csv = $resp->getContent();

        $this->assertStringContainsString('MARKER_AUDIT_HUMAN', $csv);
        $this->assertStringNotContainsString('MARKER_AUDIT_SYSTEM', $csv);
        $this->assertStringNotContainsString('MARKER_AUDIT_OTHER_AGENCY', $csv);
    }
}
