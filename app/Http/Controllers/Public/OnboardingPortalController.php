<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Jobs\ConfirmP24PropertyRowJob;
use App\Models\P24ImportRow;
use App\Models\P24OnboardingPortal;
use App\Models\P24PortalEvent;
use App\Models\User;
use Illuminate\Http\Request;
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

        $rows = $q->paginate(30)->withQueryString();
        $agency = $portal->agency;
        $counts = $this->counts($portal);
        $agents = User::withoutGlobalScopes()
            ->where('agency_id', $portal->agency_id)
            ->whereNotNull('p24_agent_id')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('onboarding.portal.review', compact('portal', 'agency', 'rows', 'counts', 'agents', 'status', 'type', 'search', 'sort'));
    }

    public function status(Request $request)
    {
        $portal = $this->portal($request);
        return response()->json([
            'counts' => $this->counts($portal),
            'is_active' => $portal->isActive(),
        ]);
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

        foreach ($rows as $row) {
            $row->update([
                'processing_at'          => now(),
                'confirmed_via'          => 'portal',
                'confirmed_by_portal_id' => $portal->id,
            ]);
            ConfirmP24PropertyRowJob::dispatch($row->id, null);
        }

        $this->logEvent($portal, $request, 'portal.bulk.confirmed', [
            'meta_json' => ['count' => $rows->count()],
        ]);

        return response()->json(['ok' => true, 'count' => $rows->count()]);
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

        $rows = $portal->rowsQuery()->where('status', 'pending')->whereNull('processing_at')->get();
        foreach ($rows as $row) {
            $row->update([
                'processing_at'          => now(),
                'confirmed_via'          => 'portal',
                'confirmed_by_portal_id' => $portal->id,
            ]);
            ConfirmP24PropertyRowJob::dispatch($row->id, null);
        }

        $this->logEvent($portal, $request, 'portal.bulk.confirmed', [
            'meta_json' => ['count' => $rows->count(), 'scope' => 'all_pending'],
        ]);

        return response()->json(['ok' => true, 'count' => $rows->count()]);
    }

    public function finish(Request $request)
    {
        $portal = $this->portal($request);
        $this->guardActive($portal);

        $portal->update(['completed_at' => now()]);
        $this->logEvent($portal, $request, 'portal.finished');

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
