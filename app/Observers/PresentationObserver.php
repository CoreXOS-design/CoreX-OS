<?php

namespace App\Observers;

use App\Models\Presentation;
use App\Services\MarketDataSnapshotService;
use Illuminate\Support\Facades\Log;

/**
 * Auto-captures market-state snapshot when a presentation is created or finalized.
 */
class PresentationObserver
{
    public function created(Presentation $presentation): void
    {
        $this->captureIfLinked($presentation);
    }

    public function updated(Presentation $presentation): void
    {
        // Capture on status change to finalized
        if ($presentation->wasChanged('status') && $presentation->status === 'finalized') {
            $this->captureIfLinked($presentation);
        }
    }

    private function captureIfLinked(Presentation $presentation): void
    {
        if (!$presentation->listing_id) {
            return;
        }

        try {
            app(MarketDataSnapshotService::class)->capturePropertySnapshot(
                (int) $presentation->listing_id,
                $presentation->id,
                $presentation->created_by_user_id
            );
        } catch (\Throwable $e) {
            Log::warning("PresentationObserver: snapshot capture failed for presentation #{$presentation->id}: {$e->getMessage()}");
        }
    }
}
