<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Presentation\PresentationFieldsExtracted;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot retroactive Tracked Property population from existing ingest tables:
 *   - presentations (with extracted CMA fields)
 *   - prospecting_listings (P24 + PP from Chrome ext)
 *   - portal_listings (Chrome capture identity)
 *
 * Idempotent. Re-runnable. Safe to scope by agency. Reuses the same
 * TrackedPropertyMatchOrCreateService that handles live ingest, so backfill +
 * future ingestion converge to the same TP universe.
 */
final class BackfillTrackedProperties extends Command
{
    protected $signature = 'tracked:backfill
                            {--agency= : Limit to a specific agency_id}
                            {--source=all : all | presentations | prospecting | portal_captures}
                            {--batch=200 : Rows per batch}
                            {--dry-run : Report counts only, no writes}';

    protected $description = 'Backfill TrackedProperty records from existing presentations + prospecting_listings + portal_listings';

    public function handle(TrackedPropertyMatchOrCreateService $service): int
    {
        $agencyId  = $this->option('agency') ? (int) $this->option('agency') : null;
        $source    = (string) $this->option('source');
        $batchSize = max(50, (int) $this->option('batch'));
        $dryRun    = (bool) $this->option('dry-run');

        $this->info('Tracked Property backfill — '
            . 'agency: ' . ($agencyId ?? 'all')
            . ', source: ' . $source
            . ', batch: ' . $batchSize
            . ($dryRun ? ' [DRY RUN]' : ''));

        $stats = [
            'presentations'   => 0,
            'prospecting'     => 0,
            'portal_captures' => 0,
            'errors'          => 0,
        ];

        if (in_array($source, ['all', 'presentations'], true)) {
            $stats['presentations'] = $this->backfillPresentations($agencyId, $batchSize, $dryRun, $stats);
        }
        if (in_array($source, ['all', 'prospecting'], true)) {
            $stats['prospecting'] = $this->backfillProspectingListings($service, $agencyId, $batchSize, $dryRun, $stats);
        }
        if (in_array($source, ['all', 'portal_captures'], true) && Schema::hasTable('portal_listings')) {
            $stats['portal_captures'] = $this->backfillPortalListings($service, $agencyId, $batchSize, $dryRun, $stats);
        }

        $this->table(
            ['Source', 'Rows processed'],
            [
                ['presentations',   $stats['presentations']],
                ['prospecting',     $stats['prospecting']],
                ['portal_captures', $stats['portal_captures']],
                ['errors (logged)', $stats['errors']],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Re-fire PresentationFieldsExtracted for each presentation with extracted fields.
     * The existing PropagateCmaToProperty listener routes the facts through
     * matchOrCreate AND back-propagates to Property in one event handler.
     */
    private function backfillPresentations(?int $agencyId, int $batch, bool $dryRun, array &$stats): int
    {
        $query = DB::table('presentations')
            ->whereNull('presentations.deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('presentation_fields')
                  ->whereColumn('presentation_fields.presentation_id', 'presentations.id')
                  ->whereNull('presentation_fields.deleted_at');
            });

        if ($agencyId !== null) {
            $query->where(function ($q) use ($agencyId) {
                $q->where('presentations.agency_id', $agencyId)
                  ->orWhereIn('presentations.branch_id', function ($sub) use ($agencyId) {
                      $sub->select('id')->from('branches')->where('agency_id', $agencyId);
                  });
            });
        }

        $count = 0;
        $query->orderBy('id')->chunkById($batch, function ($rows) use (&$count, &$stats, $dryRun) {
            foreach ($rows as $p) {
                try {
                    if (!$dryRun) {
                        $resolvedAgencyId = $p->agency_id ? (int) $p->agency_id : null;
                        if ($resolvedAgencyId === null && !empty($p->branch_id)) {
                            $resolvedAgencyId = (int) DB::table('branches')
                                ->where('id', $p->branch_id)
                                ->value('agency_id');
                        }
                        if (!$resolvedAgencyId) continue;

                        event(new PresentationFieldsExtracted(
                            presentationId: (int) $p->id,
                            agencyId: $resolvedAgencyId,
                            actorUserId: null,
                        ));
                    }
                    $count++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning("tracked:backfill presentation {$p->id} failed: " . $e->getMessage());
                }
            }
        });

        return $count;
    }

    private function backfillProspectingListings(
        TrackedPropertyMatchOrCreateService $service,
        ?int $agencyId,
        int $batch,
        bool $dryRun,
        array &$stats,
    ): int {
        $query = DB::table('prospecting_listings')
            ->whereNull('deleted_at')
            ->whereNull('tracked_property_id');

        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        }

        $count = 0;
        $query->orderBy('id')->chunkById($batch, function ($rows) use (&$count, &$stats, $service, $dryRun) {
            foreach ($rows as $listing) {
                try {
                    if (!$dryRun) {
                        $streetNumber = null;
                        $streetName   = null;
                        $addr = trim((string) ($listing->address ?? ''));
                        if ($addr !== '' && $addr !== 'Address not available'
                            && preg_match('/^(\d+\w*)\s+(.+)$/', $addr, $m)) {
                            $streetNumber = $m[1];
                            $streetName   = $m[2];
                        }

                        $tp = $service->matchOrCreate(
                            agencyId: (int) $listing->agency_id,
                            facts: array_filter([
                                'address'                 => $addr !== '' && $addr !== 'Address not available' ? $addr : null,
                                'street_number'           => $streetNumber,
                                'street_name'             => $streetName,
                                'suburb'                  => $listing->suburb !== '' ? $listing->suburb : null,
                                'property_type'           => $listing->property_type,
                                'bedrooms'                => $listing->bedrooms,
                                'bathrooms'               => $listing->bathrooms,
                                'garages'                 => $listing->garages,
                                'floor_size_m2'           => $listing->property_size_m2,
                                'erf_size_m2'             => $listing->erf_size_m2,
                                'last_known_asking_price' => $listing->price,
                            ], fn ($v) => $v !== null && $v !== ''),
                            source: [
                                'type'    => (string) ($listing->portal_source ?: 'unknown'),
                                'ref'     => (string) ($listing->portal_ref ?: "prospecting_{$listing->id}"),
                                'payload' => ['prospecting_listing_id' => $listing->id],
                            ],
                        );

                        if ($tp) {
                            DB::table('prospecting_listings')
                                ->where('id', $listing->id)
                                ->update(['tracked_property_id' => $tp->id]);
                        }
                    }
                    $count++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning("tracked:backfill prospecting listing {$listing->id} failed: " . $e->getMessage());
                }
            }
        });

        return $count;
    }

    /**
     * portal_listings has no agency_id column — resolve via the last_capture_id's user.
     * Rows whose capturing user has no agency_id are skipped (super-admin captures
     * without an active agency switcher).
     */
    private function backfillPortalListings(
        TrackedPropertyMatchOrCreateService $service,
        ?int $agencyId,
        int $batch,
        bool $dryRun,
        array &$stats,
    ): int {
        $query = DB::table('portal_listings as pl')
            ->leftJoin('portal_captures as pc', 'pc.id', '=', 'pl.last_capture_id')
            ->leftJoin('users as u', 'u.id', '=', 'pc.user_id')
            ->whereNull('pl.deleted_at')
            ->whereNull('pl.tracked_property_id');

        if ($agencyId !== null) {
            $query->where('u.agency_id', $agencyId);
        } else {
            $query->whereNotNull('u.agency_id');
        }

        // Always re-query from the start of the filter — as rows get linked to TPs,
        // they drop out of the WHERE tracked_property_id IS NULL clause naturally.
        // An offset-based pager would double-skip here (each batch's writes shift
        // the offset, so subsequent batches would miss the remaining rows). Looping
        // on a fresh filtered query exhausts safely.
        $count = 0;
        $loopGuard = 0;
        do {
            $batchRows = (clone $query)
                ->select(
                    'pl.id', 'pl.source_site', 'pl.portal_listing_id',
                    'pl.current_fields_json', 'pl.last_capture_id',
                    'u.agency_id', 'u.id as captured_by_user_id'
                )
                ->orderBy('pl.id')
                ->limit($batch)
                ->get();

            if ($batchRows->isEmpty()) break;

            // Safety: if a batch returns rows but none get linked (e.g. all errored),
            // we'd loop forever. Cap the loop count at total/batch + small buffer.
            $loopGuard++;
            if ($loopGuard > 10000) {
                Log::warning('tracked:backfill portal_listings loop guard tripped at iteration ' . $loopGuard);
                break;
            }

            foreach ($batchRows as $pl) {
                try {
                    if (!$dryRun) {
                        if (!$pl->agency_id) continue;

                        $fields = $pl->current_fields_json;
                        if (is_string($fields)) $fields = json_decode($fields, true) ?: [];
                        if (!is_array($fields)) $fields = [];

                        $addr = isset($fields['address']) ? trim((string) $fields['address']) : null;
                        $streetNumber = null;
                        $streetName   = null;
                        if ($addr && preg_match('/^(\d+\w*)\s+(.+)$/', $addr, $m)) {
                            $streetNumber = $m[1];
                            $streetName   = $m[2];
                        }

                        $tp = $service->matchOrCreate(
                            agencyId: (int) $pl->agency_id,
                            facts: array_filter([
                                'address'                 => $addr,
                                'street_number'           => $streetNumber,
                                'street_name'             => $streetName,
                                'suburb'                  => $fields['suburb'] ?? null,
                                'property_type'           => $fields['property_type'] ?? null,
                                'bedrooms'                => $fields['beds'] ?? $fields['bedrooms'] ?? null,
                                'bathrooms'               => $fields['baths'] ?? $fields['bathrooms'] ?? null,
                                'floor_size_m2'           => $fields['size_m2'] ?? $fields['floor_m2'] ?? null,
                                'erf_size_m2'             => $fields['erf_m2'] ?? null,
                                'last_known_asking_price' => $fields['price'] ?? null,
                            ], fn ($v) => $v !== null && $v !== ''),
                            source: [
                                'type'    => 'chrome_capture',
                                'ref'     => (string) ($pl->portal_listing_id ?: "portal_listing_{$pl->id}"),
                                'payload' => [
                                    'portal_listing_row_id' => $pl->id,
                                    'source_site'           => $pl->source_site,
                                    'last_capture_id'       => $pl->last_capture_id,
                                ],
                            ],
                            actorUserId: $pl->captured_by_user_id ?: null,
                        );

                        if ($tp) {
                            DB::table('portal_listings')
                                ->where('id', $pl->id)
                                ->update(['tracked_property_id' => $tp->id]);
                        }
                    }
                    $count++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning("tracked:backfill portal_listing {$pl->id} failed: " . $e->getMessage());
                }
            }

            // If this batch didn't shrink the candidate pool at all (e.g. every row
            // errored without being linked), break to avoid an infinite loop.
            // Otherwise the next iteration's filter excludes the newly-linked rows.
            if ($dryRun) break; // dry-run doesn't mutate state, so a second loop iteration would repeat
        } while ($batchRows->count() === $batch);

        return $count;
    }
}
