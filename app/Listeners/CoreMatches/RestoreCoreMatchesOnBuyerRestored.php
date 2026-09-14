<?php

declare(strict_types=1);

namespace App\Listeners\CoreMatches;

use App\Events\Contact\ContactRestoredFromLostInBuyerPipeline;
use App\Models\ContactMatch;
use Illuminate\Support\Facades\Log;

/**
 * AT-Core-Matches, Task 6 — "comes back if the buyer does." Restores every
 * set-aside match for a buyer whose Buyer Pipeline state moves off 'lost'.
 */
class RestoreCoreMatchesOnBuyerRestored
{
    public function handle(ContactRestoredFromLostInBuyerPipeline $event): void
    {
        try {
            ContactMatch::withoutGlobalScopes()
                ->where('contact_id', $event->contact->id)
                ->setAside()
                ->get()
                ->each(fn (ContactMatch $match) => $match->restoreFromSetAside());
        } catch (\Throwable $e) {
            Log::warning('RestoreCoreMatchesOnBuyerRestored failed', [
                'contact_id' => $event->contact->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
