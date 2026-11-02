<?php

namespace App\Console\Commands;

use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use App\Services\Prospecting\PropertyTypeFromHeading;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * mic-work-tab.md 13.19. Re-derives the property type of every advert whose own heading names a type the stored one contradicts
 * ("2 Bedroom Townhouse" stored as House - the extension's substring bug), then makes each property file's type follow its live lead advert
 * (newest active), not the first-ever capture. DRY RUN by default; --apply writes, and journals every change (old -> new) as JSON lines.
 * Plain DB updates: no model events, so nothing is queued or re-scored by the write itself (the rules version / on-demand rebuild covers the buyers).
 */
class MicFixPropertyTypes extends Command
{
    protected $signature = 'mic:fix-property-types {--agency= : one agency id (default: all)} {--apply : write the changes (default: dry run)} {--journal-dir=/mnt/HC_Volume_103099143/mic-type-journal}';

    protected $description = 'Fix advert property types from their headings, and file types from their live lead advert (dry run unless --apply)';

    public function handle(PropertyTypeResolver $types): int
    {
        $agency = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $apply = (bool) $this->option('apply');
        $scope = $agency ? "agency{$agency}" : 'all-agencies';
        $journal = null;
        if ($apply) {
            $dir = rtrim((string) $this->option('journal-dir'), '/');
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                $this->error("cannot create the journal directory {$dir}");

                return self::FAILURE;
            }
            $path = $dir . "/property-types-{$scope}-" . now()->format('Ymd-His') . '.jsonl';
            $journal = fopen($path, 'ab');
            $this->info("journal: {$path}");
        }
        $note = fn (array $row) => $journal ? fwrite($journal, json_encode($row + ['at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE) . "\n") : null;

        // ---- 1. adverts: the heading names a type the stored one contradicts ----
        $before = DB::table('prospecting_listings')->when($agency, fn ($q) => $q->where('agency_id', $agency))->whereNull('deleted_at')
            ->selectRaw("COALESCE(NULLIF(property_type, ''), '(blank)') as t, COUNT(*) as n")->groupBy('t')->pluck('n', 't')->all();
        $moves = [];
        $delta = [];
        $changed = 0;
        DB::table('prospecting_listings')->when($agency, fn ($q) => $q->where('agency_id', $agency))->whereNull('deleted_at')
            ->whereNotNull('card_heading')->where('card_heading', '<>', '')
            ->orderBy('id')->chunkById(2000, function ($rows) use ($apply, $note, &$moves, &$delta, &$changed) {
                foreach ($rows as $r) {
                    $new = PropertyTypeFromHeading::reconcile($r->property_type, $r->card_heading, (int) $r->agency_id);
                    if ($new === $r->property_type) {
                        continue;
                    }
                    $moves[(string) ($r->property_type ?: '(blank)') . ' -> ' . $new] = ($moves[(string) ($r->property_type ?: '(blank)') . ' -> ' . $new] ?? 0) + 1;
                    $changed++;
                    $o = (string) ($r->property_type ?: '(blank)');
                    $delta[$o] = ($delta[$o] ?? 0) - 1;
                    $delta[$new] = ($delta[$new] ?? 0) + 1;
                    if ($apply) {
                        DB::table('prospecting_listings')->where('id', $r->id)->update(['property_type' => $new]);
                        $note(['table' => 'prospecting_listings', 'id' => (int) $r->id, 'agency_id' => (int) $r->agency_id, 'field' => 'property_type', 'old' => $r->property_type, 'new' => $new, 'heading' => $r->card_heading]);
                    }
                }
            });
        arsort($moves);
        $this->line(($apply ? 'APPLIED' : 'DRY RUN') . " - adverts changed: {$changed}");
        foreach ($moves as $k => $n) {
            $this->line(sprintf('  %6d  %s', $n, $k));
        }

        // ---- 2. files: the file's type follows its live lead advert (newest active, else newest) ----
        $fileMoves = [];
        $files = 0;
        DB::table('tracked_properties')->when($agency, fn ($q) => $q->where('agency_id', $agency))->whereNull('deleted_at')->orderBy('id')
            ->chunkById(2000, function ($tps) use ($types, $apply, $note, &$fileMoves, &$files) {
                $ids = $tps->pluck('id')->all();
                $leads = DB::table('prospecting_listings')->whereIn('tracked_property_id', $ids)->whereNull('deleted_at')
                    ->orderByDesc('is_active')->orderByDesc('last_seen_at')->orderByDesc('id')
                    ->get(['id', 'tracked_property_id', 'property_type', 'agency_id'])->unique('tracked_property_id')->keyBy('tracked_property_id');
                foreach ($tps as $tp) {
                    $lead = $leads->get($tp->id);
                    $type = $lead ? trim((string) $lead->property_type) : '';
                    if ($type === '') {
                        continue;
                    }
                    $same = trim((string) $tp->property_type) !== '' && $types->keyFor($tp->property_type, (int) $tp->agency_id) === $types->keyFor($type, (int) $tp->agency_id)
                        && $types->keyFor($type, (int) $tp->agency_id) !== null;
                    if ($same || $tp->property_type === $type) {
                        continue;
                    }
                    $k = (string) ($tp->property_type ?: '(blank)') . ' -> ' . $type;
                    $fileMoves[$k] = ($fileMoves[$k] ?? 0) + 1;
                    $files++;
                    if ($apply) {
                        DB::table('tracked_properties')->where('id', $tp->id)->update(['property_type' => $type]);
                        $note(['table' => 'tracked_properties', 'id' => (int) $tp->id, 'agency_id' => (int) $tp->agency_id, 'field' => 'property_type', 'old' => $tp->property_type, 'new' => $type, 'lead_advert' => (int) $lead->id]);
                    }
                }
            });
        arsort($fileMoves);
        $this->line(($apply ? 'APPLIED' : 'DRY RUN') . " - property files changed: {$files}");
        foreach (array_slice($fileMoves, 0, 25, true) as $k => $n) {
            $this->line(sprintf('  %6d  %s', $n, $k));
        }

        // ---- before / after per advert type (a dry run shows what --apply would give) ----
        $after = $before;
        if ($apply) {
            $after = DB::table('prospecting_listings')->when($agency, fn ($q) => $q->where('agency_id', $agency))->whereNull('deleted_at')
                ->selectRaw("COALESCE(NULLIF(property_type, ''), '(blank)') as t, COUNT(*) as n")->groupBy('t')->pluck('n', 't')->all();
        } else {
            foreach ($delta as $t => $d) {
                $after[$t] = ($after[$t] ?? 0) + $d;
            }
        }
        $this->line('advert types, before -> after:');
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $t) {
            $this->line(sprintf('  %-16s %7d -> %7d', $t, $before[$t] ?? 0, $after[$t] ?? 0));
        }
        if ($journal) {
            fclose($journal);
        }
        if (! $apply) {
            $this->warn('Dry run - nothing written. Run again with --apply.');
        }

        return self::SUCCESS;
    }
}
