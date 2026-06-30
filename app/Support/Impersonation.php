<?php

namespace App\Support;

/**
 * AT-118 — audit-actor truth under switch-user (impersonation).
 *
 * Impersonation does a full Auth::login($target) and stashes the real admin's id
 * in session('impersonator_id') (see ImpersonateController). So Auth::user() — and
 * therefore every audit row's actor — reads as the IMPERSONATED user. This helper
 * surfaces the acting admin so audit writers can stamp "X (acting admin #46)" and
 * the POPIA trail is never misleading on its face. Session-safe (returns null in
 * console/job contexts with no bound session).
 */
class Impersonation
{
    public static function actingAdminId(): ?int
    {
        try {
            $request = request();
            if ($request && $request->hasSession() && $request->session()->isStarted()) {
                $id = (int) $request->session()->get('impersonator_id', 0);
                return $id > 0 ? $id : null;
            }
        } catch (\Throwable $e) {
            // No session bound (console / queue / webhook) — not impersonated.
        }
        return null;
    }
}
