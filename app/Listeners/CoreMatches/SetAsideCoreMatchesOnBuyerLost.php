<?php

declare(strict_types=1);

namespace App\Listeners\CoreMatches;

use App\Events\Contact\ContactMarkedLostInBuyerPipeline;
use App\Models\ContactMatch;
use Illuminate\Support\Facades\Log;

/**
 * AT-Core-Matches, Task 6 — Buyer Pipeline "Lost" takes the buyer off the
 * Core Matches board. Set aside, never deleted.
 */
class SetAsideCoreMatchesOnBuyerLost
{
    public function handle(ContactMarkedLostInBuyerPipeline $event): void
    {
        try {
            ContactMatch::withoutGlobalScopes()
                ->where('contact_id', $event->contact->id)
                ->notSetAside()
                ->get()
                ->each(fn (ContactMatch $match) => $match->setAside());
        } catch (\Throwable $e) {
            Log::warning('SetAsideCoreMatchesOnBuyerLost failed', [
                'contact_id' => $event->contact->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
