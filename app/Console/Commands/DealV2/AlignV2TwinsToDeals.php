<?php

namespace App\Console\Commands\DealV2;

use App\Models\Deal;
use App\Models\DealLog;
use App\Models\DealV2\DealActivityLog;
use App\Services\Finance\DealMoney;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the stale saved copy on a linked deals_v2 row back in line with the real deal — the
 * "deal of record" — for the money columns and the price. (2026-10-08, one source for a deal's
 * money; Johan: "yes correct them".)
 *
 * What it can change, on a v2 row that is linked to a deal, and ONLY there:
 *   listing_/selling_  split_percent, external, our_share_percent
 *   purchase_price (the deal's sale price, else its property value)
 *   commission_amount + commission_vat (only if the commission total differs)
 * Nothing on the real deal is ever written. Nothing else on the v2 row is touched (no status, no
 * dates). No hard deletes. The write is a plain column update — no model events — so nothing is
 * mirrored back and the "both sides external" save guard is not tripped.
 *
 * Repeatable and idempotent: it only touches a row that differs, so a second run finds nothing.
 * Each changed value is written inside one transaction per v2 row together with its audit trail
 * (a deal_logs row on the deal and a deal_activity_log row on the v2 row: old value, new value,
 * reason, who).
 *
 *   php artisan deals:align-v2-twins --dry-run                 # print rows + before/after, change nothing
 *   php artisan deals:align-v2-twins --confirm --run-by="Name" # apply
 * Without --dry-run it REFUSES to run unless --confirm is given (and --run-by, recorded in the audit).
 */
class AlignV2TwinsToDeals extends Command
{
    public const REASON = 'aligned to the deal of record - DR2 single source, approved by Johan 8 Oct 2026';

    protected $signature = 'deals:align-v2-twins
        {--dry-run : Print the rows and before/after values; change nothing}
        {--confirm : Required to apply the corrections (no effect with --dry-run)}
        {--run-by= : Who is running it — recorded in every audit entry (required with --confirm)}
        {--agency= : Limit to one agency id}';

    protected $description = 'Align stale money columns / price on linked deals_v2 rows to the real deal (dry-run first; --confirm to apply).';

    /** v2 column => how to read the deal's value. 'pct' = percent text, 'bool' = 0/1, 'int' = whole rand. */
    private const FIELDS = [
        'listing_split_percent' => 'pct', 'listing_external' => 'bool', 'listing_our_share_percent' => 'pct',
        'selling_split_percent' => 'pct', 'selling_external' => 'bool', 'selling_our_share_percent' => 'pct',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $confirm = (bool) $this->option('confirm');
        $runBy = trim((string) $this->option('run-by'));

        if (! $dry && ! $confirm) {
            $this->error('Refusing to run: this changes saved data. Use --dry-run to see exactly what it would change, or --confirm --run-by="<your name>" to apply it.');

            return self::INVALID;
        }
        if (! $dry && $runBy === '') {
            $this->error('Refusing to run: --run-by="<your name>" is required with --confirm (it is written into every audit entry).');

            return self::INVALID;
        }

        $this->line(($dry ? 'DRY RUN — nothing will be changed.' : '*** APPLYING — corrections and audit entries WILL be written. ***') . '  [' . config('app.url') . ']');

        $plan = $this->plan($this->option('agency') ? (int) $this->option('agency') : null);

        if (! $plan['rows']) {
            $this->info('Nothing to align: every linked v2 row already matches its deal.');
            $this->skipped($plan['skipped']);

            return self::SUCCESS;
        }

        foreach ($plan['rows'] as $r) {
            $this->line("deal {$r['deal_no']} (id {$r['deal_id']}) / v2 row {$r['v2_id']}:");
            foreach ($r['changes'] as $c) {
                $this->line(sprintf('    %-28s %s  →  %s', $c['field'], $c['old'], $c['new']));
            }
        }
        $fieldCount = array_sum(array_map(fn ($r) => count($r['changes']), $plan['rows']));
        $this->line(count($plan['rows']) . " v2 row(s), {$fieldCount} value(s) to align.");
        $this->skipped($plan['skipped']);

        if ($dry) {
            $this->info('Dry run only — nothing written.');

            return self::SUCCESS;
        }

        foreach ($plan['rows'] as $r) {
            DB::transaction(fn () => $this->apply($r, $runBy));
        }
        $this->info('Applied: ' . count($plan['rows']) . " v2 row(s), {$fieldCount} value(s), each with an audit entry.");

        return self::SUCCESS;
    }

    /** @return array{rows:array<int,array<string,mixed>>,skipped:array<int,string>} */
    private function plan(?int $agencyId): array
    {
        $rows = [];
        $skipped = [];

        Deal::withoutGlobalScopes()->where('deal_v2_id', '>', 0)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->orderBy('id')
            ->chunkById(100, function ($deals) use (&$rows, &$skipped) {
                foreach ($deals as $deal) {
                    $twin = DB::table('deals_v2')->where('id', $deal->deal_v2_id)->first();
                    if (! $twin || (int) $twin->legacy_deal_id !== (int) $deal->id || (int) $twin->agency_id !== (int) $deal->agency_id) {
                        $skipped[] = "deal {$deal->deal_no} (id {$deal->id}): the link to v2 row {$deal->deal_v2_id} is broken or crosses agencies — not touched (deals:parity-check reports it).";
                        continue;
                    }
                    $changes = $this->changes($deal, $twin);
                    if ($changes) {
                        $rows[] = ['deal_id' => (int) $deal->id, 'deal_no' => (string) ($deal->deal_no ?? $deal->id), 'agency_id' => (int) $deal->agency_id, 'v2_id' => (int) $twin->id, 'changes' => $changes];
                    }
                }
            });

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** @return array<int,array{field:string,old:string,new:string,db:mixed}> */
    private function changes(Deal $deal, object $twin): array
    {
        $a = $deal->getAttributes();
        $out = [];

        foreach (self::FIELDS as $col => $kind) {
            $want = $a[$col] ?? null;
            if ($kind === 'bool') {
                $new = ((int) (bool) $want);
                $old = (int) (bool) $twin->$col;
                if ($new !== $old) {
                    $out[] = ['field' => $col, 'old' => (string) $old, 'new' => (string) $new, 'db' => $new];
                }
            } else {
                // A deal value that was never set leaves the v2 column alone — the deal states nothing to align to.
                if ($want === null || $want === '') {
                    continue;
                }
                $new = DealMoney::scaled($want, 2);
                $old = DealMoney::scaled($twin->$col, 2);
                if ($new !== $old) {
                    $out[] = ['field' => $col, 'old' => DealMoney::cents($old), 'new' => DealMoney::cents($new), 'db' => DealMoney::cents($new)];
                }
            }
        }

        // Price: the deal's sale price, else its property value (whole rand) — the same rule DealSyncService uses.
        $price = DealMoney::scaled($a['sale_price'] ?? null, 0);
        if ($price <= 0) {
            $price = DealMoney::scaled($a['property_value'] ?? null, 0);
        }
        if ($price > 0 && (int) DealMoney::scaled($twin->purchase_price, 0) !== $price) {
            $out[] = ['field' => 'purchase_price', 'old' => (string) DealMoney::scaled($twin->purchase_price, 0), 'new' => (string) $price, 'db' => $price];
        }

        // Commission total (incl VAT) — only if it differs; split the same way the mirror does (15%-style via the agency rate).
        $money = DealMoney::fromDeal($deal);
        $twinInc = DealMoney::scaled($twin->commission_amount, 2) + DealMoney::scaled($twin->commission_vat, 2);
        if ($money->incVatCents !== $twinInc) {
            $out[] = ['field' => 'commission_amount', 'old' => DealMoney::cents(DealMoney::scaled($twin->commission_amount, 2)), 'new' => DealMoney::cents($money->exVatCents()), 'db' => DealMoney::cents($money->exVatCents())];
            $out[] = ['field' => 'commission_vat', 'old' => DealMoney::cents(DealMoney::scaled($twin->commission_vat, 2)), 'new' => DealMoney::cents($money->vatCents()), 'db' => DealMoney::cents($money->vatCents())];
        }

        return $out;
    }

    private function apply(array $row, string $runBy): void
    {
        $set = [];
        foreach ($row['changes'] as $c) {
            $set[$c['field']] = $c['db'];
        }
        $set['updated_at'] = now();
        // Raw column update: no model events (nothing mirrors back to the deal), and the "both sides
        // external" save guard on the model is not involved.
        DB::table('deals_v2')->where('id', $row['v2_id'])->update($set);

        foreach ($row['changes'] as $c) {
            $msg = "v2 row {$row['v2_id']} {$c['field']}: {$c['old']} → {$c['new']}. Reason: " . self::REASON . ". Run by: {$runBy} (deals:align-v2-twins on " . config('app.url') . ').';

            DealLog::create([
                'agency_id' => $row['agency_id'],
                'deal_id' => $row['deal_id'],
                'event_type' => 'dr2_single_source_alignment',
                'from_value' => $c['old'],
                'to_value' => $c['new'],
                'message' => $msg,
            ]);

            DealActivityLog::create([
                'agency_id' => $row['agency_id'],
                'deal_id' => $row['v2_id'],
                'action' => 'dr2_single_source_alignment',
                'description' => $msg,
                'metadata' => ['field' => $c['field'], 'from' => $c['old'], 'to' => $c['new'], 'reason' => self::REASON, 'run_by' => $runBy, 'deal_id' => $row['deal_id']],
                'created_at' => now(),
            ]);
        }
    }

    private function skipped(array $skipped): void
    {
        foreach ($skipped as $s) {
            $this->warn($s);
        }
    }
}
