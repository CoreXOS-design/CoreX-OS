<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\PlatformEsign\WordingVersion;

/** A worst-case filled sample (long values, every field set, tiny signatures) used to calibrate pagination. */
class AgreementSample
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public static function ctx(WordingVersion $v): array
    {
        $long = 'Caprivi Coastal Realty Holdings (Pty) Ltd t/a Caprivi Realty';
        $values = [
            'registered_name' => $long, 'trading_name' => $long, 'reg_no' => '2019 / 123456 / 07', 'vat_no' => '4123456789', 'ffc_no' => 'FFC-2026-001234',
            'address' => '1234 Marine Drive, Margate Extension 13, Margate, Ray Nkonyeni Local Municipality, KwaZulu-Natal, 4275',
            'notice_email' => 'notices.and.invoices@capriviccoastalrealty.example', 'principal_name' => 'Johannes Petrus van der Westhuizen',
            'billing_name' => 'Accounts Department Manager', 'billing_email' => 'accounts@capriviccoastalrealty.example', 'billing_cell' => '+27 82 555 0123',
            'branches' => '3', 'entity' => 'juristic', 'plan' => 'agency', 'branches_start' => '3', 'agents' => '25', 'extra_branches' => '2',
            'start_date' => '2026-11-01', 'term' => 'other', 'term_months' => '12',
            'da_holder' => $long, 'da_bank' => 'First National Bank', 'da_branch_code' => '250655', 'da_account' => '62123456789', 'da_type' => 'current',
            'sig_name' => 'Johannes Petrus van der Westhuizen', 'sig_capacity' => 'Principal and sole director', 'sig_place' => 'Margate', 'sig_date' => '2026-10-06',
            'm_holder' => $long, 'm_address' => '1234 Marine Drive, Margate Extension 13, Margate, KwaZulu-Natal, 4275', 'm_bank' => 'First National Bank',
            'm_branch_name_town' => 'Margate Business Branch, Margate', 'm_branch_no' => '250655', 'm_account' => '62123456789', 'm_account_type' => 'current',
            'm_date' => '2026-10-06', 'm_contact' => '+27 82 555 0123', 'm_amount' => '6 950', 'm_first_payment' => '2026-11-01', 'm_day' => '1',
            'm_assisted_by' => 'Not applicable', 'm_capacity' => 'Principal', 'm_place' => 'Margate',
        ];
        $rr = ['variation_text' => 'Discount agreed with the principal for the first three months of the term.', 'variation_amount' => '500',
            'rr_name' => 'Firstname Surname', 'rr_capacity' => 'Director', 'rr_place' => 'Town Name', 'rr_date' => '2026-10-07'];

        return [
            'values' => $values, 'rr' => $rr, 'rates' => $v->rates_json ?? [], 'ref' => 'CX000123',
            'calc' => AgreementPricing::compute('agency', 25, 2, 500.0, $v->rates_json ?? []),
            'initials' => ['agency' => 'JW', 'rr' => 'JR'],
            'sigs' => ['agency' => self::PNG, 'mandate' => self::PNG, 'rr' => self::PNG],
            'sign_date' => now(), 'mask' => false,
        ];
    }
}
