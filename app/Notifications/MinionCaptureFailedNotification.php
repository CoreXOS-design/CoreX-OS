<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

// AT-284 — ops alert when a minion capture session has failures. Database channel (no mail dependency).
class MinionCaptureFailedNotification extends Notification
{
    /** @param array $failedRuns MinionCaptureRun rows that failed */
    public function __construct(public array $failedRuns) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $areas = array_map(
            fn ($r) => is_object($r) ? ($r->area_label ?? '') : ($r['area_label'] ?? ''),
            $this->failedRuns
        );

        return [
            'type'    => 'minion_capture_failed',
            'count'   => count($this->failedRuns),
            'areas'   => $areas,
            'message' => count($this->failedRuns) . ' P24 minion capture failure(s): ' . implode(', ', $areas),
        ];
    }
}
