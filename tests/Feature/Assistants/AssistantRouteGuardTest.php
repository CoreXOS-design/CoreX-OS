<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use Tests\TestCase;

/**
 * AT-267 — the "no gaps stays no gaps" ratchet.
 *
 * The per-route assistant-access audit (.ai/audits/assistants-route-access-audit-2026-07-19.md)
 * found that agent-personal / finance routes were reachable by assistants because they carried
 * no permission of their own. They are now `deny_assistant`-guarded. This test locks that in: a
 * future route in these namespaces that forgets the guard fails HERE, loudly, instead of
 * silently re-opening a commission dashboard to an assistant.
 *
 * No DB needed — pure route-table inspection, so it is fast and always runs.
 */
final class AssistantRouteGuardTest extends TestCase
{
    /** Agent-personal / finance / self-destructive routes an assistant must never reach. */
    private const MUST_DENY_ASSISTANT = [
        'commission.dashboard',     // /my-earnings
        'commission.index',
        'commission.principal',
        'commission.confirm',
        'commission.pay',
        'revenue-share.calculator',
        'profile.destroy',          // deleting an assistant's account is an admin action (§10)
    ];

    public function test_named_agent_personal_routes_hard_block_assistants(): void
    {
        $missing = [];

        foreach (self::MUST_DENY_ASSISTANT as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            if ($route === null) {
                $missing[] = "{$name} (route no longer exists — update this list)";
                continue;
            }
            if (!in_array('deny_assistant', $route->gatherMiddleware(), true)) {
                $missing[] = "{$name} (missing deny_assistant middleware)";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These agent-personal routes must carry the deny_assistant middleware but do not — an "
            . "assistant could reach a finance/personal surface directly by URL.\n  - "
            . implode("\n  - ", $missing) . "\n"
        );
    }

    /**
     * Pattern ratchet: EVERY commission/revenue route must block assistants. A newly-added one
     * that forgets fails here rather than exposing agency finance to an assistant.
     */
    public function test_every_commission_and_revenue_route_blocks_assistants(): void
    {
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName() ?? '';
            if (!preg_match('/^(commission|revenue-share)\./', $name)) {
                continue;
            }
            if (!in_array('deny_assistant', $route->gatherMiddleware(), true)) {
                $offenders[] = "{$name} ({$route->uri()})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "New commission/revenue routes must carry deny_assistant (agency finance is never an "
            . "assistant's to see):\n  - " . implode("\n  - ", $offenders) . "\n"
        );
    }
}
