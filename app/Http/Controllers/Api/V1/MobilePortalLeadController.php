<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PortalLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Mobile API surface for Portal Leads.
 *
 * Mirrors the web `/corex/real-estate/portal-leads` controller but returns
 * JSON for the CoreX mobile app. Agency isolation is enforced structurally
 * via the AgencyScope global scope on the PortalLead model.
 *
 * Spec: .ai/specs/portal-leads.md
 */
class MobilePortalLeadController extends Controller
{
    /**
     * GET /api/v1/mobile/portal-leads
     *
     * Query params:
     *   date      YYYY-MM-DD  (default = today, agency timezone)
     *   portal    p24|pp      (optional)
     *   page      int         (default 1)
     *   per_page  int 1..100  (default 25)
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'     => 'sometimes|date_format:Y-m-d',
            'portal'   => 'sometimes|in:p24,pp',
            'page'     => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $date = isset($data['date'])
            ? Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay()
            : Carbon::today();

        $query = PortalLead::query()
            ->with([
                'listing:id,title,agent_id',
                'contact:id,first_name,last_name,email,phone',
                'existingContactAgent:id,name',
            ])
            ->whereBetween('received_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderByDesc('received_at');

        if (! empty($data['portal'])) {
            $query->where('portal', $data['portal']);
        }

        $perPage = (int) ($data['per_page'] ?? 25);
        $leads   = $query->paginate($perPage);

        return response()->json([
            'date'   => $date->toDateString(),
            'total'  => $leads->total(),
            'page'   => $leads->currentPage(),
            'pages'  => $leads->lastPage(),
            'leads'  => $leads->getCollection()->map(fn (PortalLead $l) => $this->transform($l))->all(),
        ]);
    }

    /**
     * GET /api/v1/mobile/portal-leads/dates
     *
     * Returns the last 30 days that have ≥1 lead, with counts and unread totals.
     * Lets the mobile UI render a "previous days" picker.
     */
    public function dates(Request $request): JsonResponse
    {
        $since = Carbon::today()->subDays(30);

        $rows = PortalLead::query()
            ->selectRaw('DATE(received_at) as d, COUNT(*) as total, SUM(CASE WHEN notified_at IS NULL THEN 1 ELSE 0 END) as unread')
            ->where('received_at', '>=', $since)
            ->groupBy('d')
            ->orderByDesc('d')
            ->get();

        return response()->json([
            'dates' => $rows->map(fn ($r) => [
                'date'   => (string) $r->d,
                'total'  => (int) $r->total,
                'unread' => (int) $r->unread,
            ])->all(),
        ]);
    }

    /** GET /api/v1/mobile/portal-leads/{portalLead} */
    public function show(PortalLead $portalLead): JsonResponse
    {
        $portalLead->load([
            'listing:id,title,agent_id',
            'contact:id,first_name,last_name,email,phone',
            'existingContactAgent:id,name',
        ]);

        return response()->json([
            'lead' => $this->transform($portalLead, full: true),
        ]);
    }

    /** POST /api/v1/mobile/portal-leads/{portalLead}/mark-read */
    public function markRead(PortalLead $portalLead): JsonResponse
    {
        if (! $portalLead->notified_at) {
            $portalLead->notified_at = now();
            $portalLead->save();
        }

        return response()->json(['ok' => true]);
    }

    private function transform(PortalLead $l, bool $full = false): array
    {
        $base = [
            'id'                  => $l->id,
            'portal'              => $l->portal,
            'portal_label'        => $l->portalLabel(),
            'lead_type'           => $l->lead_type,
            'name'                => $l->name,
            'email'               => $l->email,
            'phone'               => $l->phone,
            'is_whatsapp'         => (bool) $l->is_whatsapp,
            'listing_id'          => $l->listing_id,
            'listing_portal_ref'  => $l->listing_portal_ref,
            'listing_title'       => $l->listing?->title,
            'contact_id'          => $l->contact_id,
            'contact_exists'      => (bool) $l->contact_exists,
            'existing_agent'      => $l->existingContactAgent?->name,
            'received_at'         => optional($l->received_at)->toIso8601String(),
            'notified_at'         => optional($l->notified_at)->toIso8601String(),
            'is_unread'           => $l->notified_at === null,
        ];

        if ($full) {
            $base['message']         = $l->message;
            $base['lead_source_raw'] = $l->lead_source_raw;
        }

        return $base;
    }
}
