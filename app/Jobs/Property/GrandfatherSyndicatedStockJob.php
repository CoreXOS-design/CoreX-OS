<?php

declare(strict_types=1);

namespace App\Jobs\Property;

use App\Models\Property;
use App\Models\PropertySyndicationApproval;
use App\Models\Scopes\AgencyScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Only new stock" — Johan's D8. Spec: .ai/specs/syndication-approval-gate.md §4.4.
 *
 * Runs ONCE per agency, the instant `syndication_approval_required` flips
 * off → on. Stamps every property that is ALREADY PUBLISHED as approved, so
 * the approver never faces the agency's back catalogue and nothing that is
 * already public is re-litigated.
 *
 * Three cases are deliberately NOT stamped:
 *   1. A property that has never been published anywhere — old or new, it
 *      needs one nod before it goes out. Stamping it would put a permanent
 *      hole in the control: approval never expires (D2), so a listing loaded
 *      last month could reach the portals unseen forever.
 *   2. A property whose latest approval row is `withdrawn` — an approver
 *      deliberately revoked it. An off→on cycle of the switch must never
 *      quietly undo a human revoke.
 *   3. Off-market stock — nothing to publish, nothing to approve.
 *
 * Idempotent: only ever writes where `syndication_approved_at IS NULL`.
 */
class GrandfatherSyndicatedStockJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        private int $agencyId,
        private int $switchedOnByUserId,
    ) {
    }

    public function handle(): void
    {
        if ($this->agencyId <= 0) {
            return;
        }

        $stamped = 0;

        $this->publishedStockQuery()->chunkById(200, function ($properties) use (&$stamped) {
            foreach ($properties as $property) {
                DB::transaction(function () use ($property, &$stamped) {
                    $property->forceFill([
                        'syndication_approved_at'         => now(),
                        'syndication_approved_by_user_id' => $this->switchedOnByUserId,
                    ])->save();

                    PropertySyndicationApproval::withoutAgencyStamping(function () use ($property) {
                        PropertySyndicationApproval::create([
                            'agency_id'            => $property->agency_id,
                            'branch_id'            => $property->branch_id,
                            'property_id'          => $property->id,
                            'status'               => PropertySyndicationApproval::STATUS_APPROVED,
                            'requested_by_user_id' => $property->agent_id ?: $this->switchedOnByUserId,
                            'requested_at'         => now(),
                            'decided_by_user_id'   => $this->switchedOnByUserId,
                            'decided_at'           => now(),
                            'decision_note'        => 'Approved automatically — already published when syndication approval was switched on.',
                        ]);
                    });

                    $stamped++;
                });
            }
        });

        Log::info('Syndication approval: grandfathered already-published stock.', [
            'agency_id' => $this->agencyId,
            'stamped'   => $stamped,
        ]);
    }

    /**
     * Already published on ANY target: Property24, Private Property, or any of
     * the agency's websites.
     */
    private function publishedStockQuery()
    {
        return Property::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $this->agencyId)
            ->whereNull('syndication_approved_at')
            ->whereNotIn('status', Property::OFF_MARKET_STATUSES)
            ->where(function ($q) {
                $q->where('p24_syndication_enabled', 1)
                    ->orWhere('pp_syndication_enabled', 1)
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('property_website_syndication as pws')
                            ->whereColumn('pws.property_id', 'properties.id')
                            ->where('pws.enabled', 1)
                            ->whereNull('pws.deleted_at');
                    });
            })
            // Case 2: never resurrect a deliberate human revoke.
            //
            // An `approved` row on a property whose stamp is NULL (already
            // asserted above) can only mean one thing: it WAS approved and the
            // approval was then revoked — approve() is the only writer of the
            // stamp and revoke() is its only clearer. That single condition is
            // exact, and it deliberately does NOT catch an agent cancelling
            // their own pending request (also a `withdrawn` row, but with no
            // preceding approval), which must not block grandfathering.
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('property_syndication_approvals as psa')
                    ->whereColumn('psa.property_id', 'properties.id')
                    ->where('psa.status', PropertySyndicationApproval::STATUS_APPROVED)
                    ->whereNull('psa.deleted_at');
            });
    }
}
