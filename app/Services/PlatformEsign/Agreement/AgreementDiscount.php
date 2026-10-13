<?php

namespace App\Services\PlatformEsign\Agreement;

/**
 * The agreed discount on a Subscription Agreement (spec agency-timeline-and-platform-esign.md §11.7a): a TYPE (percentage | rand amount),
 * a VALUE and an optional DURATION in months (blank = the whole term).
 *
 * Stored in documents.rr_data (no migration): variation_type ('amount'|'percent'), variation_percent, variation_amount (rand — the
 * original key), variation_months. An agreement sent before this existed has none of variation_type, so it keeps rendering exactly as
 * it did (a flat rand amount for the whole term, described by the free-text note only).
 *
 * The percentage / amount applies to the WHOLE monthly bill (every recurring line of section 3: seats, base fee, branches). The document
 * has no VAT line (it says the company is not VAT registered and "the amounts quoted are the full amounts payable") — so the discount reduces
 * the full amount payable and there is no incl/excl split. The only once-off line, the set-up fee, is the fixed word "Waived".
 * The JS in agreement/_js.blade.php mirrors words() line for line.
 */
class AgreementDiscount
{
    public const MAX_MONTHS = 120;

    /** @return array{type:string,value:float,months:?int,structured:bool}|null null = no discount */
    public static function fromRr(array $rr): ?array
    {
        $type = ($rr['variation_type'] ?? '') === 'percent' ? 'percent' : 'amount';
        $value = (float) ($type === 'percent' ? ($rr['variation_percent'] ?? 0) : ($rr['variation_amount'] ?? 0));
        if ($value <= 0) {
            return null;
        }
        $months = (int) ($rr['variation_months'] ?? 0);

        return [
            'type' => $type, 'value' => $type === 'percent' ? min($value, 100.0) : $value,
            'months' => $months >= 1 ? min($months, self::MAX_MONTHS) : null,
            'structured' => isset($rr['variation_type']), // sent with this feature; an older agreement renders as it always did
        ];
    }

    /** The rand discount on a monthly subtotal, in cents precision, never more than the subtotal. */
    public static function amountFor(float $subtotal, ?array $spec): float
    {
        if (!$spec || $subtotal <= 0) {
            return 0.0;
        }
        $off = $spec['type'] === 'percent' ? round($subtotal * $spec['value'] / 100, 2) : $spec['value'];

        return max(0.0, min(round($off, 2), $subtotal));
    }

    /**
     * Validate and normalise what the owner typed on the send form. Returns the rr_data keys to store (empty = no discount).
     *
     * @return array<string,string>
     * @throws \DomainException
     */
    public static function parseInput(?string $type, ?string $value, ?string $months): array
    {
        $type = $type === 'percent' ? 'percent' : 'amount';
        $v = trim(str_replace(["\u{00A0}", ' '], '', (string) $value));
        $m = trim((string) $months);
        if ($v === '' && $m === '') {
            return [];
        }
        if ($type === 'percent') {
            $v = rtrim($v, '%');
            $v = str_replace(',', '.', $v);
            if ($v !== '' && (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v) || (float) $v > 100)) {
                throw new \DomainException('The discount percentage must be a number from 0 to 100, for example 30.');
            }
        } else {
            $v = preg_replace('/^R/i', '', $v);
            $v = (str_contains($v, ',') && str_contains($v, '.')) ? str_replace(',', '', $v) : str_replace(',', '.', $v);
            if ($v !== '' && !preg_match('/^\d{1,9}(\.\d{1,2})?$/', $v)) {
                throw new \DomainException('The agreed discount must be an amount in rand.');
            }
        }
        if ($m !== '' && (!preg_match('/^\d{1,3}$/', $m) || (int) $m < 1 || (int) $m > self::MAX_MONTHS)) {
            throw new \DomainException('The number of months must be a whole number from 1 to ' . self::MAX_MONTHS . ', or blank for the whole term.');
        }
        if ((float) $v <= 0) {
            if ($m !== '') {
                throw new \DomainException('Enter the discount (a percentage or an amount) for those months, or clear the months.');
            }

            return []; // 0% / R0: no discount
        }
        $out = ['variation_type' => $type, $type === 'percent' ? 'variation_percent' : 'variation_amount' => self::clean($v)];
        if ($m !== '') {
            $out['variation_months'] = (string) (int) $m;
        }

        return $out;
    }

    private static function clean(string $n): string
    {
        return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
    }

    // ── words ──────────────────────────────────────────────────────────────

    public static function pctText(float $p): string
    {
        return (fmod($p, 1.0) === 0.0 ? number_format($p, 0) : rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.')) . '%';
    }

    /** "30% for the first 3 months" · "R500 a month for the whole term" · "30% for the whole term" */
    public static function short(array $spec): string
    {
        $what = $spec['type'] === 'percent' ? self::pctText($spec['value']) : AgreementPricing::rate($spec['value']) . ' a month';
        $m = $spec['months'];

        return $what . ' ' . ($m === null ? 'for the whole term' : ($m === 1 ? 'for the first month' : 'for the first ' . $m . ' months'));
    }

    /** The sentence that opens the statement (no figures needed). */
    public static function headline(array $spec): string
    {
        $m = $spec['months'];
        $off = $spec['type'] === 'percent' ? self::pctText($spec['value']) . ' off each monthly bill' : AgreementPricing::rate($spec['value']) . ' off each monthly bill';
        $span = $m === null ? 'for the whole term' : ($m === 1 ? 'for the first month billed' : 'for the first ' . $m . ' months billed (months 1 to ' . $m . ')');

        return 'Agreed discount: ' . $off . ' ' . $span . '.';
    }

    /**
     * The figures, as separate plain sentences, from the monthly fees in the table (empty before a plan is chosen).
     *
     * @return string[]
     */
    public static function figures(array $spec, float $subtotal): array
    {
        if ($subtotal <= 0) {
            return [];
        }
        $off = self::amountFor($subtotal, $spec);
        $now = round($subtotal - $off, 2);
        $m = $spec['months'];
        $lines = ['Normal monthly fee: ' . AgreementPricing::rate($subtotal) . '.', 'Discount: ' . AgreementPricing::rate($off) . ' a month.'];
        if ($m === null) {
            $lines[] = 'Monthly fee after the discount: ' . AgreementPricing::rate($now) . '.';
        } else {
            $lines[] = 'Monthly fee for ' . ($m === 1 ? 'month 1' : 'months 1 to ' . $m) . ': ' . AgreementPricing::rate($now) . '.';
            $lines[] = 'Monthly fee from month ' . ($m + 1) . ': ' . AgreementPricing::rate($subtotal) . '.';
        }

        return $lines;
    }

    /** The closing sentence: what the percentage is applied to (the actual bill moves with seats and branches). */
    public static function footnote(array $spec): string
    {
        return $spec['type'] === 'percent'
            ? 'These figures are at the fees in the table below. Each month is billed on the actual seats and branches in that month (clause B10); the discount is ' . self::pctText($spec['value']) . ' of that month’s whole bill.'
            : 'These figures are at the fees in the table below. The discount is a fixed ' . AgreementPricing::rate($spec['value']) . ' off each monthly bill (clause B10).';
    }

    /** Everything, as one paragraph of plain words. */
    public static function words(array $spec, float $subtotal): string
    {
        return implode(' ', array_merge([self::headline($spec)], self::figures($spec, $subtotal), [self::footnote($spec)]));
    }

    /** A line for lists and the document page: the discount in words, or '' when none. */
    public static function summary(array $rr): string
    {
        $spec = self::fromRr($rr);

        return $spec ? self::short($spec) : '';
    }
}
