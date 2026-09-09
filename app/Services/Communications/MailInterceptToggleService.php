<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\DevSetting;
use App\Models\OutboundMailGuardToggleAudit;
use App\Models\User;
use App\Support\OutboundMailGuard;

/**
 * AT-URGENT-2026-09-09 — the write side of the outbound mail kill switch.
 * OutboundMailGuard is read-only and has no notion of the current user;
 * every state CHANGE goes through here so it is always audited with a
 * required reason, exactly once, in one place.
 */
class MailInterceptToggleService
{
    /** @throws \InvalidArgumentException if $reason is blank */
    public function forceIntercept(User $user, string $reason): void
    {
        $this->set($user, true, $reason);
    }

    /** @throws \InvalidArgumentException if $reason is blank */
    public function forceSend(User $user, string $reason): void
    {
        $this->set($user, false, $reason);
    }

    /** Clears the override entirely — this environment goes back to its own default. */
    public function clearOverride(User $user, string $reason): void
    {
        $this->assertReason($reason);

        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, null);

        OutboundMailGuardToggleAudit::create([
            'user_id' => $user->id,
            'direction' => OutboundMailGuard::isSendingConfirmed()
                ? OutboundMailGuardToggleAudit::DIRECTION_INTERCEPT_OFF
                : OutboundMailGuardToggleAudit::DIRECTION_INTERCEPT_ON,
            'reason' => $reason,
            'environment' => (string) config('app.env'),
        ]);
    }

    private function set(User $user, bool $intercept, string $reason): void
    {
        $this->assertReason($reason);

        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, $intercept ? '1' : '0');

        OutboundMailGuardToggleAudit::create([
            'user_id' => $user->id,
            'direction' => $intercept
                ? OutboundMailGuardToggleAudit::DIRECTION_INTERCEPT_ON
                : OutboundMailGuardToggleAudit::DIRECTION_INTERCEPT_OFF,
            'reason' => $reason,
            'environment' => (string) config('app.env'),
        ]);
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required to change outbound mail interception.');
        }
    }
}
