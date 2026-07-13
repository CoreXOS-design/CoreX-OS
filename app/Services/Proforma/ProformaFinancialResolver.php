<?php

namespace App\Services\Proforma;

use App\Models\Agency;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Services\DealMoneyLineRebuilder;

/**
 * Resolves a DR2 deal's FINANCIAL + PARTY truth for a proforma invoice — the single
 * place figures come from, so agents/BMs can never hand-edit them. Reads the DR1-twin
 * `deals` row (what the DR2 settlement screen uses), via DealMoneyLineRebuilder.
 *
 * Split deals → THIS agency's share only (external sides contribute 0 to the pools).
 */
class ProformaFinancialResolver
{
    /**
     * Is the deal at "granted onward" (never pending/declined)? Mirrors
     * Deal::statusSummary* — eligible when NOT declined AND (granted OR registered).
     */
    public function isEligible(Deal $deal): bool
    {
        $status = (string) $deal->accepted_status;

        $isDeclined   = in_array($status, ['D', 'Declined'], true);
        $isRegistered = ! empty($deal->registration_date) || in_array($status, ['R', 'Registered'], true);
        $isGranted    = ! $isRegistered
            && (! empty($deal->granted_at) || in_array($status, ['G', 'Granted'], true))
            && ! $isDeclined;

        return ! $isDeclined && ($isGranted || $isRegistered);
    }

    /** The reason a deal is not eligible (for a clear 403 message). */
    public function ineligibleReason(Deal $deal): ?string
    {
        if ($this->isEligible($deal)) {
            return null;
        }
        $status = (string) $deal->accepted_status;
        if (in_array($status, ['D', 'Declined'], true)) {
            return 'This deal is declined — a proforma can only be issued from Granted onward.';
        }
        return 'This deal is not yet Granted — a proforma can only be issued from Granted onward.';
    }

    /**
     * The full resolved snapshot the generation service + PDF need.
     *
     * @return array{
     *   vat_registered: bool, vat_rate: float,
     *   commission_excl: float, commission_vat: float, commission_incl: float,
     *   reference: string, seller_name: string, seller_contact_id: ?int,
     *   attorney_name: ?string, attorney_provider_id: ?int
     * }
     */
    public function resolve(Deal $deal): array
    {
        $agency = Agency::withoutGlobalScopes()->find($deal->agency_id);
        $vatRegistered = (bool) ($agency->vat_registered ?? false);

        $pools   = DealMoneyLineRebuilder::computeDealPools($deal);
        $vatRate = (float) ($pools['vatRate'] ?? 0.15);

        // THIS agency's share, ex VAT (external co-agency sides contribute 0 to the pools).
        $excl = round((float) ($pools['listingPool'] ?? 0) + (float) ($pools['sellingPool'] ?? 0), 2);
        $vat  = $vatRegistered ? round($excl * $vatRate, 2) : 0.0;
        $incl = round($excl + $vat, 2);

        return [
            'vat_registered'       => $vatRegistered,
            'vat_rate'             => $vatRegistered ? round($vatRate * 100, 2) : 0.0,
            'commission_excl'      => $excl,
            'commission_vat'       => $vat,
            'commission_incl'      => $incl,
            'reference'            => $this->reference($deal),
            'seller_name'          => $this->sellerName($deal),
            'seller_contact_id'    => $this->sellerContactId($deal),
            'attorney_name'        => $this->attorneyName($deal),
            'attorney_provider_id' => $deal->attorney_provider_id ? (int) $deal->attorney_provider_id : null,
        ];
    }

    public function reference(Deal $deal): string
    {
        $addr = $deal->property?->buildDisplayAddress() ?: ($deal->property_address ?: '');
        $no   = $deal->deal_no ?: ('#' . $deal->id);
        return trim($addr) !== '' ? "{$no} – {$addr}" : (string) $no;
    }

    public function sellerName(Deal $deal): string
    {
        if (! empty($deal->seller_name)) {
            return (string) $deal->seller_name;
        }
        $contact = $deal->property?->sellerOwnerContact();
        return $contact?->full_name ?: 'Seller';
    }

    public function sellerContactId(Deal $deal): ?int
    {
        $contact = $deal->property?->sellerOwnerContact();
        return $contact?->id;
    }

    public function attorneyName(Deal $deal): ?string
    {
        if ($deal->attorney_provider_id) {
            $firm = AgencyServiceProvider::withoutGlobalScopes()->find($deal->attorney_provider_id);
            if ($firm) {
                return $firm->name ?: $firm->company;
            }
        }
        return $deal->attorney_name ?: null;
    }
}
