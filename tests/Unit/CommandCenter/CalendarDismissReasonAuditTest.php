<?php

declare(strict_types=1);

namespace Tests\Unit\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventAuditEntry;
use App\Models\User;
use App\Http\Controllers\CommandCenter\CalendarController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 912b419b4 ("persist and surface the dismiss reason; audit dismiss/complete")
 * shipped with NO test — its own commit message says "verified on real QA1
 * events", i.e. someone looked at a screen. This proves the actual contract
 * from the code, not the commit message:
 *
 *  1. Dismissing WITH a reason persists dismissal_reason_code +
 *     dismissal_reason_notes and status='dismissed', and dismissalReasonLabel()
 *     — the value the event panel and the Contact page both read — surfaces it.
 *  2. The reason is NULLABLE, not required at the model/DB layer (the migration
 *     adds both columns ->nullable(); the UI enforces a code before submit, the
 *     server does not). Dismissing with NO code (the mobile API's path, which
 *     has no reason-picker UI) does NOT blank out a reason recorded earlier —
 *     CalendarEvent::markDismissed()'s own docblock: "PRESERVE any existing
 *     reason when omitted, never blank one out to null."
 *  3. dismiss() and complete() each write a CalendarEventAuditEntry — for the
 *     direct (non-recurring) path AND the recurring "scope=all" path — naming
 *     the ACTING user (not the event's owner) as performed_by_user_id and the
 *     correct calendar_event_id.
 *
 * DB-execution approach: this worktree's MySQL user (nexus@localhost) cannot
 * run `migrate:fresh` / RefreshDatabase at all right now — log_bin_trust_
 * function_creators is OFF server-wide and nexus lacks SUPER, so ANY migration
 * that creates a MySQL trigger (e.g. the contact-audit trigger, unrelated to
 * calendar) fails with ERROR 1419 before a single calendar table is touched.
 * That is shared infrastructure and out of this task's scope to change.
 *
 * Rather than fall back to a pure SQL-compilation test, this test hand-builds
 * ONLY the tables this code path actually touches (calendar_events,
 * calendar_event_audit_log, plus the two tiny tables their global scopes/
 * traits reference: properties, agencies) directly via Schema::create() in
 * the granted throwaway test schema — bypassing artisan migrate entirely, so
 * the blocked trigger migration is never reached. Every assertion below runs
 * real INSERT/UPDATE/SELECT statements through the REAL, unmodified
 * CalendarController::dismiss()/complete(), CalendarEvent::markDismissed(),
 * and RecurrenceEditService — not a reimplementation.
 *
 * The intended full end-to-end feature test (RefreshDatabase, real routes,
 * real HTTP) is NOT written here — that belongs in
 * tests/Feature/CommandCenter/ once the infra gotcha is fixed. This test
 * is deliberately narrower but genuinely executes the shipped code.
 */
final class CalendarDismissReasonAuditTest extends TestCase
{
    private const AGENCY_ID = 555001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    /**
     * hfc_dash_test_78 is a designated throwaway test schema (regex-enforced
     * by Tests\TestCase::setUp()) that has, in this environment, been left in
     * a partial state by an EARLIER unrelated `migrate:fresh` attempt that
     * aborted mid-script at the blocked trigger migration (see class docblock)
     * — real tables with real FKs pointing at a real `calendar_events` may
     * already exist from that aborted run. FK checks are disabled only around
     * this test's own drop/create of its four tables so that debris is never
     * a reason this test can't manage its own schema; nothing outside those
     * four table names is touched.
     */
    private function dropSchema(): void
    {
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('calendar_event_audit_log');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('agencies');
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ── (1) Dismiss WITH a reason: persisted AND surfaced ──────────────────

    public function test_dismiss_with_reason_persists_it_and_it_comes_back_out_via_the_label(): void
    {
        $event = $this->makeEvent(['user_id' => 501, 'status' => 'pending']);
        $actor = $this->makeUser(777);

        $this->callDismiss($event, $actor, [
            'completion_reason_code' => 'not_interested',
            'completion_reason'      => 'Buyer found another property',
        ]);

        $fresh = CalendarEvent::withoutGlobalScopes()->find($event->id);
        $this->assertSame('dismissed', $fresh->status);
        $this->assertSame('not_interested', $fresh->dismissal_reason_code);
        $this->assertSame('Buyer found another property', $fresh->dismissal_reason_notes);

        // The exact value the event panel (panelData.dismissal_reason_label)
        // and the Contact page's linked-events partial both read.
        $this->assertSame('Buyer found another property', $fresh->dismissalReasonLabel());
    }

    public function test_dismiss_with_a_code_but_no_typed_notes_surfaces_a_headline_cased_code(): void
    {
        // The reason-picker sends the raw code as "notes" too when the agent
        // didn't type anything for an "Other"-style option — dismissalReasonLabel()
        // must not just echo the raw snake_case code back at the user.
        $event = $this->makeEvent(['user_id' => 501, 'status' => 'pending']);
        $actor = $this->makeUser(777);

        $this->callDismiss($event, $actor, [
            'completion_reason_code' => 'client_unresponsive',
            'completion_reason'      => 'client_unresponsive',
        ]);

        $fresh = CalendarEvent::withoutGlobalScopes()->find($event->id);
        $this->assertSame('Client Unresponsive', $fresh->dismissalReasonLabel());
    }

    // ── (2) No reason: nullable, and existing reason is preserved ──────────

    public function test_reason_is_nullable_not_required_dismissing_with_no_code_still_dismisses(): void
    {
        $event = $this->makeEvent(['user_id' => 501, 'status' => 'pending']);
        $actor = $this->makeUser(777);

        $this->callDismiss($event, $actor, []); // no completion_reason_code/completion_reason at all

        $fresh = CalendarEvent::withoutGlobalScopes()->find($event->id);
        $this->assertSame('dismissed', $fresh->status);
        $this->assertNull($fresh->dismissal_reason_code);
        $this->assertNull($fresh->dismissalReasonLabel());
    }

    public function test_reason_less_dismiss_preserves_a_reason_recorded_earlier_mobile_api_path(): void
    {
        // Exercises CalendarEvent::markDismissed() directly — the exact method
        // the reason-less mobile API path (CommandCenterApiController::
        // calendarDismiss(), no reason-picker UI) calls.
        $event = $this->makeEvent([
            'user_id' => 501, 'status' => 'pending',
            'dismissal_reason_code' => 'already_recorded',
            'dismissal_reason_notes' => 'Set earlier from the web reason-picker',
        ]);

        $event->markDismissed(); // both args default null — the mobile-API shape

        $fresh = CalendarEvent::withoutGlobalScopes()->find($event->id);
        $this->assertSame('dismissed', $fresh->status);
        $this->assertSame('already_recorded', $fresh->dismissal_reason_code, 'A reason-less dismiss must not blank out a reason already on record.');
        $this->assertSame('Set earlier from the web reason-picker', $fresh->dismissal_reason_notes);
    }

    // ── (3) Audit: dismiss AND complete, direct AND recurring, right actor ─

    public function test_dismiss_writes_an_audit_entry_naming_the_acting_user_not_the_owner(): void
    {
        $event = $this->makeEvent(['user_id' => 501, 'status' => 'pending']);
        $actor = $this->makeUser(777); // deliberately NOT the event owner (501)

        $this->callDismiss($event, $actor, [
            'completion_reason_code' => 'lost_to_competitor',
            'completion_reason'      => 'Went with another agency',
        ]);

        $entries = CalendarEventAuditEntry::withoutGlobalScopes()->where('calendar_event_id', $event->id)->get();
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame('dismissed', $entry->action);
        $this->assertSame(777, $entry->performed_by_user_id, 'Audit must name the ACTOR, not the event owner.');
        $this->assertSame($event->id, $entry->calendar_event_id);
        $this->assertSame('dismissed', $entry->new_values['status'] ?? null);
        $this->assertSame('lost_to_competitor', $entry->new_values['dismissal_reason_code'] ?? null);
    }

    public function test_complete_writes_an_audit_entry_naming_the_acting_user(): void
    {
        $event = $this->makeEvent(['user_id' => 501, 'status' => 'pending']);
        $actor = $this->makeUser(888);

        $this->callComplete($event, $actor, []);

        $entries = CalendarEventAuditEntry::withoutGlobalScopes()->where('calendar_event_id', $event->id)->get();
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame('completed', $entry->action);
        $this->assertSame(888, $entry->performed_by_user_id);
        $this->assertSame('completed', $entry->new_values['status'] ?? null);

        $fresh = CalendarEvent::withoutGlobalScopes()->find($event->id);
        $this->assertSame('completed', $fresh->status);
    }

    public function test_recurring_scope_all_dismiss_and_complete_both_audit_against_the_parent_id(): void
    {
        $dismissParent = $this->makeEvent(['user_id' => 501, 'status' => 'pending', 'is_recurring' => true]);
        $actor1 = $this->makeUser(701);
        $this->callDismiss($dismissParent, $actor1, [
            'recur_scope' => 'all',
            'completion_reason_code' => 'series_cancelled',
            'completion_reason' => 'Whole series cancelled',
        ], wantsJson: true);

        $dismissedEntries = CalendarEventAuditEntry::withoutGlobalScopes()->where('calendar_event_id', $dismissParent->id)->get();
        $this->assertCount(1, $dismissedEntries);
        $this->assertSame('dismissed', $dismissedEntries->first()->action);
        $this->assertSame(701, $dismissedEntries->first()->performed_by_user_id);
        $freshDismiss = CalendarEvent::withoutGlobalScopes()->find($dismissParent->id);
        $this->assertSame('dismissed', $freshDismiss->status);
        $this->assertSame('series_cancelled', $freshDismiss->dismissal_reason_code);

        $completeParent = $this->makeEvent(['user_id' => 501, 'status' => 'pending', 'is_recurring' => true]);
        $actor2 = $this->makeUser(702);
        $this->callComplete($completeParent, $actor2, ['recur_scope' => 'all'], wantsJson: true);

        $completedEntries = CalendarEventAuditEntry::withoutGlobalScopes()->where('calendar_event_id', $completeParent->id)->get();
        $this->assertCount(1, $completedEntries);
        $this->assertSame('completed', $completedEntries->first()->action);
        $this->assertSame(702, $completedEntries->first()->performed_by_user_id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function callDismiss(CalendarEvent $event, User $actor, array $input, bool $wantsJson = false)
    {
        $request = Request::create('/corex/command-center/calendar/' . $event->id . '/dismiss', 'POST', $input);
        if ($wantsJson) {
            $request->headers->set('Accept', 'application/json');
        }
        $request->setUserResolver(fn () => $actor);

        return app(CalendarController::class)->dismiss($request, $event->fresh());
    }

    private function callComplete(CalendarEvent $event, User $actor, array $input, bool $wantsJson = false)
    {
        $request = Request::create('/corex/command-center/calendar/' . $event->id . '/complete', 'POST', $input);
        if ($wantsJson) {
            $request->headers->set('Accept', 'application/json');
        }
        $request->setUserResolver(fn () => $actor);

        return app(CalendarController::class)->complete($request, $event->fresh());
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->role = 'agent';
        return $user;
    }

    private function makeEvent(array $overrides = []): CalendarEvent
    {
        return CalendarEvent::create(array_merge([
            'user_id' => 501,
            'created_by_id' => 501,
            'event_type' => 'manual',
            'category' => 'viewing',
            'title' => 'Test event',
            'event_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHour(),
            'all_day' => false,
            'priority' => 'normal',
            'status' => 'pending',
            'source_type' => null,
            'is_recurring' => false,
            'agency_id' => self::AGENCY_ID,
            'branch_id' => self::AGENCY_ID,
        ], $overrides));
    }

    private function buildSchema(): void
    {
        $this->dropSchema();

        // Minimal — only satisfies LivePropertyScope's whereExists subquery
        // (fires on every calendar_events query regardless of property_id).
        Schema::create('properties', function ($table) {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
        });

        // Minimal — exactly one row so BelongsToAgency's single-agency test
        // fallback stamps agency_id the same way it would on a fresh dev DB.
        Schema::create('agencies', function ($table) {
            $table->id();
            $table->string('name')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('agencies')->insert(['id' => self::AGENCY_ID, 'name' => 'Test Agency']);

        Schema::create('calendar_events', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('event_type', 50)->nullable();
            $table->string('category', 80)->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->dateTime('event_date');
            $table->dateTime('end_date')->nullable();
            $table->boolean('all_day')->default(true);
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('pending');
            $table->string('colour', 7)->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->json('reminder_offsets')->nullable();
            $table->json('reminders_sent')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule', 255)->nullable();
            $table->unsignedBigInteger('parent_event_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('dismissal_reason_code', 50)->nullable();
            $table->text('dismissal_reason_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('calendar_event_audit_log', function ($table) {
            $table->id();
            $table->unsignedBigInteger('calendar_event_id');
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->unsignedBigInteger('on_behalf_of_user_id')->nullable();
            $table->timestamp('performed_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
}
