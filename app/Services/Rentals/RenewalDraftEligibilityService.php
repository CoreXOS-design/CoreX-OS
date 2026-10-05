<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use App\Models\User;

/**
 * .ai/specs/rental-renewals.md §5 — the ONE decision of which renewal path
 * (a lease is eligible for, so neither the scheduled auto-draft command nor
 * the Command Centre's needs-action row can ever disagree about what a
 * given lease needs. §5's own table, decided once:
 *
 *   (a) copy forward — current lease was e-signed through CoreX.
 *   (b) draft fresh — agency has a mapped template AND the data it needs
 *       is complete (checked via RenewalDraftService::missingRequiredFields(),
 *       the same GATE 1 check the renewal screen's own preview already uses).
 *   (c) neither — not enough information; the caller reports what's missing
 *       instead of attempting a draft.
 */
class RenewalDraftEligibilityService
{
    /**
     * @param array{start_date:string,end_date?:?string,rental_amount:float,deposit_amount?:?float}|null $terms
     * @return array{outcome:string,terms:array,template?:RentalLeaseTemplate,missing?:string[]}
     */
    public function decide(Lease $lease, User $user, ?array $terms = null): array
    {
        $terms = $terms ?? $this->defaultTerms($lease);

        if ($lease->source === 'esign_document' && $lease->source_document_id) {
            return ['outcome' => 'copy_forward', 'terms' => $terms];
        }

        $templates = RentalLeaseTemplate::active()
            ->where('agency_id', $lease->agency_id)
            ->orderBy('name')
            ->get();

        if ($templates->isEmpty()) {
            return ['outcome' => 'insufficient_info', 'terms' => $terms, 'missing' => ['No agency lease template configured']];
        }

        $draftService = app(RenewalDraftService::class);
        $missingByTemplate = [];

        foreach ($templates as $template) {
            $missing = $draftService->missingRequiredFields($lease, $template, $terms, $user);
            if (empty($missing)) {
                return ['outcome' => 'draft_from_template', 'terms' => $terms, 'template' => $template];
            }
            $missingByTemplate[] = $missing;
        }

        $missing = collect($missingByTemplate)->flatten()->unique()->values()->all();

        return ['outcome' => 'insufficient_info', 'terms' => $terms, 'missing' => $missing];
    }

    /**
     * §5 — the agent edits every one of these before sending, even on path
     * (a)'s copy-forward; this is only the starting point, same default the
     * renewal screen's own term-entry form already shows
     * (_renewal-term-fields.blade.php — start date = day after the current
     * end date, end date left blank).
     *
     * @return array{start_date:string,end_date:?string,rental_amount:float,deposit_amount:?float}
     */
    public function defaultTerms(Lease $lease): array
    {
        return [
            'start_date' => $lease->end_date ? $lease->end_date->copy()->addDay()->toDateString() : now()->toDateString(),
            'end_date' => null,
            'rental_amount' => (float) $lease->rental_amount,
            'deposit_amount' => $lease->deposit_amount !== null ? (float) $lease->deposit_amount : null,
        ];
    }
}
