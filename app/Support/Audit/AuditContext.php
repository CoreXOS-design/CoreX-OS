<?php

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AT-321 / AT-321-C — the pillar-agnostic single source of truth for "who / what
 * source" is behind an audited write (property, contact, …), and the bridge that
 * hands that identity to the unbypassable DB triggers via per-connection session
 * variables (@corex_actor_*, @corex_audit_handled).
 *
 * This was extracted verbatim from PropertyAuditContext so a second pillar
 * (contacts) could reuse the exact same attribution machinery instead of
 * duplicating it. PropertyAuditContext is now a thin backward-compatible forwarder
 * onto this class, so every existing property call-site keeps working unchanged
 * and now shares ONE context with the contact pillar (the HTTP middleware and the
 * job/console source-stampers drive both).
 *
 * Priority when resolving the actor:
 *   1. an explicit User passed to the audit call,
 *   2. the authenticated user (auth()->user()),
 *   3. a source pushed by the entry point (job/console/raw site),
 *   4. NEVER blank — falls back to 'unattributed' + type 'unknown' so a gap is
 *      always visible, never a silent "System".
 */
class AuditContext
{
    private static ?int $actorUserId = null;
    private static ?string $actorLabel = null;
    private static ?string $actorType = null;   // user|system|import|console|sync|portal|unknown
    private static ?string $source = null;

    /** Save/restore stack so an inline job never clobbers the caller's context. */
    private static array $stack = [];

    /** Snapshot the current context (used around job execution). */
    public static function push(): void
    {
        self::$stack[] = [self::$actorUserId, self::$actorLabel, self::$actorType, self::$source];
    }

    /** Restore the most recent snapshot. */
    public static function pop(): void
    {
        if (!empty(self::$stack)) {
            [self::$actorUserId, self::$actorLabel, self::$actorType, self::$source] = array_pop(self::$stack);
            self::pushToDb();
        }
    }

    /** Stamp the authenticated user (called by HTTP middleware). */
    public static function setUser(?User $user): void
    {
        if ($user === null) {
            return;
        }
        self::$actorUserId = (int) $user->id;
        self::$actorLabel  = $user->name ?: ('User #' . $user->id);
        self::$actorType   = 'user';
        self::$source      = null;
        self::pushToDb();
    }

    /**
     * Stamp a non-user source (jobs / imports / console / explicit raw sites).
     * $type is one of: system|import|console|sync|portal.
     */
    public static function setSource(string $label, string $type = 'system'): void
    {
        self::$source     = $label;
        self::$actorLabel = $label;
        self::$actorType  = $type;
        // Keep any known user id (a job may still know who triggered it); label
        // stays the source so the row reads "P24 import" not a bare name.
        self::pushToDb();
    }

    /** Clear all context (test isolation / end of a console run). */
    public static function reset(): void
    {
        self::$actorUserId = null;
        self::$actorLabel  = null;
        self::$actorType   = null;
        self::$source      = null;
        self::pushToDb();
    }

    /**
     * Resolve attribution for a single audit row. Never returns a blank label.
     *
     * @return array{user_id: int|null, actor_type: string, actor_label: string, source: string|null}
     */
    public static function resolve(?User $explicit = null): array
    {
        if ($explicit !== null) {
            return [
                'user_id'     => (int) $explicit->id,
                'actor_type'  => 'user',
                'actor_label' => $explicit->name ?: ('User #' . $explicit->id),
                'source'      => self::$source,
            ];
        }

        $authUser = auth()->user();
        if ($authUser) {
            return [
                'user_id'     => (int) $authUser->id,
                'actor_type'  => 'user',
                'actor_label' => $authUser->name ?: ('User #' . $authUser->id),
                'source'      => self::$source,
            ];
        }

        if (self::$actorUserId !== null || self::$actorLabel !== null) {
            return [
                'user_id'     => self::$actorUserId,
                'actor_type'  => self::$actorType ?? 'system',
                'actor_label' => self::$actorLabel ?? 'System',
                'source'      => self::$source,
            ];
        }

        // Nothing known — visible, never silent.
        return [
            'user_id'     => null,
            'actor_type'  => 'unknown',
            'actor_label' => 'unattributed',
            'source'      => null,
        ];
    }

    /** Mark that the app layer is recording this write → suppress the DB trigger. */
    public static function markHandled(): void
    {
        self::safe(fn () => DB::statement('SET @corex_audit_handled = 1'));
    }

    /** Release the trigger suppression (run after the app-layer write commits). */
    public static function clearHandled(): void
    {
        self::safe(fn () => DB::statement('SET @corex_audit_handled = 0'));
    }

    /** Push the current actor identity to the connection for the triggers to read. */
    private static function pushToDb(): void
    {
        self::safe(function () {
            DB::statement('SET @corex_actor_id = ?, @corex_actor_label = ?', [
                self::$actorUserId,
                self::$actorLabel,
            ]);
        });
    }

    /** Session-var plumbing must never break the request/save if the DB hiccups. */
    private static function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            // Best-effort only; attribution degrades to 'unattributed', never fatal.
        }
    }
}
