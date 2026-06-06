<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\AgentApplication;
use App\Models\AgentCapPeriod;
use App\Models\AgentSocialAccount;
use App\Models\CommissionLedger;
use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\Compliance\UserComplianceOverride;
use App\Models\ImpersonationLog;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\Images\AgentPhotoNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AgentPortalController extends Controller
{
    public function index()
    {
        $user = auth()->user()->load(['documents', 'branch', 'agency']);

        // ── Ensure own user_documents have correct agency_id (self-heal) ──
        if ($user->agency_id) {
            \DB::table('user_documents')
                ->where('user_id', $user->id)
                ->where(function ($q) use ($user) {
                    $q->whereNull('agency_id')
                      ->orWhere('agency_id', '!=', $user->agency_id);
                })
                ->update(['agency_id' => $user->agency_id]);
        }

        // ── User documents grouped by type (latest per type) ──
        $documents = $user->documents()
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('document_type')
            ->map(fn ($group) => $group->first());

        // ── Profile completeness ──
        $profileFields = [
            ['key' => 'name', 'label' => 'Full name', 'value' => $user->name],
            ['key' => 'email', 'label' => 'Email address', 'value' => $user->email],
            ['key' => 'phone_cell', 'label' => 'Phone / cell number', 'value' => $user->phone ?: $user->cell],
            ['key' => 'ffc_number', 'label' => 'FFC number', 'value' => $user->ffc_number],
            ['key' => 'ffc_certificate', 'label' => 'FFC certificate uploaded', 'value' => $documents->get('ffc_certificate')?->file_path ?? $user->ffc_certificate_path],
            ['key' => 'agent_photo_path', 'label' => 'Profile photo', 'value' => $documents->get('profile_photo')?->file_path ?? $user->agent_photo_path],
            ['key' => 'designation', 'label' => 'Designation', 'value' => $user->designation],
            ['key' => 'branch_id', 'label' => 'Assigned to branch', 'value' => $user->branch_id],
        ];

        $filledCount = collect($profileFields)->filter(fn($f) => !empty($f['value']))->count();
        $profilePercent = count($profileFields) > 0 ? (int) round(($filledCount / count($profileFields)) * 100) : 0;

        // ── Compliance status ──
        $complianceStatus = $this->computeComplianceStatus($user, $documents);

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

        // RMCP acknowledgement — reads from the new structured ack system
        $rmcpAckStatus = $user->rmcpAcknowledgementStatus();
        $rmcpAck = $user->currentRmcpAcknowledgement();
        $rmcpStatus = match ($rmcpAckStatus) {
            'valid'       => 'green',
            'in_progress' => 'amber',
            default       => 'red',
        };
        $rmcpLabel = match ($rmcpAckStatus) {
            'valid'       => 'Valid until ' . ($rmcpAck?->valid_until?->format('d M Y') ?? ''),
            'in_progress' => 'In progress (' . ($rmcpAck?->progressPercent() ?? 0) . '%)',
            'expired'     => 'Expired — re-acknowledge required',
            'not_started' => 'Not acknowledged',
            default       => 'No active RMCP',
        };

        // ── Earnings snapshot ──
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

        // ── Social accounts ──
        $socialAccounts = AgentSocialAccount::where('user_id', $user->id)->active()->get();

        // ── Impersonation audit (recent logins-as-this-user in last 30 days) ──
        $impersonationLogs = collect();
        if (class_exists(\App\Models\ImpersonationLog::class)
            && \Schema::hasTable('impersonation_logs')) {
            $impersonationLogs = ImpersonationLog::forUser($user->id)
                ->recent(30)
                ->where('action', 'start')
                ->with('admin')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        }

        // ── Payslip summary ──
        $latestPayslip = \App\Models\Payroll\PayrollPayslip::where('user_id', $user->id)
            ->whereHas('run', fn($q) => $q->where('status', 'finalised'))
            ->orderByDesc('period_month')->first();
        $payslipCount = \App\Models\Payroll\PayrollPayslip::where('user_id', $user->id)
            ->whereHas('run', fn($q) => $q->where('status', 'finalised'))
            ->count();

        // Determine if attention needed (for sidebar dot)
        $needsAttention = $profilePercent < 100
            || $complianceStatus['overall'] !== 'green'
            || $trainingItems->contains('status', 'red')
            || $rmcpStatus === 'red';

        // Phase 9a G2 — light presentation stats for the agent. Cheap queries,
        // drive action. Failure-isolated so a stats hiccup never breaks the
        // portal load.
        $presentationStats = [
            'this_week_count'           => 0,
            'awaiting_outcome_count'    => 0,
            'refresh_requests_count'    => 0,
        ];
        try {
            $presentationStats['this_week_count'] = \App\Models\Presentation::query()
                ->where('created_by_user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();
            $presentationStats['awaiting_outcome_count'] = \App\Models\Presentation::query()
                ->where('created_by_user_id', $user->id)
                ->where('created_at', '<=', now()->subDays(7))
                ->whereDoesntHave('outcome')
                ->count();
            $presentationStats['refresh_requests_count'] = \App\Models\PresentationRefreshRequest::query()
                ->whereIn('status', ['pending', 'acknowledged'])
                ->whereHas('link', fn ($q) => $q->where('created_by_user_id', $user->id))
                ->count();
        } catch (\Throwable $e) {
            // Stats are decorative — the portal must still render.
            \Log::warning('agent_portal.stats_failed', ['error' => $e->getMessage()]);
        }

        return view('agent.portal', compact(
            'user',
            'documents',
            'profileFields',
            'profilePercent',
            'complianceStatus',
            'trainingItems',
            'rmcpStatus',
            'rmcpLabel',
            'rmcpAck',
            'rmcpAckStatus',
            'thisMonthEarnings',
            'thisYearEarnings',
            'capPercent',
            'isCapped',
            'recentActivity',
            'socialAccounts',
            'impersonationLogs',
            'needsAttention',
            'latestPayslip',
            'payslipCount',
            'presentationStats'
        ));
    }

    public function updateProfile(ProfileUpdateRequest $request)
    {
        abort_unless(auth()->user()->hasPermission('edit_own_profile'), 403);

        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        // Sync FFC expiry date to latest FFC certificate document
        if ($request->has('ffc_expiry_date')) {
            $latestFfcDoc = $user->documents()
                ->where('document_type', 'ffc_certificate')
                ->latest()->first();
            if ($latestFfcDoc) {
                $latestFfcDoc->update(['expiry_date' => $request->ffc_expiry_date]);
            }
        }

        return redirect()->route('agent.portal')->withFragment('profile')->with('success', 'Profile updated.');
    }

    public function uploadDocument(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('upload_own_documents'), 403);

        $user = auth()->user();

        $request->validate([
            'document_type' => ['required', 'string', 'max:50'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $file = $request->file('file');
        $type = $request->document_type;

        // Map incoming type to UserDocument document_type
        $docTypeMap = [
            'ffc_certificate' => UserDocument::DOCUMENT_TYPE_FFC_CERTIFICATE,
            'photo'           => UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO,
            'id_copy'         => UserDocument::DOCUMENT_TYPE_ID_COPY,
            'pi_insurance'    => UserDocument::DOCUMENT_TYPE_PI_INSURANCE,
            'tax_clearance'   => UserDocument::DOCUMENT_TYPE_TAX_CLEARANCE,
        ];

        $documentType = $docTypeMap[$type] ?? UserDocument::DOCUMENT_TYPE_OTHER;
        $isPhoto = $documentType === UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO;

        // Profile photos are normalized to a uniform 1200×1200 square WebP so the
        // agent looks identical everywhere (spec: .ai/specs/agent-photo.md). Other
        // documents store as-is.
        if ($isPhoto) {
            $path = app(AgentPhotoNormalizer::class)->store($file, $user->id, $user->agent_photo_path);
            $fileName = 'photo.webp';
            $mimeType = 'image/webp';
            $fileSize = \Illuminate\Support\Facades\Storage::disk('public')->size($path);
        } else {
            $path = $file->store('agent-docs/' . $user->id, 'public');
            $fileName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();
            $fileSize = $file->getSize();
        }

        // Create UserDocument record (source of truth)
        UserDocument::create([
            'user_id'       => $user->id,
            'agency_id'     => $user->agency_id,
            'document_type' => $documentType,
            'file_path'     => $path,
            'file_name'     => $fileName,
            'file_size'     => $fileSize,
            'mime_type'     => $mimeType,
            'status'        => $isPhoto ? 'verified' : 'pending',
            'verified_at'   => $isPhoto ? now() : null,
            'verified_by'   => $isPhoto ? $user->id : null,
            'uploaded_by'   => $user->id,
            'expiry_date'   => $request->expiry_date,
        ]);

        // Update legacy user columns for backward compatibility
        if ($type === 'ffc_certificate') {
            $updates = ['ffc_certificate_path' => $path];
            // Sync FFC expiry date to users table
            if ($request->expiry_date) {
                $updates['ffc_expiry_date'] = $request->expiry_date;
            }
            $user->update($updates);
        } elseif ($type === 'photo') {
            $user->update(['agent_photo_path' => $path]);
        }

        $message = $isPhoto ? 'Photo uploaded.' : 'Document uploaded — pending verification.';

        return back()->with('success', $message);
    }

    // ══════════════════════════════════════════════════════════════
    // MY PAYSLIPS (self-service)
    // ══════════════════════════════════════════════════════════════

    public function myPayslips()
    {
        $user = auth()->user();

        $payslips = \App\Models\Payroll\PayrollPayslip::where('user_id', $user->id)
            ->whereHas('run', fn($q) => $q->where('status', 'finalised'))
            ->with('run')
            ->orderByDesc('period_month')
            ->paginate(12);

        return view('payroll.my-portal.index', compact('payslips'));
    }

    public function myPayslipShow($payslipId)
    {
        $payslip = \App\Models\Payroll\PayrollPayslip::with([
            'lines' => fn($q) => $q->orderBy('sort_order'),
            'run', 'employee.user.bankingDetail',
        ])->findOrFail($payslipId);

        // Authorization: own payslips only, finalised runs only
        abort_unless($payslip->user_id === auth()->id(), 403);
        abort_unless($payslip->run && $payslip->run->isFinalised(), 404);

        $earningLines = $payslip->lines->where('line_type', 'earning');
        $deductionLines = $payslip->lines->where('line_type', 'deduction');
        $contributionLines = $payslip->lines->where('line_type', 'employer_contribution');

        return view('payroll.my-portal.show', compact(
            'payslip', 'earningLines', 'deductionLines', 'contributionLines'
        ));
    }

    public function myPayslipPdf($payslipId)
    {
        $payslip = \App\Models\Payroll\PayrollPayslip::with('run')->findOrFail($payslipId);

        abort_unless($payslip->user_id === auth()->id(), 403);
        abort_unless($payslip->run && $payslip->run->isFinalised(), 404);

        $pdfService = new \App\Services\Payroll\PayslipPdfService();
        $path = $pdfService->getStoredPath($payslip);

        if (!$path) {
            $path = $pdfService->regenerate($payslip);
        }

        $periodYm = $payslip->period_month->format('Ym');
        $filename = "My-Payslip-{$periodYm}.pdf";

        return response()->download($path, $filename);
    }

    // ══════════════════════════════════════════════════════════════

    private function computeComplianceStatus(User $user, $documents): array
    {
        $items = [];

        // Document-based compliance items — check with override → provision → individual precedence
        $docItems = [
            'ffc_certificate' => 'FFC Certificate',
            'id_copy'         => 'ID Copy',
            'pi_insurance'    => 'PI Insurance',
            'tax_clearance'   => 'Tax Clearance',
        ];

        foreach ($docItems as $docType => $label) {
            // 1. Check per-user override
            $override = UserComplianceOverride::forUserAndItem($user->id, $docType);
            if ($override) {
                $overrideLabel = UserComplianceOverride::OVERRIDE_TYPE_LABELS[$override->override_type] ?? ucfirst($override->override_type);
                $items[$docType] = [
                    'status'   => 'grey',
                    'label'    => $overrideLabel . ': ' . \Illuminate\Support\Str::limit($override->reason, 60),
                    'override' => true,
                    'override_by' => $override->creator?->name,
                    'override_date' => $override->created_at?->format('d M Y'),
                ];
                continue;
            }

            // 2. Check agency provision
            $provision = AgencyComplianceProvision::coversUser($user, $docType);
            if ($provision) {
                $provLabel = 'Covered by agency';
                if ($provision->policy_reference) {
                    $provLabel .= ': ' . $provision->policy_reference;
                }
                if ($provision->effective_until) {
                    $provLabel .= ', valid to ' . $provision->effective_until->format('d M Y');
                }
                $items[$docType] = [
                    'status'    => 'green',
                    'label'     => $provLabel,
                    'provision' => true,
                ];
                continue;
            }

            // 3. Check individual document
            $doc = $documents->get($docType);
            $items[$docType] = $this->documentStatus($doc, $label);

            // Expiry overlay for PI Insurance and Tax Clearance
            if (in_array($docType, ['pi_insurance', 'tax_clearance']) && $doc && $doc->expiry_date) {
                $items[$docType] = array_merge($items[$docType], $this->expiryOverlay($doc->expiry_date));
            }

            // Show admin upload indicator
            if ($doc && $doc->uploaded_by_admin) {
                $adminName = $doc->uploader?->name ?? 'Admin';
                $items[$docType]['label'] = 'Verified — uploaded by ' . $adminName . ' on ' . $doc->created_at->format('d M Y');
                $items[$docType]['admin_upload'] = true;
            }
        }

        // FFC Number — no override/provision logic, just direct check
        $ffcOverride = UserComplianceOverride::forUserAndItem($user->id, 'ffc_number');
        if ($ffcOverride) {
            $overrideLabel = UserComplianceOverride::OVERRIDE_TYPE_LABELS[$ffcOverride->override_type] ?? ucfirst($ffcOverride->override_type);
            $items['ffc_number'] = ['status' => 'grey', 'label' => $overrideLabel . ': ' . \Illuminate\Support\Str::limit($ffcOverride->reason, 60), 'override' => true];
        } else {
            $items['ffc_number'] = [
                'status' => !empty($user->ffc_number) ? 'green' : 'red',
                'label' => !empty($user->ffc_number) ? $user->ffc_number : 'Not set',
            ];
        }

        // FFC Expiry — check override, then standard
        $ffcExpiryOverride = UserComplianceOverride::forUserAndItem($user->id, 'ffc_expiry');
        if ($ffcExpiryOverride) {
            $overrideLabel = UserComplianceOverride::OVERRIDE_TYPE_LABELS[$ffcExpiryOverride->override_type] ?? ucfirst($ffcExpiryOverride->override_type);
            $items['ffc_expiry'] = ['status' => 'grey', 'label' => $overrideLabel . ': ' . \Illuminate\Support\Str::limit($ffcExpiryOverride->reason, 60), 'override' => true];
        } else {
            $items['ffc_expiry'] = $this->expiryStatus($user->ffc_expiry_date, 'FFC');
        }

        // RMCP — from the structured acknowledgement system
        $rmcpOverride = UserComplianceOverride::forUserAndItem($user->id, 'rmcp_acknowledged');
        if ($rmcpOverride) {
            $overrideLabel = UserComplianceOverride::OVERRIDE_TYPE_LABELS[$rmcpOverride->override_type] ?? ucfirst($rmcpOverride->override_type);
            $items['rmcp_acknowledged'] = ['status' => 'grey', 'label' => $overrideLabel . ': ' . \Illuminate\Support\Str::limit($rmcpOverride->reason, 60), 'override' => true];
        } else {
            $rmcpAckSt = $user->rmcpAcknowledgementStatus();
            $items['rmcp_acknowledged'] = [
                'status' => match ($rmcpAckSt) {
                    'valid'       => 'green',
                    'in_progress' => 'amber',
                    default       => 'red',
                },
                'label' => match ($rmcpAckSt) {
                    'valid'       => 'Acknowledged',
                    'in_progress' => 'In progress',
                    'expired'     => 'Expired',
                    'not_started' => 'Not acknowledged',
                    default       => 'No active RMCP',
                },
            ];
        }

        // Employee Screening
        $screeningOverride = UserComplianceOverride::forUserAndItem($user->id, 'employee_screening');
        if ($screeningOverride) {
            $overrideLabel = UserComplianceOverride::OVERRIDE_TYPE_LABELS[$screeningOverride->override_type] ?? ucfirst($screeningOverride->override_type);
            $items['employee_screening'] = ['status' => 'grey', 'label' => $overrideLabel . ': ' . \Illuminate\Support\Str::limit($screeningOverride->reason, 60), 'override' => true];
        } else {
            $screeningSt = $user->currentScreeningStatus();
            $items['employee_screening'] = [
                'status' => match ($screeningSt) {
                    'clear'                  => 'green',
                    'pre_employment_pending' => 'amber',
                    default                  => 'red',
                },
                'label' => match ($screeningSt) {
                    'clear'                  => 'Clear' . ($user->screening_due_on ? ' — next review ' . \Carbon\Carbon::parse($user->screening_due_on)->format('d M Y') : ''),
                    'pre_employment_pending' => 'Screening in progress',
                    'concerns_flagged'       => 'Concerns flagged',
                    'overdue'                => 'Overdue — review pending',
                    'expired'                => 'Expired — re-screen required',
                    'never_screened'         => 'Pre-employment screening pending',
                    default                  => 'Not screened',
                },
            ];
        }

        // Overall = worst status (grey = neutral, does not count as issue)
        $statuses = collect($items)->pluck('status');
        $overall = 'green';
        if ($statuses->contains('red')) {
            $overall = 'red';
        } elseif ($statuses->contains('amber')) {
            $overall = 'amber';
        }

        $items['overall'] = $overall;
        $items['issues_count'] = $statuses->filter(fn ($s) => !in_array($s, ['green', 'grey']))->count();

        return $items;
    }

    private function documentStatus($doc, string $label): array
    {
        if (!$doc) {
            return ['status' => 'red', 'label' => 'Not uploaded', 'expiry' => null, 'days_to_expiry' => null];
        }

        return match ($doc->status) {
            'pending' => ['status' => 'amber', 'label' => 'Pending verification', 'expiry' => $doc->expiry_date, 'days_to_expiry' => null],
            'verified' => ['status' => 'green', 'label' => 'Verified', 'expiry' => $doc->expiry_date, 'days_to_expiry' => null],
            'rejected' => ['status' => 'red', 'label' => 'Rejected: ' . ($doc->rejected_reason ?? ''), 'expiry' => null, 'days_to_expiry' => null],
            'expired' => ['status' => 'red', 'label' => 'Expired', 'expiry' => $doc->expiry_date, 'days_to_expiry' => null],
            default => ['status' => 'red', 'label' => 'Unknown', 'expiry' => null, 'days_to_expiry' => null],
        };
    }

    private function expiryStatus($date, string $label): array
    {
        if (!$date) {
            return ['status' => 'red', 'label' => 'Not set', 'expiry' => null, 'days_to_expiry' => null];
        }

        $expiry = Carbon::parse($date);
        $days = (int) now()->diffInDays($expiry, false);

        if ($days < 0) {
            return ['status' => 'red', 'label' => 'Expired', 'expiry' => $expiry, 'days_to_expiry' => $days];
        }
        if ($days <= 30) {
            return ['status' => 'red', 'label' => 'Expires ' . $expiry->format('d M Y') . " ({$days} days)", 'expiry' => $expiry, 'days_to_expiry' => $days];
        }
        if ($days <= 60) {
            return ['status' => 'amber', 'label' => 'Expires ' . $expiry->format('d M Y') . " ({$days} days)", 'expiry' => $expiry, 'days_to_expiry' => $days];
        }

        return ['status' => 'green', 'label' => 'Valid until ' . $expiry->format('d M Y'), 'expiry' => $expiry, 'days_to_expiry' => $days];
    }

    private function expiryOverlay($date): array
    {
        if (!$date) return [];

        $expiry = Carbon::parse($date);
        $days = (int) now()->diffInDays($expiry, false);

        if ($days < 0) {
            return ['status' => 'red', 'expiry' => $expiry, 'days_to_expiry' => $days];
        }
        if ($days <= 30) {
            return ['status' => 'red', 'expiry' => $expiry, 'days_to_expiry' => $days];
        }
        if ($days <= 60) {
            return ['status' => 'amber', 'expiry' => $expiry, 'days_to_expiry' => $days];
        }

        return ['expiry' => $expiry, 'days_to_expiry' => $days];
    }
}
