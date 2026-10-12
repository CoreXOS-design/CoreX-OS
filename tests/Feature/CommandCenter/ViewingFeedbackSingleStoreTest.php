<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\CommandCenter\AgencyFeedbackOption;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\CommandCenter\CalendarEventInvitation;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\Role;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Properties\PropertyViewings;
use App\Services\Properties\ViewingFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Viewing feedback - ONE store (Johan's rulings R1-R10, 2026-10-08). Spec: .ai/specs/calendar-viewing-feedback.md
 *
 * Covers: both legacy stores before/after the migration command, a row with both, multi-property viewings with
 * one property not viewed / one declined, completed-with-no-feedback, internal comment never on the seller page,
 * every tick and every seller comment shown, the permission matrix, the change log, archive/restore, blank rows,
 * removing a property from an appointment, safe re-save of an old row (R10) and the appointment panel.
 */
final class ViewingFeedbackSingleStoreTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;
    private User $creator;     // agent, branch A - created the appointment
    private User $otherAgent;  // agent, branch A
    private User $bmA;         // branch manager, branch A
    private User $bmB;         // branch manager, branch B
    private User $admin;       // admin, branch A
    private User $agentB;      // agent, branch B
    private Contact $buyer;
    /** @var array<string,AgencyFeedbackOption> */
    private array $opt = [];

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        Bus::fake();
        $this->withoutVite();

        foreach (['super_admin', 'admin', 'branch_manager', 'agent', 'viewer', 'office_admin'] as $name) {
            Role::forceCreate(['name' => $name, 'label' => ucfirst($name), 'agency_id' => null, 'is_owner' => $name === 'super_admin']);
        }
        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $this->seed(\Database\Seeders\CalendarEventClassSeeder::class);
        $ref = new \ReflectionProperty(CalendarEventClassSetting::class, 'resolveCache');
        $ref->setAccessible(true);
        $ref->setValue(null, []);

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branchA = Branch::create(['agency_id' => $this->agency->id, 'name' => 'A']);
        $this->branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'B']);
        $mk = fn (string $role, Branch $b) => User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $b->id, 'role' => $role]);
        $this->creator = $mk('agent', $this->branchA);
        $this->otherAgent = $mk('agent', $this->branchA);
        $this->bmA = $mk('branch_manager', $this->branchA);
        $this->bmB = $mk('branch_manager', $this->branchB);
        $this->admin = $mk('admin', $this->branchA);
        $this->agentB = $mk('agent', $this->branchB);
        $this->buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'first_name' => 'Lettie', 'last_name' => 'Venter',
            'phone' => '0831234567', 'email' => 'lettie@example.co.za',
        ]);
        foreach (['Price', 'Location', 'Condition', 'Size', 'Layout', 'Damp / maintenance', 'School zone', 'Parking', 'Garden / outdoor'] as $i => $label) {
            $this->opt[$label] = $this->option('concern', $label, ($i + 1) * 10);
        }
        foreach (['Interested', 'Not interested', 'Made offer'] as $i => $label) {
            $this->opt[$label] = $this->option('outcome', $label, ($i + 1) * 10);
        }
    }

    // ── R1: both legacy stores, before and after the migration command ────

    public function test_rows_from_each_legacy_store_before_and_after_the_migration(): void
    {
        $a = $this->property('Column-store home');
        $b = $this->property('JSON-store home');
        $evA = $this->viewing('Old form viewing', [$a], ['status' => 'completed']);
        $evB = $this->viewing('Current form viewing', [$b], ['status' => 'completed']);

        // Store 1: the older per-contact form - content in the columns.
        $this->row($evA, $a, ['concern_option_ids' => [$this->id('Damp / maintenance')], 'outcome_option_id' => $this->id('Not interested'), 'seller_visible_notes' => 'Too dark']);
        // Store 2: the current per-property form - content in the JSON bundle, stamped as a listing presentation.
        $this->row($evB, $b, [
            'feedback_kind' => 'listing_presentation', 'concern_option_ids' => null, 'outcome_option_id' => null,
            'kind_specific_data' => ['outcome' => 'Made offer', 'concern_ids' => [$this->id('Price'), $this->id('Size')], 'mandate_type' => null, 'seller_notes' => 'Loved the view'],
        ]);

        $svc = app(PropertyViewings::class);
        // BEFORE: the JSON row's ticks / outcome / comment are invisible (the original bug); the column row is fine.
        $this->assertSame(['Damp / maintenance' => 1], $svc->rollup($a->id)['top_concern_labels']);
        $this->assertSame([], $svc->rollup($b->id)['top_concern_labels']);
        $this->assertSame(1, $svc->rollup($b->id)['total_viewings'], 'completed still counts as held');

        // DRY RUN writes nothing.
        $this->artisan('viewing-feedback:migrate-single-store')->assertExitCode(0);
        $this->assertSame('listing_presentation', CalendarEventFeedback::withoutGlobalScopes()->where('property_id', $b->id)->value('feedback_kind'));
        $this->assertSame(0, DB::table('viewing_feedback_migration_log')->count());

        // APPLY.
        $this->artisan('viewing-feedback:migrate-single-store', ['--apply' => true])->assertExitCode(0);
        $moved = CalendarEventFeedback::withoutGlobalScopes()->where('property_id', $b->id)->first();
        $this->assertSame('viewing', $moved->feedback_kind, 'mismatch 10: stamped as what it is');
        $this->assertSame($this->id('Made offer'), (int) $moved->outcome_option_id);
        $this->assertEqualsCanonicalizing([$this->id('Price'), $this->id('Size')], $moved->concern_option_ids);
        $this->assertSame('Loved the view', $moved->seller_visible_notes);
        $this->assertNotEmpty($moved->kind_specific_data, 'originals kept');
        // The column row is untouched.
        $this->assertSame('Too dark', CalendarEventFeedback::withoutGlobalScopes()->where('property_id', $a->id)->value('seller_visible_notes'));

        // AFTER: both stores read identically through the one source.
        $this->assertEqualsCanonicalizing(['Price' => 1, 'Size' => 1], $svc->rollup($b->id, true)['top_concern_labels']);
        $this->assertSame('Loved the view', $svc->sellerNotes($b->id)[0]['notes']);
        $this->assertSame('Made offer', $svc->sellerNotes($b->id)[0]['outcome_label']);

        // IDEMPOTENT: a second apply changes nothing and logs nothing new.
        $logged = DB::table('viewing_feedback_migration_log')->count();
        $this->artisan('viewing-feedback:migrate-single-store', ['--apply' => true])->assertExitCode(0);
        $this->assertSame($logged, DB::table('viewing_feedback_migration_log')->count());

        // REVERSIBLE: --reverse puts the previous column values back.
        $this->artisan('viewing-feedback:migrate-single-store', ['--reverse' => true, '--apply' => true])->assertExitCode(0);
        $back = CalendarEventFeedback::withoutGlobalScopes()->where('property_id', $b->id)->first();
        $this->assertSame('listing_presentation', $back->feedback_kind);
        $this->assertNull($back->outcome_option_id);
        $this->assertEmpty($back->concern_option_ids);
        $this->assertNotEmpty($back->kind_specific_data);
    }

    public function test_a_row_with_both_stores_keeps_the_column_value_and_adds_the_json_ticks(): void
    {
        $p = $this->property('Both');
        $ev = $this->viewing('v', [$p], ['status' => 'completed']);
        $this->row($ev, $p, [
            'feedback_kind' => 'listing_presentation',
            'concern_option_ids' => [$this->id('Location')], 'outcome_option_id' => $this->id('Interested'), 'seller_visible_notes' => 'Column comment',
            'kind_specific_data' => ['outcome' => 'Not interested', 'concern_ids' => [$this->id('Parking')], 'seller_notes' => 'JSON comment'],
        ]);

        $this->artisan('viewing-feedback:migrate-single-store', ['--apply' => true])->assertExitCode(0);
        $fb = CalendarEventFeedback::withoutGlobalScopes()->first();
        $this->assertSame($this->id('Interested'), (int) $fb->outcome_option_id, 'the column outcome wins');
        $this->assertSame('Column comment', $fb->seller_visible_notes, 'the column comment wins');
        $this->assertEqualsCanonicalizing([$this->id('Location'), $this->id('Parking')], $fb->concern_option_ids, 'ticks from both are kept');
    }

    // ── R6: counting rule ────────────────────────────────────────────────

    public function test_completed_with_no_feedback_counts_as_a_viewing_held(): void
    {
        $p = $this->property('P');
        $this->viewing('done', [$p], ['status' => 'completed']);

        $this->assertSame(1, app(PropertyViewings::class)->rollup($p->id, true)['total_viewings']);
        $seller = $this->get('/property/live/' . $this->sellerLink($p)->token)->assertOk()->getContent();
        $this->assertStringContainsString('1 viewing recorded so far', $seller);
    }

    public function test_multi_property_viewing_one_not_viewed_one_declined_one_viewed(): void
    {
        [$viewed, $notViewed, $declined] = [$this->property('Viewed'), $this->property('Not viewed'), $this->property('Declined')];
        $ev = $this->viewing('Three homes', [$viewed, $notViewed, $declined], ['status' => 'completed']);
        $this->row($ev, $viewed, ['concern_option_ids' => [$this->id('Size')]]);
        $this->row($ev, $notViewed, ['viewing_status' => 'did_not_happen', 'internal_notes' => 'Buyer ran out of time']);
        $this->row($ev, $declined, ['viewing_status' => 'declined_on_arrival', 'internal_notes' => 'Did not like the street']);

        $svc = app(PropertyViewings::class);
        $this->assertSame(1, $svc->rollup($viewed->id, true)['total_viewings']);

        // (a) did not happen: not a viewing, and nothing at all for the seller.
        $this->assertSame(0, $svc->rollup($notViewed->id, true)['total_viewings']);
        $this->assertSame(0, $svc->rollup($notViewed->id, true)['declined_on_arrival']);
        $page = $this->get('/property/live/' . $this->sellerLink($notViewed)->token)->assertOk()->getContent();
        $this->assertStringNotContainsString('What buyers said', $page);
        $this->assertStringNotContainsString('ran out of time', $page);

        // (b) declined on arrival: feedback, NOT a viewing held, shown on its own line.
        $r = $svc->rollup($declined->id, true);
        $this->assertSame(0, $r['total_viewings']);
        $this->assertSame(1, $r['declined_on_arrival']);
        $page = $this->get('/property/live/' . $this->sellerLink($declined)->token)->assertOk()->getContent();
        $this->assertStringContainsString('1 buyer arrived but chose not to view', $page);
        $text = preg_replace('/\s+/', ' ', strip_tags($page));
        $this->assertDoesNotMatchRegularExpression('/\d+ viewings? recorded so far/', $text, 'no viewing is claimed for a buyer who declined');
        $this->assertDoesNotMatchRegularExpression('/[1-9]\d* viewings? held/', $text);
        $this->assertStringNotContainsString('Did not like the street', $page, 'the internal comment never reaches the seller');
    }

    public function test_booked_only_dismissed_and_deleted_never_count(): void
    {
        $p = $this->property('P');
        $this->viewing('booked', [$p], ['status' => 'pending', 'event_date' => now()->addDay()]);
        $this->viewing('dismissed', [$p], ['status' => 'dismissed']);
        $del = $this->viewing('deleted', [$p], ['status' => 'completed']);
        $del->delete();

        $this->assertSame(0, app(PropertyViewings::class)->rollup($p->id)['total_viewings']);
    }

    // ── R5: seller sees ALL ticks and ALL seller comments, never internal ─

    public function test_the_seller_sees_every_tick_and_every_comment_and_never_an_internal_comment(): void
    {
        $p = $this->property('P');
        $labels = array_keys($this->opt);
        $concernLabels = array_slice($labels, 0, 9); // all nine concerns
        foreach (range(1, 7) as $n) {
            $ev = $this->viewing('v' . $n, [$p], ['status' => 'completed', 'event_date' => now()->subDays($n)]);
            $this->row($ev, $p, [
                'concern_option_ids' => $n === 1 ? array_map(fn ($l) => $this->id($l), $concernLabels) : [$this->id('Price')],
                'seller_visible_notes' => "SELLER-COMMENT-$n", 'internal_notes' => "INTERNAL-COMMENT-$n", 'next_action_notes' => "NEXT-$n",
            ], ['contact_id' => null]);
        }

        $seller = $this->get('/property/live/' . $this->sellerLink($p)->token)->assertOk()->getContent();
        foreach ($concernLabels as $label) {
            $this->assertStringContainsString(htmlspecialchars($label, ENT_QUOTES), $seller, "tick '$label' must reach the seller (no top-2 cut)");
        }
        foreach (range(1, 7) as $n) {
            $this->assertStringContainsString("SELLER-COMMENT-$n", $seller, 'every seller comment, no top-5 cut');
            $this->assertStringNotContainsString("INTERNAL-COMMENT-$n", $seller);
            $this->assertStringNotContainsString("NEXT-$n", $seller);
        }
    }

    // ── R7: blank rows ───────────────────────────────────────────────────

    public function test_an_existing_blank_row_is_not_feedback_and_does_not_make_a_viewing_held(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('pending viewing with a blank row', [$p], ['status' => 'pending']);
        $this->row($ev, $p, []);   // nothing ticked, nothing written

        $r = app(PropertyViewings::class)->rollup($p->id, true);
        $this->assertSame(0, $r['total_viewings']);
        $this->assertSame(0, $r['total_feedback_rows']);
        $this->assertSame(0, $r['viewings_with_feedback']);
    }

    public function test_the_form_does_not_save_a_row_for_a_property_the_agent_did_not_touch(): void
    {
        [$touched, $untouched] = [$this->property('Touched'), $this->property('Untouched')];
        $ev = $this->viewing('two homes', [$touched, $untouched], ['status' => 'pending']);

        $this->actingAs($this->creator)->postJson(route('command-center.calendar.feedback.store', $ev), ['feedback' => [
            ['property_id' => $touched->id, 'viewing_status' => 'viewed', 'concern_ids' => [$this->id('Size')]],
            ['property_id' => $untouched->id, 'viewing_status' => 'viewed', 'concern_ids' => [], 'seller_visible_notes' => '', 'internal_notes' => null],
        ]])->assertOk()->assertJson(['skipped' => [$untouched->id]]);

        $this->assertSame(1, CalendarEventFeedback::withoutGlobalScopes()->count());
        $this->assertNull(CalendarEventFeedback::withoutGlobalScopes()->where('property_id', $untouched->id)->first());
    }

    // ── R3: permission matrix ────────────────────────────────────────────

    public function test_permission_matrix_creator_branch_manager_admin_and_everyone_else(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('v', [$p], ['status' => 'completed', 'user_id' => $this->creator->id, 'created_by_id' => $this->creator->id, 'branch_id' => $this->branchA->id]);
        $svc = app(ViewingFeedbackService::class);

        $this->assertTrue($svc->canEdit($this->creator, $ev), 'the agent who created the appointment');
        $this->assertTrue($svc->canEdit($this->bmA, $ev), 'a branch manager of that branch');
        $this->assertTrue($svc->canEdit($this->admin, $ev), 'an admin');
        $this->assertFalse($svc->canEdit($this->otherAgent, $ev), 'another agent in the same branch');
        $this->assertFalse($svc->canEdit($this->bmB, $ev), 'a branch manager of a DIFFERENT branch');
        $this->assertFalse($svc->canEdit($this->agentB, $ev), 'an agent of another branch');

        // Server-side enforcement on the write routes (visibility is granted by invitation so only the permission differs).
        foreach ([$this->otherAgent, $this->bmA, $this->bmB, $this->agentB] as $u) {
            CalendarEventInvitation::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'event_id' => $ev->id, 'invitee_user_id' => $u->id, 'inviter_user_id' => $this->creator->id, 'status' => 'accepted']);
        }
        $payload = ['feedback' => [['property_id' => $p->id, 'concern_ids' => [$this->id('Size')]]]];
        $this->actingAs($this->otherAgent)->postJson(route('command-center.calendar.feedback.store', $ev), $payload)->assertForbidden();
        $this->actingAs($this->bmB)->postJson(route('command-center.calendar.feedback.store', $ev), $payload)->assertForbidden();
        $this->actingAs($this->agentB)->postJson(route('command-center.calendar.feedback.store', $ev), $payload)->assertForbidden();
        $this->assertSame(0, CalendarEventFeedback::withoutGlobalScopes()->count());

        // Everyone who can see it still READS it (read-only form), flagged can_edit=false.
        $this->actingAs($this->otherAgent)->getJson(route('command-center.calendar.feedback.show', $ev))->assertOk()->assertJson(['can_edit' => false]);
        $this->actingAs($this->creator)->getJson(route('command-center.calendar.feedback.show', $ev))->assertOk()->assertJson(['can_edit' => true]);

        $this->actingAs($this->bmA)->postJson(route('command-center.calendar.feedback.store', $ev), $payload)->assertOk();
        $this->assertSame(1, CalendarEventFeedback::withoutGlobalScopes()->count());
        $this->actingAs($this->admin)->postJson(route('command-center.calendar.feedback.store', $ev), ['feedback' => [['property_id' => $p->id, 'concern_ids' => [$this->id('Size'), $this->id('Price')]]]])->assertOk();
        $this->assertSame(1, CalendarEventFeedback::withoutGlobalScopes()->count(), 'same row, edited');
    }

    // ── R4: change log, original capturer never overwritten ──────────────

    public function test_change_log_records_property_field_old_new_who_and_when_and_the_capturer_is_never_overwritten(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('v', [$p], ['status' => 'pending']);
        $url = route('command-center.calendar.feedback.store', $ev);

        $this->actingAs($this->creator)->postJson($url, ['feedback' => [['property_id' => $p->id, 'outcome_id' => $this->id('Interested'),
            'concern_ids' => [$this->id('Size')], 'seller_visible_notes' => 'Nice garden', 'internal_notes' => 'Wants a discount']]])->assertOk();
        $row = CalendarEventFeedback::withoutGlobalScopes()->first();
        $capturedAt = $row->captured_at;
        $this->assertSame($this->creator->id, (int) $row->captured_by_user_id);
        $this->assertNull($row->last_edited_by_user_id);

        $created = DB::table('calendar_event_feedback_log')->where('action', 'created')->pluck('new_value', 'field');
        $this->assertSame('Interested', $created['outcome']);
        $this->assertSame('Size', $created['concerns']);
        $this->assertSame('Nice garden', $created['seller_comment']);
        $this->assertSame('Wants a discount', $created['internal_comment']);

        // An admin edits.
        $this->travel(2)->hours();
        $this->actingAs($this->admin)->postJson($url, ['feedback' => [['property_id' => $p->id, 'outcome_id' => $this->id('Not interested'),
            'concern_ids' => [$this->id('Size')], 'seller_visible_notes' => 'Nice garden', 'internal_notes' => 'Wants a big discount']]])->assertOk();
        $row->refresh();
        $this->assertSame($this->creator->id, (int) $row->captured_by_user_id, 'original capturer never overwritten');
        $this->assertTrue($capturedAt->equalTo($row->captured_at), 'original capture time never overwritten');
        $this->assertSame($this->admin->id, (int) $row->last_edited_by_user_id);
        $this->assertNotNull($row->last_edited_at);

        $edits = DB::table('calendar_event_feedback_log')->where('action', 'edited')->get()->keyBy('field');
        $this->assertEqualsCanonicalizing(['outcome', 'internal_comment'], $edits->keys()->all());
        $this->assertSame('Interested', $edits['outcome']->old_value);
        $this->assertSame('Not interested', $edits['outcome']->new_value);
        $this->assertSame('Wants a discount', $edits['internal_comment']->old_value);
        $this->assertSame('Wants a big discount', $edits['internal_comment']->new_value);
        $this->assertSame($this->admin->id, (int) $edits['outcome']->user_id);
        $this->assertSame($p->id, (int) $edits['outcome']->property_id);
        $this->assertSame((int) $this->buyer->id, (int) $edits['outcome']->contact_id);
        $this->assertNotNull($edits['outcome']->created_at);

        // An unchanged re-save writes nothing and logs nothing.
        $n = DB::table('calendar_event_feedback_log')->count();
        $this->actingAs($this->admin)->postJson($url, ['feedback' => [['property_id' => $p->id, 'outcome_id' => $this->id('Not interested'),
            'concern_ids' => [$this->id('Size')], 'seller_visible_notes' => 'Nice garden', 'internal_notes' => 'Wants a big discount']]])->assertOk();
        $this->assertSame($n, DB::table('calendar_event_feedback_log')->count());
    }

    // ── R8: archive / restore ────────────────────────────────────────────

    public function test_archive_and_restore_are_soft_logged_and_permission_checked(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('v', [$p], ['status' => 'pending']);
        $fb = $this->row($ev, $p, ['concern_option_ids' => [$this->id('Size')]]);
        CalendarEventInvitation::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'event_id' => $ev->id, 'invitee_user_id' => $this->otherAgent->id, 'inviter_user_id' => $this->creator->id, 'status' => 'accepted']);

        $this->actingAs($this->otherAgent)->postJson(route('command-center.calendar.feedback.archive', [$ev, $fb->id]))->assertForbidden();
        $this->assertNull(CalendarEventFeedback::withoutGlobalScopes()->find($fb->id)->deleted_at);

        $this->actingAs($this->creator)->postJson(route('command-center.calendar.feedback.archive', [$ev, $fb->id]), ['reason' => 'entered on the wrong property'])->assertOk();
        $archived = CalendarEventFeedback::withoutGlobalScopes()->withTrashed()->find($fb->id);
        $this->assertNotNull($archived->deleted_at, 'soft delete - the row is still there');
        $this->assertSame($this->creator->id, (int) $archived->archived_by_user_id);
        $this->assertSame(0, app(PropertyViewings::class)->rollup($p->id)['total_feedback_rows'], 'archived feedback does not count');
        $this->assertTrue(DB::table('calendar_event_feedback_log')->where('action', 'archived')->where('note', 'entered on the wrong property')->exists());

        $this->actingAs($this->creator)->postJson(route('command-center.calendar.feedback.restore', [$ev, $fb->id]))->assertOk();
        $this->assertNull(CalendarEventFeedback::withoutGlobalScopes()->find($fb->id)->deleted_at);
        $this->assertSame(1, app(PropertyViewings::class)->rollup($p->id)['total_feedback_rows']);
        $this->assertTrue(DB::table('calendar_event_feedback_log')->where('action', 'restored')->exists());
    }

    // ── R9: removing a property from the appointment ─────────────────────

    public function test_removing_a_property_from_the_appointment_stops_it_counting_and_archives_its_feedback(): void
    {
        [$keep, $drop] = [$this->property('Keep'), $this->property('Drop')];
        $ev = $this->viewing('two homes', [$keep, $drop], ['status' => 'completed']);
        $this->row($ev, $keep, ['concern_option_ids' => [$this->id('Size')]]);
        $dropRow = $this->row($ev, $drop, ['concern_option_ids' => [$this->id('Price')]]);
        $svc = app(PropertyViewings::class);
        $this->assertSame(1, $svc->rollup($drop->id)['total_viewings']);

        // The appointment is edited so it only covers $keep (the real re-sync path).
        app(\App\Services\CommandCenter\Calendar\CalendarEventCreator::class)
            ->syncEventLinks($ev, ['property_ids' => [$keep->id], 'category' => 'viewing'], $this->creator);

        $this->assertSame(0, $svc->rollup($drop->id)['total_viewings'], 'no longer counts for the removed property');
        $this->assertSame(1, $svc->rollup($keep->id)['total_viewings']);
        $gone = CalendarEventFeedback::withoutGlobalScopes()->withTrashed()->find($dropRow->id);
        $this->assertNotNull($gone, 'archived, not deleted');
        $this->assertNotNull($gone->deleted_at);
        $this->assertSame('property removed from appointment', $gone->archive_reason);
    }

    // ── R10: a re-save of an old row is safe ─────────────────────────────

    public function test_resaving_an_old_form_row_updates_it_in_place_instead_of_failing_or_duplicating(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('v', [$p], ['status' => 'completed']);
        $old = $this->row($ev, $p, ['concern_option_ids' => [$this->id('Damp / maintenance')], 'internal_notes' => 'old note']);

        // The payload the form now sends (previously: HTTP 500 duplicate key on cef_event_contact_property_unique).
        $this->actingAs($this->creator)->postJson(route('command-center.calendar.feedback.store', $ev), ['feedback' => [[
            'property_id' => $p->id, 'viewing_status' => 'viewed', 'outcome_id' => $this->id('Interested'),
            'concern_ids' => [$this->id('Damp / maintenance')], 'seller_visible_notes' => null, 'internal_notes' => 'old note',
        ]]])->assertOk();

        $this->assertSame(1, CalendarEventFeedback::withoutGlobalScopes()->withTrashed()->count(), 'no second row');
        $fb = CalendarEventFeedback::withoutGlobalScopes()->find($old->id);
        $this->assertSame($this->id('Interested'), (int) $fb->outcome_option_id);
        $this->assertEquals([$this->id('Damp / maintenance')], $fb->concern_option_ids, 'the old tick is kept, not lost');
    }

    // ── R2: the appointment panel ────────────────────────────────────────

    public function test_the_appointment_panel_shows_the_captured_feedback_per_property(): void
    {
        [$a, $b] = [$this->property('Alpha'), $this->property('Beta')];
        $ev = $this->viewing('two homes', [$a, $b], ['status' => 'completed']);
        $this->row($ev, $a, ['concern_option_ids' => [$this->id('Size')], 'outcome_option_id' => $this->id('Interested'),
            'seller_visible_notes' => 'Seller sees this', 'internal_notes' => 'Agents see this'], ['captured_by_user_id' => $this->creator->id]);
        $this->row($ev, $b, ['viewing_status' => 'declined_on_arrival']);

        $json = $this->actingAs($this->admin)->getJson(route('command-center.calendar.show', $ev))->assertOk()->json('viewing_feedback');
        $this->assertTrue($json['can_edit']);
        $byLabel = collect($json['properties'])->keyBy(fn ($p) => $p['property_id']);
        $alpha = $byLabel[$a->id]['captures'][0];
        $this->assertSame('Interested', $alpha['outcome_label']);
        $this->assertSame(['Size'], $alpha['concerns']);
        $this->assertSame('Seller sees this', $alpha['seller_notes']);
        $this->assertSame('Agents see this', $alpha['internal_notes']);
        $this->assertSame($this->creator->name, $alpha['captured_by']);
        $this->assertNotNull($alpha['captured_at']);
        $this->assertSame('declined_on_arrival', $byLabel[$b->id]['viewing_status']);
    }

    public function test_the_intelligence_tab_shows_status_ticks_both_comments_and_who_captured_it(): void
    {
        $p = $this->property('Intel');
        $ev = $this->viewing('v', [$p], ['status' => 'completed']);
        $this->row($ev, $p, ['concern_option_ids' => [$this->id('Damp / maintenance')], 'seller_visible_notes' => 'SELLER-LINE', 'internal_notes' => 'INTERNAL-LINE'],
            ['last_edited_by_user_id' => $this->admin->id, 'last_edited_at' => now()->subDay()]);
        $ev2 = $this->viewing('v2', [$p], ['status' => 'completed']);
        $this->row($ev2, $p, ['viewing_status' => 'declined_on_arrival', 'internal_notes' => 'left at the gate']);

        $super = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'super_admin']);
        $html = $this->actingAs($super)->get(route('corex.properties.show', $p))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('Seller comment: SELLER-LINE', $text);
        $this->assertStringContainsString('Internal comment: INTERNAL-LINE', $text);
        $this->assertStringContainsString('Damp / maintenance', $text);
        $this->assertStringContainsString('Buyer declined to view on arrival', $text);
        $this->assertStringContainsString('Captured by ' . $this->creator->name, $text);
        $this->assertStringContainsString('last edited by ' . $this->admin->name, $text);
        $this->assertStringContainsString('arrived but chose not to view', $text);
    }

    // ── mismatch 11: the contact page reads the property's own row ───────

    public function test_a_property_never_gets_a_sibling_propertys_feedback(): void
    {
        [$a, $b] = [$this->property('A'), $this->property('B')];
        $ev = $this->viewing('two homes', [$a, $b], ['status' => 'completed']);
        $this->row($ev, $a, ['internal_notes' => 'about A']);
        $this->row($ev, $b, ['internal_notes' => 'about B']);

        $caps = PropertyViewings::capturesByEvent([$ev->id]);
        $this->assertSame('about B', PropertyViewings::pick($caps->get($ev->id), $b->id, false, $this->buyer->id)['internal_comment']);
        $this->assertSame('about A', PropertyViewings::pick($caps->get($ev->id), $a->id, false, $this->buyer->id)['internal_comment']);
        $c = $this->property('C');
        $this->assertNull(PropertyViewings::pick($caps->get($ev->id), $c->id, false), 'a property with no row gets nothing - not the first row');
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function option(string $category, string $label, int $sort): AgencyFeedbackOption
    {
        return AgencyFeedbackOption::withoutGlobalScopes()->create([
            'agency_id' => null, 'category' => $category, 'label' => $label, 'is_active' => true, 'sort_order' => $sort, 'is_system_default' => true,
        ]);
    }

    private function id(string $label): int
    {
        return (int) $this->opt[$label]->id;
    }

    private function property(string $title): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->creator->id, 'branch_id' => $this->branchA->id,
            'external_id' => (string) Str::uuid(), 'title' => $title . ' ' . Str::random(4), 'suburb' => 'Margate',
            'property_type' => 'apartment', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1_000_000,
        ]);
    }

    /** @param Property[] $properties */
    private function viewing(string $title, array $properties, array $extra = []): CalendarEvent
    {
        $ev = CalendarEvent::withoutGlobalScopes()->create(array_merge([
            'user_id' => $this->creator->id, 'created_by_id' => $this->creator->id, 'event_type' => 'manual', 'category' => 'viewing',
            'title' => $title, 'event_date' => now()->subDays(3), 'status' => 'pending',
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id,
        ], $extra));
        foreach ($properties as $p) {
            CalendarEventLink::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'calendar_event_id' => $ev->id,
                'linkable_type' => Property::class, 'linkable_id' => $p->id, 'role' => 'subject_property', 'created_by_user_id' => $this->creator->id]);
        }
        CalendarEventLink::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'calendar_event_id' => $ev->id,
            'linkable_type' => Contact::class, 'linkable_id' => $this->buyer->id, 'role' => 'buyer_contact', 'created_by_user_id' => $this->creator->id]);

        return $ev;
    }

    private function row(CalendarEvent $ev, ?Property $p, array $fields, array $extra = []): CalendarEventFeedback
    {
        return CalendarEventFeedback::withoutGlobalScopes()->create(array_merge([
            'calendar_event_id' => $ev->id, 'contact_id' => $this->buyer->id, 'property_id' => $p?->id,
            'feedback_kind' => 'viewing', 'visibility' => 'public_to_seller', 'concern_option_ids' => [],
            'captured_by_user_id' => $this->creator->id, 'captured_at' => now()->subDays(2),
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id,
        ], $fields, $extra));
    }

    private function sellerLink(Property $p): PropertySellerLink
    {
        $seller = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'first_name' => 'Tanya', 'last_name' => 'Seller' . Str::random(3),
            'phone' => '083' . random_int(1000000, 9999999), 'email' => 'tanya-' . Str::random(5) . '@example.co.za',
        ]);

        return PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'contact_id' => $seller->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->creator->id, 'generated_at' => now(),
        ]);
    }
}
