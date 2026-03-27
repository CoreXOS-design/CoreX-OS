<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentApplication;
use App\Models\AgentCapPeriod;
use App\Models\CommissionLedger;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AgentPortalController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // ── Profile completeness ──
        $profileFields = [
            ['key' => 'name', 'label' => 'Full name', 'value' => $user->name],
            ['key' => 'email', 'label' => 'Email address', 'value' => $user->email],
            ['key' => 'phone_cell', 'label' => 'Phone / cell number', 'value' => $user->phone ?: $user->cell],
            ['key' => 'ffc_number', 'label' => 'FFC number', 'value' => $user->ffc_number],
            ['key' => 'ffc_certificate_path', 'label' => 'FFC certificate uploaded', 'value' => $user->ffc_certificate_path],
            ['key' => 'agent_photo_path', 'label' => 'Profile photo', 'value' => $user->agent_photo_path],
            ['key' => 'designation', 'label' => 'Designation', 'value' => $user->designation],
            ['key' => 'branch_id', 'label' => 'Assigned to branch', 'value' => $user->branch_id],
        ];

        $filledCount = collect($profileFields)->filter(fn($f) => !empty($f['value']))->count();
        $profilePercent = count($profileFields) > 0 ? (int) round(($filledCount / count($profileFields)) * 100) : 0;

        // ── FFC status ──
        $ffcStatus = $this->calculateFfcStatus($user);

        // ── Training status ──
        $requiredCourses = TrainingCourse::where('is_required', true)->published()->get();
        $trainingItems = $requiredCourses->map(function ($course) use ($user) {
            $completion = TrainingCompletion::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            $lessonsTotal = $course->lessonCount();
            $lessonsDone = $course->completedLessonCountForUser($user->id);

            if ($completion && (!$completion->expires_at || $completion->expires_at->gt(now()))) {
                $status = 'green';
                $label = 'Completed';
                if ($completion->expires_at && $completion->expires_at->lte(now()->addDays(30))) {
                    $status = 'amber';
                    $label = 'Expiring ' . $completion->expires_at->format('d M');
                }
            } else {
                $status = 'red';
                $label = "{$lessonsDone}/{$lessonsTotal} lessons";
            }

            return [
                'id' => $course->id,
                'title' => $course->title,
                'status' => $status,
                'label' => $label,
                'lessons_done' => $lessonsDone,
                'lessons_total' => $lessonsTotal,
            ];
        });

        // RMCP acknowledgement
        $rmcpCourse = TrainingCourse::where('title', 'like', '%RMCP%')->published()->first();
        $rmcpStatus = 'red';
        $rmcpLabel = 'Not acknowledged';
        if ($rmcpCourse) {
            $rmcpCompletion = TrainingCompletion::where('user_id', $user->id)
                ->where('course_id', $rmcpCourse->id)
                ->first();
            if ($rmcpCompletion) {
                $rmcpStatus = 'green';
                $rmcpLabel = 'Acknowledged ' . $rmcpCompletion->completed_at->format('d M Y');
            }
        }

        // ── Earnings snapshot ──
        $agencyId = $user->effectiveAgencyId() ?? 1;
        $thisMonthEarnings = (float) (CommissionLedger::forUser($user->id)->thisMonth()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('net_agent_amount') ?? 0);

        $thisYearEarnings = (float) (CommissionLedger::forUser($user->id)->thisYear()
            ->whereIn('status', ['pending', 'confirmed', 'paid'])
            ->sum('net_agent_amount') ?? 0);

        $capPeriod = AgentCapPeriod::forUser($user->id)->current()->first();
        $capPercent = 0;
        $isCapped = false;
        if ($capPeriod) {
            $capTotal = (float) ($capPeriod->cap_amount ?? 0);
            $capPaid = (float) ($capPeriod->company_dollar_paid ?? 0);
            $capPercent = $capTotal > 0 ? min(100, (int) round(($capPaid / $capTotal) * 100)) : 0;
            $isCapped = $capPeriod->is_capped;
        }

        // ── Recent activity ──
        $recentActivity = CommissionLedger::forUser($user->id)
            ->orderByDesc('deal_date')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        // ── Documents on file ──
        $docTypes = [
            ['key' => 'ffc_certificate_path', 'label' => 'FFC Certificate', 'type' => 'ffc_certificate', 'value' => $user->ffc_certificate_path],
            ['key' => 'agent_photo_path', 'label' => 'Profile Photo', 'type' => 'photo', 'value' => $user->agent_photo_path],
        ];

        // Check for application documents if onboarded
        $application = AgentApplication::where('user_id', $user->id)->first();
        if ($application) {
            $appDocs = $application->documents()->get();
            foreach (['id_copy' => 'ID Copy', 'pi_insurance' => 'PI Insurance', 'tax_clearance' => 'Tax Clearance'] as $type => $label) {
                $doc = $appDocs->where('document_type', $type)->first();
                $docTypes[] = ['key' => "app_{$type}", 'label' => $label, 'type' => $type, 'value' => $doc?->file_path];
            }
        } else {
            foreach (['id_copy' => 'ID Copy', 'pi_insurance' => 'PI Insurance', 'tax_clearance' => 'Tax Clearance'] as $type => $label) {
                $docTypes[] = ['key' => "app_{$type}", 'label' => $label, 'type' => $type, 'value' => null];
            }
        }

        // Determine if attention needed (for sidebar dot)
        $needsAttention = $profilePercent < 100
            || $ffcStatus['status'] !== 'green'
            || $trainingItems->contains('status', 'red')
            || $rmcpStatus === 'red';

        return view('agent.portal', compact(
            'user',
            'profileFields',
            'profilePercent',
            'ffcStatus',
            'trainingItems',
            'rmcpStatus',
            'rmcpLabel',
            'rmcpCourse',
            'thisMonthEarnings',
            'thisYearEarnings',
            'capPercent',
            'isCapped',
            'recentActivity',
            'docTypes',
            'needsAttention'
        ));
    }

    public function uploadDocument(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'document_type' => ['required', 'string', 'max:50'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store('agent-docs/' . $user->id, 'public');
        $type = $request->document_type;

        // Update user column if it maps to one
        if ($type === 'ffc_certificate') {
            $user->update(['ffc_certificate_path' => $path]);
        } elseif ($type === 'photo') {
            $user->update(['agent_photo_path' => $path]);
        }

        // If user was onboarded, also store in application_documents
        $application = AgentApplication::where('user_id', $user->id)->first();
        if ($application && in_array($type, ['id_copy', 'pi_insurance', 'tax_clearance', 'ffc_certificate'])) {
            \App\Models\ApplicationDocument::updateOrCreate(
                ['application_id' => $application->id, 'document_type' => $type],
                ['file_path' => $path, 'file_name' => $file->getClientOriginalName()]
            );
        }

        return back()->with('success', 'Document uploaded.');
    }

    private function calculateFfcStatus($user): array
    {
        $hasFile = !empty($user->ffc_certificate_path);
        $hasNumber = !empty($user->ffc_number);

        // Check expiry from application
        $expiryDate = null;
        $application = AgentApplication::where('user_id', $user->id)->first();
        if ($application && $application->ffc_expiry) {
            $expiryDate = $application->ffc_expiry;
        }

        if ($expiryDate) {
            $days = (int) now()->diffInDays(Carbon::parse($expiryDate), false);
            if ($days < 0) {
                return ['status' => 'red', 'label' => 'Expired', 'expiry' => $expiryDate];
            } elseif ($days <= 60) {
                return ['status' => 'amber', 'label' => 'Expiring ' . Carbon::parse($expiryDate)->format('d M'), 'expiry' => $expiryDate];
            }
            return ['status' => 'green', 'label' => 'Valid until ' . Carbon::parse($expiryDate)->format('d M Y'), 'expiry' => $expiryDate];
        }

        if ($hasFile && $hasNumber) {
            return ['status' => 'green', 'label' => $user->ffc_number, 'expiry' => null];
        } elseif ($hasNumber) {
            return ['status' => 'amber', 'label' => $user->ffc_number . ' (cert missing)', 'expiry' => null];
        }

        return ['status' => 'red', 'label' => 'Not set', 'expiry' => null];
    }
}
