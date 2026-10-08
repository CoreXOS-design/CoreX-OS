<?php

namespace App\Services\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\DealSettlement;
use App\Services\DealMoneyLineRebuilder;
use App\Services\Finance\DealMoney;
use App\Services\Finance\SettlementScreenRows;
use Illuminate\Support\Facades\DB;

/**
 * The guard behind "one source for a deal's money" (2026-10-08).
 *
 * Walks EVERY linked deal ↔ deals_v2 pair and compares, in whole cents:
 *   - the structure of the link (one twin per deal, both pointers agree, no twin
 *     left behind for a missing deal);
 *   - what the v2 screens would show for the deal (DealV2::money()) against the real
 *     deal's own money (DealMoney::fromDeal) — FAIL if they ever differ;
 *   - what the deal's stored money lines (the figure the dashboards, performance and
 *     payslips sum) say against the real deal's money — FAIL if they differ;
 *   - what the twin's OWN stored money columns say against the real deal — WARN only:
 *     on a linked row that copy is dormant (nothing displays it), but it is listed
 *     so a stale copy is on the record and cannot be mistaken for the truth.
 *
 * Read-only. It never repairs anything: a stored row that needs correcting is
 * reported with its exact wrong and right figures for a decision.
 */
class DealTwinIntegrityService
{
    public const FAIL = 'FAIL';
    public const WARN = 'WARN';

    /** Keys of DealMoney::snapshot() that decide what a screen shows. */
    private const MONEY_KEYS = [
        'inc_vat', 'ex_vat', 'listing_split', 'selling_split', 'listing_external', 'selling_external',
        'listing_pool', 'selling_pool', 'our_total', 'listing_ext_payable', 'selling_ext_payable',
    ];

    /**
     * @return array{pairs:int,findings:array<int,array<string,mixed>>}
     */
    public function audit(?int $agencyId = null): array
    {
        $findings = [];
        $pairs = 0;

        // ── structure: more than one twin for one real deal ──
        $dupes = DB::table('deals_v2')->whereNotNull('legacy_deal_id')
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->select('legacy_deal_id', DB::raw('COUNT(*) as n'), DB::raw('GROUP_CONCAT(id) as ids'))
            ->groupBy('legacy_deal_id')->having('n', '>', 1)->get();
        foreach ($dupes as $d) {
            $findings[] = $this->finding(self::FAIL, 'duplicate_twin', (int) $d->legacy_deal_id, null, null,
                "{$d->n} v2 rows ({$d->ids}) are linked to the same deal — a deal can have only one.");
        }

        // ── structure: a twin whose deal is missing or does not point back ──
        $orphans = DB::table('deals_v2 as v')
            ->leftJoin('deals as d', 'd.id', '=', 'v.legacy_deal_id')
            ->whereNotNull('v.legacy_deal_id')->whereNull('v.deleted_at')
            ->when($agencyId, fn ($q) => $q->where('v.agency_id', $agencyId))
            ->where(fn ($q) => $q->whereNull('d.id')->orWhereNull('d.deal_v2_id')->orWhereColumn('d.deal_v2_id', '!=', 'v.id'))
            ->get(['v.id as v2_id', 'v.legacy_deal_id', 'd.id as deal_id', 'd.deal_v2_id']);
        foreach ($orphans as $o) {
            $findings[] = $this->finding(self::FAIL, 'link_broken', (int) $o->legacy_deal_id, null, (int) $o->v2_id,
                $o->deal_id === null
                    ? "v2 row {$o->v2_id} is linked to deal {$o->legacy_deal_id}, which does not exist."
                    : "v2 row {$o->v2_id} says it belongs to deal {$o->legacy_deal_id}, but that deal points to v2 row " . ($o->deal_v2_id ?: 'none') . '.');
        }

        // ── every linked pair ──
        Deal::withoutGlobalScopes()->where('deal_v2_id', '>', 0)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->orderBy('id')
            ->chunkById(100, function ($deals) use (&$findings, &$pairs) {
                foreach ($deals as $deal) {
                    $pairs++;
                    array_push($findings, ...$this->auditPair($deal));
                }
            });

        return ['pairs' => $pairs, 'findings' => $findings];
    }

    /** @return array<int,array<string,mixed>> */
    public function auditPair(Deal $deal): array
    {
        $out = [];
        $no = (string) ($deal->deal_no ?? $deal->id);
        $twin = DealV2::withoutGlobalScopes()->find($deal->deal_v2_id);
        if (! $twin) {
            return [$this->finding(self::FAIL, 'twin_missing', (int) $deal->id, $no, (int) $deal->deal_v2_id,
                "Deal points to v2 row {$deal->deal_v2_id}, which does not exist.")];
        }
        if ((int) $twin->legacy_deal_id !== (int) $deal->id) {
            return [$this->finding(self::FAIL, 'link_broken', (int) $deal->id, $no, (int) $twin->id,
                "Deal points to v2 row {$twin->id}, but that row is linked to deal " . ($twin->legacy_deal_id ?: 'none') . '.')];
        }

        // The money crosses this link, so both ends must belong to the same agency.
        if ((int) $twin->agency_id !== (int) $deal->agency_id) {
            return [$this->finding(self::FAIL, 'agency_mismatch', (int) $deal->id, $no, (int) $twin->id,
                "The deal belongs to agency {$deal->agency_id} but its v2 row to agency {$twin->agency_id} — money must never cross agencies.")];
        }

        $real = DealMoney::fromDeal($deal)->snapshot();

        // What the v2 screens would show.
        $shown = $this->shownMoney($twin)->snapshot();
        if ($diff = $this->differences($real, $shown)) {
            $out[] = $this->finding(self::FAIL, 'v2_screen_differs', (int) $deal->id, $no, (int) $twin->id,
                'v2 would show different money from the deal: ' . $this->describe($diff), $real['our_total'], $shown['our_total']);
        }

        // What the stored money lines (dashboards / performance / payslips) hold.
        foreach ($this->moneyLineProblems($deal, $real) as $problem) {
            $out[] = $this->finding($problem['severity'], $problem['code'], (int) $deal->id, $no, (int) $twin->id, $problem['message']);
        }

        // The twin's own stored copy — dormant on a linked row, reported for the record.
        $stored = $twin->ownColumnsMoney()->snapshot();
        if ($diff = $this->differences($real, $stored)) {
            $out[] = $this->finding(self::WARN, 'stored_copy_stale', (int) $deal->id, $no, (int) $twin->id,
                'the v2 row\'s own saved copy is out of date (nothing displays it): ' . $this->describe($diff),
                $real['our_total'], $stored['our_total']);
        }

        return $out;
    }

    // ── screen = saved ──────────────────────────────────────────────────

    /**
     * For EVERY deal that has saved money lines (linked to a v2 row or not): works out what the
     * settlement screen shows — pools and every agent row — and compares it, in cents, with the
     * saved lines the dashboards, performance and payslip feeds sum. The saved figures are the
     * figures of record (Johan, 2026-10-08); any difference is a FAIL.
     *
     * The one thing deliberately not compared: a FIXED PAYE on a deal not yet paid. The screen
     * previews it; the saved line records it once paid.
     *
     * @return array{deals:int,findings:array<int,array<string,mixed>>}
     */
    public function auditScreens(?int $agencyId = null): array
    {
        $findings = [];
        $deals = 0;

        Deal::withoutGlobalScopes()->whereNull('deleted_at')
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$findings, &$deals) {
                foreach ($chunk as $deal) {
                    $lines = DB::table('deal_money_lines')->where('deal_id', $deal->id)->whereNull('deleted_at')->get();
                    if ($lines->isEmpty()) {
                        continue;
                    }
                    $deals++;
                    array_push($findings, ...$this->auditScreen($deal, $lines));
                }
            });

        return ['deals' => $deals, 'findings' => $findings];
    }

    /** @return array<int,array<string,mixed>> */
    public function auditScreen(Deal $deal, $lines): array
    {
        $no = (string) ($deal->deal_no ?? $deal->id);
        $pools = DealMoneyLineRebuilder::computeDealPools($deal);
        $settlements = DealSettlement::withoutGlobalScopes()->where('deal_id', $deal->id)->get()
            ->groupBy(fn ($s) => $s->side . ':' . $s->user_id);

        $out = [];
        foreach (['listing' => $pools['listingPool'], 'selling' => $pools['sellingPool']] as $side => $pool) {
            $sideLines = $lines->filter(fn ($l) => strtolower(trim((string) $l->side)) === $side);
            if ($sideLines->isEmpty()) {
                continue;
            }
            $poolCents = DealMoney::scaled($pool, 2);
            $rows = collect(SettlementScreenRows::build($deal, $side, $pool, $settlements))->filter(fn ($r) => (int) $r['user_id'] !== 0)->keyBy('user_id');

            foreach ($sideLines as $l) {
                $saved = DealMoney::scaled($l->side_pool_ex_vat, 2);
                if ($saved !== $poolCents) {
                    $out[] = $this->finding(self::FAIL, 'screen_differs_from_saved', (int) $deal->id, $no, null,
                        ucfirst($side) . ' pool: the settlement screen shows R ' . DealMoney::cents($poolCents) . ' but R ' . DealMoney::cents($saved) . ' is saved.', $saved, $poolCents);
                    break;
                }
            }

            foreach ($sideLines as $l) {
                $row = $rows->get((int) $l->user_id);
                if (! $row) {
                    $out[] = $this->finding(self::FAIL, 'screen_differs_from_saved', (int) $deal->id, $no, null,
                        ucfirst($side) . ' side: agent ' . $l->user_id . ' has a saved line but is missing from the settlement screen.');
                    continue;
                }
                $fixedUnpaid = strtolower(trim((string) $l->paye_method)) !== 'percentage' && ! $l->paid_at;
                $pairs = ['allocated' => 'pool_share_ex_vat', 'gross' => 'agent_gross_ex_vat', 'company' => 'company_gross_ex_vat']
                    + ($fixedUnpaid ? [] : ['paye' => 'paye_amount', 'net' => 'agent_net_ex_vat']);
                foreach ($pairs as $screenKey => $col) {
                    $shown = DealMoney::scaled($row[$screenKey], 2);
                    $saved = DealMoney::scaled($l->$col, 2);
                    if ($shown !== $saved) {
                        $out[] = $this->finding(self::FAIL, 'screen_differs_from_saved', (int) $deal->id, $no, null,
                            ucfirst($side) . " side, agent {$l->user_id}, {$screenKey}: the settlement screen shows R " . DealMoney::cents($shown) . ' but R ' . DealMoney::cents($saved) . ' is saved.', $saved, $shown);
                    }
                }
            }
            $savedUsers = $sideLines->pluck('user_id')->map(fn ($u) => (int) $u)->all();
            foreach ($rows as $uid => $r) {
                if (! in_array((int) $uid, $savedUsers, true)) {
                    $out[] = $this->finding(self::FAIL, 'screen_differs_from_saved', (int) $deal->id, $no, null,
                        ucfirst($side) . " side: the settlement screen shows agent {$uid} but no money line is saved for them.");
                }
            }
        }

        return $out;
    }

    /** What the v2 screens read for this twin — one overridable seam so the guard itself can be tested against a regression. */
    protected function shownMoney(DealV2 $twin): DealMoney
    {
        return $twin->money();
    }

    /** @return array<int,array{severity:string,code:string,message:string}> */
    private function moneyLineProblems(Deal $deal, array $real): array
    {
        $lines = DB::table('deal_money_lines')->where('deal_id', $deal->id)->whereNull('deleted_at')
            ->get(['side', 'side_pool_ex_vat']);

        if ($lines->isEmpty()) {
            $hasAgents = DB::table('deal_user')->where('deal_id', $deal->id)->exists();

            return $hasAgents
                ? [['severity' => self::WARN, 'code' => 'money_lines_missing',
                    'message' => 'The deal has agents but no saved money lines yet, so dashboards do not count it.']]
                : [];
        }

        $problems = [];
        foreach (DealMoney::SIDES as $side) {
            $sideLines = $lines->filter(fn ($l) => strtolower(trim((string) $l->side)) === $side);
            if ($sideLines->isEmpty()) {
                continue;
            }
            $distinct = $sideLines->map(fn ($l) => DealMoney::scaled($l->side_pool_ex_vat, 2))->unique()->values();
            $expected = $real[$side . '_pool'];
            if ($distinct->count() !== 1 || $distinct->first() !== $expected) {
                $problems[] = ['severity' => self::FAIL, 'code' => 'money_lines_differ',
                    'message' => ucfirst($side) . ' side: the saved money lines say R ' . $distinct->map(fn ($c) => DealMoney::cents($c))->implode(' / ')
                        . ' but the deal works out to R ' . DealMoney::cents($expected) . ' — dashboards and payslips would not match the deal screen.'];
            }
        }

        return $problems;
    }

    /** @return array<string,array{0:int,1:int}> key => [real, other] */
    private function differences(array $real, array $other): array
    {
        $diff = [];
        foreach (self::MONEY_KEYS as $k) {
            if ($real[$k] !== $other[$k]) {
                $diff[$k] = [$real[$k], $other[$k]];
            }
        }

        return $diff;
    }

    private function describe(array $diff): string
    {
        $parts = [];
        foreach ($diff as $k => [$right, $wrong]) {
            $isMoney = ! str_contains($k, 'external') || str_contains($k, 'payable');
            $isSplit = str_ends_with($k, '_split');
            $fmt = fn ($v) => $isSplit ? DealMoney::cents($v) . '%' : ($isMoney ? 'R ' . DealMoney::cents($v) : ($v ? 'other agency' : 'ours'));
            $parts[] = str_replace('_', ' ', $k) . ': deal ' . $fmt($right) . ' vs ' . $fmt($wrong);
        }

        return implode('; ', $parts);
    }

    private function finding(string $severity, string $code, int $dealId, ?string $dealNo, ?int $v2Id, string $message, ?int $rightCents = null, ?int $wrongCents = null): array
    {
        return [
            'severity' => $severity, 'code' => $code, 'deal_id' => $dealId, 'deal_no' => $dealNo, 'v2_id' => $v2Id,
            'message' => $message,
            'right' => $rightCents === null ? null : DealMoney::cents($rightCents),
            'wrong' => $wrongCents === null ? null : DealMoney::cents($wrongCents),
        ];
    }
}
