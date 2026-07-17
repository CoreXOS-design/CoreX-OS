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
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\BranchScope;
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

            $counts = ['total' => count($rows), 'new' => 0, 'link' => 0, 'skip' => 0, 'errors' => 0];
            foreach ($rows as $r) {
                if (!empty($r['errors'])) {
                    $counts['errors']++;
                    P24ImportRow::create([
                        'run_id'       => $run->id,
                        'row_type'     => 'agent',
                        'external_id'  => $r['external_id'],
                        'payload_json' => $r['payload'],
                        'mapped_json'  => $r['mapped'],
                        'errors_json'  => $r['errors'],
                        'action'       => $r['action'],
                        'status'       => 'error',
                    ]);
                    continue;
                }

                // Resolve the email against existing users now (not just at import time)
                // so the preview tells the admin the truth: create / link / skip.
                // Mirrors ProcessImporterRunJob::processAgents() exactly.
                [$action, $match] = $this->resolveAgentMatch($r['mapped']['email'] ?? '', (int) $run->agency_id);
                $counts[$action === 'create' ? 'new' : ($action === 'update' ? 'link' : 'skip')]++;

                P24ImportRow::create([
                    'run_id'            => $run->id,
                    'row_type'          => 'agent',
                    'external_id'       => $r['external_id'],
                    'payload_json'      => $r['payload'],
                    'mapped_json'       => $r['mapped'],
                    'errors_json'       => null,
                    'resolved_agent_id' => $match?->id,
                    'action'            => $action,
                    'status'            => 'pending',
                ]);
            }
            $run->update(['status' => 'pending_confirm', 'counts_json' => $counts]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['errors' => ['agents_csv' => ['Parse failed: ' . $e->getMessage()]]], 422);
            }
            return back()->withErrors(['agents_csv' => 'Parse failed: ' . $e->getMessage()]);
        }

        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['redirect' => route('admin.importer.preview', $run)]);
        }
        return redirect()->route('admin.importer.preview', $run);
    }

    /**
     * Decide what will happen to an agent row by matching on email — the single
     * source of truth shared with the preview. Mirrors
     * ProcessImporterRunJob::processAgents() so the preview never lies:
     *  - no match                               → 'create' (new inactive agent)
     *  - existing user in THIS agency / unassigned → 'update' (link P24 ids only, no duplicate)
     *  - existing user in ANOTHER agency        → 'skip'   (multi-tenancy: never touch it)
     *
     * @return array{0:string,1:?User} [action, matchedUser]
     */
    private function resolveAgentMatch(string $email, int $agencyId): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return ['create', null];
        }
        $user = User::withoutGlobalScopes()->withTrashed()->where('email', $email)->first();
        if (!$user) {
            return ['create', null];
        }
        if ($user->agency_id && (int) $user->agency_id !== $agencyId) {
            return ['skip', $user];
        }
        return ['update', $user];
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
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['errors' => ['listings_csv' => ['Import agents for this agency first so listings can be linked.']]], 422);
            }
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
            'mark_compliant_on_confirm' => $request->boolean('mark_compliant_on_confirm'),
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

            // Fallback owner for listings whose P24 agent isn't in this agency.
            // Rather than fail the row, assign it to an active admin so the
            // listing still imports; it can be reassigned from the portal
            // later. Prefer the admin running the import. The Agency Admin
            // Rule (User::booted) guarantees ≥1 active admin per agency.
            $fallbackAdmin = User::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('role', 'admin')
                ->where('is_active', 1)
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [auth()->id()])
                ->orderBy('id')
                ->first();
            $fallbackAdminId = $fallbackAdmin?->id;

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
            }

            $run->update(['status' => 'pending_confirm', 'counts_json' => $counts]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json(['errors' => ['listings_csv' => ['Parse failed: ' . $e->getMessage()]]], 422);
            }
            return back()->withErrors(['listings_csv' => 'Parse failed: ' . $e->getMessage()]);
        }

        // Each listings upload gets its own portal so prior runs stay isolated.
        $agency = Agency::find($agencyId);
        $label  = ($agency?->name ?? 'Agency') . ' · ' . now()->format('Y-m-d H:i');
        $portal = P24OnboardingPortal::create([
            'agency_id'    => $agencyId,
            'token'        => P24OnboardingPortal::generateToken(),
            'slug'         => P24OnboardingPortal::generateSlug(($agency?->name ?? 'agency') . '-' . now()->format('YmdHis')),
            'label'        => $label,
            'created_by'   => auth()->id(),
            'expires_at'   => now()->addDays(30),
            'run_ids_json' => [$run->id],
        ]);
        P24PortalEvent::log([
            'portal_id'   => $portal->id,
            'agency_id'   => $portal->agency_id,
            'actor_type'  => 'admin',
            'actor_label' => auth()->user()?->name ?? 'admin',
            'event'       => 'portal.created',
            'meta_json'   => ['auto' => true, 'run_id' => $run->id, 'rows' => $counts['listings'] ?? null],
            'ip'          => $request->ip(),
        ]);

        if ($request->ajax() || $request->expectsJson()) {
            return response()->json([
                'redirect'   => route('admin.importer.review', ['run_id' => $run->id]),
                'portal_url' => $portal->publicUrl(),
            ]);
        }
        return redirect()->route('admin.importer.review', ['run_id' => $run->id])
            ->with('status', 'Upload complete. Review link: ' . $portal->publicUrl());
    }

    /**
     * Admin "Property Review" page — rebuilt as a per-agency portal
     * management + activity log. Confirming happens in the public portal,
     * not here.
     */
    public function review(Request $request)
    {
        // Agencies that either have at least one listing row OR an existing portal
        $agencyIdsWithRows = P24ImportRun::withoutGlobalScope(AgencyScope::class)
            ->where('kind', 'listings_images')
            ->whereNotNull('agency_id')
            ->distinct()->pluck('agency_id');
        $agencyIdsWithPortals = P24OnboardingPortal::withoutGlobalScope(AgencyScope::class)
            ->whereNull('deleted_at')
            ->distinct()->pluck('agency_id');
        $agencyIds = $agencyIdsWithRows->merge($agencyIdsWithPortals)->unique()->filter()->values();

        $agencies = Agency::whereIn('id', $agencyIds)->orderBy('name')->get();

        $cards = $agencies->map(function (Agency $agency) {
            $rowQ = P24ImportRow::query()
                ->where('row_type', 'listing')
                ->whereHas('run', fn($r) => $r->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id));

            $counts = [
                'pending'    => (clone $rowQ)->where('status', 'pending')->whereNull('processing_at')->count(),
                'processing' => (clone $rowQ)->where('status', 'pending')->whereNotNull('processing_at')->count(),
                'confirmed'  => (clone $rowQ)->where('status', 'confirmed')->count(),
                'excluded'   => (clone $rowQ)->where('status', 'excluded')->count(),
                'error'      => (clone $rowQ)->where('status', 'error')->count(),
                'total'      => (clone $rowQ)->count(),
            ];

            $portals = P24OnboardingPortal::withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agency->id)
                ->orderByDesc('id')->limit(10)->get();

            $events = P24PortalEvent::withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agency->id)
                ->orderByDesc('id')->limit(25)->get();

            $agents = $this->importedAgents($agency);

            return [
                'agency'      => $agency,
                'counts'      => $counts,
                'portals'     => $portals,
                'events'      => $events,
                'agents'      => $agents,
                'agentCounts' => $this->agentInviteCounts($agents),
            ];
        });

        return view('admin.importer.review', compact('cards'));
    }

    /**
     * Every agent CoreX imported for this agency, across every agent run it
     * has ever had — an agency imported over two runs still gets one list.
     *
     * Sourced from the import rows rather than `users.role = 'agent'` so the
     * list means "agents we imported", not "agents that exist". An agent the
     * agency added by hand afterwards is not ours to invite.
     *
     * Unscoped deliberately: the importer is owner-only and spans agencies,
     * but AgencyScope/BranchScope resolve against the *viewing* owner's own
     * agency. They no-op for an owner until they use the agency switcher —
     * at which point a scoped query silently returns an empty list and the
     * page reports "no agents" for an agency that has twenty. SoftDeletes is
     * left on, so an archived agent drops out of the list and the counts.
     */
    private function importedAgents(Agency $agency): \Illuminate\Support\Collection
    {
        $userIds = P24ImportRow::query()
            ->where('row_type', 'agent')
            ->where('status', 'confirmed')
            ->whereNotNull('target_id')
            ->whereHas('run', fn($r) => $r->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id))
            ->pluck('target_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::withoutGlobalScopes([AgencyScope::class, BranchScope::class])
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * The three states that drive both the card and the bulk button's skip
     * logic. `active` = already set a password via a previous invite;
     * `invited` = invite sent, not yet accepted; `pending` = never invited.
     */
    private function agentInviteCounts(\Illuminate\Support\Collection $agents): array
    {
        return [
            'total'   => $agents->count(),
            'active'  => $agents->filter(fn(User $u) => (bool) $u->is_active)->count(),
            'invited' => $agents->filter(fn(User $u) => !$u->is_active && $u->invited_at !== null)->count(),
            'pending' => $agents->filter(fn(User $u) => !$u->is_active && $u->invited_at === null)->count(),
        ];
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

        $label = $data['label'] ?? (Agency::find($data['agency_id'])?->name);
        $portal = P24OnboardingPortal::create([
            'agency_id'    => $data['agency_id'],
            'token'        => P24OnboardingPortal::generateToken(),
            'slug'         => P24OnboardingPortal::generateSlug($label),
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

    /**
     * Invite one agent — the "chase the bounced one" path.
     *
     * Bound by raw id, not by `User $user`: implicit route-model binding
     * resolves through AgencyScope, so an owner who has switched agencies
     * would get a 404 on an agent that plainly exists on the page in front
     * of them.
     */
    public function sendInvite(Request $request, int $userId)
    {
        $user = User::withoutGlobalScopes([AgencyScope::class, BranchScope::class])->find($userId);

        if (!$user) {
            return back()->with('error', 'That agent no longer exists — they may have been archived since this page loaded.');
        }

        if (blank($user->email)) {
            return back()->with('error', "{$user->name} has no email address on file, so there is nowhere to send an invite.");
        }

        $resend = $user->invited_at !== null;
        SendAgentInviteJob::dispatch($user->id);

        return back()->with('status', $this->inviteVerb(1) . ' for ' . $user->email . ($resend ? ' (re-sent).' : '.'));
    }

    /**
     * Send invite links to every agent imported for this agency — the last
     * step of onboarding, once their properties are in.
     *
     * Agency-scoped rather than run-scoped: an agency imported over two agent
     * runs is still one agency to the person pressing the button, and they
     * should not have to know which run an agent arrived in.
     *
     * Safe to press twice. Agents who already accepted an invite (`is_active`)
     * or already hold an unaccepted one (`invited_at`) are skipped and
     * reported, so a second press chases only the genuinely uninvited rather
     * than re-mailing the whole agency. Re-sending is a per-agent decision.
     */
    public function sendAgencyInvites(Request $request, Agency $agency)
    {
        $agents = $this->importedAgents($agency);
        $counts = $this->agentInviteCounts($agents);

        $sendable = $agents->filter(
            fn(User $u) => !$u->is_active && $u->invited_at === null && filled($u->email)
        );

        // Absorb rather than reject: nothing to do is a normal end-state here,
        // not an error. Tell them why the press did nothing.
        if ($sendable->isEmpty()) {
            $why = match (true) {
                $counts['total'] === 0 => 'No agents have been imported for ' . $agency->name . ' yet.',
                $counts['pending'] === 0 => 'Every imported agent for ' . $agency->name . ' has already been invited or is already active.',
                default => 'No agent for ' . $agency->name . ' has an email address on file.',
            };
            return back()->with('status', $why);
        }

        foreach ($sendable as $agent) {
            SendAgentInviteJob::dispatch($agent->id);
        }

        $noEmail = $agents->filter(fn(User $u) => !$u->is_active && $u->invited_at === null && blank($u->email))->count();

        $skips = collect([
            $counts['invited'] ? $counts['invited'] . ' already invited' : null,
            $counts['active'] ? $counts['active'] . ' already active' : null,
            $noEmail ? $noEmail . ' with no email address' : null,
        ])->filter();

        P24PortalEvent::log([
            'portal_id'   => null,
            'agency_id'   => $agency->id,
            'actor_type'  => 'admin',
            'actor_label' => auth()->user()?->name ?? 'admin',
            'event'       => 'agents.invites_sent',
            'meta_json'   => [
                'sent'            => $sendable->count(),
                'skipped_invited' => $counts['invited'],
                'skipped_active'  => $counts['active'],
                'skipped_no_email' => $noEmail,
                'total_imported'  => $counts['total'],
            ],
            'ip'          => $request->ip(),
        ]);

        $message = $this->inviteVerb($sendable->count()) . ' for ' . $sendable->count() . ' ' .
            str('agent')->plural($sendable->count()) . '.';

        if ($skips->isNotEmpty()) {
            $message .= ' Skipped ' . $skips->join(', ', ' and ') . '.';
        }

        return back()->with('status', $message);
    }

    /**
     * Invites go through the queue, so on a queued connection "sent" is a lie
     * until the worker picks them up — say what actually happened instead.
     */
    private function inviteVerb(int $count): string
    {
        return config('queue.default') === 'sync'
            ? ($count === 1 ? 'Invite sent' : 'Invites sent')
            : ($count === 1 ? 'Invite queued' : 'Invites queued');
    }

    /**
     * Browse the cached Property24 location tree (Province → City → Suburb).
     * The tree is rendered as nested collapsible accordions. Cities + suburbs
     * are loaded on demand via /api/v1/p24/* so this page renders instantly
     * regardless of how many suburbs are cached (~27k as of EXDEV).
     */
    public function p24Locations()
    {
        $provinces = \App\Models\P24Province::query()
            ->withCount(['cities'])
            ->orderBy('name')
            ->get();

        $totals = [
            'provinces' => $provinces->count(),
            'cities'    => \App\Models\P24City::count(),
            'suburbs'   => \App\Models\P24Suburb::whereNotNull('p24_city_id')->count(),
        ];

        $lastSyncedAgency = \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->whereNotNull('p24_locations_synced_at')
            ->orderByDesc('p24_locations_synced_at')
            ->first();

        return view('admin.importer.p24-locations', [
            'provinces'     => $provinces,
            'totals'        => $totals,
            'lastSyncedAt'  => $lastSyncedAgency?->p24_locations_synced_at,
            'lastSyncError' => $lastSyncedAgency?->p24_last_sync_error
                ?? \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                    ->whereNotNull('p24_last_sync_error')->value('p24_last_sync_error'),
        ]);
    }

    public function refreshP24Locations(\Illuminate\Http\Request $request)
    {
        $current = \Illuminate\Support\Facades\Cache::get(
            \App\Console\Commands\SyncP24Locations::PROGRESS_KEY
        );
        if (is_array($current) && ($current['status'] ?? null) === 'running') {
            $msg = 'Sync already running — watch the progress bar.';
            return $request->expectsJson()
                ? response()->json(['success' => true, 'already_running' => true, 'message' => $msg])
                : back()->with('success', $msg);
        }

        // Seed cache with a running stub so the UI's poller immediately sees
        // a running state (otherwise there's a 1–2s window before the queue
        // worker picks up the job).
        \Illuminate\Support\Facades\Cache::put(
            \App\Console\Commands\SyncP24Locations::PROGRESS_KEY,
            [
                'status'          => 'running',
                'provinces_total' => 0,
                'provinces_done'  => 0,
                'cities_done'     => 0,
                'suburbs_done'    => 0,
                'current'         => 'Queuing sync…',
                'error'           => null,
                'started_at'      => now()->toIso8601String(),
                'finished_at'     => null,
            ],
            \App\Console\Commands\SyncP24Locations::PROGRESS_TTL
        );

        // Prefer detached shell over queue: independent of worker config and
        // works on any host with PHP CLI. The artisan command writes progress
        // to cache so the UI poller picks it up the same way either path.
        //
        // PHP_BINARY in a web request points at php-fpm (which can't run CLI
        // scripts) — explicitly resolve a real CLI binary instead.
        $php = $this->resolvePhpCliBinary();
        $base      = base_path();
        $logFile   = storage_path('logs/p24-sync.log');
        $cmd       = sprintf('%s %s/artisan p24:sync-locations', escapeshellarg($php), escapeshellarg($base));

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                // Windows — start /B keeps the child detached from the request.
                pclose(popen('start /B "" ' . $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1', 'r'));
            } else {
                // POSIX — nohup + & detaches; disown via shell builtin not needed when stdin/out/err redirected.
                exec('nohup ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');
            }
        } catch (\Throwable $e) {
            // Fall back to the queue if exec is disabled or fails.
            \App\Jobs\SyncP24LocationsJob::dispatch();
        }

        $msg = 'Property24 location sync started. Progress will update below.';
        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $msg])
            : back()->with('success', $msg);
    }

    /**
     * Find a PHP CLI binary on the host. PHP_BINARY in a web request resolves
     * to whatever SAPI is serving the request — under FPM that's php-fpm,
     * which can't run artisan. We try CLI-only paths first and fall back to
     * the unqualified `php` on PATH.
     */
    private function resolvePhpCliBinary(): string
    {
        $candidates = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
        ];
        // If PHP_BINARY isn't php-fpm/php-cgi, use it.
        if (defined('PHP_BINARY') && PHP_BINARY) {
            $name = strtolower(basename(PHP_BINARY));
            if (!str_contains($name, 'fpm') && !str_contains($name, 'cgi')) {
                return PHP_BINARY;
            }
        }
        foreach ($candidates as $path) {
            if (is_executable($path)) return $path;
        }
        return 'php'; // Last-resort PATH lookup.
    }

    public function p24LocationsStatus()
    {
        $progress = \Illuminate\Support\Facades\Cache::get(
            \App\Console\Commands\SyncP24Locations::PROGRESS_KEY
        ) ?: ['status' => 'idle'];

        return response()->json($progress);
    }

    /**
     * Private Property locations page — hidden background data (no tree).
     * Shows only refresh + progress + counts + last-synced, matching the spec
     * at .ai/specs/pp-locations-importer.md.
     */
    public function ppLocations()
    {
        $totals = [
            'provinces' => \App\Models\PpProvince::count(),
            'cities'    => \App\Models\PpCity::count(),
            'suburbs'   => \App\Models\PpSuburb::count(),
        ];

        $lastSyncedAgency = \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->whereNotNull('pp_locations_synced_at')
            ->orderByDesc('pp_locations_synced_at')
            ->first();

        return view('admin.importer.pp-locations', [
            'totals'        => $totals,
            'lastSyncedAt'  => $lastSyncedAgency?->pp_locations_synced_at,
            'lastSyncError' => $lastSyncedAgency?->pp_locations_last_error
                ?? \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                    ->whereNotNull('pp_locations_last_error')->value('pp_locations_last_error'),
        ]);
    }

    public function refreshPpLocations(\Illuminate\Http\Request $request)
    {
        $current = \Illuminate\Support\Facades\Cache::get(
            \App\Console\Commands\SyncPpLocations::PROGRESS_KEY
        );
        if (is_array($current) && ($current['status'] ?? null) === 'running') {
            $msg = 'Sync already running — watch the progress bar.';
            return $request->expectsJson()
                ? response()->json(['success' => true, 'already_running' => true, 'message' => $msg])
                : back()->with('success', $msg);
        }

        \Illuminate\Support\Facades\Cache::put(
            \App\Console\Commands\SyncPpLocations::PROGRESS_KEY,
            [
                'status'          => 'running',
                'provinces_total' => 0,
                'provinces_done'  => 0,
                'cities_done'     => 0,
                'suburbs_done'    => 0,
                'current'         => 'Queuing sync…',
                'error'           => null,
                'started_at'      => now()->toIso8601String(),
                'finished_at'     => null,
            ],
            \App\Console\Commands\SyncPpLocations::PROGRESS_TTL
        );

        $php     = $this->resolvePhpCliBinary();
        $base    = base_path();
        $logFile = storage_path('logs/pp-sync.log');
        $cmd     = sprintf('%s %s/artisan pp:sync-locations', escapeshellarg($php), escapeshellarg($base));

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                pclose(popen('start /B "" ' . $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1', 'r'));
            } else {
                exec('nohup ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Artisan::queue('pp:sync-locations');
        }

        $msg = 'Private Property location sync started. Progress will update below.';
        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $msg])
            : back()->with('success', $msg);
    }

    public function ppLocationsStatus()
    {
        $progress = \Illuminate\Support\Facades\Cache::get(
            \App\Console\Commands\SyncPpLocations::PROGRESS_KEY
        ) ?: ['status' => 'idle'];

        return response()->json($progress);
    }
}
