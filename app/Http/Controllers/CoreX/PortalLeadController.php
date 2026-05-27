<?php

namespace App\Http\Controllers\CoreX;

use App\Events\Leads\NewPortalLeadReceived;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalLeadController extends Controller
{
    public function index(Request $request): View
    {
        $query = PortalLead::query()
            ->with(['listing:id,title,agent_id', 'contact:id,first_name,last_name,email,phone,created_by_user_id', 'existingContactAgent:id,name'])
            ->orderByDesc('received_at');

        if ($portal = $request->get('portal')) {
            if (in_array($portal, [PortalLead::PORTAL_P24, PortalLead::PORTAL_PP], true)) {
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
     * JSON endpoint for the Alpine toast poller — returns leads received in
     * the current agency that have not yet been shown (notified_at IS NULL).
     */
    public function poll(Request $request): JsonResponse
    {
        $sinceParam = $request->get('since');
        $unshown = PortalLead::query()
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

    /**
     * Inject a synthetic portal lead targeted at the given agent, then fire
     * NewPortalLeadReceived so the in-app toast poller and FCM push listener
     * both run against it. Used by admins to verify the popup + mobile
     * notification path end-to-end without waiting for a real P24/PP lead.
     */
    public function sendTestLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => 'required|integer|exists:users,id',
            'portal'   => 'nullable|in:p24,pp',
        ]);

        $actor = $request->user();
        if (! $actor) {
            return response()->json(['ok' => false, 'message' => 'Not authenticated.'], 422);
        }

        // Owner-role users with no active agency switcher have a NULL agency_id.
        // Fall back to the target agent's agency so the test still works without
        // forcing the owner to switch.
        $agent = User::query()->withoutGlobalScopes()->find($data['agent_id']);
        if (! $agent || ! $agent->agency_id) {
            return response()->json(['ok' => false, 'message' => 'Agent not found or has no agency.'], 422);
        }

        $actorAgencyId = method_exists($actor, 'effectiveAgencyId')
            ? $actor->effectiveAgencyId()
            : $actor->agency_id;

        $isOwner = method_exists($actor, 'isOwnerRole') && $actor->isOwnerRole();
        if (! $isOwner && $actorAgencyId && (int) $agent->agency_id !== (int) $actorAgencyId) {
            return response()->json(['ok' => false, 'message' => 'Agent not in your agency.'], 422);
        }

        $agencyId = (int) $agent->agency_id;

        $listingId = Property::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('agent_id', $agent->id)
            ->orderByDesc('id')
            ->value('id');

        $lead = new PortalLead([
            'agency_id'                 => $agencyId,
            'portal'                    => $data['portal'] ?? PortalLead::PORTAL_P24,
            'lead_type'                 => 'Test',
            'listing_id'                => $listingId,
            'listing_portal_ref'        => 'TEST-' . now()->format('His'),
            'contact_id'                => null,
            'contact_exists'            => false,
            'existing_contact_agent_id' => $agent->id,
            'name'                      => 'TEST LEAD — ' . $agent->name,
            'email'                     => 'test+lead@corexos.co.za',
            'phone'                     => '+27 000 000 000',
            'message'                   => 'This is a test lead sent from the Portal Leads admin to verify the popup + mobile push pipeline.',
            'is_whatsapp'               => false,
            'lead_source_raw'           => ['__test' => true, 'sent_by_user_id' => $actor->id],
            'received_at'               => now(),
        ]);
        $lead->agency_id = $agencyId;
        $lead->save();

        // Push diagnostics — gather BEFORE firing the event so we can tell the
        // user exactly why mobile push will / won't reach them.
        $agentDeviceCount   = DeviceToken::query()->where('user_id', $agent->id)->count();
        $agencyDeviceCount  = DeviceToken::query()
            ->whereIn('user_id', User::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->pluck('id'))
            ->count();
        $fcmClassExists     = class_exists(\App\Services\Push\FcmService::class);
        $fcmMessagingBound  = false;
        if ($fcmClassExists) {
            try {
                app(\Kreait\Firebase\Contract\Messaging::class);
                $fcmMessagingBound = true;
            } catch (\Throwable) {
                $fcmMessagingBound = false;
            }
        }

        event(new NewPortalLeadReceived($lead));

        $pushReadiness = ($agentDeviceCount > 0) && $fcmClassExists && $fcmMessagingBound;
        $pushBlocker = null;
        if (!$fcmClassExists)      $pushBlocker = 'FcmService class missing — package not installed.';
        elseif (!$fcmMessagingBound) $pushBlocker = 'Firebase Messaging not configured — check FIREBASE_CREDENTIALS / service provider binding.';
        elseif ($agentDeviceCount === 0) $pushBlocker = "Agent has 0 device tokens registered. They must log into the mobile app to register a device.";

        return response()->json([
            'ok'         => true,
            'lead_id'    => $lead->id,
            'message'    => $pushReadiness
                ? "Test lead sent to {$agent->name}. Popup within ~10s. Push fired to {$agentDeviceCount} device(s)."
                : "Test lead saved (popup will appear within ~10s). Push NOT delivered: {$pushBlocker}",
            'diagnostics' => [
                'agent_device_tokens'  => $agentDeviceCount,
                'agency_device_tokens' => $agencyDeviceCount,
                'fcm_class_exists'     => $fcmClassExists,
                'fcm_messaging_bound'  => $fcmMessagingBound,
            ],
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
