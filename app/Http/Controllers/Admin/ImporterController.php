<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ConfirmP24PropertyRowJob;
use App\Jobs\ProcessImporterRunJob;
use App\Jobs\SendAgentInviteJob;
use App\Models\Agency;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\User;
use App\Services\Importer\P24AgentsCsvParser;
use App\Services\Importer\P24ImagesCsvParser;
use App\Services\Importer\P24ListingsCsvParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImporterController extends Controller
{
    /**
     * P24 Importer is a System-Owner-only tool — it creates agents and
     * listings across agencies and is not something an agency admin
     * should reach. Route middleware would work, but centralising the
     * gate in the controller guarantees every action is covered even if
     * a new route is added later without the middleware.
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            abort_unless($user && $user->isOwnerRole(), 403, 'P24 Importer is restricted to System Owners.');
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $agencies = Agency::orderBy('name')->get();
        $runs = P24ImportRun::with('agency', 'user')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $activeAgencyId = (int) ($request->get('agency_id')
            ?? session('active_agency_id')
            ?? auth()->user()?->agency_id);

        $hasAgentsRun = $activeAgencyId
            ? P24ImportRun::where('agency_id', $activeAgencyId)
                ->where('kind', 'agents')
                ->whereIn('status', ['completed', 'pending_confirm'])
                ->exists()
            : false;

        return view('admin.importer.index', compact('agencies', 'runs', 'activeAgencyId', 'hasAgentsRun'));
    }

    public function uploadAgents(Request $request)
    {
        $request->validate([
            'agency_id'  => 'required|integer|exists:agencies,id',
            'agents_csv' => 'required|file|mimes:csv,txt|max:51200',
        ]);

        $path = $request->file('agents_csv')->store('imports/p24/agents');

        $run = P24ImportRun::create([
            'user_id'          => auth()->id(),
            'agency_id'        => $request->integer('agency_id'),
            'kind'             => 'agents',
            'status'           => 'parsing',
            'agents_csv_path'  => $path,
        ]);

        try {
            $parser = new P24AgentsCsvParser();
            $rows = $parser->parse(\Storage::path($path));

            $counts = ['total' => count($rows), 'errors' => 0];
            foreach ($rows as $r) {
                if (!empty($r['errors'])) $counts['errors']++;
                P24ImportRow::create([
                    'run_id'       => $run->id,
                    'row_type'     => 'agent',
                    'external_id'  => $r['external_id'],
                    'payload_json' => $r['payload'],
                    'mapped_json'  => $r['mapped'],
                    'errors_json'  => $r['errors'] ?: null,
                    'action'       => $r['action'],
                    'status'       => empty($r['errors']) ? 'pending' : 'error',
                ]);
            }
            $run->update(['status' => 'pending_confirm', 'counts_json' => $counts]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return back()->withErrors(['agents_csv' => 'Parse failed: ' . $e->getMessage()]);
        }

        return redirect()->route('admin.importer.preview', $run);
    }

    public function preview(P24ImportRun $run)
    {
        $run->load('rows', 'agency');
        return view('admin.importer.preview', compact('run'));
    }

    public function confirmAgents(Request $request, P24ImportRun $run)
    {
        abort_if($run->kind !== 'agents', 400);
        // Apply any exclusion toggles
        $excluded = (array) $request->input('excluded', []);
        if (!empty($excluded)) {
            P24ImportRow::whereIn('id', $excluded)
                ->where('run_id', $run->id)
                ->update(['status' => 'excluded', 'excluded_at' => now()]);
        }
        $run->update(['confirmed_at' => now(), 'status' => 'importing']);
        ProcessImporterRunJob::dispatchSync($run->id);
        return redirect()->route('admin.importer.show', $run);
    }

    public function cancelRun(P24ImportRun $run)
    {
        $run->update(['status' => 'cancelled']);
        $run->delete(); // soft delete
        return redirect()->route('admin.importer.index')->with('status', 'Run cancelled.');
    }

    public function show(P24ImportRun $run)
    {
        $run->load(['rows' => fn($q) => $q->orderBy('id')]);
        return view('admin.importer.show', compact('run'));
    }

    public function uploadListings(Request $request)
    {
        $request->validate([
            'agency_id'     => 'required|integer|exists:agencies,id',
            'listings_csv'  => 'required|file|mimes:csv,txt|max:51200',
            'images_csv'    => 'required|file|mimes:csv,txt|max:51200',
        ]);

        $agencyId = $request->integer('agency_id');

        // Guardrail: agents must have been imported for this agency first
        $hasAgentsRun = P24ImportRun::where('agency_id', $agencyId)
            ->where('kind', 'agents')
            ->whereIn('status', ['completed', 'pending_confirm'])
            ->exists();
        if (!$hasAgentsRun) {
            return back()->withErrors(['listings_csv' => 'Import agents for this agency first so listings can be linked.']);
        }

        $listingsPath = $request->file('listings_csv')->store('imports/p24/listings');
        $imagesPath = $request->file('images_csv')->store('imports/p24/images');

        $run = P24ImportRun::create([
            'user_id'           => auth()->id(),
            'agency_id'         => $agencyId,
            'kind'              => 'listings_images',
            'status'            => 'parsing',
            'listings_csv_path' => $listingsPath,
            'images_csv_path'   => $imagesPath,
        ]);

        try {
            $listings = (new P24ListingsCsvParser())->parse(\Storage::path($listingsPath));
            $images   = (new P24ImagesCsvParser())->parse(\Storage::path($imagesPath));

            $totalImages = array_sum(array_map('count', $images));
            $counts = [
                'listings'      => count($listings),
                'images_total'  => $totalImages,
                'listings_with_images' => count(array_intersect_key($images, array_flip(array_column($listings, 'external_id')))),
            ];

            // Build agent resolver map: p24_agent_id → users.id for this agency
            $agentMap = User::where('agency_id', $agencyId)
                ->whereNotNull('p24_agent_id')
                ->pluck('id', 'p24_agent_id')
                ->toArray();

            foreach ($listings as $r) {
                $errors = $r['errors'];
                $primary = $r['primary_agent_p24'];
                $resolvedId = $primary ? ($agentMap[$primary] ?? null) : null;
                if (!$resolvedId) {
                    $errors[] = 'Primary agent not resolved (p24_agent_id=' . ($primary ?? 'null') . ')';
                }

                $urls = $images[$r['external_id']] ?? [];

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
                    'status'            => !empty($errors) ? 'error' : 'pending',
                ]);
            }

            $run->update(['status' => 'pending_confirm', 'counts_json' => $counts]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return back()->withErrors(['listings_csv' => 'Parse failed: ' . $e->getMessage()]);
        }

        return redirect()->route('admin.importer.review', ['run_id' => $run->id]);
    }

    public function review(Request $request)
    {
        $agencies = Agency::orderBy('name')->get();
        $runs = P24ImportRun::where('kind', 'listings_images')
            ->orderByDesc('id')->limit(50)->get();

        $q = P24ImportRow::with(['run', 'resolvedAgent'])
            ->where('row_type', 'listing');

        if ($request->filled('agency_id')) {
            $q->whereHas('run', fn($r) => $r->where('agency_id', $request->integer('agency_id')));
        }
        if ($request->filled('run_id')) {
            $q->where('run_id', $request->integer('run_id'));
        }
        if ($request->filled('status') && $request->get('status') !== 'all') {
            $q->where('status', $request->get('status'));
        } else {
            $q->where('status', 'pending');
        }
        if ($request->filled('agent_id')) {
            $q->where('resolved_agent_id', $request->integer('agent_id'));
        }
        if ($request->filled('listing_type') && $request->get('listing_type') !== 'all') {
            $type = $request->get('listing_type');
            $q->where(function ($qq) use ($type) {
                $qq->whereJsonContains('mapped_json->listing_type', $type)
                   ->orWhereRaw("JSON_EXTRACT(mapped_json, '$.listing_type') = ?", [$type]);
            });
        }
        if ($request->filled('has_errors')) {
            if ($request->get('has_errors') === 'yes') {
                $q->whereNotNull('errors_json');
            } elseif ($request->get('has_errors') === 'no') {
                $q->whereNull('errors_json');
            }
        }
        if ($request->filled('search')) {
            $s = '%' . $request->get('search') . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('external_id', 'like', $s)
                   ->orWhereRaw("JSON_EXTRACT(mapped_json, '$.address') LIKE ?", [$s]);
            });
        }

        $rows = $q->orderByDesc('id')->paginate(50)->withQueryString();

        return view('admin.importer.review', compact('rows', 'agencies', 'runs'));
    }

    public function rowDetails(P24ImportRow $row)
    {
        $row->load('run', 'resolvedAgent');
        return view('admin.importer.partials.property-drawer', compact('row'));
    }

    public function confirmRow(P24ImportRow $row)
    {
        abort_if($row->row_type !== 'listing', 400);
        ConfirmP24PropertyRowJob::dispatchSync($row->id, auth()->id());
        return response()->json(['ok' => true, 'row_id' => $row->id]);
    }

    public function excludeRow(P24ImportRow $row)
    {
        $row->update(['status' => 'excluded', 'excluded_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function resolveAgentRow(Request $request, P24ImportRow $row)
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $row->update([
            'resolved_agent_id' => $request->integer('user_id'),
            'errors_json'       => collect($row->errors_json ?? [])
                ->reject(fn($e) => str_contains($e, 'Primary agent not resolved'))
                ->values()->all() ?: null,
            'status'            => 'pending',
        ]);
        return response()->json(['ok' => true]);
    }

    public function confirmBulk(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        foreach ($ids as $id) {
            ConfirmP24PropertyRowJob::dispatchSync((int)$id, auth()->id());
        }
        return response()->json(['ok' => true, 'count' => count($ids)]);
    }

    public function excludeBulk(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        P24ImportRow::whereIn('id', $ids)->update(['status' => 'excluded', 'excluded_at' => now()]);
        return response()->json(['ok' => true, 'count' => count($ids)]);
    }

    public function sendInvite(User $user)
    {
        SendAgentInviteJob::dispatchSync($user->id);
        return back()->with('status', "Invite sent to {$user->email}");
    }

    public function sendAllInvites(P24ImportRun $run)
    {
        abort_if($run->kind !== 'agents', 400);
        $userIds = $run->rows()
            ->where('row_type', 'agent')
            ->where('status', 'confirmed')
            ->whereNotNull('target_id')
            ->pluck('target_id');
        foreach ($userIds as $uid) {
            SendAgentInviteJob::dispatchSync((int)$uid);
        }
        return back()->with('status', 'Invites sent to ' . count($userIds) . ' agents.');
    }
}
