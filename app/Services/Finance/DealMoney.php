<?php

namespace App\Services\Finance;

use App\Models\Deal;
use App\Models\PerformanceSetting;

/**
 * One deal's money, worked out in whole CENTS with integer arithmetic only —
 * there is no float anywhere in this class, so a rand figure can never be
 * off by a floating-point sliver.
 *
 * This is the ONE place the Deal Register v2 reads a deal's money from. A v2
 * "twin" row that is linked to a real deal (`deals_v2.legacy_deal_id`) never
 * works its own money out from its own columns — it asks DealV2::money(),
 * which builds this from the REAL deal's inputs. So a second, diverging copy
 * of a deal's money cannot exist: there is only ever one set of inputs.
 * (2026-10-08, after the v2 copy of 10 deals lost the "other agency handled
 * this side" marker and would have shown our share doubled — deal 1818.)
 *
 * The rules are the same ones every other screen already uses
 * (CommissionPoolCalculator): the commission is captured INCL VAT; the pools
 * are worked out EX VAT; a side handed to an external agency contributes
 * nothing to our pool and its whole side amount (incl VAT) is owed out; a
 * side we handled keeps 100% of its split. "Our share %" is deliberately not
 * a factor. Every division rounds half-up to the cent, side by side — the
 * same rounding deal_money_lines is stored with.
 */
final class DealMoney
{
    public const SIDES = ['listing', 'selling'];

    /** @var array<string,array{pct:int,external:bool}> pct is in hundredths of a percent (50% = 5000) */
    private array $side;

    private function __construct(
        public readonly int $incVatCents,
        public readonly int $vatRateHundredths,
        array $side
    ) {
        $this->side = $side;
    }

    /**
     * @param mixed $incVat        commission incl VAT, rand (decimal string/number)
     * @param mixed $vatPercent    e.g. 15
     * @param array{split:mixed,external:mixed} $listing
     * @param array{split:mixed,external:mixed} $selling
     */
    public static function fromInputs($incVat, $vatPercent, array $listing, array $selling): self
    {
        $inc = max(0, self::scaled($incVat, 2));

        $l = self::sideInput($listing);
        $s = self::sideInput($selling);

        // The two splits must make 100%. If they do not, they are scaled to (the rule the saved
        // money lines have always applied), so a deal whose splits were typed as 70/40 reads the
        // same everywhere. Both zero → 50/50.
        $sum = $l['pct'] + $s['pct'];
        if ($sum <= 0) {
            $l['pct'] = $s['pct'] = 5000;
        } elseif (abs($sum - 10000) > 1) {
            $l['pct'] = self::divRound($l['pct'] * 10000, $sum);
            $s['pct'] = self::divRound($s['pct'] * 10000, $sum);
        }

        return new self($inc, max(0, self::scaled($vatPercent, 2)), ['listing' => $l, 'selling' => $s]);
    }

    /** The real (legacy `deals`) deal's money — the authoritative source. */
    public static function fromDeal(Deal $deal): self
    {
        // Raw attributes, not the casted accessors: the decimal cast hands back text,
        // the raw value is exactly what the row holds.
        $a = $deal->getAttributes();

        return self::fromInputs(
            $a['total_commission'] ?? 0,
            PerformanceSetting::get('vat_rate', 15, $deal->agency_id ? (int) $deal->agency_id : null),
            ['split' => $a['listing_split_percent'] ?? null, 'external' => $a['listing_external'] ?? false],
            ['split' => $a['selling_split_percent'] ?? null, 'external' => $a['selling_external'] ?? false],
        );
    }

    // ── headline figures ─────────────────────────────────────────────────

    public function exVatCents(): int
    {
        if ($this->incVatCents <= 0) {
            return 0;
        }

        // inc / (1 + vat%) with vat% held in hundredths: inc * 10000 / (10000 + vat)
        return self::divRound($this->incVatCents * 10000, 10000 + $this->vatRateHundredths);
    }

    public function vatCents(): int
    {
        return $this->incVatCents - $this->exVatCents();
    }

    public function isExternal(string $side): bool
    {
        return $this->side[$side]['external'];
    }

    /** The VAT rate as a plain fraction for views that still take a float: 1500 → 0.15 (text cast, no arithmetic). */
    public function vatRateFloat(): float
    {
        return (float) ('0.' . str_pad((string) $this->vatRateHundredths, 4, '0', STR_PAD_LEFT));
    }

    /** The side's split as a percent string, e.g. "50.00". */
    public function splitPercent(string $side): string
    {
        return self::cents($this->side[$side]['pct']);
    }

    /** Our (internal) pool for a side, ex VAT. Zero when the other agency handled the side. */
    public function sidePoolCents(string $side): int
    {
        if ($this->side[$side]['external']) {
            return 0;
        }

        return self::divRound($this->exVatCents() * $this->side[$side]['pct'], 10000);
    }

    /** What we owe OUT to the other agency for a side, incl VAT. Zero for a side we handled. */
    public function externalPayableCents(string $side): int
    {
        if (! $this->side[$side]['external']) {
            return 0;
        }

        return self::divRound($this->incVatCents * $this->side[$side]['pct'], 10000);
    }

    /** Our share of the commission, ex VAT — what HFC keeps across both sides. */
    public function ourTotalCents(): int
    {
        return $this->sidePoolCents('listing') + $this->sidePoolCents('selling');
    }

    public function externalPayableTotalCents(): int
    {
        return $this->externalPayableCents('listing') + $this->externalPayableCents('selling');
    }

    /** The external total with its VAT taken out — the ex-VAT leg of the settlement checksum. */
    public function externalPayableExVatCents(): int
    {
        return self::divRound($this->externalPayableTotalCents() * 10000, 10000 + $this->vatRateHundredths);
    }

    /** Every figure the screens show, as plain cents — for comparing two readings of one deal. */
    public function snapshot(): array
    {
        return [
            'inc_vat'              => $this->incVatCents,
            'ex_vat'               => $this->exVatCents(),
            'listing_split'        => $this->side['listing']['pct'],
            'selling_split'        => $this->side['selling']['pct'],
            'listing_external'     => $this->side['listing']['external'] ? 1 : 0,
            'selling_external'     => $this->side['selling']['external'] ? 1 : 0,
            'listing_pool'         => $this->sidePoolCents('listing'),
            'selling_pool'         => $this->sidePoolCents('selling'),
            'our_total'            => $this->ourTotalCents(),
            'listing_ext_payable'  => $this->externalPayableCents('listing'),
            'selling_ext_payable'  => $this->externalPayableCents('selling'),
        ];
    }

    // ── presentation edge (no arithmetic — string formatting only) ───────

    /** 5100000 → "51000.00" */
    public static function cents(int $cents): string
    {
        $neg = $cents < 0;
        $abs = $neg ? -$cents : $cents;

        return ($neg ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** For callers that still take a float: a straight cast of the exact decimal text. */
    public static function toFloat(int $cents): float
    {
        return (float) self::cents($cents);
    }

    // ── internals ────────────────────────────────────────────────────────

    private static function sideInput(array $in): array
    {
        $pct = $in['split'] === null || $in['split'] === '' ? 5000 : self::scaled($in['split'], 2);

        return [
            'pct' => max(0, min(10000, $pct)),
            'external' => self::truthy($in['external'] ?? false),
        ];
    }

    private static function truthy($v): bool
    {
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $v;
    }

    /** Half-up integer division for non-negative operands. */
    private static function divRound(int $num, int $den): int
    {
        if ($den <= 0) {
            return 0;
        }

        return intdiv($num * 2 + $den, $den * 2);
    }

    /**
     * Decimal text → integer at $scale decimals, rounded half-up, by string
     * handling only ("58650.00" → 5865000). Accepts the strings the database
     * returns and the ints/floats PHP code passes; a float is first turned
     * into text at 6 decimals, never multiplied.
     */
    public static function scaled($value, int $scale): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $s = is_float($value) ? sprintf('%.6F', $value) : trim((string) $value);
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '+-');
        if (! preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $m)) {
            return 0;
        }
        $int = $m[1] === '' ? '0' : $m[1];
        $frac = $m[2] ?? '';
        $keep = substr(str_pad($frac, $scale, '0'), 0, $scale);
        $next = strlen($frac) > $scale ? (int) $frac[$scale] : 0;
        $n = (int) ($int . $keep) + ($next >= 5 ? 1 : 0);

        return $neg ? -$n : $n;
    }
}
