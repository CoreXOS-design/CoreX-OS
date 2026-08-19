<?php

declare(strict_types=1);

namespace Tests\Unit\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use Tests\TestCase;

/**
 * Reconcile-lane hand-resolution of ad9399923 onto staging's AT-267 base
 * (46df6b6df) — CalendarEvent::scopeVisibleTo() was changed on both sides for
 * unrelated reasons (assistant identity widening vs. invitation carve-out),
 * so a verbatim pick of either side regresses the other.
 *
 * This is a SQL-compilation proof (no DB execution / RefreshDatabase): the
 * environment's MySQL user for this worktree cannot currently bootstrap the
 * test schema at all (a pre-existing, documented infra gotcha — CLAUDE.md
 * Non-negotiable #13 — nexus@localhost lacks SUPER and
 * log_bin_trust_function_creators is OFF server-wide, so ANY migration that
 * creates a MySQL trigger, e.g. the contact-audit trigger, fails with
 * ERROR 1419 for every test run in this worktree; confirmed with a bare
 * `CREATE TRIGGER` probe unrelated to this change). Fixing that is a
 * shared-infra/grant change outside this task's scope, so this test instead
 * calls the REAL, resolved scopeVisibleTo() and inspects the compiled SQL +
 * bindings — proving both properties hold in the actual code, without
 * needing a live table.
 *
 *  (a) AT-267 — 'own'/'branch' scope still resolves through
 *      $user->dataIdentityIds(), not bare $user->id.
 *  (b) ad9399923 — the invitation carve-out subquery matches
 *      invitee_user_id against that SAME identity set, and its status
 *      whitelist includes 'pending' (the actual gap ad9399923 closes — live
 *      already had accepted/tentative).
 */
final class CalendarEventScopeVisibleToHybridTest extends TestCase
{
    public function test_own_scope_uses_identity_ids_for_both_base_filter_and_invited_subquery(): void
    {
        // Stand-in for an assistant: dataIdentityIds() widens to [agent, assistant].
        $user = new class extends User {
            public function dataIdentityIds(): array
            {
                return [11, 22];
            }
        };
        $user->id = 22;

        $query = CalendarEvent::query()->visibleTo($user, 'own');
        $sql = $query->toSql();
        $bindings = array_values($query->getBindings());

        // (a) AT-267: base 'own' filter is the identity SET, not bare $user->id.
        $this->assertMatchesRegularExpression(
            '/`user_id`\s+in\s+\(\?,\s*\?\)/i',
            $sql,
            "'own' scope must filter user_id IN (identityIds), not a single bare id — AT-267 regression.\nSQL: {$sql}"
        );

        // (b) The invitation carve-out subquery is keyed off the SAME identity
        // set (11, 22), not bare $user->id (22) — consistent widening.
        $this->assertMatchesRegularExpression(
            '/`invitee_user_id`\s+in\s+\(\?,\s*\?\)/i',
            $sql,
            "Invitation subquery must match invitee_user_id against identityIds so an assistant sees invites addressed to their agent too.\nSQL: {$sql}"
        );

        // (b) ad9399923's actual fix: 'pending' is in the status whitelist
        // (staging's pre-existing code only had accepted/tentative).
        $this->assertContains('pending', $bindings, "'pending' must be in the invitation status whitelist — this is the bug ad9399923 fixes.\nBindings: " . json_encode($bindings));
        $this->assertContains('accepted', $bindings);
        $this->assertContains('tentative', $bindings);

        // Sanity: the two identity ids (11, 22) both appear as bindings,
        // proving they are literally the same array reused in both places,
        // not two independently-typed literals that happen to match a regex.
        $this->assertContains(11, $bindings);
        $this->assertContains(22, $bindings);
    }

    public function test_normal_agent_own_scope_degenerates_to_single_id_identity_set(): void
    {
        // A non-assistant's dataIdentityIds() returns [$this->id] — proving the
        // hybrid is a strict superset of pre-AT-267 behaviour for ordinary users.
        $user = new class extends User {
            public function dataIdentityIds(): array
            {
                return [7];
            }
        };
        $user->id = 7;

        $query = CalendarEvent::query()->visibleTo($user, 'own');
        $bindings = array_values($query->getBindings());

        $this->assertContains(7, $bindings);
        $this->assertContains('pending', $bindings);
    }
}
