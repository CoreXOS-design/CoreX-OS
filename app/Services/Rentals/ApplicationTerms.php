<?php

namespace App\Services\Rentals;

use App\Models\LeaseSetting;
use App\Models\RentalApplicationQualifyingSetting;

/**
 * The agency's own terms for a rental application (rental-applications.md "Application terms"): what the applicant
 * is told they will pay, where the deposit goes, the summary they read and the documents they bring. ONE builder
 * for every surface (online form, agent review, printed/PDF application) so the three can never say different things.
 *
 * Everything is agency settings; an agency that has set nothing gets empty arrays and the surfaces print nothing.
 * Settings live in LeaseSetting (deposit multiple, lease fee, renewal fee, rent-before-occupation wording, deposit
 * bank details) and RentalApplicationQualifyingSetting (application fee, summary, documents).
 */
class ApplicationTerms
{
    /**
     * @param  float|null  $monthlyRent  the property's monthly rent; the Deposit row needs it, so it is left out without it
     * @param  int  $applicants  how many people are applying (applicant + co-applicant): the fee is per applicant
     * @param  string|null  $propertyAddress  the payment reference when the agency's rule is "the property address"
     * @param  string|null  $tenantName  the payment reference when the rule is "the tenant's name"
     * @param  string|null  $leaseReference  the payment reference when the rule is "the lease reference"
     * @return array{
     *     amounts: list<array{label: string, value: string}>,
     *     bank: array{rows: list<array{label: string, value: string}>, reference: string, note: string}|null,
     *     summary_heading: string,
     *     summary: list<string>,
     *     documents: list<string>
     * }
     */
    public static function for(
        ?int $agencyId,
        ?float $monthlyRent = null,
        int $applicants = 1,
        ?string $propertyAddress = null,
        ?string $tenantName = null,
        ?string $leaseReference = null,
    ): array {
        return [
            'amounts' => self::amounts($agencyId, $monthlyRent, max(1, $applicants)),
            'bank' => self::bank($agencyId, $propertyAddress, $tenantName, $leaseReference),
            'summary_heading' => self::summaryHeading(RentalApplicationQualifyingSetting::tenantInfoSummaryFor($agencyId)),
            'summary' => self::lines(self::withoutHeading(RentalApplicationQualifyingSetting::tenantInfoSummaryFor($agencyId))),
            'documents' => self::lines(RentalApplicationQualifyingSetting::documentsToAccompanyFor($agencyId)),
        ];
    }

    /** 'R 1,234.00' - the format every rental screen and document prints money in. */
    public static function money(float $amount): string
    {
        return 'R ' . number_format($amount, 2, '.', ',');
    }

    /** @return list<array{label: string, value: string}> */
    private static function amounts(?int $agencyId, ?float $monthlyRent, int $applicants): array
    {
        $rows = [];

        $fee = RentalApplicationQualifyingSetting::applicationFeeFor($agencyId);
        if ($fee !== null) {
            $value = self::money($fee);
            if ($applicants > 1) {
                $value .= ' (' . $applicants . ' applicants: ' . self::money($fee * $applicants) . ')';
            }
            $rows[] = ['label' => 'Application fee (per applicant)', 'value' => $value];
        }

        if ($monthlyRent !== null && $monthlyRent > 0) {
            $months = LeaseSetting::defaultDepositMonthsFor($agencyId);
            $rows[] = [
                'label' => 'Deposit',
                'value' => self::money(round($monthlyRent * $months, 2)) . ' (' . rtrim(rtrim(number_format($months, 2, '.', ''), '0'), '.') . ' × monthly rent)'
                    . (($note = LeaseSetting::depositNoteFor($agencyId)) !== '' ? ' - ' . $note : ''),
            ];
        }

        $leaseFee = LeaseSetting::defaultLeaseFeeFor($agencyId);
        if ($leaseFee > 0) {
            $rows[] = ['label' => LeaseSetting::leaseFeeLabelFor($agencyId), 'value' => self::money($leaseFee)];
        }

        $renewal = LeaseSetting::renewalFeeFor($agencyId);
        if ($renewal !== null) {
            $rows[] = ['label' => 'Renewal fee', 'value' => self::money($renewal)];
        }

        $beforeOccupation = LeaseSetting::rentOccupationTextFor($agencyId);
        if ($beforeOccupation !== '') {
            $rows[] = ['label' => 'Rent payable before occupation', 'value' => $beforeOccupation];
        }

        return $rows;
    }

    /** @return array{rows: list<array{label: string, value: string}>, reference: string, note: string}|null */
    private static function bank(?int $agencyId, ?string $propertyAddress, ?string $tenantName, ?string $leaseReference): ?array
    {
        $details = LeaseSetting::depositBankDetailsFor($agencyId);
        if ($details === null) {
            return null;
        }

        $reference = match (LeaseSetting::depositReferenceRuleFor($agencyId)) {
            'tenant_name' => trim((string) $tenantName),
            'lease_reference' => trim((string) $leaseReference),
            default => trim((string) $propertyAddress),
        };

        return [
            'rows' => array_map(fn (array $r) => ['label' => $r['label'], 'value' => $r['value']], $details),
            'reference' => $reference,
            'note' => LeaseSetting::depositBankNoteFor($agencyId),
        ];
    }

    /** A first line starting with # is the list's own heading ('' when there is none): the agency's wording, not ours. */
    private static function summaryHeading(string $text): string
    {
        $first = trim((string) (preg_split('/\R/u', ltrim($text))[0] ?? ''));

        return str_starts_with($first, '#') ? trim(ltrim($first, '# ')) : '';
    }

    private static function withoutHeading(string $text): string
    {
        return self::summaryHeading($text) !== '' ? (string) preg_replace('/^[^\r\n]*\R?/u', '', ltrim($text), 1) : $text;
    }

    /** @return list<string> one entry per non-blank line, trimmed, a leading bullet mark removed */
    private static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/^\s*[-•*·]\s*/u', '', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }
}
