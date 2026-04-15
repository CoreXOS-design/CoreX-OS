<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ConfirmP24PropertyRowJob;
use App\Jobs\ProcessImporterRunJob;
use App\Jobs\SendAgentInviteJob;
use App\Models\Agency;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\P24OnboardingPortal;
use App\Models\P24PortalEvent;
use App\Models\User;
use App\Notifications\OnboardingPortalInvitation;
use App\Services\Importer\P24AgentsCsvParser;
use App\Services\Importer\P24ImagesCsvParser;
use App\Services\Importer\P24ListingsCsvParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImporterController extends Controller
{
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
            $agentMap = User::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
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

    /**
     * Admin "Property Review" page — rebuilt as a per-agency portal
     * management + activity log. Confirming happens in the public portal,
     * not here.
     */
    public function review(Request $request)
    {
        // Agencies that either have at least one listing row OR an existing portal
        $agencyIdsWithRows = P24ImportRun::where('kind', 'listings_images')
            ->whereNotNull('agency_id')
            ->distinct()->pluck('agency_id');
        $agencyIdsWithPortals = P24OnboardingPortal::whereNull('deleted_at')
            ->distinct()->pluck('agency_id');
        $agencyIds = $agencyIdsWithRows->merge($agencyIdsWithPortals)->unique()->filter()->values();

        $agencies = Agency::whereIn('id', $agencyIds)->orderBy('name')->get();

        $cards = $agencies->map(function (Agency $agency) {
            $rowQ = P24ImportRow::query()
                ->where('row_type', 'listing')
                ->whereHas('run', fn($r) => $r->where('agency_id', $agency->id));

            $counts = [
                'pending'    => (clone $rowQ)->where('status', 'pending')->whereNull('processing_at')->count(),
                'processing' => (clone $rowQ)->where('status', 'pending')->whereNotNull('processing_at')->count(),
                'confirmed'  => (clone $rowQ)->where('status', 'confirmed')->count(),
                'excluded'   => (clone $rowQ)->where('status', 'excluded')->count(),
                'error'      => (clone $rowQ)->where('status', 'error')->count(),
                'total'      => (clone $rowQ)->count(),
            ];

            $portals = P24OnboardingPortal::where('agency_id', $agency->id)
                ->orderByDesc('id')->limit(10)->get();

            $events = P24PortalEvent::where('agency_id', $agency->id)
                ->orderByDesc('id')->limit(25)->get();

            return [
                'agency'  => $agency,
                'counts'  => $counts,
                'portals' => $portals,
                'events'  => $events,
            ];
        });

        return view('admin.importer.review', compact('cards'));
    }

    public function createPortal(Request $request)
    {
        $data = $request->validate([
            'agency_id'   => 'required|integer|exists:agencies,id',
            'label'       => 'nullable|string|max:255',
            'expires_in_days' => 'nullable|integer|min:1|max:180',
            'run_ids'     => 'nullable|array',
            'run_ids.*'   => 'integer|exists:p24_import_runs,id',
        ]);

        // Enforce one active portal per agency (Q3 decision). Supersede any active.
        $existing = P24OnboardingPortal::where('agency_id', $data['agency_id'])
            ->whereNull('revoked_at')
            ->whereNull('completed_at')
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->get();
        foreach ($existing as $old) {
            $old->update(['revoked_at' => now(), 'revoked_reason' => 'superseded']);
            P24PortalEvent::log([
                'portal_id'   => $old->id,
                'agency_id'   => $old->agency_id,
                'actor_type'  => 'admin',
                'actor_label' => auth()->user()?->name ?? 'admin',
                'event'       => 'portal.revoked',
                'meta_json'   => ['reason' => 'superseded'],
                'ip'          => $request->ip(),
            ]);
        }

        $portal = P24OnboardingPortal::create([
            'agency_id'    => $data['agency_id'],
            'token'        => P24OnboardingPortal::generateToken(),
            'label'        => $data['label'] ?? null,
            'created_by'   => auth()->id(),
            'expires_at'   => now()->addDays((int) ($data['expires_in_days'] ?? 30)),
            'run_ids_json' => $data['run_ids'] ?? null,
        ]);

        P24PortalEvent::log([
            'portal_id'   => $portal->id,
            'agency_id'   => $portal->agency_id,
            'actor_type'  => 'admin',
            'actor_label' => auth()->user()?->name ?? 'admin',
            'event'       => 'portal.created',
            'meta_json'   => ['label' => $portal->label, 'expires_at' => $portal->expires_at?->toIso8601String()],
            'ip'          => $request->ip(),
        ]);

        return back()->with('status', 'Portal created: ' . $portal->publicUrl());
    }

    public function revokePortal(Request $request, P24OnboardingPortal $portal)
    {
        $portal->update([
            'revoked_at'     => now(),
            'revoked_reason' => $request->input('reason', 'manual'),
        ]);
        P24PortalEvent::log([
            'portal_id'   => $portal->id,
            'agency_id'   => $portal->agency_id,
            'actor_type'  => 'admin',
            'actor_label' => auth()->user()?->name ?? 'admin',
            'event'       => 'portal.revoked',
            'meta_json'   => ['reason' => $portal->revoked_reason],
            'ip'          => $request->ip(),
        ]);
        return back()->with('status', 'Portal revoked.');
    }

    public function extendPortal(Request $request, P24OnboardingPortal $portal)
    {
        $days = (int) $request->input('days', 30);
        $new = ($portal->expires_at && $portal->expires_at->isFuture() ? $portal->expires_at : now())
            ->addDays(max(1, min(180, $days)));
        $portal->update(['expires_at' => $new]);
        P24PortalEvent::log([
            'portal_id'   => $portal->id,
            'agency_id'   => $portal->agency_id,
            'actor_type'  => 'admin',
            'actor_label' => auth()->user()?->name ?? 'admin',
            'event'       => 'portal.extended',
            'meta_json'   => ['new_expiry' => $new->toIso8601String(), 'added_days' => $days],
            'ip'          => $request->ip(),
        ]);
        return back()->with('status', 'Portal extended to ' . $new->toDayDateTimeString());
    }

    public function invitePortal(Request $request, P24OnboardingPortal $portal)
    {
        $data = $request->validate(['email' => 'required|email']);
        \Illuminate\Support\Facades\Notification::route('mail', $data['email'])
            ->notify(new OnboardingPortalInvitation($portal));
        P24PortalEvent::log([
            'portal_id'   => $portal->id,
            'agency_id'   => $portal->agency_id,
            'actor_type'  => 'admin',
            'actor_label' => auth()->user()?->name ?? 'admin',
            'event'       => 'portal.invite_sent',
            'meta_json'   => ['email' => $data['email']],
            'ip'          => $request->ip(),
        ]);
        return back()->with('status', 'Invite sent to ' . $data['email']);
    }


    public function rowDetails(P24ImportRow $row)
    {
        $row->load('run', 'resolvedAgent');
        return view('admin.importer.partials.property-drawer', compact('row'));
    }

    public function confirmRow(P24ImportRow $row)
    {
        abort_if($row->row_type !== 'listing', 400);
        if (empty($row->processing_at) && $row->status !== 'confirmed') {
            $row->update(['processing_at' => now(), 'confirmed_via' => 'admin']);
            ConfirmP24PropertyRowJob::dispatch($row->id, auth()->id());
        }
        return response()->json(['ok' => true, 'row_id' => $row->id, 'status' => 'processing']);
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
        $rows = P24ImportRow::whereIn('id', $ids)
            ->where('row_type', 'listing')
            ->whereIn('status', ['pending', 'error'])
            ->whereNull('processing_at')
            ->get();
        foreach ($rows as $row) {
            $row->update(['processing_at' => now(), 'confirmed_via' => 'admin']);
            ConfirmP24PropertyRowJob::dispatch($row->id, auth()->id());
        }
        return response()->json(['ok' => true, 'count' => $rows->count()]);
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
