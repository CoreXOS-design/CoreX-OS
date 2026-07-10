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
            ->with(['listing:id,title,agent_id,unit_number,complex_name,street_number,street_name,address,suburb,city', 'contact:id,first_name,last_name,email,phone,created_by_user_id', 'existingContactAgent:id,name'])
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
            $query->where(function ($q) use ($agentId) {
                $q->whereHas('listing', fn ($lq) => $lq->where('agent_id', $agentId))
                  ->orWhere('existing_contact_agent_id', $agentId);
            });
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
