<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\Agency;

/**
 * The defined field schema of the CoreX Subscription Agreement (spec §11.4): which values the RECIPIENT fills, which are RR-side,
 * their types, limits and validation. The wording only places tokens; this class decides what a token accepts.
 */
class AgreementFields
{
    /** Values never logged, masked on owner screens, revealed only by an audited action (spec §11.10). */
    public const SENSITIVE = ['da_account', 'm_account'];

    public const ACCOUNT_TYPES = ['current' => 'Current (cheque)', 'savings' => 'Savings', 'transmission' => 'Transmission'];

    /**
     * key => [label, side (r=recipient|rr), type, required, max]
     * types: text | email | tel | textarea | int | date | radio | select | money | sig
     */
    public static function schema(): array
    {
        $r = fn (string $label, string $type = 'text', bool $req = true, int $max = 255, array $opts = []) => ['label' => $label, 'side' => 'r', 'type' => $type, 'required' => $req, 'max' => $max, 'options' => $opts];
        $rr = fn (string $label, string $type = 'text', bool $req = true, int $max = 255) => ['label' => $label, 'side' => 'rr', 'type' => $type, 'required' => $req, 'max' => $max, 'options' => []];

        return [
            // Part A §1
            'registered_name' => $r('Registered name'),
            'trading_name'    => $r('Trading name'),
            'reg_no'          => $r('Registration number', 'text', true, 60),
            'vat_no'          => $r('VAT number', 'text', false, 40),
            'ffc_no'          => $r('PPRA Fidelity Fund Certificate number', 'text', true, 60),
            'address'         => $r('Physical address', 'textarea', true, 500),
            'notice_email'    => $r('Email address for notices and invoices', 'email'),
            'principal_name'  => $r('Principal — full name'),
            'billing_name'    => $r('Billing contact name'),
            'billing_email'   => $r('Billing contact email', 'email'),
            'billing_cell'    => $r('Billing contact cell', 'tel', true, 40),
            'branches'        => $r('Number of branches', 'int', true, 3),
            // §2, §3
            'entity'          => $r('Type of entity', 'radio', true, 20, ['juristic' => 'Company, close corporation or trust', 'natural' => 'Sole proprietor']),
            'plan'            => $r('Plan', 'radio', true, 20, ['team' => 'CoreX Team', 'agency' => 'CoreX Agency']),
            'branches_start'  => $r('Branches at start', 'int', true, 3),
            'agents'          => $r('Number of agents (seats) at start', 'int', true, 3),
            'extra_branches'  => $r('Additional branches', 'int', true, 3),
            // §4
            'start_date'      => $r('Start date', 'date'),
            'term'            => $r('Initial term', 'radio', true, 20, ['one_month' => '1 month', 'other' => 'Other']),
            'term_months'     => $r('Initial term (months)', 'int', false, 3),
            // §5
            'da_holder'       => $r('Account holder'),
            'da_bank'         => $r('Bank'),
            'da_branch_code'  => $r('Branch code', 'text', true, 20),
            'da_account'      => $r('Account number', 'text', true, 30),
            'da_type'         => $r('Account type', 'select', true, 20, self::ACCOUNT_TYPES),
            // §6 (agency signs)
            'sig_name'        => $r('Name of person signing'),
            'sig_capacity'    => $r('Capacity'),
            'sig_place'       => $r('Place'),
            'sig_date'        => $r('Date', 'date'),
            // Netcash mandate
            'm_holder'          => $r('Accountholder'),
            'm_address'         => $r('Address', 'textarea', true, 500),
            'm_bank'            => $r('Bank name'),
            'm_branch_name_town'=> $r('Branch name and town'),
            'm_branch_no'       => $r('Branch number', 'text', true, 20),
            'm_account'         => $r('Account number', 'text', true, 30),
            'm_account_type'    => $r('Type of account', 'select', true, 20, self::ACCOUNT_TYPES),
            'm_date'            => $r('Date', 'date'),
            'm_contact'         => $r('Contact number', 'tel', true, 40),
            'm_amount'          => $r('Amount', 'money', true, 14),
            'm_first_payment'   => $r('First payment date', 'date'),
            'm_day'             => $r('Day of month', 'int', true, 2),
            'm_assisted_by'     => $r('Assisted by', 'text', false, 120),
            'm_capacity'        => $r('Capacity', 'text', false, 120),
            'm_place'           => $r('Place signed', 'text', true, 120),
            // signatures (data URIs)
            'sigA'            => $r('Signature', 'sig', true, 400000),
            'sigM'            => $r('Signature as used for operating on the account', 'sig', true, 400000),
            // RR side
            'variation_text'   => $rr('Agreed variations', 'textarea', false, 500),
            'variation_amount' => $rr('Agreed discount (R)', 'money', false, 14),
            'rr_name'          => $rr('Name'),
            'rr_capacity'      => $rr('Capacity'),
            'rr_place'         => $rr('Place'),
            'rr_date'          => $rr('Date', 'date'),
            'sigR'             => $rr('Company signature', 'sig', true, 400000),
        ];
    }

    public static function recipientKeys(): array
    {
        return array_keys(array_filter(self::schema(), fn ($f) => $f['side'] === 'r'));
    }

    public static function rrKeys(): array
    {
        return array_keys(array_filter(self::schema(), fn ($f) => $f['side'] === 'rr'));
    }

    /**
     * SINGLE ENTRY (spec §11.20, Johan 2026-10-06): every value that used to be typed in both the agreement and the Netcash mandate is typed ONCE; the other
     * place mirrors it, read-only. target => source. The mandate is the one place bank details are typed (section 5 mirrors it); the agency's place and date
     * are typed in Part A and the mandate mirrors them (address and contact number only FOLLOW Part A — see FOLLOW). Applies to agreements sent with `rr_data.single_entry`; earlier ones keep both typeable.
     */
    public const MIRRORS = [
        'da_holder' => 'm_holder', 'da_bank' => 'm_bank', 'da_branch_code' => 'm_branch_no', 'da_account' => 'm_account', 'da_type' => 'm_account_type',
        'm_place' => 'sig_place', 'm_date' => 'sig_date',
    ];

    /**
     * FOLLOW (Johan 2026-10-06 13:20): the mandate's address and contact number start from Part A (address, billing cell) and follow it while the
     * recipient has not edited the mandate field; their own value then sticks; clearing the field makes it follow Part A again. Editable, never locked.
     * target => source.
     */
    public const FOLLOW = ['m_address' => 'address', 'm_contact' => 'billing_cell'];

    /** Mandate field => Part A field it is pre-filled from (spec §11.4). The recipient can still change them. Legacy agreements only — see MIRRORS. */
    public static function mandateMirror(): array
    {
        return [
            'm_holder' => 'da_holder', 'm_address' => 'address', 'm_bank' => 'da_bank', 'm_branch_no' => 'da_branch_code',
            'm_account' => 'da_account', 'm_account_type' => 'da_type', 'm_contact' => 'billing_cell', 'm_amount' => '@total',
            'm_place' => 'sig_place',
        ];
    }

    /**
     * Whitelist + sanitise an input array for one side. Unknown keys and keys of the other side are dropped,
     * so a recipient request can never write an RR-side field and vice versa.
     *
     * @return array<string,mixed>
     */
    public static function clean(array $input, string $side): array
    {
        $out = [];
        foreach (self::schema() as $key => $f) {
            if ($f['side'] !== $side || !array_key_exists($key, $input)) {
                continue;
            }
            $v = $input[$key];
            if ($v === null) {
                $out[$key] = '';
                continue;
            }
            if (!is_scalar($v)) {
                continue;
            }
            $v = (string) $v;
            switch ($f['type']) {
                case 'sig':
                    $out[$key] = self::cleanSignature($v);
                    break;
                case 'textarea':
                    $out[$key] = mb_substr(trim(preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r", '', $v))), 0, $f['max']);
                    break;
                case 'int':
                    $d = preg_replace('/\D+/', '', $v);
                    $out[$key] = $d === '' ? '' : (string) min((int) $d, (int) str_repeat('9', $f['max']));
                    break;
                case 'money':
                    $n = str_replace([' ', ','], ['', '.'], $v);
                    $out[$key] = preg_match('/^\d{1,9}(\.\d{1,2})?$/', $n) ? $n : (trim($v) === '' ? '' : mb_substr(trim($v), 0, 14));
                    break;
                case 'date':
                    $out[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) ? trim($v) : '';
                    break;
                case 'radio':
                case 'select':
                    $out[$key] = array_key_exists($v, $f['options']) ? $v : '';
                    break;
                default:
                    $out[$key] = mb_substr(trim(preg_replace('/\s+/u', ' ', preg_replace('/\p{C}+/u', ' ', $v))), 0, $f['max']);
            }
        }

        return $out;
    }

    /** Only a real PNG data-URI of sane size is kept. */
    public static function cleanSignature(?string $uri): string
    {
        if (!$uri || !str_starts_with($uri, 'data:image/png;base64,') || strlen($uri) > 400_000) {
            return '';
        }
        $bin = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);

        return ($bin !== false && @getimagesizefromstring($bin) !== false) ? $uri : '';
    }

    /**
     * Final (submit-time) validation of the recipient's entries.
     *
     * @param array<string,mixed> $d recipient values (already cleaned)
     * @return array<string,string> key => message
     */
    public static function validateRecipient(array $d, array $rates, array $skip = []): array
    {
        $errors = [];
        foreach (self::schema() as $key => $f) {
            if ($f['side'] !== 'r' || in_array($key, $skip, true)) {
                continue; // a mirrored field is validated where it is typed (its source)
            }
            if (in_array($key, AgreementService::DERIVED_KEYS, true)) {
                continue; // decided by the number of agents and branches (AgreementPricing::derive), never entered
            }
            $v = trim((string) ($d[$key] ?? ''));
            if ($key === 'term_months') {
                if (($d['term'] ?? '') === 'other' && $v === '') {
                    $errors[$key] = 'Enter the number of months for the initial term.';
                }
                continue;
            }
            if ($v === '' && $f['required']) {
                $errors[$key] = match ($f['type']) {
                    'radio', 'select' => 'Choose one: ' . $f['label'] . '.',
                    'sig' => 'Sign here: ' . $f['label'] . '.',
                    default => $f['label'] . ' is required.',
                };
                continue;
            }
            if ($v === '') {
                continue;
            }
            if ($f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = 'Enter a valid email address.';
            }
            if ($f['type'] === 'tel' && !preg_match('/^[0-9 +()\-]{7,40}$/', $v)) {
                $errors[$key] = 'Enter a valid phone number.';
            }
            if ($f['type'] === 'date' && !strtotime($v)) {
                $errors[$key] = 'Enter a valid date.';
            }
        }
        foreach (['branches' => 1, 'agents' => 1, 'm_day' => 1] as $k => $min) {
            if (!isset($errors[$k]) && isset($d[$k]) && $d[$k] !== '' && (int) $d[$k] < $min) {
                $errors[$k] = 'Must be at least ' . $min . '.';
            }
        }
        if (!isset($errors['m_day']) && isset($d['m_day']) && $d['m_day'] !== '' && (int) $d['m_day'] > 31) {
            $errors['m_day'] = 'Day of the month must be 1 to 31.';
        }
        if (!isset($errors['term_months']) && ($d['term'] ?? '') === 'other' && isset($d['term_months']) && $d['term_months'] !== '' && ((int) $d['term_months'] < 2 || (int) $d['term_months'] > 120)) {
            $errors['term_months'] = 'Enter 2 to 120 months.';
        }
        if (!isset($errors['agents']) && ($d['plan'] ?? '') === 'team' && (int) ($d['agents'] ?? 0) > (int) ($rates['team_max_seats'] ?? 10)) {
            $errors['agents'] = 'CoreX Team is for up to ' . (int) ($rates['team_max_seats'] ?? 10) . ' seats — choose the CoreX Agency plan for more.';
        }
        foreach (['da_account', 'm_account'] as $k) {
            if (!isset($errors[$k]) && !empty($d[$k]) && !preg_match('/^[0-9 \-]{4,30}$/', (string) $d[$k])) {
                $errors[$k] = 'Account numbers contain digits only.';
            }
        }

        return $errors;
    }

    /**
     * Values an existing agency record already holds (spec §11.9). Blank when the record has nothing.
     *
     * @return array<string,string>
     */
    public static function prefillFromAgency(?Agency $a): array
    {
        if (!$a) {
            return [];
        }
        $map = [
            'registered_name' => $a->name, 'trading_name' => $a->trading_name ?: $a->name, 'reg_no' => $a->reg_no,
            'vat_no' => $a->vat_no, 'ffc_no' => $a->ffc_no ?: $a->ppra_number, 'address' => $a->address, 'notice_email' => $a->email,
        ];

        return array_filter(array_map(fn ($v) => trim((string) $v), $map), fn ($v) => $v !== '');
    }
}
