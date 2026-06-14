<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Mail\PolicyAcknowledgementReminder;
use App\Models\Agency;
use App\Models\Compliance\AgencyPolicy;
use App\Models\Compliance\PolicyAcknowledgement;
use App\Models\Compliance\PolicyVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Compliance-officer policy register (AT-29). Mirrors RmcpDashboardController
 * with a policy selector (?policy=key) and a real-email reminder.
 */
class PolicyDashboardController extends Controller
{
    public function index(Request $request)
    {
        $agencyId = Auth::user()->effectiveAgencyId();
        $agency = Agency::findOrFail($agencyId);

        $policies = AgencyPolicy::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedKey = $request->query('policy') ?: optional($policies->first())->policy_key;
        $selectedPolicy = $policies->firstWhere('policy_key', $selectedKey);

        $activeVersion = $selectedPolicy
            ? PolicyVersion::where('policy_id', $selectedPolicy->id)->active()->first()
            : null;

        $staff = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $totalStaff = $staff->count();

        $staffData = [];
        $validCount = 0;
        $inProgressCount = 0;
        $expiredCount = 0;
        $neverStartedCount = 0;
        $expiringSoonCount = 0;

        foreach ($staff as $user) {
            $ack = null;
            $status = 'no_policy';

            if ($activeVersion) {
                $ack = PolicyAcknowledgement::where('user_id', $user->id)
                    ->where('policy_version_id', $activeVersion->id)
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
                'user'            => $user,
                'status'          => $status,
                'acknowledged_on' => $ack?->completed_at,
                'valid_until'     => $ack?->valid_until,
                'progress'        => $ack?->progressPercent() ?? 0,
            ];
        }

        $filterStatus = $request->query('status');
        if ($filterStatus) {
            $staffData = collect($staffData)->filter(fn($s) => $s['status'] === $filterStatus)->values()->all();
        }

        $search = $request->query('search');
        if ($search) {
            $staffData = collect($staffData)->filter(fn($s) =>
                str_contains(strtolower($s['user']->name), strtolower($search)) ||
                str_contains(strtolower($s['user']->email), strtolower($search))
            )->values()->all();
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc');
        $staffData = collect($staffData)->sortBy(function ($s) use ($sort) {
            return match ($sort) {
                'status'      => array_search($s['status'], ['not_started', 'expired', 'in_progress', 'valid', 'no_policy']),
                'valid_until' => $s['valid_until']?->timestamp ?? 0,
                default       => strtolower($s['user']->name),
            };
        }, SORT_REGULAR, $direction === 'desc')->values()->all();

        return view('compliance.policy-dashboard.index', [
            'agency'            => $agency,
            'policies'          => $policies,
            'selectedPolicy'    => $selectedPolicy,
            'selectedKey'       => $selectedKey,
            'activeVersion'     => $activeVersion,
            'staffData'         => $staffData,
            'totalStaff'        => $totalStaff,
            'validCount'        => $validCount,
            'inProgressCount'   => $inProgressCount,
            'expiredCount'      => $expiredCount,
            'neverStartedCount' => $neverStartedCount,
            'expiringSoonCount' => $expiringSoonCount,
            'search'            => $search,
            'filterStatus'      => $filterStatus,
            'sort'              => $sort,
            'direction'         => $direction,
        ]);
    }

    /**
     * Send a real reminder email to a staff member for an outstanding policy.
     */
    public function sendReminder(Request $request)
    {
        $agencyId = Auth::user()->effectiveAgencyId();

        $validated = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'policy_key' => 'required|string',
        ]);

        $policy = AgencyPolicy::where('agency_id', $agencyId)
            ->where('policy_key', $validated['policy_key'])
            ->firstOrFail();

        $recipient = User::where('agency_id', $agencyId)->findOrFail($validated['user_id']);

        if (!$recipient->email) {
            return back()->with('error', "{$recipient->name} has no email address on file.");
        }

        try {
            Mail::to($recipient->email)->send(new PolicyAcknowledgementReminder($recipient, $policy, Auth::user()));

            Log::info('Policy reminder sent', [
                'to_user_id' => $recipient->id,
                'policy_key' => $policy->policy_key,
                'sent_by'    => Auth::id(),
            ]);

            return back()->with('success', "Reminder emailed to {$recipient->name}.");
        } catch (\Throwable $e) {
            Log::error('Policy reminder email failed', [
                'to_user_id' => $recipient->id,
                'policy_key' => $policy->policy_key,
                'error'      => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not send the reminder email. Please try again or contact support.');
        }
    }

    public function report(Request $request)
    {
        $agencyId = Auth::user()->effectiveAgencyId();
        $agency = Agency::findOrFail($agencyId);

        $selectedKey = $request->query('policy');
        $selectedPolicy = AgencyPolicy::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->when($selectedKey, fn($q) => $q->where('policy_key', $selectedKey))
            ->orderBy('name')
            ->first();

        $activeVersion = $selectedPolicy
            ? PolicyVersion::where('policy_id', $selectedPolicy->id)->active()->first()
            : null;

        $staff = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $staffData = [];
        foreach ($staff as $user) {
            $ack = $activeVersion
                ? PolicyAcknowledgement::where('user_id', $user->id)
                    ->where('policy_version_id', $activeVersion->id)
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

        return view('compliance.policy-dashboard.report', compact('agency', 'selectedPolicy', 'activeVersion', 'staffData'));
    }
}
