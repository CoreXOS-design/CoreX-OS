<?php

namespace App\Services\Communications;

use App\Models\Deal;
use Illuminate\Support\Facades\DB;

/**
 * CX-113 Phase A (Johan, 2026-08-21, correction to the original all-emails premise):
 * "we cannot simply pull all emails into unfiled. the emails needs to match on buyer
 * or seller or supplier that are involved in a dr2 deal." An email belongs on the
 * Unfiled Emails screen ONLY if a party on it (To/From/CC) is ALSO a party on a
 * DR2-twinned deal — as buyer, seller, or supplier (attorney, bond originator, bond
 * attorney, or a COC/work-order supplier). Everything else is contact-level
 * correspondence and stays exactly where it already is (untouched).
 *
 * Reuses the SAME two data sources AttorneyCorrespondenceResolver::activeDealsForFirm()
 * already established and ships against (AT-231): deals.attorney_provider_id /
 * bond_originator_provider_id + deal_step_work_orders.service_provider_id — extended
 * here with bond_attorney_provider_id (the column exists, holds the same shape, AT-231
 * just never needed it since it was 0-populated at the time). Buyer/seller comes from
 * deal_contacts (DR1's own party pivot; deal_v2_contacts is unused — 0 rows on live as
 * of this investigation, so it is deliberately NOT read from).
 *
 * "Supplier" person-level email resolution: a provider firm's own `.email` column is
 * routinely null in real data — the actual emailable person lives one join deeper at
 * agency_service_provider_contacts.email (confirmed against live: e.g. "Van Dyk &
 * Swart Inc" the firm has no email, "Linda" the contact person does). Both are read,
 * firm email as a fallback, contact-person email as the primary source.
 */
class Dr2DealPartyEmailResolver
{
    /**
     * Every distinct, normalised (lower/trim) email address belonging to a buyer,
     * seller, or supplier on any DR2-twinned deal (deals.deal_v2_id not null) in
     * this agency. Cheap at current data volume (tens of rows) — revisit with
     * caching if the deal-party tables grow into the thousands.
     *
     * @return array<int, string>
     */
    public function partyEmailsForAgency(int $agencyId): array
    {
        $buyerSeller = DB::table('deal_contacts')
            ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
            ->join('contacts', 'contacts.id', '=', 'deal_contacts.contact_id')
            ->where('deals.agency_id', $agencyId)
            ->whereNotNull('deals.deal_v2_id')
            ->whereNotNull('contacts.email')
            ->pluck('contacts.email');

        $providerColumns = ['attorney_provider_id', 'bond_originator_provider_id', 'bond_attorney_provider_id'];
        $supplierEmails = collect();

        foreach ($providerColumns as $column) {
            // Primary: the specific person's own address.
            $supplierEmails = $supplierEmails->merge(
                DB::table('deals')
                    ->join('agency_service_provider_contacts', 'agency_service_provider_contacts.service_provider_id', '=', "deals.$column")
                    ->where('deals.agency_id', $agencyId)
                    ->whereNotNull('deals.deal_v2_id')
                    ->whereNotNull("deals.$column")
                    ->whereNull('agency_service_provider_contacts.deleted_at')
                    ->pluck('agency_service_provider_contacts.email')
            );
            // Fallback: the firm's own inbox, when it has one and no person is captured.
            $supplierEmails = $supplierEmails->merge(
                DB::table('deals')
                    ->join('agency_service_providers', 'agency_service_providers.id', '=', "deals.$column")
                    ->where('deals.agency_id', $agencyId)
                    ->whereNotNull('deals.deal_v2_id')
                    ->whereNotNull("deals.$column")
                    ->whereNull('agency_service_providers.deleted_at')
                    ->pluck('agency_service_providers.email')
            );
        }

        // COC / work-order suppliers (electrician, entomologist, gas, etc. — the
        // agency-configured service_type list, not a fixed taxonomy). Same two
        // resolution paths: a direct recipient_email on the work order row, or the
        // assigned supplier's contact-person email.
        $workOrderBase = DB::table('deal_step_work_orders')
            ->join('deals', 'deals.id', '=', 'deal_step_work_orders.dr1_deal_id')
            ->where('deals.agency_id', $agencyId)
            ->whereNotNull('deals.deal_v2_id')
            ->whereNull('deal_step_work_orders.deleted_at');

        $supplierEmails = $supplierEmails->merge(
            (clone $workOrderBase)->whereNotNull('deal_step_work_orders.recipient_email')
                ->pluck('deal_step_work_orders.recipient_email')
        );
        $supplierEmails = $supplierEmails->merge(
            (clone $workOrderBase)
                ->join('agency_service_provider_contacts', 'agency_service_provider_contacts.service_provider_id', '=', 'deal_step_work_orders.service_provider_id')
                ->whereNotNull('deal_step_work_orders.service_provider_id')
                ->whereNull('agency_service_provider_contacts.deleted_at')
                ->pluck('agency_service_provider_contacts.email')
        );

        return $buyerSeller->merge($supplierEmails)
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter(fn ($e) => $e !== '' && str_contains($e, '@'))
            ->unique()
            ->values()
            ->all();
    }
}
