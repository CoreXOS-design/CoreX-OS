<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AgentComplianceController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);   // AT-253 Rule 17

        // Get all active agents
        $agents = User::agencyMembers()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        // Get required training courses
        $requiredCourses = TrainingCourse::where('is_required', true)
            ->published()
            ->get();
        $requiredCourseCount = $requiredCourses->count();

        // Build compliance data per agent
        $agentData = $agents->map(function ($agent) use ($requiredCourses, $requiredCourseCount) {
            // FFC status
            $ffcStatus = $this->calculateFfcStatus($agent);

            // Training status
            $trainingStatus = $this->calculateTrainingStatus($agent, $requiredCourses, $requiredCourseCount);

            // Overall = worst of all statuses
            $statuses = [$ffcStatus['status'], $trainingStatus['status']];
            if (in_array('red', $statuses)) {
                $overall = 'red';
            } elseif (in_array('amber', $statuses)) {
                $overall = 'amber';
            } else {
                $overall = 'green';
            }

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'designation' => $agent->designation,
                'ffc' => $ffcStatus,
                'training' => $trainingStatus,
                'overall' => $overall,
            ];
        });

        // Sort: red first, then amber, then green
        $sortOrder = ['red' => 0, 'amber' => 1, 'green' => 2];
        $agentData = $agentData->sortBy(fn($a) => $sortOrder[$a['overall']] ?? 3)->values();

        // Summary counts
        $totalAgents = $agentData->count();
        $compliantCount = $agentData->where('overall', 'green')->count();
        $atRiskCount = $agentData->where('overall', 'amber')->count();
        $nonCompliantCount = $agentData->where('overall', 'red')->count();

        // Expiring soon items (next 30 days)
        $expiringSoon = [];
        foreach ($agentData as $agent) {
            if ($agent['ffc']['status'] === 'amber' && $agent['ffc']['expiry_date']) {
                $expiringSoon[] = [
                    'agent_name' => $agent['name'],
                    'item' => 'FFC',
                    'detail' => 'expires ' . Carbon::parse($agent['ffc']['expiry_date'])->format('d M Y'),
                ];
            }
            if ($agent['training']['expiring_courses']) {
                foreach ($agent['training']['expiring_courses'] as $courseName) {
                    $expiringSoon[] = [
                        'agent_name' => $agent['name'],
                        'item' => 'Training',
                        'detail' => $courseName . ' expiring',
                    ];
                }
            }
        }

        return view('compliance.agent-dashboard', compact(
            'agentData',
            'totalAgents',
            'compliantCount',
            'atRiskCount',
            'nonCompliantCount',
            'expiringSoon',
            'requiredCourseCount'
        ));
    }

    private function calculateFfcStatus(User $agent): array
    {
        // Check if FFC certificate uploaded
        $hasFile = !empty($agent->ffc_certificate_path);
        $hasNumber = !empty($agent->ffc_number);

        // Users table doesn't have ffc_expiry column yet —
        // check agent_applications if user was onboarded
        $expiryDate = null;
        if ($agent->id) {
            $application = \App\Models\AgentApplication::where('user_id', $agent->id)->first();
            if ($application && $application->ffc_expiry) {
                $expiryDate = $application->ffc_expiry;
            }
        }

        if ($expiryDate) {
            $daysRemaining = (int) now()->diffInDays(Carbon::parse($expiryDate), false);
            if ($daysRemaining < 0) {
                return ['status' => 'red', 'label' => 'Expired', 'expiry_date' => $expiryDate, 'days' => $daysRemaining];
            } elseif ($daysRemaining <= 60) {
                return ['status' => 'amber', 'label' => 'Expiring ' . Carbon::parse($expiryDate)->format('d M'), 'expiry_date' => $expiryDate, 'days' => $daysRemaining];
            } else {
                return ['status' => 'green', 'label' => 'Valid until ' . Carbon::parse($expiryDate)->format('d M Y'), 'expiry_date' => $expiryDate, 'days' => $daysRemaining];
            }
        }

        // No expiry date tracked — check by file/number presence
        if ($hasFile && $hasNumber) {
            return ['status' => 'green', 'label' => $agent->ffc_number, 'expiry_date' => null, 'days' => null];
        } elseif ($hasNumber) {
            return ['status' => 'amber', 'label' => $agent->ffc_number . ' (no cert)', 'expiry_date' => null, 'days' => null];
        }

        return ['status' => 'red', 'label' => 'Not set', 'expiry_date' => null, 'days' => null];
    }

    private function calculateTrainingStatus(User $agent, $requiredCourses, int $requiredCourseCount): array
    {
        if ($requiredCourseCount === 0) {
            return ['status' => 'green', 'label' => 'No required courses', 'completed' => 0, 'total' => 0, 'expiring_courses' => []];
        }

        $completedCount = 0;
        $expiringCourses = [];
        $hasExpired = false;

        foreach ($requiredCourses as $course) {
            $completion = TrainingCompletion::where('user_id', $agent->id)
                ->where('course_id', $course->id)
                ->first();

            if ($completion) {
                if ($completion->expires_at && $completion->expires_at->lte(now())) {
                    $hasExpired = true;
                } elseif ($completion->expires_at && $completion->expires_at->lte(now()->addDays(30))) {
                    $expiringCourses[] = $course->title;
                    $completedCount++;
                } else {
                    $completedCount++;
                }
            }
        }

        $label = "{$completedCount}/{$requiredCourseCount} courses";

        if ($hasExpired || $completedCount < $requiredCourseCount) {
            return ['status' => 'red', 'label' => $label, 'completed' => $completedCount, 'total' => $requiredCourseCount, 'expiring_courses' => $expiringCourses];
        }

        if (!empty($expiringCourses)) {
            return ['status' => 'amber', 'label' => $label, 'completed' => $completedCount, 'total' => $requiredCourseCount, 'expiring_courses' => $expiringCourses];
        }

        return ['status' => 'green', 'label' => $label, 'completed' => $completedCount, 'total' => $requiredCourseCount, 'expiring_courses' => []];
    }
}
