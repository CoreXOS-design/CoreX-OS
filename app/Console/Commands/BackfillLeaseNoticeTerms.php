<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\Rentals\LeaseNoticeTermsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/leases.md §18.5 — gives an existing lease its notice and early-cancellation terms from the AGENCY's defaults
 * (Settings → Leases), by the same rule a new lease uses (LeaseNoticeTermsService::defaultsFor).
 *
 * Soft: it only ever fills a lease that holds NO notice term of its own (a lease with a notice period, an earliest notice date
 * or an early-cancellation answer is never touched); the agreement's own "earliest date the lease may end" is never written or
 * changed. Active and draft leases only (the ones a tenant or owner sees); archived, cancelled and expired leases are left.
 * Writes through the query builder (no model events), tags the terms `agency_default` so the lease screen can say they are the
 * agency's defaults and not read off the signed lease, logs one `lease_notice_terms_backfilled` row per lease, and prints
 * counts. Reversible: `--revert` clears exactly what this command wrote, and only while it is still what is there and nobody
 * has changed the terms since. A deploy never runs it — by hand, `--dry-run` first, `--agency=` / `--lease=` to limit.
 */
class BackfillLeaseNoticeTerms extends Command
{
    protected $signature = 'leases:backfill-notice-terms
        {--dry-run : Report what would be filled, write nothing}
        {--agency= : Only this agency id}
        {--lease= : Only this lease id}
        {--revert : Undo a previous back-fill (clears only what it wrote and nobody has changed since)}';

    protected $description = "Fill existing leases' notice / early-cancellation terms from the agency defaults (dry-run first; reversible; logged)";

    public function handle(LeaseNoticeTermsService $svc): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $query = Lease::withoutGlobalScopes();
        if ($this->option('agency') !== null) {
            $query->where('agency_id', (int) $this->option('agency'));
        }
        if ($this->option('lease') !== null) {
            $query->where('id', (int) $this->option('lease'));
        }

        return $this->option('revert') ? $this->revert($query, $dryRun) : $this->fill($query, $svc, $dryRun);
    }

    private function fill($query, LeaseNoticeTermsService $svc, bool $dryRun): int
    {
        $report = ['scanned' => 0, 'would_fill' => 0, 'already_have_terms' => 0, 'skipped_archived_or_deleted' => 0, 'skipped_other_status' => 0];
        $perAgency = [];
        $samples = [];

        $query->orderBy('id')->chunkById(200, function ($leases) use ($svc, $dryRun, &$report, &$perAgency, &$samples) {
            foreach ($leases as $lease) {
                $report['scanned']++;
                if ($lease->deleted_at !== null) { // archived leases are soft-deleted
                    $report['skipped_archived_or_deleted']++;

                    continue;
                }
                if (! in_array($lease->status, [Lease::STATUS_ACTIVE, Lease::STATUS_DRAFT], true)) {
                    $report['skipped_other_status']++;

                    continue;
                }

                $terms = $svc->termsOf($lease);
                $stored = $svc->stored($terms);
                $hasOwn = false;
                foreach (LeaseNoticeTermsService::KEYS as $key) {
                    if ($stored[$key] !== null) {
                        $hasOwn = true;
                    }
                }
                if ($hasOwn) {
                    $report['already_have_terms']++;

                    continue;
                }

                $values = $svc->defaultsFor((int) $lease->agency_id, $lease->start_date);
                $write = array_filter(array_intersect_key($values, array_flip(LeaseNoticeTermsService::KEYS)), fn ($v) => $v !== null);
                if ($write === []) {
                    continue;
                }

                $report['would_fill']++;
                $perAgency[$lease->agency_id] = ($perAgency[$lease->agency_id] ?? 0) + 1;
                if (count($samples) < 6) {
                    $samples[] = [$lease->id, $lease->agency_id, $lease->status, $this->line_($write)];
                }
                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($lease, $terms, $write) {
                    $now = now();
                    if ($terms) {
                        DB::table('lease_agreement_terms')->where('id', $terms->id)->update($write + ['notice_terms_source' => LeaseNoticeTermsService::SOURCE_AGENCY_DEFAULT]);
                    } else {
                        // One literal row with agency_id stamped in plain sight (the notice values spread last: they
                        // never overlap these keys). The raw-insert guard reads this shape as verified.
                        DB::table('lease_agreement_terms')->insert([
                            'agency_id' => $lease->agency_id, 'lease_id' => $lease->id, 'source' => 'captured',
                            'notice_terms_source' => LeaseNoticeTermsService::SOURCE_AGENCY_DEFAULT, 'created_at' => $now, 'updated_at' => $now,
                            ...$write,
                        ]);
                    }
                    LeaseEvent::create([
                        'lease_id' => $lease->id,
                        'event_type' => LeaseEvent::TYPE_NOTICE_TERMS_BACKFILLED,
                        'description' => 'Notice terms set from the agency defaults by the back-fill: ' . $this->line_($write),
                        'actor_user_id' => null,
                        'metadata' => ['values' => $write, 'created_terms_row' => $terms === null],
                        'occurred_at' => $now,
                        'created_at' => $now,
                    ]);
                });
            }
        });

        $this->line(($dryRun ? 'DRY RUN — nothing written. ' : '') . 'Lease notice terms back-fill');
        $this->table(['', 'leases'], collect($report)->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());
        $this->line('Would fill / filled, by agency:');
        $this->table(['agency', 'leases'], collect($perAgency)->map(fn ($n, $a) => [$a, $n])->values()->all());
        if ($samples !== []) {
            $this->line('Examples:');
            $this->table(['lease', 'agency', 'status', 'values'], $samples);
        }

        return self::SUCCESS;
    }

    private function revert($query, bool $dryRun): int
    {
        $svc = app(LeaseNoticeTermsService::class);
        $reverted = 0;
        $kept = 0;

        $query->orderBy('id')->chunkById(200, function ($leases) use ($svc, $dryRun, &$reverted, &$kept) {
            foreach ($leases as $lease) {
                $latest = LeaseEvent::where('lease_id', $lease->id)
                    ->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_BACKFILLED)
                    ->orderByDesc('id')
                    ->first();
                if (! $latest || ! empty($latest->metadata['reverted'])) {
                    continue;
                }

                $terms = $svc->termsOf($lease);
                $wrote = (array) ($latest->metadata['values'] ?? []);
                $changedSince = LeaseEvent::where('lease_id', $lease->id)
                    ->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CHANGED)
                    ->where('id', '>', $latest->id)
                    ->exists();

                $stillThere = $terms && ! $changedSince && $terms->notice_terms_source === LeaseNoticeTermsService::SOURCE_AGENCY_DEFAULT
                    && $terms->notice_terms_confirmed_at === null; // an agent confirmed them since: they are the lease's now
                if ($stillThere) {
                    $current = $svc->stored($terms);
                    foreach ($wrote as $key => $value) {
                        if (($current[$key] ?? null) != $value) {
                            $stillThere = false;
                        }
                    }
                }
                if (! $stillThere) {
                    $kept++;

                    continue;
                }

                $reverted++;
                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($lease, $terms, $wrote, $latest) {
                    DB::table('lease_agreement_terms')->where('id', $terms->id)
                        ->update(array_fill_keys(array_keys($wrote), null) + ['notice_terms_source' => null]);
                    LeaseEvent::create([
                        'lease_id' => $lease->id,
                        'event_type' => LeaseEvent::TYPE_NOTICE_TERMS_BACKFILLED,
                        'description' => 'Back-filled notice terms removed (reverted)',
                        'actor_user_id' => null,
                        'metadata' => ['reverted' => true, 'reverts_event_id' => $latest->id, 'cleared' => array_keys($wrote)],
                        'occurred_at' => now(),
                        'created_at' => now(),
                    ]);
                });
            }
        });

        $this->line(($dryRun ? 'DRY RUN — nothing written. ' : '') . "Reverted {$reverted} lease(s); {$kept} left as they were because the terms were changed or confirmed after the back-fill.");

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $values */
    private function line_(array $values): string
    {
        return collect($values)->map(fn ($v, $k) => str_replace('_', ' ', $k) . ' ' . $v)->implode(', ');
    }
}
