<?php

namespace App\Http\Controllers\Rental;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\LeaseRecord;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Http\Request;

class RentalDivisionController extends Controller
{
    public function __construct(private SignatureService $signatureService)
    {
    }

    /**
     * Rental Division dashboard — high-level overview with metric tiles.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $data = $this->signatureService->getRentalDashboardData($user);

        $counts = [
            'needs_approval'      => $data['counts']['pending_approval'],
            'draft'               => $data['counts']['draft'],
            'ready_to_sign'       => $data['counts']['ready_to_sign'],
            'in_progress'         => $data['counts']['awaiting_signatures'],
            'completed'           => $data['counts']['completed'],
            'active_leases'       => $data['activeLeaseCount'],
            'expiring_soon'       => $data['upcomingRenewals']->count(),
        ];

        return view('rental.dashboard', compact('counts'));
    }

    /**
     * Electronic Signatures — reuses existing rental dashboard data + view.
     */
    public function signatures(Request $request)
    {
        $user = $request->user();
        $data = $this->signatureService->getRentalDashboardData($user);

        return view('rental.signatures', [
            'groups'             => $data['groups'],
            'signatureTemplates' => $data['signatureTemplates'],
            'fieldStatus'        => $data['fieldStatus'],
            'counts'             => $data['counts'],
            'upcomingRenewals'   => $data['upcomingRenewals'],
            'expiredLeases'      => $data['expiredLeases'],
            'activeLeases'       => $data['activeLeases'],
            'activeLeaseCount'   => $data['activeLeaseCount'],
            'lastUpdate'         => $data['lastUpdate'] ?? '',
            'user'               => $user,
        ]);
    }

    /**
     * Active Leases — completed signed leases currently active.
     */
    public function activeLeases(Request $request)
    {
        $user = $request->user();

        $leases = LeaseRecord::visibleTo($user)
            ->whereIn('status', [LeaseRecord::STATUS_ACTIVE, LeaseRecord::STATUS_EXPIRING_SOON])
            ->with(['document', 'signatureTemplate'])
            ->orderBy('lease_end_date')
            ->get();

        return view('rental.active-leases', compact('leases'));
    }

    /**
     * Expired Leases.
     */
    public function expiredLeases(Request $request)
    {
        $user = $request->user();

        $leases = LeaseRecord::visibleTo($user)
            ->where('status', LeaseRecord::STATUS_EXPIRED)
            ->with(['document', 'signatureTemplate'])
            ->orderByDesc('lease_end_date')
            ->get();

        return view('rental.expired-leases', compact('leases'));
    }

    /**
     * Rental Settings — placeholder.
     */
    public function settings()
    {
        return view('rental.settings');
    }
}
