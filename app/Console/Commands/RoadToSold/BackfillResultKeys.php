<?php

namespace App\Console\Commands\RoadToSold;

use App\Services\RoadToSold\ResultOptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4 (road-to-sold.md 23.10): give every existing presentation outcome the shared result behind its old fixed value, by the fixed map
 * (won_mandate / won_sale -> won_mandate, lost_to_competitor -> lost_other_agency, lost_to_price_dispute -> lost_price, lost_to_no_decision / no_response ->
 * lost_not_ready, still_pending -> undecided, other -> not_selling). Only rows with no result_key are touched; the old outcome value is never changed.
 *
 *   php artisan rts:backfill-result-keys [--agency=1]            dry run: counts by value
 *   php artisan rts:backfill-result-keys --agency=1 --apply      do it, with a row journal in /mnt/HC_Volume_103099143/mic-unwind/
 *   php artisan rts:backfill-result-keys --undo=<journal.jsonl>  clear the keys the journal wrote
 */
class BackfillResultKeys extends Command
{
    protected $signature = 'rts:backfill-result-keys {--agency= : Only this agency id} {--apply} {--undo=} {--journal-dir=/mnt/HC_Volume_103099143/mic-unwind}';

    protected $description = 'Road to Sold v4: set the shared result on existing presentation outcomes (dry run first; journalled; reversible)';

    public function handle(): int
    {
        if (! Schema::hasColumn('presentation_outcomes', 'result_key')) {
            $this->error('presentation_outcomes.result_key is not there yet (migration not run).');

            return self::FAILURE;
        }
        if ($this->option('undo')) {
            return $this->undo((string) $this->option('undo'));
        }
        $apply = (bool) $this->option('apply');
        $rows = DB::table('presentation_outcomes')->whereNull('result_key')->whereNull('deleted_at')
            ->when($this->option('agency'), fn ($q, $a) => $q->where('agency_id', (int) $a))->get(['id', 'agency_id', 'outcome']);
        $fh = null;
        $path = null;
        if ($apply) {
            @mkdir((string) $this->option('journal-dir'), 0775, true);
            $path = rtrim((string) $this->option('journal-dir'), '/') . '/rts-backfill-result-keys-' . now()->format('Ymd-His') . '.jsonl';
            $fh = fopen($path, 'ab');
        }
        $by = [];
        $n = 0;
        foreach ($rows as $r) {
            $key = ResultOptionService::keyForEnum($r->outcome);
            if ($key === null) {
                continue;
            }
            $by[$r->outcome . ' -> ' . $key] = ($by[$r->outcome . ' -> ' . $key] ?? 0) + 1;
            $n++;
            if ($apply) {
                DB::table('presentation_outcomes')->where('id', $r->id)->update(['result_key' => $key]);
                fwrite($fh, json_encode(['id' => (int) $r->id, 'agency_id' => (int) $r->agency_id, 'result_key' => $key]) . "\n");
            }
        }
        if ($fh) {
            fclose($fh);
        }
        $this->info(($apply ? '[APPLIED] ' : '[DRY RUN] ') . "$n presentation outcome(s) " . ($apply ? 'given' : 'would be given') . ' a result: ' . json_encode($by));
        $this->line($apply ? "  journal: $path   undo: php artisan rts:backfill-result-keys --undo=$path" : '  (nothing written - add --apply)');

        return self::SUCCESS;
    }

    private function undo(string $file): int
    {
        if (! is_file($file)) {
            $this->error("Journal not found: $file");

            return self::FAILURE;
        }
        $n = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $j = json_decode($line, true);
            if (is_array($j) && ! empty($j['id'])) {
                DB::table('presentation_outcomes')->where('id', $j['id'])->where('result_key', $j['result_key'])->update(['result_key' => null]);
                $n++;
            }
        }
        $this->info("Undone: $n row(s) cleared.");

        return self::SUCCESS;
    }
}
