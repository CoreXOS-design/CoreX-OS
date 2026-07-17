<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Jobs\ConfirmP24PropertyRowJob;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\P24OnboardingPortal;
use App\Models\P24PortalEvent;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class OnboardingPortalController extends Controller
{
    private function portal(Request $request): P24OnboardingPortal
    {
        $portal = $request->attributes->get('onboarding_portal');
        abort_unless($portal instanceof P24OnboardingPortal, 404);
        return $portal;
    }

    private function guardActive(P24OnboardingPortal $portal): void
    {
        abort_unless($portal->isActive(), 410, 'This onboarding link is no longer active.');
    }

    private function actorLabel(Request $request): string
    {
        return 'Portal visitor · ' . P24PortalEvent::maskIp($request->ip());
    }

    private function logEvent(P24OnboardingPortal $portal, Request $request, string $event, array $extra = []): void
    {
        P24PortalEvent::log(array_merge([
            'portal_id'          => $portal->id,
            'agency_id'          => $portal->agency_id,
            'actor_type'         => 'portal_visitor',
            'actor_label'        => $this->actorLabel($request),
            'event'              => $event,
            'ip'                 => $request->ip(),
            'user_agent'         => substr((string) $request->userAgent(), 0, 500),
        ], $extra));
    }

    public function welcome(Request $request)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $portal->increment('open_count');
        $portal->update(['last_opened_at' => now()]);
        $this->logEvent($portal, $request, 'portal.opened');

        $agency = $portal->agency;
        $counts = $this->counts($portal);

        return view('onboarding.portal.welcome', compact('portal', 'agency', 'counts'));
    }

    public function review(Request $request)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $status = $request->get('status', 'pending');
        $type   = $request->get('listing_type', 'all');
        $search = trim((string) $request->get('search', ''));
        $sort   = (string) $request->get('sort', 'id_desc');

        $q = $portal->rowsQuery()->with('resolvedAgent');

        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($type !== 'all') {
            $q->whereRaw("JSON_EXTRACT(mapped_json, '$.listing_type') = ?", [$type]);
        }
        if ($search !== '') {
            $s = '%' . $search . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('external_id', 'like', $s)
                   ->orWhereRaw("JSON_EXTRACT(mapped_json, '$.address') LIKE ?", [$s])
                   ->orWhereRaw("JSON_EXTRACT(mapped_json, '$.headline') LIKE ?", [$s]);
            });
        }

        match ($sort) {
            'status_asc'  => $q->orderByRaw("JSON_EXTRACT(mapped_json, '$.status') ASC")->orderByDesc('id'),
            'status_desc' => $q->orderByRaw("JSON_EXTRACT(mapped_json, '$.status') DESC")->orderByDesc('id'),
            default       => $q->orderByDesc('id'),
        };

        $perPage = (int) $request->get('per_page', 30);
        if (!in_array($perPage, [15, 30, 50, 100, 200], true)) {
            $perPage = 30;
        }

        $rows = $q->paginate($perPage)->withQueryString();
        $agency = $portal->agency;
        $counts = $this->counts($portal);
        $agents = User::withoutGlobalScopes()
            ->where('agency_id', $portal->agency_id)
            ->whereNotNull('p24_agent_id')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('onboarding.portal.review', compact('portal', 'agency', 'rows', 'counts', 'agents', 'status', 'type', 'search', 'sort', 'perPage'));
    }

    public function status(Request $request)
    {
        $portal = $this->portal($request);

        // Property-side progress comes from the Bus batch when the browser hands
        // us its id; it is the clean denominator for "how many of THIS import's
        // properties are written". Gallery progress is derived from the property
        // columns, so it survives a page reload that loses the batch id.
        $batchId = $request->query('batch_id');
        $batch = $batchId ? Bus::findBatch($batchId) : null;

        return response()->json([
            'counts'    => $this->counts($portal),
            'is_active' => $portal->isActive(),
            'batch'     => $batch ? [
                'id'        => $batch->id,
                'total'     => $batch->totalJobs,
                'processed' => $batch->processedJobs(),
                'failed'    => $batch->failedJobs,
                'progress'  => $batch->progress(),
                'finished'  => $batch->finished(),
            ] : null,
            'galleries' => $this->galleryProgress($portal),
        ]);
    }

    /**
     * Gallery-completeness rollup for every property this portal has confirmed.
     * Drives the second progress bar and is the live answer to the acceptance
     * bar "are any galleries permanently short" — `incomplete` + `failed` should
     * settle to 0 once the image lane drains.
     */
    private function galleryProgress(P24OnboardingPortal $portal): array
    {
        $targetIds = (clone $portal->rowsQuery())
            ->where('status', 'confirmed')
            ->whereNotNull('target_id')
            ->pluck('target_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        if (empty($targetIds)) {
            return [
                'total' => 0, 'complete' => 0, 'incomplete' => 0, 'pending' => 0,
                'failed' => 0, 'images_expected' => 0, 'images_stored' => 0,
            ];
        }

        $agg = Property::withoutGlobalScopes()
            ->whereIn('id', $targetIds)
            ->selectRaw("
                COUNT(*)                                              as total,
                SUM(gallery_import_status = 'complete')              as complete,
                SUM(gallery_import_status = 'incomplete')            as incomplete,
                SUM(gallery_import_status = 'pending')               as pending,
                SUM(gallery_import_status = 'failed')                as failed,
                COALESCE(SUM(gallery_expected_count), 0)             as images_expected,
                COALESCE(SUM(gallery_stored_count), 0)               as images_stored
            ")
            ->first();

        return [
            'total'           => (int) $agg->total,
            'complete'        => (int) $agg->complete,
            'incomplete'      => (int) $agg->incomplete,
            'pending'         => (int) $agg->pending,
            'failed'          => (int) $agg->failed,
            'images_expected' => (int) $agg->images_expected,
            'images_stored'   => (int) $agg->images_stored,
        ];
    }

    public function confirmRow(Request $request, $token, $rowId)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $row = $this->findOwnedRow($portal, (int) $rowId);
        abort_unless(in_array($row->status, ['pending', 'error'], true), 422, 'Row is not confirmable.');

        $row->update([
            'processing_at'          => now(),
            'status'                 => 'pending',
            'confirmed_via'          => 'portal',
            'confirmed_by_portal_id' => $portal->id,
        ]);

        ConfirmP24PropertyRowJob::dispatchSync($row->id, null);
        $row->refresh();

        $this->logEvent($portal, $request, 'portal.row.confirmed', [
            'target_row_id'      => $row->id,
            'target_external_id' => $row->external_id,
        ]);

        return response()->json([
            'ok'     => $row->status === 'confirmed',
            'row_id' => $row->id,
            'status' => $row->status,
            'errors' => (array) ($row->errors_json ?? []),
            'counts' => $this->counts($portal),
        ]);
    }

    public function excludeRow(Request $request, $token, $rowId)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $row = $this->findOwnedRow($portal, (int) $rowId);
        $row->update([
            'status'                 => 'excluded',
            'excluded_at'            => now(),
            'confirmed_via'          => 'portal',
            'confirmed_by_portal_id' => $portal->id,
        ]);

        $this->logEvent($portal, $request, 'portal.row.excluded', [
            'target_row_id'      => $row->id,
            'target_external_id' => $row->external_id,
        ]);

        return response()->json(['ok' => true]);
    }

    public function reassignAgent(Request $request, $token, $rowId)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $agent = User::withoutGlobalScopes()->where('id', $data['user_id'])
            ->where('agency_id', $portal->agency_id)
            ->whereNotNull('p24_agent_id')
            ->first();
        abort_unless($agent, 422, 'Agent is not valid for this agency.');

        $row = $this->findOwnedRow($portal, (int) $rowId);
        $prev = $row->resolved_agent_id;
        $row->update([
            'resolved_agent_id' => $agent->id,
            'errors_json'       => collect($row->errors_json ?? [])
                ->reject(fn($e) => str_contains($e, 'Primary agent not resolved'))
                ->values()->all() ?: null,
            'status'            => $row->status === 'error' ? 'pending' : $row->status,
        ]);

        $this->logEvent($portal, $request, 'portal.row.agent_reassigned', [
            'target_row_id'      => $row->id,
            'target_external_id' => $row->external_id,
            'meta_json'          => ['from' => $prev, 'to' => $agent->id, 'to_name' => $agent->name],
        ]);

        return response()->json(['ok' => true, 'agent_name' => $agent->name]);
    }

    public function bulkConfirm(Request $request)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $ids = (array) $request->input('ids', []);
        $rows = $portal->rowsQuery()
            ->whereIn('id', $ids)
            ->whereIn('status', ['pending', 'error'])
            ->get();

        $batch = $this->dispatchConfirmBatch($portal, $rows);

        $this->logEvent($portal, $request, 'portal.bulk.confirmed', [
            'meta_json' => ['count' => $rows->count(), 'scope' => 'selected', 'batch_id' => $batch?->id],
        ]);

        return response()->json(['ok' => true, 'count' => $rows->count(), 'batch_id' => $batch?->id]);
    }

    public function bulkExclude(Request $request)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $ids = (array) $request->input('ids', []);
        $affected = $portal->rowsQuery()
            ->whereIn('id', $ids)
            ->whereIn('status', ['pending', 'error', 'confirmed'])
            ->update([
                'status'                 => 'excluded',
                'excluded_at'            => now(),
                'confirmed_via'          => 'portal',
                'confirmed_by_portal_id' => $portal->id,
            ]);

        $this->logEvent($portal, $request, 'portal.bulk.excluded', [
            'meta_json' => ['count' => $affected],
        ]);

        return response()->json(['ok' => true, 'count' => $affected]);
    }

    public function confirmAllFiltered(Request $request)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $rows = $portal->rowsQuery()
            ->whereIn('status', ['pending', 'error'])
            ->whereNull('processing_at')
            ->get();

        $batch = $this->dispatchConfirmBatch($portal, $rows);

        $this->logEvent($portal, $request, 'portal.bulk.confirmed', [
            'meta_json' => ['count' => $rows->count(), 'scope' => 'all_pending', 'batch_id' => $batch?->id],
        ]);

        return response()->json(['ok' => true, 'count' => $rows->count(), 'batch_id' => $batch?->id]);
    }

    /**
     * The server-side "8 tabs" — confirm every given row from ONE click.
     *
     * Marks the rows processing up front (so a double-click cannot enqueue them
     * twice), then fans the confirm jobs out as a Bus batch on the wide
     * p24import lane. The property writes race across all workers; images stream
     * in behind on the narrow p24images lane. Returns the batch so the caller
     * can hand its id to the browser for progress polling.
     *
     * Returns null when there is nothing to confirm — an empty batch is not an
     * error, just a no-op the UI reports plainly.
     */
    private function dispatchConfirmBatch(P24OnboardingPortal $portal, $rows): ?\Illuminate\Bus\Batch
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $ids = $rows->pluck('id');
        P24ImportRow::whereIn('id', $ids)->update([
            'processing_at'          => now(),
            'confirmed_via'          => 'portal',
            'confirmed_by_portal_id' => $portal->id,
        ]);

        // The runs these rows belong to — marked completed once the batch drains
        // so the importer UI stops showing them as pending_confirm forever.
        $runIds = $rows->pluck('run_id')->unique()->values()->all();

        try {
            return Bus::batch(
                $rows->map(fn ($row) => new ConfirmP24PropertyRowJob($row->id, null))->all()
            )
                ->name("p24-import-portal-{$portal->id}")
                ->onQueue('p24import')
                // One rate-limited gallery must not fail the whole import — each row
                // heals independently on its own retries.
                ->allowFailures()
                ->finally(function () use ($runIds) {
                    // Property writes are done (galleries stream on their own lane).
                    // Mark each run completed only if nothing is still pending/processing.
                    foreach ($runIds as $runId) {
                        $outstanding = P24ImportRow::where('run_id', $runId)
                            ->where('row_type', 'listing')
                            ->where(function ($q) {
                                $q->where('status', 'pending')->orWhereNotNull('processing_at');
                            })->exists();
                        if (!$outstanding) {
                            P24ImportRun::where('id', $runId)
                                ->whereNotIn('status', ['cancelled', 'failed'])
                                ->update(['status' => 'completed', 'confirmed_at' => now(), 'completed_at' => now()]);
                        }
                    }
                })
                ->dispatch();
        } catch (\Throwable $e) {
            // The rows were stamped `processing_at` up front, but the batch never
            // dispatched (e.g. a serialization/config error). Un-stamp them so
            // they don't sit orphaned as "processing" forever — `confirmAllFiltered`
            // filters on whereNull('processing_at'), so a stale stamp would make a
            // row permanently un-re-importable. Re-throw so the caller surfaces the
            // failure instead of a silent no-op. (This is the exact trap that left
            // 506 rows stuck when the Batchable bug threw here — AT/2026-07-17.)
            P24ImportRow::whereIn('id', $ids)->update(['processing_at' => null]);
            throw $e;
        }
    }

    public function finish(Request $request)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $portal->update(['completed_at' => now()]);
        $this->logEvent($portal, $request, 'portal.finished');

        // Review is done — close out every run behind this portal that has no
        // outstanding rows, so a run never lingers in 'pending_confirm' after the
        // agency has finished (galleries keep streaming on their own lane; that's
        // not "outstanding" for the run's purposes). Belt-and-suspenders with the
        // Import-All batch's finally callback for paths that don't go through it
        // (row-by-row confirm, exclude-the-rest).
        $runIds = (clone $portal->rowsQuery())->distinct()->pluck('run_id')->filter()->all();
        foreach ($runIds as $runId) {
            $outstanding = P24ImportRow::where('run_id', $runId)
                ->where('row_type', 'listing')
                ->where(function ($q) {
                    $q->where('status', 'pending')->orWhereNotNull('processing_at');
                })->exists();
            if (!$outstanding) {
                P24ImportRun::where('id', $runId)
                    ->whereNotIn('status', ['cancelled', 'failed'])
                    ->update(['status' => 'completed', 'confirmed_at' => now(), 'completed_at' => now()]);
            }
        }

        $agency = $portal->agency;
        $counts = $this->counts($portal);
        return view('onboarding.portal.finish', compact('portal', 'agency', 'counts'));
    }

    private function findOwnedRow(P24OnboardingPortal $portal, int $rowId): P24ImportRow
    {
        $row = $portal->rowsQuery()->where('p24_import_rows.id', $rowId)->first();
        if (!$row) {
            // Deep diagnostics — same model, raw PDO, raw SQL, both connections.
            $rawRow = P24ImportRow::withTrashed()->find($rowId);
            $runFromRow = $rawRow?->run_id ? \App\Models\P24ImportRun::withTrashed()->find($rawRow->run_id) : null;

            $connName   = \Illuminate\Support\Facades\DB::connection()->getName();
            $dbName     = \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
            $driver     = \Illuminate\Support\Facades\DB::connection()->getDriverName();

            $raw = \Illuminate\Support\Facades\DB::selectOne('SELECT id, external_id, status, run_id, deleted_at FROM p24_import_rows WHERE id = ?', [$rowId]);
            $tableCount  = \Illuminate\Support\Facades\DB::selectOne('SELECT COUNT(*) AS c FROM p24_import_rows')->c;
            $agencyCount = \Illuminate\Support\Facades\DB::selectOne(
                'SELECT COUNT(*) AS c FROM p24_import_rows WHERE row_type = ? AND run_id IN (SELECT id FROM p24_import_runs WHERE agency_id = ?)',
                ['listing', $portal->agency_id]
            )->c;

            $runScoped = null;
            if (!empty($portal->run_ids_json)) {
                $in = implode(',', array_map('intval', $portal->run_ids_json));
                $runScoped = \Illuminate\Support\Facades\DB::selectOne("SELECT COUNT(*) AS c FROM p24_import_rows WHERE run_id IN ({$in})")->c;
            }

            // What does the portal's query think?
            $portalSqlCount = $portal->rowsQuery()->count();

            // Compiled SQL for the failing query
            $q = $portal->rowsQuery()->where('p24_import_rows.id', $rowId);
            $sql = $q->toSql();
            $bindings = $q->getBindings();

            $diag = [
                'rowId_type'       => gettype($rowId),
                'rowId_value'      => $rowId,
                'connection'       => $connName,
                'database'         => $dbName,
                'driver'           => $driver,
                'model_find'       => $rawRow ? ['id' => $rawRow->id, 'run_id' => $rawRow->run_id, 'status' => $rawRow->status, 'row_type' => $rawRow->row_type, 'trashed' => $rawRow->trashed()] : null,
                'raw_pdo_find'     => $raw,
                'total_rows_in_table'   => $tableCount,
                'rows_for_portal_agency' => $agencyCount,
                'rows_for_portal_runs'   => $runScoped,
                'portal_query_count'     => $portalSqlCount,
                'portal_agency'    => $portal->agency_id,
                'portal_runs'      => $portal->run_ids_json,
                'failing_sql'      => $sql,
                'failing_bindings' => $bindings,
                'run_from_row'     => $runFromRow ? ['id' => $runFromRow->id, 'agency_id' => $runFromRow->agency_id, 'trashed' => $runFromRow->trashed()] : null,
            ];
            Log::warning('Portal confirm: row not found in scope', [
                'portal_id' => $portal->id,
                'row_id'    => $rowId,
                'diag'      => $diag,
            ]);
            abort(response()->json([
                'message'      => 'Listing row not found in this portal.',
                'row_id'       => $rowId,
                'portal_id'    => $portal->id,
                'diagnostics'  => $diag,
            ], 404));
        }
        return $row;
    }

    private function counts(P24OnboardingPortal $portal): array
    {
        $base = $portal->rowsQuery();
        return [
            'pending'    => (clone $base)->where('status', 'pending')->whereNull('processing_at')->count(),
            'processing' => (clone $base)->where('status', 'pending')->whereNotNull('processing_at')->count(),
            'confirmed'  => (clone $base)->where('status', 'confirmed')->count(),
            'excluded'   => (clone $base)->where('status', 'excluded')->count(),
            'error'      => (clone $base)->where('status', 'error')->count(),
            'total'      => (clone $base)->count(),
        ];
    }
}
