<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Role;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AT-265 — the alarm for "CoreX cannot answer who is allowed to do what."
 *
 * `role_permissions` being empty is not a configuration state. It is a catastrophe: either the
 * table was never provisioned on this environment, or something truncated/soft-deleted it. Before
 * AT-265 that state FAILED OPEN — every user got every permission, platform-wide, silently. It now
 * fails CLOSED, which means the system is safe but visibly broken, and somebody must be told
 * immediately and unmistakably.
 *
 * ── Two rules govern everything in this class ────────────────────────────────────────────────
 *
 * 1. THE ALARM MAY NEVER BREAK THE THING IT IS WATCHING. It is raised from inside
 *    PermissionService, on the hot path of every single request. If the mailer is down, the queue
 *    is gone, or the users table is unreadable, this class swallows it and returns. A permission
 *    check must never 500 because the alarm about it failed — and, far more importantly, it must
 *    never end up ALLOWING something because the alarm threw on the way to denying it.
 *
 * 2. THE LOG IS THE GUARANTEE; THE NOTIFICATION IS BEST-EFFORT. The gateway is the right way to
 *    reach an admin (AT-235), but it is deliberately suppressible: a user's open-hours window, a
 *    disabled preference, or an unseeded notification catalogue will all silently drop the alert.
 *    That is correct for a "your listing needs photos" nudge and completely wrong for a security
 *    lockdown. So `Log::critical` on the dedicated `security` channel fires unconditionally and is
 *    the record of truth. The gateway alert rides on top for the humans.
 */
final class PermissionLockdownAlarm
{
    /** How long between repeat alarms for the same condition. The condition persists; the noise must not. */
    private const ALARM_TTL_MINUTES = 15;

    /** How long between repeat break-glass audit lines for the SAME operator. */
    private const BREAK_GLASS_TTL_MINUTES = 5;

    /**
     * Re-entrancy guard. The gateway reads users, roles and preferences — if any of that ever grows
     * a permission check, the alarm would raise itself raising itself. Bounded here, once.
     */
    private static bool $raising = false;

    /**
     * The grants table is empty and a user has therefore been DENIED. Shout.
     *
     * @param  string  $context  Where the denial happened, e.g. "userHasPermission(deals.settle)".
     */
    public static function raise(string $context): void
    {
        if (self::$raising) {
            return;
        }

        self::$raising = true;

        try {
            // Unconditional, every time, whatever else fails. This is the guarantee.
            Log::channel('security')->critical(
                'AT-265 PERMISSION LOCKDOWN: role_permissions is EMPTY — denying all non-owner access. '
                . 'CoreX cannot determine who may do what. Run `php artisan deploy:sync-reference-data` '
                . 'to reprovision grants from config/corex-permissions.php.',
                [
                    'context'    => $context,
                    'user_id'    => auth()->id(),
                    'url'        => request()?->fullUrl(),
                    'ip'         => request()?->ip(),
                    'app_env'    => app()->environment(),
                ],
            );

            // Best-effort, throttled: tell the humans. Cache::add is atomic — the first process
            // through the window wins and the rest fall straight through.
            if (Cache::add('at265:lockdown-alarm', 1, now()->addMinutes(self::ALARM_TTL_MINUTES))) {
                self::notifyOwners();
            }
        } catch (\Throwable $e) {
            // Rule 1. An alarm that throws must not become a permission that passes.
            try {
                Log::channel('security')->error('AT-265 alarm itself failed: ' . $e->getMessage());
            } catch (\Throwable) {
                // Nothing left to do. Deny still happened — that is what matters.
            }
        } finally {
            self::$raising = false;
        }
    }

    /**
     * An owner/super-admin sailed through the bypass WHILE the grants table was empty.
     *
     * The bypass is preserved deliberately: it is the break-glass. If permissions vanish on live at
     * 22:00, somebody has to be able to log in and run the reprovision — a system that locks out
     * even the person who can fix it has traded a security hole for an outage with no exit.
     *
     * But break-glass that leaves no trace is just a back door. Every use is recorded: who, when,
     * from where, doing what.
     */
    public static function recordBreakGlass(User $user, string $context): void
    {
        try {
            $key = 'at265:break-glass:' . $user->id;

            if (! Cache::add($key, 1, now()->addMinutes(self::BREAK_GLASS_TTL_MINUTES))) {
                return; // Already recorded for this operator in this window.
            }

            Log::channel('security')->warning(
                'AT-265 BREAK-GLASS: owner-role bypass used while role_permissions is EMPTY. '
                . 'Access was granted by the owner bypass, NOT by a grant.',
                [
                    'user_id' => $user->id,
                    'email'   => $user->email,
                    'role'    => $user->role,
                    'context' => $context,
                    'url'     => request()?->fullUrl(),
                    'ip'      => request()?->ip(),
                ],
            );
        } catch (\Throwable) {
            // Auditing must never break the break-glass. The operator still gets in.
        }
    }

    /** Route the alarm to the people who can actually fix it, through the AT-235 gateway. */
    private static function notifyOwners(): void
    {
        $ownerRoles = Role::query()->where('is_owner', true)->pluck('name')->all();

        if (empty($ownerRoles)) {
            return;
        }

        // Global scopes off deliberately: this is a PLATFORM alarm, and the super-admin who must
        // receive it commonly carries agency_id = NULL (see the agency-context bug class).
        $owners = User::withoutGlobalScopes()
            ->whereIn('role', $ownerRoles)
            ->whereNull('deleted_at')
            ->limit(10)
            ->get();

        if ($owners->isEmpty()) {
            return;
        }

        $dispatcher = app(NotificationDispatcher::class);

        foreach ($owners as $owner) {
            try {
                $dispatcher->fire($owner, 'security.permissions_unavailable', $owner, [
                    'title'    => 'URGENT: CoreX permissions are unavailable',
                    'body'     => 'The role_permissions table is empty, so CoreX cannot determine who is allowed '
                        . 'to do what. Every non-owner user is currently DENIED all access (this is the safe '
                        . 'posture — it is not a lockout of the platform, it is a refusal to guess). Run '
                        . '`php artisan deploy:sync-reference-data` on this environment to reprovision grants.',
                    'subject_label'    => 'Permission system',
                    'severity'         => 'critical',
                    // Stable per hour: the condition persists until fixed, so the dedup key must NOT be
                    // now() — that is exactly the bug that let 1.9M notifications out (AT-235 R3).
                    'threshold_hit_at' => now()->startOfHour(),
                ]);
            } catch (\Throwable $e) {
                Log::channel('security')->error(
                    'AT-265: could not deliver the lockdown alarm to owner ' . $owner->id . ': ' . $e->getMessage()
                );
            }
        }
    }
}
