<?php

namespace App\Support\Minion;

use App\Models\MinionCaptureSettings;
use App\Models\User;
use App\Notifications\MinionCaptureFailedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// AT-284 — failure alerting. Always logs; best-effort DB notification to permissioned
// agency users. Never throws (an alert failure must not break a capture run).
class MinionAlerts
{
    public static function failures(int $agencyId, array $runs): void
    {
        $failed = array_values(array_filter($runs, fn ($r) => (($r->status ?? null) === 'failed')));
        if (! $failed) {
            return;
        }

        Log::error('AT-284 minion capture failures', [
            'agency' => $agencyId,
            'count'  => count($failed),
            'areas'  => array_map(fn ($r) => $r->area_label, $failed),
        ]);

        if (! (MinionCaptureSettings::resolved($agencyId)['alert_enabled'] ?? true)) {
            return;
        }

        try {
            $recipients = User::query()->where('agency_id', $agencyId)->get()
                ->filter(function ($u) {
                    try {
                        // Recipient = agency settings admins. Confirm the exact permission slug at finalization.
                        return method_exists($u, 'hasPermission') ? (bool) $u->hasPermission('access_settings') : false;
                    } catch (\Throwable $e) {
                        return false;
                    }
                });

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new MinionCaptureFailedNotification($failed));
            }
        } catch (\Throwable $e) {
            Log::warning('AT-284 minion alert dispatch skipped: ' . $e->getMessage());
        }
    }
}
