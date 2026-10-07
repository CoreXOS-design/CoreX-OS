<?php

declare(strict_types=1);

namespace Tests\Unit\CommandCenter;

use App\Http\Controllers\CommandCenter\CalendarController;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Proves the applyFilters() identity-source fix (bare $user->id / $user->branch_id
 * -> dataIdentityIds() / effectiveBranchId(), 4 sites) — the change now live on
 * fix/calendar-applyfilters, NOT yet folded. Johan's role_permissions change is
 * already live on production (admin='all', branch_manager='branch' for HFC), so
 * this gap is no longer theoretical: a branch manager invited to an event outside
 * their branch, or an assistant covering their agent, silently does not see it,
 * TODAY, until this fix ships.
 *
 * Four properties, proven simultaneously per test where the scenario calls for it:
 *   (a) a branch manager still sees every event in their OWN branch — the
 *       behaviour Johan just switched on; this fix must not regress it.
 *   (b) a branch manager ALSO sees an event they were invited to OUTSIDE their
 *       branch — the actual bug being fixed.
 *   (c) an assistant sees their agent's events, including ones the AGENT (not
 *       the assistant) was invited to — AT-267 + the invitation carve-out,
 *       both surviving through applyFilters() (the controller/in-memory layer),
 *       not just CalendarEvent::scopeVisibleTo() (the SQL layer, fixed earlier
 *       the same day in a separate commit).
 *   (d) someone with no relationship to an event — wrong branch, not invited,
 *       not the assistant's agent — never sees it. This is the one that matters
 *       most: every positive assertion above is paired with a negative control
 *       in the SAME test, over the SAME result set, so a fix that leaks can't
 *       hide behind a passing "sees the right thing" assertion alone.
 *
 * DB-execution approach: RefreshDatabase against the REAL calendar_events /
 * calendar_event_invitations / calendar_event_class_settings / agencies tables
 * (rolled back per test) — no raw DDL. This file used to DROP and re-create those
 * tables by hand (a workaround for an old migrate:fresh trigger error); inside a
 * lane's persistent test schema that destroyed tables every later test file needed.
 * The REAL, unmodified (except for this fix) CalendarController::applyFilters() is
 * invoked via reflection (it's `private`, called from 10 sites in the same class —
 * not changed to `protected` for this test, to keep the shipped diff to exactly the
 * 4-site swap and nothing else).
 *
 * calendar_event_class_settings is seeded for this test (not needed by
 * the dismiss-reason or scopeVisibleTo tests) because applyFilters() runs the
 * full CalendarVisibilityResolver::canSee() chain, not just the scope filter —
 * and canSee() returns false with NO matching class-settings row for any
 * viewer who isn't the creator/admin/super_admin/invited. One global
 * (agency_id=null) row with visibility=['all'] on all three colours is seeded
 * so class-visibility is a non-factor, isolating the identity/scope variable
 * this test actually exercises.
 */
final class CalendarApplyFiltersIdentityFixTest extends TestCase
{
    use RefreshDatabase;

    private const AGENCY_ID = 555002;

    protected function setUp(): void
    {
        parent::setUp();
        // The events use made-up user/branch ids whose parent rows are irrelevant to
        // what is proved here, so foreign-key checks are off for this connection
        // (restored in tearDown). The agency row below is real.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        DB::table('agencies')->insert([
            'id' => self::AGENCY_ID, 'name' => 'Test Agency', 'slug' => 'test-agency-'.self::AGENCY_ID,
        ]);

        // One global (agency_id=null) row, visible to 'all' roles on every
        // colour, so class-based visibility never blocks the scope/identity
        // behaviour this test actually exercises. Find-or-update, not create: the
        // row may already exist and (agency_id, event_class) is unique.
        CalendarEventClassSetting::withoutGlobalScopes()->updateOrCreate(
            ['agency_id' => null, 'event_class' => 'viewing'],
            [
                'is_active' => true,
                'event_nature' => 'actionable',
                'green_days' => 7, 'amber_days' => 2, 'red_days' => 0, 'show_days' => 365,
                'green_visibility' => ['all'], 'amber_visibility' => ['all'], 'red_visibility' => ['all'],
                'green_notifications' => [], 'amber_notifications' => [], 'red_notifications' => [],
                'daily_digest_enabled' => false, 'daily_digest_roles' => [],
                'label' => 'Viewing', 'actor_role' => 'both', 'completion_behaviour' => 'freeform',
                'occupies_time' => true, 'autofill_buyers' => false,
            ]
        );
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_branch_manager_keeps_own_branch_sees_invited_outside_branch_does_not_leak_unrelated_other_branch_event(): void
    {
        $branchA = 9001;
        $branchB = 9002;

        $manager = $this->makeUser(701, $branchA, 'branch_manager');

        // (a) regression guard — own-branch event, manager NOT the creator, NOT
        // invited. Must still be visible under scope='branch' after the fix.
        $ownBranchAgent = $this->makeUser(702, $branchA, 'agent');
        $ownBranchEvent = $this->makeEvent(['user_id' => 702, 'branch_id' => $branchA, 'title' => 'Own branch event']);

        // (b) event in a DIFFERENT branch, manager invited to it. NOTE: for an
        // ordinary (non-assistant, non-branch-switched) manager this passes
        // even pre-fix — dataIdentityIds() degenerates to [$user->id] for any
        // non-assistant, so the invitee_user_id lookup was never actually
        // broken for a plain manager. Kept as a correctness assertion (it must
        // keep working), but the branch_id half of the fix — the part that
        // actually changes behaviour for a manager — is proven by
        // test_multi_branch_manager_switched_branch_is_followed_not_their_home_branch()
        // below, which fails pre-fix and passes post-fix.
        $otherBranchAgent = $this->makeUser(703, $branchB, 'agent');
        $invitedOutsideBranchEvent = $this->makeEvent(['user_id' => 703, 'branch_id' => $branchB, 'title' => 'Invited outside branch']);
        CalendarEventInvitation::create([
            'agency_id' => self::AGENCY_ID, 'event_id' => $invitedOutsideBranchEvent->id,
            'invitee_user_id' => 701, 'inviter_user_id' => 703, 'status' => 'accepted',
        ]);

        // (d) the leak guard — same other branch, same agent, but the manager
        // has NO relationship to this one at all. Must stay invisible.
        $unrelatedOtherBranchEvent = $this->makeEvent(['user_id' => 703, 'branch_id' => $branchB, 'title' => 'Unrelated other-branch event']);

        $result = $this->callApplyFilters(
            collect([$ownBranchEvent, $invitedOutsideBranchEvent, $unrelatedOtherBranchEvent]),
            $manager,
            'branch'
        );
        $ids = $result->pluck('id')->all();

        $this->assertContains($ownBranchEvent->id, $ids, 'REGRESSION: branch manager must still see events in their own branch.');
        $this->assertContains($invitedOutsideBranchEvent->id, $ids, 'THE FIX: branch manager must see an event they were invited to outside their branch.');
        $this->assertNotContains($unrelatedOtherBranchEvent->id, $ids, 'LEAK: branch manager must NOT see an unrelated event in another branch they were not invited to.');
    }

    /**
     * The other half of the fix: applyFilters() compared against bare
     * $user->branch_id, never effectiveBranchId() — which honours the
     * admin-multi-branch-manager session override (view_as_branch_id). A
     * manager who manages several branches and has switched "acting as"
     * branch B via the branch switcher has $user->branch_id still pointing at
     * their HOME branch (branch A); before the fix, applyFilters() silently
     * re-filtered back to branch A regardless of the switch. This is the
     * scenario the previous test's "invited outside branch" case does NOT
     * cover — this one fails pre-fix.
     */
    public function test_multi_branch_manager_switched_branch_is_followed_not_their_home_branch(): void
    {
        $homeBranch = 9001;
        $switchedBranch = 9002;

        $manager = $this->makeUser(704, $homeBranch, 'branch_manager');
        session(['view_as_branch_id' => $switchedBranch]);

        $switchedBranchEvent = $this->makeEvent(['user_id' => 705, 'branch_id' => $switchedBranch, 'title' => 'Event in the switched-to branch']);
        $homeBranchEvent = $this->makeEvent(['user_id' => 706, 'branch_id' => $homeBranch, 'title' => 'Event in the (currently inactive) home branch']);

        $result = $this->callApplyFilters(collect([$switchedBranchEvent, $homeBranchEvent]), $manager, 'branch');
        $ids = $result->pluck('id')->all();

        $this->assertContains($switchedBranchEvent->id, $ids, 'THE FIX: a branch-switched manager must see events in the branch they switched TO.');
        $this->assertNotContains($homeBranchEvent->id, $ids, 'While switched, the manager\'s home branch is not the active branch — its events should not leak in either.');
    }

    public function test_assistant_sees_agents_own_events_and_agents_invitations_through_the_controller_not_just_the_database(): void
    {
        $branchA = 9001;

        $agent = $this->makeUser(801, $branchA, 'agent');
        // Anonymous subclass, same technique used earlier today for the
        // scopeVisibleTo() hybrid proof — overrides ONLY dataIdentityIds()
        // (AT-267's own assignment-resolution logic is proven elsewhere,
        // e.g. tests/Feature/Assistants/AssistantSeesTheAgentsBookTest.php;
        // this test's job is proving applyFilters() correctly CONSUMES
        // whatever dataIdentityIds() returns, which is the actual bug).
        $assistant = new class extends User {
            public function dataIdentityIds(): array { return [801, 802]; }
        };
        $assistant->id = 802;
        $assistant->branch_id = $branchA;
        $assistant->role = 'assistant';
        $assistant->is_assistant = true;

        // (c) part 1 — an event the AGENT owns directly (not the assistant).
        $agentsOwnEvent = $this->makeEvent(['user_id' => 801, 'branch_id' => $branchA, 'title' => "Agent's own event"]);

        // (c) part 2 — an event the AGENT (not the assistant) was invited to.
        $thirdParty = $this->makeUser(803, $branchA, 'agent');
        $agentsInvitedEvent = $this->makeEvent(['user_id' => 803, 'branch_id' => $branchA, 'title' => "Agent's invitation"]);
        CalendarEventInvitation::create([
            'agency_id' => self::AGENCY_ID, 'event_id' => $agentsInvitedEvent->id,
            'invitee_user_id' => 801, 'inviter_user_id' => 803, 'status' => 'pending',
        ]);

        // (d) leak guard — a third party's event with NO connection whatsoever
        // to the agent or the assistant.
        $unrelatedEvent = $this->makeEvent(['user_id' => 803, 'branch_id' => $branchA, 'title' => 'Unrelated event']);

        $result = $this->callApplyFilters(
            collect([$agentsOwnEvent, $agentsInvitedEvent, $unrelatedEvent]),
            $assistant,
            'own'
        );
        $ids = $result->pluck('id')->all();

        $this->assertContains($agentsOwnEvent->id, $ids, 'AT-267 REGRESSION: assistant must see their assigned agent\'s own events, via the CONTROLLER path.');
        $this->assertContains($agentsInvitedEvent->id, $ids, 'THE FIX: assistant must see an event their agent was invited to, via the CONTROLLER path.');
        $this->assertNotContains($unrelatedEvent->id, $ids, 'LEAK: assistant must NOT see an event with no connection to them or their agent.');
    }

    public function test_uninvolved_user_in_a_different_branch_never_sees_a_private_event(): void
    {
        $branchA = 9001;
        $branchC = 9003;

        $bystander = $this->makeUser(901, $branchC, 'agent');
        $owner = $this->makeUser(902, $branchA, 'agent');
        $someoneElse = $this->makeUser(903, $branchA, 'agent');

        $ownEvent = $this->makeEvent(['user_id' => 902, 'branch_id' => $branchA, 'title' => 'Owner\'s own event']);
        // Invited, but to someone else entirely — not the bystander.
        $invitedElsewhereEvent = $this->makeEvent(['user_id' => 902, 'branch_id' => $branchA, 'title' => 'Invited someone else']);
        CalendarEventInvitation::create([
            'agency_id' => self::AGENCY_ID, 'event_id' => $invitedElsewhereEvent->id,
            'invitee_user_id' => 903, 'inviter_user_id' => 902, 'status' => 'accepted',
        ]);

        $result = $this->callApplyFilters(collect([$ownEvent, $invitedElsewhereEvent]), $bystander, 'own');

        $this->assertCount(0, $result, 'LEAK: an uninvolved user in a different branch, scope=own, must see NOTHING from another agent\'s book.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function callApplyFilters($events, User $user, string $scope)
    {
        $controller = app(CalendarController::class);
        $method = new ReflectionMethod($controller, 'applyFilters');
        $method->setAccessible(true);

        return $method->invoke($controller, $events, $user, [], [], $scope);
    }

    private function makeUser(int $id, int $branchId, string $role): User
    {
        $user = new User();
        $user->id = $id;
        $user->branch_id = $branchId;
        $user->agency_id = self::AGENCY_ID;
        $user->role = $role;
        $user->is_assistant = false;
        return $user;
    }

    private function makeEvent(array $overrides = []): CalendarEvent
    {
        return CalendarEvent::create(array_merge([
            'created_by_id' => $overrides['user_id'] ?? 1,
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
        ], $overrides));
    }
}
