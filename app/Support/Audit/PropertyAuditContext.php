<?php

namespace App\Support\Audit;

use App\Models\User;

/**
 * AT-321 — backward-compatible forwarder onto the pillar-agnostic {@see AuditContext}.
 *
 * The attribution state and the DB-session-var plumbing now live in AuditContext so
 * the contact pillar (AT-321-C) can share the exact same machinery instead of
 * duplicating it. Every existing property call-site calls these static methods
 * unchanged; each one simply delegates to AuditContext, so property and contact now
 * resolve against ONE shared context. Do not add state here — it is a pure shim.
 */
class PropertyAuditContext
{
    public static function push(): void
    {
        AuditContext::push();
    }

    public static function pop(): void
    {
        AuditContext::pop();
    }

    public static function setUser(?User $user): void
    {
        AuditContext::setUser($user);
    }

    public static function setSource(string $label, string $type = 'system'): void
    {
        AuditContext::setSource($label, $type);
    }

    public static function reset(): void
    {
        AuditContext::reset();
    }

    /**
     * @return array{user_id: int|null, actor_type: string, actor_label: string, source: string|null}
     */
    public static function resolve(?User $explicit = null): array
    {
        return AuditContext::resolve($explicit);
    }

    public static function markHandled(): void
    {
        AuditContext::markHandled();
    }

    public static function clearHandled(): void
    {
        AuditContext::clearHandled();
    }
}
