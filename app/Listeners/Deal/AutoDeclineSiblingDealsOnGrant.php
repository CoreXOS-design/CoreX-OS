<?php

declare(strict_types=1);

namespace App\Listeners\Deal;

use App\Events\Deal\DealStageAdvanced;
use App\Models\DealLog;
use App\Services\Deal\DealPropertyStatusService;

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
 *
 * AT-398 — a granted deal can now carry more than one property; siblings are
 * collected across EVERY linked property (deal_properties), deduplicated
 * before declining so a sibling that happens to share more than one property
 * with the granted deal is only declined (and logged) once.
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
            $propertyIds = $deal->properties()->pluck('properties.id');
            if ($propertyIds->isEmpty()) {
                return; // a name-only deal has no property to arbitrate
            }

            // Every OTHER active (pending/granted) deal on ANY linked property.
            // Crosses branch/agency scopes — offers may span branches.
            $statusService = app(DealPropertyStatusService::class);
            $siblings = $propertyIds
                ->flatMap(fn ($propertyId) => $statusService
                    ->otherPropertyDeals((int) $propertyId, (int) $deal->id)
                    ->whereIn('accepted_status', ['P', 'G'])
                    ->get())
                ->unique('id');

            foreach ($siblings as $sibling) {
                try {
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
                            'Auto-declined: deal #%s was granted on a shared property (only one deal may be granted at a time).',
                            (string) ($deal->deal_no ?? $deal->id),
                        ),
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Wave2 AutoDeclineSiblingDealsOnGrant failed for one sibling', [
                        'error' => $e->getMessage(), 'deal_id' => $deal->id, 'sibling_id' => $sibling->id,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Wave2 AutoDeclineSiblingDealsOnGrant failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
