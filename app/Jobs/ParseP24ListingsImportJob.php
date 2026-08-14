<?php

namespace App\Jobs;

use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\User;
use App\Services\Importer\P24ImagesCsvParser;
use App\Services\Importer\P24ListingsCsvParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Parses the listings+images CSVs and creates every P24ImportRow — the work
 * ImporterController::uploadListings() used to do fully synchronously inside
 * the HTTP request. For a large agency (thousands of rows, one DB insert
 * each) that request could run long enough to interact badly with
 * session/CSRF handling under load — observed live 2026-08-14 importing
 * 4,753 listings for Demo Agency Test. Runs on the wide `p24import` lane:
 * it's DB writes, no CDN call, same reasoning as ConfirmP24PropertyRowJob.
 *
 * Spec: .ai/specs/importer-async-parse.md
 */
class ParseP24ListingsImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /**
     * A parse failure (bad CSV, malformed row) is a data problem, not a
     * transient one — retrying would just fail the same way and delay the
     * admin finding out. A crashed WORKER PROCESS (not a caught exception)
     * can still redeliver this job once; the idempotency guard in handle()
     * is what makes that safe, not $tries.
     */
    public int $tries = 1;

    public function __construct(public int $runId)
    {
        $this->onQueue('p24import');
    }

    public function handle(): void
    {
        $run = P24ImportRun::find($this->runId);
        if (!$run || $run->trashed() || $run->status !== 'parsing') {
            return;
        }

        // Idempotency guard: a redelivered job after a worker crash mid-parse
        // would otherwise duplicate whatever rows the dead attempt already
        // created. Soft-delete and start clean — cheap, and this run has not
        // been confirmed against yet (still 'parsing'), so nothing downstream
        // depends on the partial rows.
        P24ImportRow::where('run_id', $run->id)->delete();

        try {
            $listings = (new P24ListingsCsvParser())->parse(Storage::path($run->listings_csv_path));
            $images   = (new P24ImagesCsvParser())->parse(Storage::path($run->images_csv_path));

            $totalImages = array_sum(array_map('count', $images));
            $counts = [
                'listings'      => count($listings),
                'images_total'  => $totalImages,
                'listings_with_images' => count(array_intersect_key($images, array_flip(array_column($listings, 'external_id')))),
            ];

            // Build agent resolver map: p24_agent_id → users.id for this agency
            $agentMap = User::withoutGlobalScopes()
                ->where('agency_id', $run->agency_id)
                ->whereNotNull('p24_agent_id')
                ->pluck('id', 'p24_agent_id')
                ->toArray();

            // Fallback owner for listings whose P24 agent isn't in this agency.
            // Prefer the admin who started the import — a queued job has no
            // request-scoped auth user, so $run->user_id stands in for what
            // was auth()->id() in the synchronous version.
            $fallbackAdmin = User::withoutGlobalScopes()
                ->where('agency_id', $run->agency_id)
                ->where('role', 'admin')
                ->where('is_active', 1)
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$run->user_id])
                ->orderBy('id')
                ->first();
            $fallbackAdminId = $fallbackAdmin?->id;

            $parsed = 0;
            foreach ($listings as $r) {
                $errors = $r['errors'];
                $primary = $r['primary_agent_p24'];
                $resolvedId = $primary ? ($agentMap[$primary] ?? null) : null;
                if (!$resolvedId) {
                    if ($fallbackAdminId) {
                        // Auto-assign to admin so the listing imports. Keep the
                        // "Primary agent not resolved" phrase so the reassign
                        // endpoints can detect and clear this note.
                        $resolvedId = $fallbackAdminId;
                        $errors[] = 'Primary agent not resolved (p24_agent_id=' . ($primary ?? 'null')
                            . ') — auto-assigned to ' . ($fallbackAdmin->name ?? 'admin') . '; reassign if needed.';
                    } else {
                        // No admin to fall back to — keep it a hard error.
                        $errors[] = 'Primary agent not resolved (p24_agent_id=' . ($primary ?? 'null') . ')';
                    }
                }

                $urls = $images[$r['external_id']] ?? [];

                // A row only blocks (status=error) on a fatal parser problem,
                // or an unresolved agent with no admin fallback. An auto-assigned
                // listing is importable, so it stays pending.
                $isError = !empty($r['errors']) || !$resolvedId;

                P24ImportRow::create([
                    'run_id'            => $run->id,
                    'row_type'          => 'listing',
                    'external_id'       => $r['external_id'],
                    'payload_json'      => $r['payload'],
                    'mapped_json'       => $r['mapped'],
                    'resolved_agent_id' => $resolvedId,
                    'image_urls_json'   => $urls,
                    'errors_json'       => $errors ?: null,
                    'action'            => $r['action'],
                    'status'            => $isError ? 'error' : 'pending',
                ]);

                // Incremental progress — lets the portal's polling status
                // endpoint show "N parsed so far" instead of a blank spinner.
                $parsed++;
                if ($parsed % 250 === 0) {
                    $run->update(['counts_json' => array_merge($counts, ['listings_parsed_so_far' => $parsed])]);
                }
            }

            $run->update(['status' => 'pending_confirm', 'counts_json' => $counts]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }
}
