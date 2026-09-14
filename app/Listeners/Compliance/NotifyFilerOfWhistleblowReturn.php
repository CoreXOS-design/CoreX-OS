<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Events\Compliance\WhistleblowReportReturned;
use App\Models\User;
use App\Notifications\Compliance\WhistleblowReportReturnedNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * "Changes requested — agent has been notified" becomes true. Spec §9.2. Explicitly registered.
 */
class NotifyFilerOfWhistleblowReturn
{
    public function handle(WhistleblowReportReturned $event): void
    {
        $complaint = $event->complaint;
        $filer = $complaint->reported_by_user_id
            ? User::withoutGlobalScopes()->find($complaint->reported_by_user_id)
            : null;
        if (! $filer) {
            return;
        }

        $eventKey = $event->outcome === 'rejected' ? 'whistleblow.rejected' : 'whistleblow.changes_requested';

        try {
            app(NotificationDispatcher::class)->send(
                $filer,
                $eventKey,
                $complaint,
                new WhistleblowReportReturnedNotification($complaint, $event->outcome, $event->notes, $event->returnedByUserId),
                [
                    'threshold_hit_at' => now()->toIso8601String(),
                    'complaint_id'     => $complaint->id,
                    'outcome'          => $event->outcome,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Whistleblow return notification failed (non-fatal)', [
                'complaint_id' => $complaint->id,
                'filer_id'     => $filer->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
