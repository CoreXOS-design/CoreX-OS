<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\RmcpAcknowledgement;
use App\Models\Compliance\RmcpVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RmcpDashboardController extends Controller
{
    public function index(Request $request)
    {
        $agencyId = Auth::user()->effectiveAgencyId();
        $agency = Agency::findOrFail($agencyId);
        $activeVersion = RmcpVersion::where('agency_id', $agencyId)->active()->first();

        // Active staff for this agency
        $staff = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $totalStaff = $staff->count();

        // Build staff status list
        $staffData = [];
        $validCount = 0;
        $inProgressCount = 0;
        $expiredCount = 0;
        $neverStartedCount = 0;
        $expiringSoonCount = 0;

        foreach ($staff as $user) {
            $ack = null;
            $status = 'no_rmcp';

            if ($activeVersion) {
                $ack = RmcpAcknowledgement::where('user_id', $user->id)
                    ->where('rmcp_version_id', $activeVersion->id)
                    ->whereIn('status', ['in_progress', 'completed'])
                    ->latest()
                    ->first();

                if (!$ack) {
                    $status = 'not_started';
                    $neverStartedCount++;
                } elseif ($ack->isValid()) {
                    $status = 'valid';
                    $validCount++;
                    if ($ack->valid_until && $ack->valid_until->diffInDays(now()) <= 30) {
                        $expiringSoonCount++;
                    }
                } elseif ($ack->isComplete()) {
                    $status = 'expired';
                    $expiredCount++;
                } else {
                    $status = 'in_progress';
                    $inProgressCount++;
                }
            }

            $staffData[] = [
                'user'           => $user,
                'status'         => $status,
                'acknowledged_on' => $ack?->completed_at,
                'valid_until'    => $ack?->valid_until,
                'progress'       => $ack?->progressPercent() ?? 0,
            ];
        }

        // Filter
        $filterStatus = $request->query('status');
        if ($filterStatus) {
            $staffData = collect($staffData)->filter(fn($s) => $s['status'] === $filterStatus)->values()->all();
        }

        // Search
        $search = $request->query('search');
        if ($search) {
            $staffData = collect($staffData)->filter(fn($s) =>
                str_contains(strtolower($s['user']->name), strtolower($search)) ||
                str_contains(strtolower($s['user']->email), strtolower($search))
            )->values()->all();
        }

        // Sort
        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc');
        $staffData = collect($staffData)->sortBy(function ($s) use ($sort) {
            return match ($sort) {
                'status'     => array_search($s['status'], ['not_started', 'expired', 'in_progress', 'valid', 'no_rmcp']),
                'valid_until' => $s['valid_until']?->timestamp ?? 0,
                default       => strtolower($s['user']->name),
            };
        }, SORT_REGULAR, $direction === 'desc')->values()->all();

        return view('compliance.rmcp-dashboard.index', [
            'agency'           => $agency,
            'activeVersion'    => $activeVersion,
            'staffData'        => $staffData,
            'totalStaff'       => $totalStaff,
            'validCount'       => $validCount,
            'inProgressCount'  => $inProgressCount,
            'expiredCount'     => $expiredCount,
            'neverStartedCount' => $neverStartedCount,
            'expiringSoonCount' => $expiringSoonCount,
            'search'           => $search,
            'filterStatus'     => $filterStatus,
            'sort'             => $sort,
            'direction'        => $direction,
        ]);
    }

    public function sendReminder(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        Log::info('RMCP reminder sent', [
            'to_user_id' => $validated['user_id'],
            'sent_by'    => Auth::id(),
        ]);

        return back()->with('success', 'Reminder logged. Email notification coming soon.');
    }

    public function report()
    {
        $agencyId = Auth::user()->effectiveAgencyId();
        $agency = Agency::findOrFail($agencyId);
        $activeVersion = RmcpVersion::where('agency_id', $agencyId)->active()->first();

        $staff = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $staffData = [];
        foreach ($staff as $user) {
            $ack = $activeVersion
                ? RmcpAcknowledgement::where('user_id', $user->id)
                    ->where('rmcp_version_id', $activeVersion->id)
                    ->completed()
                    ->latest()
                    ->first()
                : null;

            $staffData[] = [
                'name'            => $user->name,
                'email'           => $user->email,
                'role'            => $user->role,
                'acknowledged_on' => $ack?->completed_at?->format('d M Y'),
                'valid_until'     => $ack?->valid_until?->format('d M Y'),
                'status'          => $ack && $ack->isValid() ? 'Valid' : ($ack ? 'Expired' : 'Not acknowledged'),
            ];
        }

        return view('compliance.rmcp-dashboard.report', compact('agency', 'activeVersion', 'staffData'));
    }
}
