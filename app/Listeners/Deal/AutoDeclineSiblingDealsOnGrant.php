<?php

declare(strict_types=1);

namespace App\Listeners\Deal;

use App\Events\Deal\DealStageAdvanced;
use App\Models\Deal;
use App\Models\DealLog;

/**
 * DR2 Wave 2 — grant CASCADES, it doesn't block the normal flow. A property may
 * hold many PENDING offers (4 offers = 4 pending deals). The moment ONE deal is
 * Granted, every OTHER active deal on that property is AUTO-DECLINED — audited
 * ("auto-declined: deal #X granted"), never silent — so only one deal is granted
 * at a time and only one proceeds to Registered.
 *
 * The grant-while-another-GRANTED conflict is blocked upstream (the block modal /
 * DuplicateGrantException) BEFORE the write, so by the time we get here the
 * granted deal is the only committed deal — the siblings we decline are pending
 * (a defensive G in the set is declined too). Auto-declined deals stay
 * RE-GRANTABLE if this grant later falls through (declined→granted is legal while
 * no other grant exists and the property is not Registered).
 */
class AutoDeclineSiblingDealsOnGrant
{
    public function handle(DealStageAdvanced $event): void
    {
        try {
            if ($event->toStage !== 'G') {
                return; // only a fresh Granted cascades
            }

            $deal = $event->deal;
            if (empty($deal->property_id)) {
                return; // a name-only deal has no property to arbitrate
            }

            // Every OTHER active (pending/granted) deal on the same property.
            // Crosses branch/agency scopes — offers may span branches.
            $siblings = Deal::withoutGlobalScopes()
                ->where('property_id', $deal->property_id)
                ->where('id', '!=', (int) $deal->id)
                ->whereNull('deleted_at')
                ->whereIn('accepted_status', ['P', 'G'])
                ->get();

            foreach ($siblings as $sibling) {
                $from = (string) $sibling->accepted_status;
                // save() (loud) fires DealClosed → the revert listener, which is a
                // no-op here because the just-granted deal keeps the property active.
                $sibling->accepted_status = 'D';
                $sibling->save();

                DealLog::create([
                    'deal_id'       => $sibling->id,
                    'actor_user_id' => $event->actorUserId,
                    'event_type'    => 'auto_declined',
                    'from_value'    => $from,
                    'to_value'      => 'D',
                    'message'       => sprintf(
                        'Auto-declined: deal #%s was granted on this property (only one deal may be granted at a time).',
                        (string) ($deal->deal_no ?? $deal->id),
                    ),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('Wave2 AutoDeclineSiblingDealsOnGrant failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
