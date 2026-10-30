<?php

namespace App\Services\Revenue;

use App\Models\RevenueBillingRecord;
use App\Models\RevenuePriceList;
use App\Models\RevenueProspect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The money rules of the owner's Revenue section, in one place (spec: .ai/specs/revenue.md).
 *  - Price list (owner-editable, default = corexweb.co.za/pricing): one flat monthly price per band of ACTIVE agents (unlimited within
 *    the band), plus R750 for each branch after the first. 31+ agents is on quotation: no computed price, set a special deal.
 *  - A signed agency's monthly = its special-deal override if set, else the price list, else unknown. No setup fee, no VAT line.
 *  - Billing starts on the 1st of the month AFTER go-live (the take-on month is free).
 *  - A prospect's monthly = a range (users min-max priced on the list, or its override); weighted = midpoint x chance %.
 *  - Cash flow: month by month, signed (from the month after go-live) + open pipeline (weighted, from the month after its expected go-live).
 */
class RevenueCalculator
{
    private array $prices;

    public function __construct(?array $prices = null)
    {
        $this->prices = $prices ?? RevenuePriceList::current();
    }

    /** Monthly price for this many active agents on the list, or null when it is on quotation / unknown. */
    public function listPrice(?int $users, int $branches = 1): ?float
    {
        if (! $users || $users < 1 || $users >= (int) $this->prices['quote_from']) {
            return null;
        }
        foreach ($this->prices['bands'] as $band) {
            if ($users >= (int) $band['from'] && $users <= (int) $band['to']) {
                return (float) $band['price'] + max(0, $branches - 1) * (float) $this->prices['branch_fee'];
            }
        }

        return null;
    }

    /** The first month an agency / prospect is billed: the month after go-live. */
    public function billingFrom(?Carbon $goLive): ?Carbon
    {
        return $goLive?->copy()->startOfMonth()->addMonth();
    }

    public function monthly(RevenueBillingRecord $r): ?float
    {
        return $r->override_monthly ?: $this->listPrice($r->users, max(1, (int) $r->branches));
    }

    /** @return Collection<int,array> */
    public function signed(): Collection
    {
        $agencies = \Illuminate\Support\Facades\DB::table('agencies')->pluck('name', 'id');

        return RevenueBillingRecord::orderBy('name')->get()->map(fn (RevenueBillingRecord $r) => $this->signedRow($r, $agencies[$r->agency_id] ?? null));
    }

    public function signedRow(RevenueBillingRecord $r, ?string $agency = null): array
    {
        $monthly = $this->monthly($r);

        return [
            'record' => $r, 'name' => $r->name, 'agency' => $agency, 'users' => $r->users, 'branches' => (int) $r->branches, 'monthly' => $monthly,
            'price' => $monthly && $r->users ? round($monthly / $r->users, 2) : null, 'start' => $r->start_date, 'term' => $r->term_months,
            'override' => $r->override_monthly ? ($r->override_note ?: 'Special deal') : null,
            'quote' => ! $monthly && $r->users && $r->users >= (int) $this->prices['quote_from'],
        ];
    }

    public function signedTotals(Collection $rows): array
    {
        return ['agencies' => $rows->count(), 'users' => (int) $rows->sum('users'), 'monthly' => (float) $rows->sum('monthly')];
    }

    /** @return array{low:?float,high:?float,weighted:float} */
    public function prospectMoney(RevenueProspect $p): array
    {
        if ($p->monthly_override) {
            $low = $high = (float) $p->monthly_override;
        } else {
            $min = $p->users_min ?: $p->users_max;
            $max = $p->users_max ?: $p->users_min;
            $low = $this->listPrice($min);
            $high = $this->listPrice($max);
        }
        $mid = ($low !== null && $high !== null) ? ($low + $high) / 2 : ($low ?? $high ?? 0.0);

        return ['low' => $low, 'high' => $high, 'weighted' => round($mid * $p->chance_percent / 100, 2)];
    }

    /** @return Collection<int,array> open prospects (not signed) with their money */
    public function pipeline(): Collection
    {
        return RevenueProspect::where('stage', '!=', 'signed')->orderBy('name')->get()->map(fn (RevenueProspect $p) => ['prospect' => $p] + $this->prospectMoney($p));
    }

    public function pipelineTotals(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'users_min' => (int) $rows->sum(fn ($r) => $r['prospect']->users_min ?: $r['prospect']->users_max),
            'users_max' => (int) $rows->sum(fn ($r) => $r['prospect']->users_max ?: $r['prospect']->users_min),
            'low' => (float) $rows->sum('low'), 'high' => (float) $rows->sum('high'), 'weighted' => (float) $rows->sum('weighted'),
        ];
    }

    /** @return list<array{month:Carbon,signed:float,pipeline:float,total:float}> */
    public function cashFlow(?Carbon $from = null, int $months = 12): array
    {
        $from = ($from ?: now())->copy()->startOfMonth();
        $signed = $this->signed();
        $pipeline = $this->pipeline();
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $from->copy()->addMonths($i);
            $s = (float) $signed->filter(fn ($r) => $r['monthly'] && (! $r['start'] || $this->billingFrom($r['start'])->lte($m)))->sum('monthly');
            $p = (float) $pipeline->filter(fn ($r) => $r['prospect']->expected_start_month && $this->billingFrom($r['prospect']->expected_start_month)->lte($m))->sum('weighted');
            $out[] = ['month' => $m, 'signed' => round($s, 2), 'pipeline' => round($p, 2), 'total' => round($s + $p, 2)];
        }

        return $out;
    }

    /** No VAT line: RR Technologies is not VAT-registered yet; the prices become VAT-inclusive later with no increase. */
    public function vatLabel(): string
    {
        return '';
    }
}
