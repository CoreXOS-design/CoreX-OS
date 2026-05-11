<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventInvitation;
use App\Models\CommandCenter\CommandTask;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use App\Services\CommandCenter\Calendar\CalendarVisibilityResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommandCentreService
{
    public function assembleForUser(User $user): array
    {
        $cacheKey = "command_centre_{$user->id}";
        return Cache::remember($cacheKey, 300, function () use ($user) {
            $cards = $this->getAgentCards($user);

            $role = $user->effectiveRole();
            if (in_array($role, ['branch_manager', 'admin', 'super_admin', 'owner'])) {
                $cards = array_merge($cards, $this->getBranchManagerCards($user));
            }
            if (in_array($role, ['admin', 'super_admin', 'owner'])) {
                $cards = array_merge($cards, $this->getAdminCards($user));
            }

            // Sort by urgency: critical → high → medium → low
            $urgencyOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            usort($cards, fn($a, $b) => ($urgencyOrder[$a['urgency']] ?? 9) <=> ($urgencyOrder[$b['urgency']] ?? 9));

            return $cards;
        });
    }

    public function getAgentCards(User $user): array
    {
        $cards = [];
        $userId = $user->id;
        $agencyId = $user->effectiveAgencyId() ?? 1;

        // A1 — Today's Appointments (always visible)
        $cards[] = $this->todayAppointments($user);

        // A2 — Pending Invitations
        $card = $this->pendingInvitations($userId);
        if ($card['count'] > 0) $cards[] = $card;

        // A3 — Overdue/Inbox items (tasks + events)
        $card = $this->overdueItems($user);
        if ($card['count'] > 0) $cards[] = $card;

        // A4 — Buyers Needing Follow-up
        $card = $this->buyersNeedingFollowUp($userId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // A5 — Buyer Portal Activity
        $card = $this->buyerPortalActivity($userId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // A6 — Listings Needing Attention
        $card = $this->listingsNeedingAttention($userId);
        if ($card['count'] > 0) $cards[] = $card;

        // A7 — E-Sign Activity (unified: approval + awaiting signatures + awaiting others)
        $card = $this->esignActivity($userId);
        if ($card['count'] > 0) $cards[] = $card;

        // A8 — FICA: CO Review Queue
        $card = $this->ficaReview($userId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // A8b — FICA: My Submissions Tracking
        $card = $this->myFicaSubmissions($userId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // A9 — Active Buyer Pipeline (warm + cold)
        $card = $this->activeBuyerPipeline($userId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // A10 — My Compliance
        $card = $this->myCompliance($user);
        if ($card['count'] > 0) $cards[] = $card;

        // A11 — Deal Steps Assigned to Me
        $card = $this->myDealSteps($userId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // A12 — Prospecting Activity (unified: claims + buyer-matched)
        $card = $this->prospectingActivity($userId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // A13 — Draft Presentations
        $card = $this->draftPresentations($userId);
        if ($card['count'] > 0) $cards[] = $card;

        // A14 — Leave Applications (pending/submitted)
        $card = $this->myLeaveApplications($userId);
        if ($card['count'] > 0) $cards[] = $card;

        // A15 — Sales Documents Awaiting Return
        $card = $this->salesDocsAwaitingReturn($userId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // A16 — Training & Qualifications
        $card = $this->myTraining($userId);
        if ($card['count'] > 0) $cards[] = $card;

        // A17 — Events Needing Feedback Capture
        $card = $this->eventsNeedingFeedback($userId);
        if ($card['count'] > 0) $cards[] = $card;

        // A18 — Unread Notifications
        $card = $this->unreadNotifications($userId);
        if ($card['count'] > 0) $cards[] = $card;

        // A19 — Recent Activity (always visible)
        $cards[] = $this->recentActivity($userId, $agencyId);

        return $cards;
    }

    public function getBranchManagerCards(User $user): array
    {
        $branchId = $user->effectiveBranchId();
        if (!$branchId) return [];

        $cards = [];
        $agencyId = $user->effectiveAgencyId() ?? 1;

        // B1 — Branch Agent Watch
        $card = $this->branchAgentWatch($branchId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // B2 — Branch Listings Needing Review
        $card = $this->branchListingsReview($branchId);
        if ($card['count'] > 0) $cards[] = $card;

        // B3 — Branch Compliance Queue
        $card = $this->branchComplianceQueue($branchId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // B4 — Leave Applications Awaiting BM Approval
        $card = $this->leaveAwaitingApproval($branchId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // B5 — Branch Lost Value
        $card = $this->branchLostValue($branchId, $agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        return $cards;
    }

    public function getAdminCards(User $user): array
    {
        $agencyId = $user->effectiveAgencyId() ?? 1;
        $cards = [];

        // C1 — Agency Health Snapshot (always visible)
        $cards[] = $this->agencyHealthSnapshot($agencyId);

        // C2 — Agency Compliance Flags
        $card = $this->agencyComplianceFlags($agencyId);
        if ($card['count'] > 0) $cards[] = $card;

        // C3 — Strategic Insights (always visible for owners)
        $cards[] = $this->strategicInsights($agencyId);

        return $cards;
    }

    // ─── AGENT CARD METHODS ─────────────────────────────────

    private function todayAppointments(User $user): array
    {
        $calendarService = new CalendarEventService();
        $todayEvents = $calendarService->getTodayEvents($user);

        $thresholdResolver = app(CalendarThresholdResolver::class);
        $visibilityResolver = app(CalendarVisibilityResolver::class);

        // Also get tomorrow's
        $tomorrowRaw = $calendarService->getEventsForRange(
            $user,
            now()->addDay()->startOfDay()->toDateString(),
            now()->addDay()->endOfDay()->toDateString()
        );
        $tomorrowEvents = collect($visibilityResolver->filterVisible($tomorrowRaw, $user))
            ->sortBy('event_date')->take(5)->values();

        $items = collect($todayEvents)->merge($tomorrowEvents)->take(8)->map(fn($e) => [
            'id' => $e->id,
            'title' => $e->title,
            'time' => $e->event_date->format('H:i'),
            'date_label' => $e->event_date->isToday() ? 'Today' : 'Tomorrow',
            'category' => $e->category,
        ])->values()->toArray();

        return [
            'card_id' => 'today_appointments',
            'title' => "Today's Schedule",
            'icon' => 'calendar',
            'urgency' => 'high',
            'count' => count($items),
            'items' => $items,
            'view_all_url' => route('command-center.calendar'),
            'always_visible' => true,
        ];
    }

    private function pendingInvitations(int $userId): array
    {
        $invitations = CalendarEventInvitation::forUser($userId)
            ->where('status', 'pending')
            ->with(['event' => fn($q) => $q->withoutGlobalScopes(), 'inviter'])
            ->orderByDesc('created_at')
            ->limit(5)->get();

        return [
            'card_id' => 'pending_invitations',
            'title' => 'Pending Invitations',
            'icon' => 'mail',
            'urgency' => 'high',
            'count' => CalendarEventInvitation::forUser($userId)->where('status', 'pending')->count(),
            'items' => $invitations->map(fn($inv) => [
                'id' => $inv->id,
                'title' => $inv->event?->title ?? 'Event',
                'inviter' => $inv->inviter?->name ?? 'Unknown',
                'time' => $inv->event?->event_date?->format('D d M, H:i') ?? '',
                'respond_url' => route('command-center.calendar.invitations.respond', $inv->id),
            ])->toArray(),
            'view_all_url' => route('command-center.calendar.invitations'),
        ];
    }

    private function overdueItems(User $user): array
    {
        $overdueTasks = CommandTask::forUser($user->id)
            ->overdue()->whereNull('resolution')
            ->with(['property', 'contact'])
            ->orderBy('due_date')->limit(5)->get();

        $overdueEvents = CalendarEvent::forUser($user->id)
            ->where('status', 'overdue')->whereNull('resolution')
            ->orderBy('event_date')->limit(5)->get();

        $items = collect();
        foreach ($overdueTasks as $t) {
            $items->push([
                'type' => 'task',
                'id' => $t->id,
                'title' => $t->title,
                'due' => $t->due_date?->format('d M') ?? '',
                'days_overdue' => $t->due_date ? (int) $t->due_date->diffInDays(now()) : 0,
            ]);
        }
        foreach ($overdueEvents as $e) {
            $items->push([
                'type' => 'event',
                'id' => $e->id,
                'title' => $e->title,
                'due' => $e->event_date?->format('d M') ?? '',
                'days_overdue' => $e->event_date ? (int) $e->event_date->diffInDays(now()) : 0,
            ]);
        }

        return [
            'card_id' => 'overdue_items',
            'title' => 'Overdue & Unresolved',
            'icon' => 'alert-triangle',
            'urgency' => 'critical',
            'count' => $items->count(),
            'items' => $items->sortByDesc('days_overdue')->take(5)->values()->toArray(),
            'view_all_url' => route('corex.dashboard'),
        ];
    }

    private function buyersNeedingFollowUp(int $userId, int $agencyId): array
    {
        // High-risk buyers (score > 60) assigned to this agent
        $highRisk = DB::table('buyer_lost_risk_scores as r')
            ->join('contacts as c', 'c.id', '=', 'r.contact_id')
            ->where('c.agency_id', $agencyId)
            ->where('c.created_by_user_id', $userId)
            ->where('r.score', '>', 60)
            ->orderByDesc('r.score')
            ->limit(5)
            ->get(['c.id', 'c.first_name', 'c.last_name', 'r.score', 'r.computed_at']);

        // Buyers with no activity for 14+ days
        $stale = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->where('created_by_user_id', $userId)
            ->where('is_buyer', true)
            ->where('buyer_state', '!=', 'lost')
            ->where(function ($q) {
                $q->where('last_activity_at', '<', now()->subDays(14))
                  ->orWhereNull('last_activity_at');
            })
            ->orderBy('last_activity_at')
            ->limit(5)
            ->get(['id', 'first_name', 'last_name', 'last_activity_at']);

        $items = collect();
        foreach ($highRisk as $b) {
            $items->push([
                'id' => $b->id,
                'name' => trim($b->first_name . ' ' . $b->last_name),
                'reason' => 'Risk score: ' . $b->score,
                'urgency_value' => $b->score,
            ]);
        }
        foreach ($stale as $b) {
            if (!$items->contains('id', $b->id)) {
                $days = $b->last_activity_at ? (int) now()->diffInDays($b->last_activity_at) : 999;
                $items->push([
                    'id' => $b->id,
                    'name' => trim($b->first_name . ' ' . $b->last_name),
                    'reason' => $days > 900 ? 'No activity recorded' : "No activity for {$days} days",
                    'urgency_value' => $days,
                ]);
            }
        }

        return [
            'card_id' => 'buyers_follow_up',
            'title' => 'Buyers Needing Follow-up',
            'icon' => 'users',
            'urgency' => 'medium',
            'count' => $items->count(),
            'items' => $items->take(5)->values()->toArray(),
            'view_all_url' => '/corex/command-center/buyers/pipeline',
        ];
    }

    private function buyerPortalActivity(int $userId, int $agencyId): array
    {
        $responses = DB::table('buyer_property_responses as r')
            ->join('contacts as c', 'c.id', '=', 'r.contact_id')
            ->join('properties as p', 'p.id', '=', 'r.property_id')
            ->where('c.agency_id', $agencyId)
            ->where('c.created_by_user_id', $userId)
            ->where('r.responded_at', '>=', now()->subDays(7))
            ->whereIn('r.response', ['interested', 'viewing_requested'])
            ->orderByDesc('r.responded_at')
            ->limit(5)
            ->get(['c.id as contact_id', 'c.first_name', 'c.last_name', 'r.response', 'p.id as property_id',
                    DB::raw("COALESCE(p.title, CONCAT('Property #', p.id)) as property_title"), 'r.responded_at']);

        return [
            'card_id' => 'buyer_portal_activity',
            'title' => 'Buyer Portal Activity',
            'icon' => 'activity',
            'urgency' => 'high',
            'count' => $responses->count(),
            'items' => $responses->map(fn($r) => [
                'contact_id' => $r->contact_id,
                'name' => trim($r->first_name . ' ' . $r->last_name),
                'action' => $r->response === 'viewing_requested' ? 'Requested viewing' : 'Interested',
                'property' => $r->property_title,
                'property_id' => $r->property_id,
                'when' => \Carbon\Carbon::parse($r->responded_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/corex/command-center/buyers/pipeline',
        ];
    }

    private function listingsNeedingAttention(int $userId): array
    {
        $stale = DB::table('properties')
            ->where('agent_id', $userId)
            ->where('status', 'available')
            ->where('created_at', '<', now()->subDays(60))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('property_marketing_activities')
                  ->whereColumn('property_marketing_activities.property_id', 'properties.id')
                  ->where('occurred_at', '>=', now()->subDays(14));
            })
            ->orderBy('created_at')
            ->limit(5)
            ->get(['id', DB::raw("COALESCE(title, CONCAT('Property #', id)) as title"), 'created_at']);

        return [
            'card_id' => 'listings_attention',
            'title' => 'Listings Needing Attention',
            'icon' => 'home',
            'urgency' => 'medium',
            'count' => $stale->count(),
            'items' => $stale->map(fn($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'days_on_market' => (int) now()->diffInDays($p->created_at),
            ])->toArray(),
            'view_all_url' => '/corex/command-center/properties',
        ];
    }

    private function esignNeedingApproval(int $userId): array
    {
        try {
            // Templates where I created them and status = pending_agent_approval
            $pending = DB::table('signature_templates')
                ->where('created_by', $userId)
                ->where('status', 'pending_agent_approval')
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT CONCAT(first_name, ' ', last_name) FROM contacts WHERE contacts.id = (SELECT contact_id FROM signature_requests WHERE signature_template_id = signature_templates.id LIMIT 1)), '')), ''), 'Document') as party_name"), 'status', 'created_at']);
        } catch (\Throwable $e) {
            // Simpler fallback query
            try {
                $pending = DB::table('signature_templates')
                    ->where('created_by', $userId)
                    ->where('status', 'pending_agent_approval')
                    ->whereNull('deleted_at')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(['id', 'status', 'created_at']);
            } catch (\Throwable $e2) {
                $pending = collect();
            }
        }

        return [
            'card_id' => 'esign_needs_approval',
            'title' => 'E-Sign Needs Your Approval',
            'icon' => 'file-signature',
            'urgency' => 'critical',
            'count' => $pending->count(),
            'items' => $pending->map(fn($d) => [
                'id' => $d->id,
                'title' => $d->party_name ?? 'Document #' . $d->id,
                'status' => 'Needs approval',
                'created' => \Carbon\Carbon::parse($d->created_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/docuperfect/esign/my-documents',
        ];
    }

    private function esignAwaitingSignatures(int $userId): array
    {
        try {
            // Templates I created that are in 'ready' status (waiting for signers)
            $pending = DB::table('signature_templates')
                ->where('created_by', $userId)
                ->where('status', 'ready')
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'status', 'created_at']);
        } catch (\Throwable $e) {
            $pending = collect();
        }

        return [
            'card_id' => 'esign_awaiting_signatures',
            'title' => 'E-Sign Awaiting Signatures',
            'icon' => 'file-signature',
            'urgency' => 'medium',
            'count' => $pending->count(),
            'items' => $pending->map(fn($d) => [
                'id' => $d->id,
                'title' => 'Document #' . $d->id,
                'status' => 'Ready — awaiting signer',
                'created' => \Carbon\Carbon::parse($d->created_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/docuperfect/esign/my-documents',
        ];
    }

    private function ficaReview(int $userId, int $agencyId): array
    {
        // FICA submissions pending review (for compliance officers)
        $pending = DB::table('fica_submissions')
            ->where('agency_id', $agencyId)
            ->whereIn('status', ['submitted', 'pending_review'])
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->limit(5)
            ->get(['id', 'contact_id', 'status', 'created_at']);

        // Enrich with contact names
        $contactIds = $pending->pluck('contact_id')->filter()->toArray();
        $contactNames = !empty($contactIds)
            ? DB::table('contacts')->whereIn('id', $contactIds)->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id')
            : collect();

        return [
            'card_id' => 'fica_review',
            'title' => 'FICA Review Queue',
            'icon' => 'shield-check',
            'urgency' => 'high',
            'count' => $pending->count(),
            'items' => $pending->map(fn($f) => [
                'id' => $f->id,
                'contact' => $contactNames[$f->contact_id] ?? 'Contact',
                'status' => str_replace('_', ' ', $f->status),
                'days_waiting' => (int) now()->diffInDays($f->created_at),
            ])->toArray(),
            'view_all_url' => '/corex/compliance/fica',
        ];
    }

    private function myCompliance(User $user): array
    {
        $items = collect();

        // FFC expiry
        if ($user->ffc_expiry_date) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($user->ffc_expiry_date, false);
            if ($daysLeft <= 30) {
                $items->push([
                    'label' => 'FFC expires',
                    'value' => $daysLeft <= 0 ? 'EXPIRED' : "in {$daysLeft} days",
                    'critical' => $daysLeft <= 0,
                ]);
            }
        }

        // RMCP overdue items (table may not exist yet)
        try {
            $rmcpOverdue = DB::table('rmcp_entries')
                ->where('user_id', $user->id)
                ->where('status', '!=', 'completed')
                ->where('due_date', '<', now())
                ->count();
            if ($rmcpOverdue > 0) {
                $items->push(['label' => 'RMCP items overdue', 'value' => $rmcpOverdue, 'critical' => true]);
            }
        } catch (\Throwable $e) {}

        return [
            'card_id' => 'my_compliance',
            'title' => 'My Compliance',
            'icon' => 'shield',
            'urgency' => $items->contains('critical', true) ? 'critical' : 'medium',
            'count' => $items->count(),
            'items' => $items->toArray(),
            'view_all_url' => '/corex/compliance/fica',
        ];
    }

    private function recentActivity(int $userId, int $agencyId): array
    {
        $activity = DB::table('buyer_activity_log')
            ->where('agency_id', $agencyId)
            ->where(function ($q) use ($userId) {
                $q->where('logged_by_user_id', $userId)
                  ->orWhereIn('contact_id', function ($sub) use ($userId) {
                      $sub->select('id')->from('contacts')
                          ->where('created_by_user_id', $userId);
                  });
            })
            ->orderByDesc('activity_date')
            ->limit(5)
            ->get(['id', 'activity_type', 'activity_date']);

        return [
            'card_id' => 'recent_activity',
            'title' => 'Recent Activity',
            'icon' => 'clock',
            'urgency' => 'low',
            'count' => $activity->count(),
            'items' => $activity->map(fn($a) => [
                'action' => str_replace('_', ' ', $a->activity_type ?? ''),
                'summary' => ucfirst(str_replace('_', ' ', $a->activity_type ?? '')),
                'when' => \Carbon\Carbon::parse($a->activity_date)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => route('command-center.calendar'),
            'always_visible' => true,
        ];
    }

    // ─── BM CARD METHODS ────────────────────────────────────

    private function branchAgentWatch(int $branchId, int $agencyId): array
    {
        // Agents in branch with no daily activity in 7+ days
        $inactive = DB::table('users')
            ->where('branch_id', $branchId)
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNotIn('role', ['admin', 'super_admin', 'owner'])
            ->where(function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('daily_activity_entries')
                        ->whereColumn('daily_activity_entries.user_id', 'users.id')
                        ->where('created_at', '>=', now()->subDays(7));
                });
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'role']);

        return [
            'card_id' => 'branch_agent_watch',
            'title' => 'Agent Watch',
            'icon' => 'eye',
            'urgency' => 'medium',
            'count' => $inactive->count(),
            'items' => $inactive->map(fn($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'issue' => 'No activity in 7+ days',
            ])->toArray(),
            'view_all_url' => '/corex/command-center/reporting/branch',
        ];
    }

    private function branchListingsReview(int $branchId): array
    {
        $stale = DB::table('properties')
            ->where('branch_id', $branchId)
            ->where('status', 'available')
            ->where('created_at', '<', now()->subDays(60))
            ->orderBy('created_at')
            ->limit(5)
            ->get(['id', DB::raw("COALESCE(title, CONCAT('Property #', id)) as title"), 'created_at', 'agent_id']);

        $agentIds = $stale->pluck('agent_id')->filter()->toArray();
        $agents = !empty($agentIds) ? DB::table('users')->whereIn('id', $agentIds)->pluck('name', 'id') : collect();

        return [
            'card_id' => 'branch_listings_review',
            'title' => 'Branch Listings Review',
            'icon' => 'building',
            'urgency' => 'medium',
            'count' => $stale->count(),
            'items' => $stale->map(fn($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'days_on_market' => (int) now()->diffInDays($p->created_at),
                'agent' => $agents[$p->agent_id] ?? 'Unassigned',
            ])->toArray(),
            'view_all_url' => '/corex/command-center/properties',
        ];
    }

    private function branchComplianceQueue(int $branchId, int $agencyId): array
    {
        $ficaPending = DB::table('fica_submissions as f')
            ->join('contacts as c', 'c.id', '=', 'f.contact_id')
            ->where('f.agency_id', $agencyId)
            ->where('c.branch_id', $branchId)
            ->whereIn('f.status', ['submitted', 'pending_review'])
            ->whereNull('f.deleted_at')
            ->count();

        return [
            'card_id' => 'branch_compliance',
            'title' => 'Branch Compliance Queue',
            'icon' => 'clipboard-check',
            'urgency' => $ficaPending > 5 ? 'critical' : 'high',
            'count' => $ficaPending,
            'items' => [['label' => 'FICA submissions pending', 'value' => $ficaPending]],
            'view_all_url' => '/corex/compliance/fica',
        ];
    }

    private function branchLostValue(int $branchId, int $agencyId): array
    {
        $lostValue = (int) DB::table('buyer_lost_records as l')
            ->join('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where('c.agency_id', $agencyId)
            ->where('c.branch_id', $branchId)
            ->where('l.created_at', '>=', now()->subDays(30))
            ->sum('l.preapproval_amount_at_loss');

        $topReasons = DB::table('buyer_lost_records as l')
            ->join('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where('c.agency_id', $agencyId)
            ->where('c.branch_id', $branchId)
            ->where('l.created_at', '>=', now()->subDays(30))
            ->select('l.reason_label as reason', DB::raw('COUNT(*) as cnt'))
            ->groupBy('l.reason_label')
            ->orderByDesc('cnt')
            ->limit(3)
            ->get();

        return [
            'card_id' => 'branch_lost_value',
            'title' => 'Lost Value (30 days)',
            'icon' => 'trending-down',
            'urgency' => $lostValue > 0 ? 'medium' : 'low',
            'count' => $lostValue > 0 ? 1 : 0,
            'items' => [[
                'value_display' => 'R ' . number_format($lostValue),
                'top_reasons' => $topReasons->map(fn($r) => [
                    'reason' => str_replace('_', ' ', $r->reason ?? 'Unknown'),
                    'count' => $r->cnt,
                ])->toArray(),
            ]],
            'view_all_url' => '/corex/command-center/lost-deals',
        ];
    }

    // ─── ADMIN CARD METHODS ─────────────────────────────────

    private function agencyHealthSnapshot(int $agencyId): array
    {
        $agents = DB::table('users')->where('agency_id', $agencyId)->where('is_active', true)->count();
        $listings = DB::table('properties')->where('agency_id', $agencyId)->where('status', 'available')->count();
        $activeBuyers = DB::table('contacts')->where('agency_id', $agencyId)->where('is_buyer', true)
            ->whereIn('buyer_state', ['new', 'warm'])->count();
        $lostValue30d = (int) DB::table('buyer_lost_records as l')
            ->join('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where('c.agency_id', $agencyId)
            ->where('l.created_at', '>=', now()->subDays(30))
            ->sum('l.preapproval_amount_at_loss');

        return [
            'card_id' => 'agency_health',
            'title' => 'Agency Snapshot',
            'icon' => 'bar-chart',
            'urgency' => 'low',
            'count' => 0,
            'items' => [[
                'agents' => $agents,
                'listings' => $listings,
                'active_buyers' => $activeBuyers,
                'lost_value_30d' => 'R ' . number_format($lostValue30d),
            ]],
            'view_all_url' => '/corex/command-center/reporting/agency',
            'always_visible' => true,
        ];
    }

    private function agencyComplianceFlags(int $agencyId): array
    {
        // Users with FFC expiring in 30 days
        $ffcExpiring = DB::table('users')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNotNull('ffc_expiry_date')
            ->where('ffc_expiry_date', '<=', now()->addDays(30))
            ->orderBy('ffc_expiry_date')
            ->limit(5)
            ->get(['id', 'name', 'ffc_expiry_date']);

        return [
            'card_id' => 'agency_compliance',
            'title' => 'Compliance Flags',
            'icon' => 'alert-circle',
            'urgency' => $ffcExpiring->count() > 0 ? 'critical' : 'low',
            'count' => $ffcExpiring->count(),
            'items' => $ffcExpiring->map(fn($u) => [
                'name' => $u->name,
                'label' => 'FFC expires ' . \Carbon\Carbon::parse($u->ffc_expiry_date)->format('d M Y'),
                'days_left' => (int) now()->startOfDay()->diffInDays($u->ffc_expiry_date, false),
            ])->toArray(),
            'view_all_url' => '/corex/compliance/fica',
        ];
    }

    private function strategicInsights(int $agencyId): array
    {
        $reportingService = app(\App\Services\ReportingService::class);
        $insights = $reportingService->getAgencyInsights($agencyId, 30);

        return [
            'card_id' => 'strategic_insights',
            'title' => 'Strategic Insights',
            'icon' => 'lightbulb',
            'urgency' => 'low',
            'count' => 0,
            'items' => array_map(fn($text) => ['text' => $text], array_slice($insights, 0, 5)),
            'view_all_url' => '/corex/command-center/reporting/agency',
            'always_visible' => true,
        ];
    }

    // ─── NEW P1 CARD METHODS ────────────────────────────────

    private function myFicaSubmissions(int $userId, int $agencyId): array
    {
        try {
            // FICA submissions where this user is the requesting agent and status needs their action
            $corrections = DB::table('fica_submissions')
                ->where('requested_by', $userId)
                ->where('agency_id', $agencyId)
                ->where('status', 'corrections_requested')
                ->whereNull('deleted_at')
                ->orderBy('updated_at')
                ->limit(5)
                ->get(['id', 'contact_id', 'status', 'updated_at']);

            // Also track submitted ones (pending CO review — informational)
            $pendingReview = DB::table('fica_submissions')
                ->where('requested_by', $userId)
                ->where('agency_id', $agencyId)
                ->whereIn('status', ['submitted', 'under_review'])
                ->whereNull('deleted_at')
                ->count();

            $contactIds = $corrections->pluck('contact_id')->filter()->toArray();
            $names = !empty($contactIds)
                ? DB::table('contacts')->whereIn('id', $contactIds)->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id')
                : collect();

            $items = $corrections->map(fn($f) => [
                'id' => $f->id,
                'contact' => $names[$f->contact_id] ?? 'Contact',
                'status' => 'Corrections requested',
                'urgency_label' => 'Action needed',
            ])->toArray();

            // Add informational count
            if ($pendingReview > 0) {
                $items[] = ['id' => 0, 'contact' => '', 'status' => "{$pendingReview} pending CO review", 'urgency_label' => 'Tracking'];
            }
        } catch (\Throwable $e) {
            $items = [];
            $corrections = collect();
        }

        return [
            'card_id' => 'my_fica_submissions',
            'title' => 'My FICA Submissions',
            'icon' => 'shield-check',
            'urgency' => $corrections->count() > 0 ? 'high' : 'low',
            'count' => $corrections->count(),
            'items' => $items,
            'view_all_url' => '/corex/compliance/fica',
        ];
    }

    private function myDealSteps(int $userId, int $agencyId): array
    {
        try {
            $user = \App\Models\User::withoutGlobalScopes()->find($userId);
            $role = $user?->effectiveRole() ?? 'agent';
            $isAdmin = in_array($role, ['admin', 'super_admin', 'owner'], true);

            $steps = DB::table('deal_step_instances as dsi')
                ->join('deals_v2 as d', 'd.id', '=', 'dsi.deal_id')
                ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
                ->where('b.agency_id', $agencyId)
                ->where('d.status', 'active')
                ->whereNull('d.deleted_at')
                ->whereNull('dsi.deleted_at')
                ->whereIn('dsi.status', ['active', 'not_started'])
                ->whereNotNull('dsi.due_date')
                ->when(!$isAdmin, function ($q) use ($userId) {
                    $q->where(function ($q2) use ($userId) {
                        $q2->where('d.listing_agent_id', $userId)
                           ->orWhere('d.selling_agent_id', $userId)
                           ->orWhereIn('d.id', function ($sub) use ($userId) {
                               $sub->select('deal_id')->from('deal_v2_agents')->where('user_id', $userId);
                           });
                    });
                })
                ->orderBy('dsi.due_date')
                ->limit(5)
                ->get(['dsi.id', 'dsi.name as step_name', 'dsi.status', 'dsi.due_date', 'd.reference']);
        } catch (\Throwable $e) {
            $steps = collect();
        }

        return [
            'card_id' => 'my_deal_steps',
            'title' => 'Deal Steps',
            'icon' => 'clipboard-check',
            'urgency' => 'high',
            'count' => $steps->count(),
            'items' => $steps->map(fn($s) => [
                'id' => $s->id,
                'title' => $s->step_name ?? 'Step',
                'deal_ref' => $s->reference ?? '',
                'status' => str_replace('_', ' ', $s->status),
                'due' => $s->due_date ? \Carbon\Carbon::parse($s->due_date)->format('d M') : '',
            ])->toArray(),
            'view_all_url' => '/deals-v2',
        ];
    }

    private function myLeaveApplications(int $userId): array
    {
        try {
            $pending = DB::table('leave_applications')
                ->where('user_id', $userId)
                ->whereIn('status', ['draft', 'submitted'])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'status', 'start_date', 'end_date', 'created_at']);
        } catch (\Throwable $e) {
            $pending = collect();
        }

        return [
            'card_id' => 'my_leave',
            'title' => 'My Leave Applications',
            'icon' => 'calendar',
            'urgency' => 'medium',
            'count' => $pending->count(),
            'items' => $pending->map(fn($l) => [
                'id' => $l->id,
                'status' => ucfirst($l->status),
                'dates' => \Carbon\Carbon::parse($l->start_date)->format('d M') . ' – ' . \Carbon\Carbon::parse($l->end_date)->format('d M'),
            ])->toArray(),
            'view_all_url' => '/corex/my-portal/leave',
        ];
    }

    private function leaveAwaitingApproval(int $branchId, int $agencyId): array
    {
        try {
            $pending = DB::table('leave_applications as la')
                ->join('users as u', 'u.id', '=', 'la.user_id')
                ->where('u.branch_id', $branchId)
                ->where('u.agency_id', $agencyId)
                ->where('la.status', 'submitted')
                ->orderBy('la.created_at')
                ->limit(5)
                ->get(['la.id', 'u.name', 'la.start_date', 'la.end_date', 'la.created_at']);
        } catch (\Throwable $e) {
            $pending = collect();
        }

        return [
            'card_id' => 'leave_approvals',
            'title' => 'Leave Awaiting Approval',
            'icon' => 'calendar',
            'urgency' => 'high',
            'count' => $pending->count(),
            'items' => $pending->map(fn($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'dates' => \Carbon\Carbon::parse($l->start_date)->format('d M') . ' – ' . \Carbon\Carbon::parse($l->end_date)->format('d M'),
                'waiting' => \Carbon\Carbon::parse($l->created_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/corex/my-portal/leave',
        ];
    }

    private function salesDocsAwaitingReturn(int $userId, int $agencyId): array
    {
        try {
            $pending = DB::table('sales_document_sends as sds')
                ->where('sds.sender_user_id', $userId)
                ->whereIn('sds.status', ['sent', 'acknowledged'])
                ->orderBy('sds.created_at')
                ->limit(5)
                ->get(['sds.id', 'sds.subject', 'sds.status', 'sds.created_at']);
        } catch (\Throwable $e) {
            $pending = collect();
        }

        return [
            'card_id' => 'sales_docs_return',
            'title' => 'Documents Awaiting Return',
            'icon' => 'file-signature',
            'urgency' => 'medium',
            'count' => $pending->count(),
            'items' => $pending->map(fn($d) => [
                'id' => $d->id,
                'title' => $d->subject ?? 'Document',
                'status' => ucfirst(str_replace('_', ' ', $d->status)),
                'sent' => \Carbon\Carbon::parse($d->created_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/docuperfect/esign/my-documents',
        ];
    }

    private function myTraining(int $userId): array
    {
        try {
            // Required courses not yet completed
            $incomplete = DB::table('training_courses as tc')
                ->where('tc.is_required', true)
                ->where('tc.is_published', true)
                ->whereNotExists(function ($q) use ($userId) {
                    $q->select(DB::raw(1))
                      ->from('training_completions')
                      ->whereColumn('training_completions.course_id', 'tc.id')
                      ->where('training_completions.user_id', $userId);
                })
                ->orderBy('tc.title')
                ->limit(5)
                ->get(['tc.id', 'tc.title']);

            // Expiring completions (within 30 days)
            $expiring = DB::table('training_completions as tp')
                ->join('training_courses as tc', 'tc.id', '=', 'tp.course_id')
                ->where('tp.user_id', $userId)
                ->whereNotNull('tp.expires_at')
                ->where('tp.expires_at', '<=', now()->addDays(30))
                ->orderBy('tp.expires_at')
                ->limit(3)
                ->get(['tc.id', 'tc.title', 'tp.expires_at']);
        } catch (\Throwable $e) {
            $incomplete = collect();
            $expiring = collect();
        }

        $items = collect();
        foreach ($incomplete as $c) {
            $items->push(['id' => $c->id, 'title' => $c->title, 'reason' => 'Not completed', 'critical' => false]);
        }
        foreach ($expiring as $c) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($c->expires_at, false);
            $items->push(['id' => $c->id, 'title' => $c->title, 'reason' => $daysLeft <= 0 ? 'EXPIRED' : "Expires in {$daysLeft}d", 'critical' => $daysLeft <= 0]);
        }

        return [
            'card_id' => 'my_training',
            'title' => 'Training & Qualifications',
            'icon' => 'lightbulb',
            'urgency' => $items->contains('critical', true) ? 'critical' : ($items->count() > 0 ? 'medium' : 'low'),
            'count' => $items->count(),
            'items' => $items->take(5)->values()->toArray(),
            'view_all_url' => '/corex/training',
        ];
    }

    private function eventsNeedingFeedback(int $userId): array
    {
        // Completed events (last 7 days) where user is owner AND event class has completion_behaviour requiring feedback BUT no feedback captured
        try {
            $events = CalendarEvent::withoutGlobalScopes()
                ->where('user_id', $userId)
                ->where('status', 'completed')
                ->where('event_date', '>=', now()->subDays(7))
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('calendar_event_feedback')
                      ->whereColumn('calendar_event_feedback.event_id', 'calendar_events.id');
                })
                ->orderByDesc('event_date')
                ->limit(5)
                ->get(['id', 'title', 'event_date', 'category']);

            // Filter to only classes that require feedback
            $feedbackClasses = CalendarEventClassSetting::withoutGlobalScopes()
                ->where('completion_behaviour', 'require_feedback')
                ->pluck('event_class')
                ->toArray();

            $filtered = $events->filter(fn($e) => in_array($e->category, $feedbackClasses));
        } catch (\Throwable $e) {
            $filtered = collect();
        }

        return [
            'card_id' => 'events_feedback',
            'title' => 'Feedback Not Captured',
            'icon' => 'alert-circle',
            'urgency' => 'high',
            'count' => $filtered->count(),
            'items' => $filtered->map(fn($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'date' => $e->event_date->format('D d M'),
            ])->values()->toArray(),
            'view_all_url' => route('command-center.calendar'),
        ];
    }

    private function esignSentAwaitingOthers(int $userId): array
    {
        try {
            $pending = DB::table('signature_templates')
                ->where('created_by', $userId)
                ->whereIn('status', ['signing', 'awaiting_seller', 'awaiting_lessor', 'awaiting_lessee', 'awaiting_tenant', 'awaiting_landlord'])
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'status', 'created_at']);
        } catch (\Throwable $e) {
            $pending = collect();
        }

        return [
            'card_id' => 'esign_awaiting_others',
            'title' => 'E-Sign Awaiting Other Parties',
            'icon' => 'file-signature',
            'urgency' => 'medium',
            'count' => $pending->count(),
            'items' => $pending->map(fn($d) => [
                'id' => $d->id,
                'title' => 'Document #' . $d->id,
                'status' => ucfirst(str_replace('_', ' ', $d->status)),
                'created' => \Carbon\Carbon::parse($d->created_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/docuperfect/esign/my-documents',
        ];
    }

    // ─── NEW EMPIRICAL CARDS ────────────────────────────────

    private function activeBuyerPipeline(int $userId, int $agencyId): array
    {
        // Layer 3: Pipeline workspace scope — reads agency's buyer_pipeline_default_scope
        $user = \App\Models\User::withoutGlobalScopes()->find($userId);
        $role = $user?->effectiveRole() ?? 'agent';
        $isAdmin = in_array($role, ['admin', 'super_admin', 'owner'], true);

        $settings = \App\Models\AgencyContactSettings::forAgency($agencyId);
        $pipelineScope = $isAdmin ? 'agency' : ($settings->buyer_pipeline_default_scope ?? 'own');

        $baseQuery = fn() => DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->where('is_buyer', 1)
            ->whereNull('deleted_at');

        // Apply Layer 3 scope
        $scopeFilter = function ($q) use ($pipelineScope, $userId, $user) {
            if ($pipelineScope === 'own') {
                $q->where('created_by_user_id', $userId);
            } elseif ($pipelineScope === 'branch' && $user?->branch_id) {
                $q->whereIn('created_by_user_id', function ($sub) use ($user) {
                    $sub->select('id')->from('users')->where('branch_id', $user->branch_id)->whereNull('deleted_at');
                });
            }
            // 'agency' = no additional filter
        };

        $warm = (clone $baseQuery())->where('buyer_state', 'warm')->where($scopeFilter)->count();
        $cold = (clone $baseQuery())->where('buyer_state', 'cold')->where($scopeFilter)->count();
        $newBuyers = (clone $baseQuery())->where('buyer_state', 'new')->where($scopeFilter)->count();
        $total = $warm + $cold + $newBuyers;

        $scopeLabels = ['own' => 'Mine', 'branch' => 'Branch', 'agency' => 'Agency'];

        return [
            'card_id' => 'active_buyer_pipeline',
            'title' => 'Buyer Pipeline (' . ($scopeLabels[$pipelineScope] ?? 'Mine') . ')',
            'icon' => 'users',
            'urgency' => $cold > 0 ? 'high' : 'medium',
            'count' => $total,
            'items' => [
                ['label' => 'Warm buyers', 'value' => $warm, 'colour' => '#f59e0b'],
                ['label' => 'Cold buyers', 'value' => $cold, 'colour' => '#3b82f6'],
                ['label' => 'New buyers', 'value' => $newBuyers, 'colour' => '#10b981'],
            ],
            'view_all_url' => '/corex/command-center/buyers/pipeline?scope=' . $pipelineScope,
        ];
    }

    private function myProspectingClaims(int $userId): array
    {
        try {
            $claims = DB::table('prospecting_claims as pc')
                ->join('prospecting_listings as pl', 'pl.id', '=', 'pc.prospecting_listing_id')
                ->where('pc.user_id', $userId)
                ->where('pc.is_active', 1)
                ->whereNull('pc.deleted_at')
                ->orderByDesc('pc.claimed_at')
                ->limit(5)
                ->get(['pc.id', 'pl.address', 'pl.suburb', 'pl.price', 'pc.claimed_at', 'pc.status']);
        } catch (\Throwable $e) {
            $claims = collect();
        }

        $totalCount = DB::table('prospecting_claims')
            ->where('user_id', $userId)->where('is_active', 1)->whereNull('deleted_at')->count();

        return [
            'card_id' => 'prospecting_claims',
            'title' => 'Prospecting Claims Active',
            'icon' => 'eye',
            'urgency' => 'medium',
            'count' => $totalCount,
            'items' => $claims->map(fn($c) => [
                'id' => $c->id,
                'title' => $c->address ?: ($c->suburb ?? 'Listing'),
                'price' => $c->price ? 'R ' . number_format($c->price) : '',
                'claimed' => \Carbon\Carbon::parse($c->claimed_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/prospecting?claim_filter=my_claims',
        ];
    }

    private function draftPresentations(int $userId): array
    {
        try {
            $drafts = DB::table('presentations')
                ->where('created_by_user_id', $userId)
                ->where('status', 'draft')
                ->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(['id', 'title', 'property_address', 'updated_at']);
        } catch (\Throwable $e) {
            $drafts = collect();
        }

        return [
            'card_id' => 'draft_presentations',
            'title' => 'Draft Presentations',
            'icon' => 'bar-chart',
            'urgency' => 'low',
            'count' => $drafts->count(),
            'items' => $drafts->map(fn($p) => [
                'id' => $p->id,
                'title' => $p->title ?: ($p->property_address ?: 'Presentation'),
                'updated' => \Carbon\Carbon::parse($p->updated_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/presentations',
        ];
    }

    private function unreadNotifications(int $userId): array
    {
        $count = DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->count();

        $recent = DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'type', 'data', 'created_at']);

        return [
            'card_id' => 'unread_notifications',
            'title' => 'Unread Notifications',
            'icon' => 'mail',
            'urgency' => $count > 5 ? 'high' : 'medium',
            'count' => $count,
            'items' => $recent->map(fn($n) => [
                'id' => $n->id,
                'message' => json_decode($n->data, true)['message'] ?? str_replace('_', ' ', class_basename($n->type)),
                'when' => \Carbon\Carbon::parse($n->created_at)->diffForHumans(),
            ])->toArray(),
            'view_all_url' => '/corex/notifications',
        ];
    }

    private function prospectingBuyerMatched(int $agencyId): array
    {
        try {
            $matchedCount = DB::table('prospecting_buyer_matches as m')
                ->join('prospecting_listings as pl', 'pl.id', '=', 'm.prospecting_listing_id')
                ->where('pl.agency_id', $agencyId)
                ->where('pl.is_active', 1)
                ->whereNull('m.dismissed_at')
                ->distinct('m.prospecting_listing_id')
                ->count('m.prospecting_listing_id');

            $topMatches = DB::table('prospecting_buyer_matches as m')
                ->join('prospecting_listings as pl', 'pl.id', '=', 'm.prospecting_listing_id')
                ->where('pl.agency_id', $agencyId)
                ->where('pl.is_active', 1)
                ->whereNull('m.dismissed_at')
                ->select('pl.id', 'pl.address', 'pl.suburb', 'pl.price',
                    DB::raw('COUNT(m.id) as match_count'),
                    DB::raw('MAX(m.score) as top_score'))
                ->groupBy('pl.id', 'pl.address', 'pl.suburb', 'pl.price')
                ->orderByDesc('match_count')
                ->limit(5)
                ->get();
        } catch (\Throwable $e) {
            $matchedCount = 0;
            $topMatches = collect();
        }

        return [
            'card_id' => 'prospecting_buyer_matched',
            'title' => 'Prospecting — Buyer Matched',
            'icon' => 'eye',
            'urgency' => $matchedCount >= 10 ? 'high' : 'medium',
            'count' => $matchedCount,
            'items' => $topMatches->map(fn($m) => [
                'id' => $m->id,
                'title' => $m->address ?: ($m->suburb ?? 'Listing'),
                'match_count' => $m->match_count,
                'top_score' => $m->top_score,
                'price' => $m->price ? 'R ' . number_format($m->price) : '',
            ])->toArray(),
            'view_all_url' => '/prospecting?matched_only=1',
        ];
    }

    // ─── UNIFIED CARDS ──────────────────────────────────────

    private function esignActivity(int $userId): array
    {
        // Sub-count 1: Needs my approval
        try {
            $needsApproval = DB::table('signature_templates')
                ->where('created_by', $userId)
                ->where('status', 'pending_agent_approval')
                ->whereNull('deleted_at')
                ->count();
        } catch (\Throwable $e) { $needsApproval = 0; }

        // Sub-count 2: Awaiting signatures (ready — sent to signers)
        try {
            $awaitingSignatures = DB::table('signature_templates')
                ->where('created_by', $userId)
                ->where('status', 'ready')
                ->whereNull('deleted_at')
                ->count();
        } catch (\Throwable $e) { $awaitingSignatures = 0; }

        // Sub-count 3: Awaiting other parties (signing in progress)
        try {
            $awaitingOthers = DB::table('signature_templates')
                ->where('created_by', $userId)
                ->whereIn('status', ['signing', 'awaiting_seller', 'awaiting_lessor', 'awaiting_lessee', 'awaiting_tenant', 'awaiting_landlord'])
                ->whereNull('deleted_at')
                ->count();
        } catch (\Throwable $e) { $awaitingOthers = 0; }

        $total = $needsApproval + $awaitingSignatures + $awaitingOthers;
        $urgency = $needsApproval > 0 ? 'critical' : ($awaitingSignatures > 0 ? 'high' : 'medium');

        return [
            'card_id' => 'esign_activity',
            'title' => 'E-Sign Activity',
            'icon' => 'file-signature',
            'urgency' => $urgency,
            'count' => $total,
            'items' => array_filter([
                $needsApproval > 0 ? ['label' => 'Need approval', 'value' => $needsApproval, 'colour' => '#ef4444'] : null,
                $awaitingSignatures > 0 ? ['label' => 'Awaiting signatures', 'value' => $awaitingSignatures, 'colour' => '#f59e0b'] : null,
                $awaitingOthers > 0 ? ['label' => 'Awaiting others', 'value' => $awaitingOthers, 'colour' => '#64748b'] : null,
            ]),
            'view_all_url' => '/docuperfect/esign/my-documents',
        ];
    }

    private function prospectingActivity(int $userId, int $agencyId): array
    {
        // Sub-count 1: My active claims
        $claims = DB::table('prospecting_claims')
            ->where('user_id', $userId)->where('is_active', 1)->whereNull('deleted_at')->count();

        // Sub-count 2: Buyer-matched listings (agency-wide)
        try {
            $matched = DB::table('prospecting_buyer_matches as m')
                ->join('prospecting_listings as pl', 'pl.id', '=', 'm.prospecting_listing_id')
                ->where('pl.agency_id', $agencyId)
                ->where('pl.is_active', 1)
                ->whereNull('m.dismissed_at')
                ->distinct('m.prospecting_listing_id')
                ->count('m.prospecting_listing_id');
        } catch (\Throwable $e) { $matched = 0; }

        $total = $claims + ($matched > 0 ? 1 : 0); // Count as "has matched" not raw number
        $urgency = $claims > 0 ? 'medium' : ($matched > 0 ? 'medium' : 'low');

        return [
            'card_id' => 'prospecting_activity',
            'title' => 'Prospecting',
            'icon' => 'eye',
            'urgency' => $urgency,
            'count' => $claims + $matched,
            'items' => array_filter([
                $claims > 0 ? ['label' => 'Active claims', 'value' => $claims, 'colour' => '#0ea5e9'] : null,
                $matched > 0 ? ['label' => 'Buyer-matched listings', 'value' => $matched > 99 ? '99+' : $matched, 'colour' => '#10b981'] : null,
            ]),
            'view_all_url' => '/prospecting',
        ];
    }
}
