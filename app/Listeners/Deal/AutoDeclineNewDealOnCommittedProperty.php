<?php

declare(strict_types=1);

namespace App\Listeners\Deal;

use App\Events\Deal\DealCreated;
use App\Models\Deal;
use App\Models\DealLog;
use App\Services\Deal\DealPropertyStatusService;

/**
 * DR2 Wave 2 — auto-decline a NEW deal captured against a property that ALREADY
 * carries a granted/registered deal.
 *
 * Johan's doctrine: an offer is legally captured no matter what — the capture
 * NEVER fails. But a property may hold only ONE deal in the committed lane
 * (Granted or Registered). So a new offer landing on a property that is already
 * committed to another deal is captured, then AUTO-DECLINED on save — audited,
 * never silent — exactly as the grant CASCADE declines existing siblings.
 *
 * This lives on DealCreated (not the controller) so it holds for EVERY creation
 * path — DR2 capture, twin sync, any future API — a single chokepoint, per the
 * events catalogue (non-negotiable #9). The interactive DR2 capture surfaces the
 * clickable "deal #XXXX" notice on top of this enforcement.
 *
 * It fires ONLY against a COMMITTED (G/R) sibling — multiple PENDING offers with
 * no grant yet are legal and all stay pending (the whole multi-offer premise).
 */
class AutoDeclineNewDealOnCommittedProperty
{
    public function handle(DealCreated $event): void
    {
        try {
            $deal = $event->deal;
            if (empty($deal->property_id)) {
                return; // a name-only deal has no property constraint to violate
            }

            // Only a new ACTIVE (pending/granted) offer can violate the one-committed
            // -deal rule. A deal born Declined/Registered is not a fresh offer to arbitrate.
            $from = (string) ($deal->accepted_status ?? '');
            if (! in_array($from, ['P', 'G'], true)) {
                return;
            }

            $existing = app(DealPropertyStatusService::class)->existingCommittedDeal($deal);
            if ($existing === null) {
                return; // no granted/registered deal on this property → multi-offer is legal
            }

            // Captured, but cannot stand active against a committed property → decline.
            // saveQuietly() persists the status without re-firing the observer churn
            // (a born-declined deal never truly "changed" status); the audit note is
            // written explicitly below, mirroring the grant-cascade log.
            $deal->accepted_status = 'D';
            $deal->saveQuietly();

            DealLog::create([
                'deal_id'       => $deal->id,
                'actor_user_id' => $event->actorUserId,
                'event_type'    => 'auto_declined',
                'from_value'    => $from,
                'to_value'      => 'D',
                'message'       => sprintf(
                    'Auto-declined on capture: deal #%s already carries a %s status on this property (only one deal may be granted at a time).',
                    (string) ($existing->deal_no ?? $existing->id),
                    $existing->accepted_status === 'R' ? 'Registered' : 'Granted',
                ),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Wave2 AutoDeclineNewDealOnCommittedProperty failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
