<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\AgentApplication;
use App\Models\AgentCapPeriod;
use App\Models\AgentMentor;
use App\Models\AgentSponsorship;
use App\Models\ApplicationDocument;
use App\Models\CommissionSetting;
use App\Models\OnboardingChecklist;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $agencyId = $user->effectiveAgencyId() ?? 1;

        $search = $request->get('search');
        $designation = $request->get('designation');

        // Load applications grouped by pipeline status
        $pipeline = [];
        foreach (AgentApplication::PIPELINE_STATUSES as $status) {
            $query = AgentApplication::where('agency_id', $agencyId)
                ->byStatus($status)
                ->with('checklist')
                ->recent();

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            if ($designation) {
                $query->where('designation', $designation);
            }

            $pipeline[$status] = $query->get();
        }

        $totalPending = AgentApplication::where('agency_id', $agencyId)->pending()->count();

        return view('onboarding.index', compact('pipeline', 'totalPending'));
    }

    public function create()
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $agents = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('onboarding.create', compact('agents'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'id_number' => ['nullable', 'string', 'max:20'],
            'current_agency' => ['nullable', 'string', 'max:255'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:50'],
            'ffc_number' => ['nullable', 'string', 'max:100'],
            'ffc_expiry' => ['nullable', 'date'],
            'ppra_status' => ['nullable', 'string', 'max:50'],
            'designation' => ['required', 'in:property_practitioner,candidate_practitioner,intern'],
            'motivation' => ['nullable', 'string', 'max:5000'],
            'referral_source' => ['nullable', 'string', 'max:255'],
            'referred_by_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $validated['agency_id'] = $user->effectiveAgencyId() ?? 1;
        $validated['status'] = 'applied';
        $validated['status_changed_at'] = now();

        $application = AgentApplication::create($validated);
        $application->seedChecklist();

        return redirect()->route('onboarding.show', $application)
            ->with('success', 'Application created. Checklist has been seeded.');
    }

    public function show($id)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $application = AgentApplication::with(['documents', 'checklist', 'referredBy', 'reviewedByUser', 'activatedByUser', 'user'])
            ->findOrFail($id);

        $agents = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('onboarding.show', compact('application', 'agents'));
    }

    public function updateStatus($id, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $application = AgentApplication::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'in:' . implode(',', AgentApplication::STATUSES)],
            'status_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Check requirements if advancing forward
        $targetStatus = $validated['status'];
        if (!in_array($targetStatus, ['rejected', 'withdrawn'])) {
            if (!$application->canAdvanceTo($targetStatus)) {
                return back()->with('error', 'Cannot advance — required checklist items are incomplete.');
            }
        }

        $application->update([
            'status' => $targetStatus,
            'status_changed_at' => now(),
            'status_notes' => $validated['status_notes'] ?? $application->status_notes,
            'reviewed_by' => $user->id,
        ]);

        return back()->with('success', 'Status updated to ' . AgentApplication::STATUS_LABELS[$targetStatus] . '.');
    }

    public function uploadDocument($id, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $application = AgentApplication::findOrFail($id);

        $request->validate([
            'document_type' => ['required', 'in:id_copy,ffc_certificate,qualifications,pi_insurance,tax_clearance,proof_of_address,cv,other'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store('onboarding/' . $application->id, 'public');

        ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type' => $request->document_type,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function verifyDocument($docId, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $doc = ApplicationDocument::findOrFail($docId);

        $validated = $request->validate([
            'action' => ['required', 'in:verify,reject'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
        ]);

        if ($validated['action'] === 'verify') {
            $doc->update([
                'status' => 'verified',
                'verified_by' => $user->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);

            // Auto-tick related checklist items
            $this->autoTickChecklist($doc);
        } else {
            $doc->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['rejection_reason'],
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]);
        }

        return back()->with('success', 'Document ' . ($validated['action'] === 'verify' ? 'verified' : 'rejected') . '.');
    }

    public function toggleChecklist($itemId)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $item = OnboardingChecklist::findOrFail($itemId);

        if ($item->is_completed) {
            $item->update([
                'is_completed' => false,
                'completed_at' => null,
                'completed_by' => null,
            ]);
        } else {
            $item->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $user->id,
            ]);
        }

        return back()->with('success', 'Checklist updated.');
    }

    public function activate($id, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $application = AgentApplication::with('checklist')->findOrFail($id);

        // Check all required items complete
        if (!$application->canAdvanceTo('activated')) {
            return back()->with('error', 'Cannot activate — required checklist items are incomplete.');
        }

        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        $agencyId = $application->agency_id;

        // 1. Create User record
        $password = Str::random(12);
        $newUser = User::create([
            'name' => $application->full_name,
            'email' => $application->email,
            'password' => Hash::make($password),
            'role' => 'agent',
            'designation' => $application->designation_label,
            'agency_id' => $agencyId,
            'branch_id' => $validated['branch_id'] ?? null,
            'is_active' => true,
            'phone' => $application->phone,
            'ffc_number' => $application->ffc_number,
            'anniversary_date' => now()->toDateString(),
        ]);

        // 3. Create AgentSponsorship if referred
        if ($application->referred_by_user_id) {
            AgentSponsorship::firstOrCreate(
                ['agent_user_id' => $newUser->id],
                [
                    'sponsor_user_id' => $application->referred_by_user_id,
                    'sponsored_at' => now()->toDateString(),
                    'is_active' => true,
                ]
            );
        }

        // 4. Create AgentCapPeriod
        $settings = CommissionSetting::forAgency($agencyId);
        AgentCapPeriod::create([
            'user_id' => $newUser->id,
            'agency_id' => $agencyId,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addYear()->subDay()->toDateString(),
            'cap_amount' => $settings->annual_cap,
        ]);

        // 5. If candidate/intern, create AgentMentor placeholder
        if (in_array($application->designation, ['candidate_practitioner', 'intern'])) {
            AgentMentor::firstOrCreate(
                ['mentee_user_id' => $newUser->id],
                [
                    'mentor_user_id' => $user->id,
                    'assigned_at' => now()->toDateString(),
                    'transactions_required' => $settings->mentor_transactions ?? 3,
                ]
            );
        }

        // 6. Update application
        $application->update([
            'user_id' => $newUser->id,
            'activated_at' => now(),
            'activated_by' => $user->id,
            'status' => 'activated',
            'status_changed_at' => now(),
        ]);

        // Auto-tick user_account_created and portal_access
        OnboardingChecklist::where('application_id', $application->id)
            ->whereIn('item_key', ['user_account_created', 'portal_access'])
            ->update(['is_completed' => true, 'completed_at' => now(), 'completed_by' => $user->id]);

        return back()->with('success', "Agent {$application->full_name} activated. Temporary password: {$password}");
    }

    // ── Helpers ──

    private function autoTickChecklist(ApplicationDocument $doc): void
    {
        $mapping = [
            'id_copy' => 'identity_verified',
            'ffc_certificate' => 'ffc_valid',
            'pi_insurance' => 'pi_insurance',
            'tax_clearance' => 'tax_clearance',
            'proof_of_address' => 'proof_of_address',
            'qualifications' => 'qualifications_verified',
        ];

        $key = $mapping[$doc->document_type] ?? null;
        if (!$key) {
            return;
        }

        OnboardingChecklist::where('application_id', $doc->application_id)
            ->where('item_key', $key)
            ->where('is_completed', false)
            ->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => auth()->id(),
                'notes' => 'Auto-verified from document upload',
            ]);
    }
}
