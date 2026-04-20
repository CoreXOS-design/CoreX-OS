<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\AgentApplication;
use App\Models\AgentCapPeriod;
use App\Models\AgentSocialAccount;
use App\Models\CommissionLedger;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Models\UserDocument;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AgentPortalController extends Controller
{
    public function index()
    {
        $user = auth()->user()->load(['documents', 'branch', 'agency']);

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

        // Determine if attention needed (for sidebar dot)
        $needsAttention = $profilePercent < 100
            || $complianceStatus['overall'] !== 'green'
            || $trainingItems->contains('status', 'red')
            || $rmcpStatus === 'red';

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
            'needsAttention'
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

        return redirect('/my-portal#profile')->with('success', 'Profile updated.');
    }

    public function uploadDocument(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('upload_own_documents'), 403);

        $user = auth()->user();

        $request->validate([
            'document_type' => ['required', 'string', 'max:50'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $file = $request->file('file');
        $path = $file->store('agent-docs/' . $user->id, 'public');
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

        // Create UserDocument record (source of truth)
        UserDocument::create([
            'user_id'       => $user->id,
            'agency_id'     => $user->agency_id,
            'document_type' => $documentType,
            'file_path'     => $path,
            'file_name'     => $file->getClientOriginalName(),
            'file_size'     => $file->getSize(),
            'mime_type'     => $file->getMimeType(),
            'status'        => $isPhoto ? 'verified' : 'pending',
            'verified_at'   => $isPhoto ? now() : null,
            'verified_by'   => $isPhoto ? $user->id : null,
            'uploaded_by'   => $user->id,
            'expiry_date'   => $request->expiry_date,
        ]);

        // Update legacy user columns for backward compatibility
        if ($type === 'ffc_certificate') {
            $user->update(['ffc_certificate_path' => $path]);
        } elseif ($type === 'photo') {
            $user->update(['agent_photo_path' => $path]);
        }

        $message = $isPhoto ? 'Photo uploaded.' : 'Document uploaded — pending verification.';

        return back()->with('success', $message);
    }

    private function computeComplianceStatus(User $user, $documents): array
    {
        $items = [];

        // FFC Number
        $items['ffc_number'] = [
            'status' => !empty($user->ffc_number) ? 'green' : 'red',
            'label' => !empty($user->ffc_number) ? $user->ffc_number : 'Not set',
        ];

        // FFC Certificate
        $ffcDoc = $documents->get('ffc_certificate');
        $items['ffc_certificate'] = $this->documentStatus($ffcDoc, 'FFC Certificate');

        // FFC Expiry
        $items['ffc_expiry'] = $this->expiryStatus($user->ffc_expiry_date, 'FFC');

        // ID Copy
        $idDoc = $documents->get('id_copy');
        $items['id_copy'] = $this->documentStatus($idDoc, 'ID Copy');

        // PI Insurance
        $piDoc = $documents->get('pi_insurance');
        $items['pi_insurance'] = $this->documentStatus($piDoc, 'PI Insurance');
        if ($piDoc && $piDoc->expiry_date) {
            $items['pi_insurance'] = array_merge($items['pi_insurance'], $this->expiryOverlay($piDoc->expiry_date));
        }

        // Tax Clearance
        $taxDoc = $documents->get('tax_clearance');
        $items['tax_clearance'] = $this->documentStatus($taxDoc, 'Tax Clearance');
        if ($taxDoc && $taxDoc->expiry_date) {
            $items['tax_clearance'] = array_merge($items['tax_clearance'], $this->expiryOverlay($taxDoc->expiry_date));
        }

        // RMCP — from the structured acknowledgement system
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

        // Overall = worst status
        $statuses = collect($items)->pluck('status');
        $overall = 'green';
        if ($statuses->contains('red')) {
            $overall = 'red';
        } elseif ($statuses->contains('amber')) {
            $overall = 'amber';
        }

        $items['overall'] = $overall;
        $items['issues_count'] = $statuses->filter(fn ($s) => $s !== 'green')->count();

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
