<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\PortalLead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalLeadController extends Controller
{
    public function index(Request $request): View
    {
        $query = PortalLead::query()
            ->visibleTo($request->user())
            ->with([
                // pp_second_agent_id + listing.agent power the AT-308 filter (listing's
                // agent) and the "Agent" column; contact.created_at + last_contacted_at
                // power the cross-agent badge's keep-vs-move dates.
                'listing:id,title,agent_id,pp_second_agent_id,unit_number,complex_name,street_number,street_name,address,suburb,city',
                'listing.agent:id,name',
                'contact:id,first_name,last_name,email,phone,created_by_user_id,created_at,last_contacted_at',
                'existingContactAgent:id,name',
            ])
            ->orderByDesc('received_at');

        if ($portal = $request->get('portal')) {
            if (in_array($portal, [PortalLead::PORTAL_P24, PortalLead::PORTAL_PP, PortalLead::PORTAL_WEBSITE], true)) {
                $query->where('portal', $portal);
            }
        }

        if ($from = $request->get('from')) {
            $query->where('received_at', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->where('received_at', '<=', $to . ' 23:59:59');
        }

        if ($agentId = $request->get('agent_id')) {
            // AT-308 — Johan ruling (a): "leads for agent X" = every enquiry on
            // agent X's LISTINGS / stock. Bind STRICTLY to the listing's agent —
            // the primary OR the co-listing second agent (mirroring
            // PortalLead::agentIds() and scopeVisibleTo) — and NOT the enquiring
            // contact's existing agent. The old contact-agent OR-branch over-matched:
            // a lead on X's listing whose buyer already belonged to Y matched the
            // "X" filter yet the row rendered "Y", so the filter looked broken.
            // The buyer-belongs-to-another-agent signal now lives on the row as the
            // cross-agent badge, where it informs a keep-vs-move decision instead of
            // silently widening the filter.
            $query->whereHas('listing', fn ($lq) => $lq
                ->where('agent_id', $agentId)
                ->orWhere('pp_second_agent_id', $agentId));
        }

        if (($status = $request->get('status')) !== null && $status !== '') {
            $query->where('contact_exists', $status === 'existing');
        }

        $leads = $query->paginate(25)->withQueryString();

        $agents = User::query()->orderBy('name')->get(['id', 'name']);

        return view('corex.portal-leads.index', [
            'leads'    => $leads,
            'agents'   => $agents,
            'filters'  => $request->only(['portal', 'from', 'to', 'agent_id', 'status']),
        ]);
    }

    /**
     * JSON endpoint for the Alpine toast poller — returns leads the current
     * user may see (their own, or wider per the Portal Leads Data Scope) that
     * have not yet been shown (notified_at IS NULL).
     */
    public function poll(Request $request): JsonResponse
    {
        $sinceParam = $request->get('since');
        $unshown = PortalLead::query()
            ->visibleTo($request->user())
            ->whereNull('notified_at')
            ->when($sinceParam, fn ($q) => $q->where('received_at', '>=', $sinceParam))
            ->orderByDesc('received_at')
            ->limit(10)
            ->get(['id', 'portal', 'lead_type', 'name', 'phone', 'email', 'listing_id', 'listing_portal_ref', 'contact_exists', 'received_at']);

        return response()->json([
            'leads' => $unshown->map(fn ($l) => [
                'id'                  => $l->id,
                'portal'              => $l->portal,
                'portal_label'        => $l->portalLabel(),
                'lead_type'           => $l->lead_type,
                'name'                => $l->name,
                'phone'               => $l->phone,
                'email'               => $l->email,
                'listing_id'          => $l->listing_id,
                'listing_portal_ref'  => $l->listing_portal_ref,
                'contact_exists'      => $l->contact_exists,
                'received_at'         => optional($l->received_at)->toIso8601String(),
                'view_url'            => route('corex.portal-leads.index', ['highlight' => $l->id]),
            ])->all(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function markNotified(PortalLead $portalLead): JsonResponse
    {
        if (! $portalLead->notified_at) {
            $portalLead->notified_at = now();
            $portalLead->save();
        }
        return response()->json(['ok' => true]);
    }
}
