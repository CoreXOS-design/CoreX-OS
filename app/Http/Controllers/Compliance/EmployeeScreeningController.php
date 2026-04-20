<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\EmployeeScreening;
use App\Models\Compliance\EmployeeScreeningCheck;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmployeeScreeningController extends Controller
{
    public function index(Request $request)
    {
        $query = EmployeeScreening::with(['user', 'initiator'])
            ->orderBy('next_due_on');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('risk_tier')) {
            $query->where('risk_tier', $request->risk_tier);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $sort = $request->query('sort', 'next_due_on');
        $direction = $request->query('direction', 'asc');
        $allowed = ['initiated_on', 'completed_on', 'next_due_on', 'status', 'risk_tier'];
        if (in_array($sort, $allowed)) {
            $query->reorder($sort, $direction);
        }

        $screenings = $query->paginate(20)->withQueryString();

        return view('compliance.screenings.index', compact('screenings', 'sort', 'direction'));
    }

    public function show(EmployeeScreening $screening)
    {
        $screening->load(['user', 'checks.checker', 'checks.supportingDocument', 'initiator', 'completer']);

        $expectedTypes = $screening->expectedChecks();
        $existingTypes = $screening->checks->pluck('check_type')->toArray();

        // Ensure all expected checks exist
        foreach ($expectedTypes as $type) {
            if (!in_array($type, $existingTypes)) {
                EmployeeScreeningCheck::create([
                    'employee_screening_id' => $screening->id,
                    'check_type'            => $type,
                    'result'                => 'pending',
                ]);
            }
        }

        $screening->load('checks.checker');

        return view('compliance.screenings.show', compact('screening'));
    }

    public function create(Request $request, ?User $user = null)
    {
        $agencyId = Auth::user()->effectiveAgencyId();
        $users = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'risk_tier', 'screening_status']);

        $selectedUser = $user;
        $suggestedType = 'periodic';
        if ($selectedUser && $selectedUser->screening_status === 'never_screened') {
            $suggestedType = 'pre_employment';
        }

        return view('compliance.screenings.create', compact('users', 'selectedUser', 'suggestedType'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'        => 'required|exists:users,id',
            'screening_type' => 'required|in:pre_employment,periodic,tfs_list_update,triggered',
            'risk_tier'      => 'required|in:high,medium,low',
        ]);

        $screening = EmployeeScreening::create([
            'agency_id'      => Auth::user()->effectiveAgencyId(),
            'user_id'        => $validated['user_id'],
            'screening_type' => $validated['screening_type'],
            'risk_tier'      => $validated['risk_tier'],
            'status'         => 'in_progress',
            'initiated_on'   => now()->toDateString(),
            'initiated_by'   => Auth::id(),
        ]);

        // Update user screening status
        User::where('id', $validated['user_id'])->update([
            'screening_status' => 'pre_employment_pending',
        ]);

        // Create check rows
        $checkTypes = EmployeeScreeningCheck::typesForScreening($validated['screening_type']);
        foreach ($checkTypes as $type) {
            EmployeeScreeningCheck::create([
                'employee_screening_id' => $screening->id,
                'check_type'            => $type,
                'result'                => 'pending',
            ]);
        }

        return redirect()->route('compliance.screenings.show', $screening)
            ->with('success', 'Screening started.');
    }

    public function updateCheck(Request $request, EmployeeScreeningCheck $check)
    {
        $validated = $request->validate([
            'result'           => 'required|in:clear,concerns,fail,not_applicable',
            'notes'            => 'nullable|string|max:2000',
            'reference_number' => 'nullable|string|max:100',
        ]);

        $check->update([
            'result'           => $validated['result'],
            'checked_on'       => now()->toDateString(),
            'checked_by'       => Auth::id(),
            'notes'            => $validated['notes'] ?? $check->notes,
            'reference_number' => $validated['reference_number'] ?? $check->reference_number,
        ]);

        $screening = $check->screening;

        return response()->json([
            'success'    => true,
            'message'    => 'Check updated.',
            'completion' => $screening->completionPercent(),
        ]);
    }

    public function uploadCheckDocument(Request $request, EmployeeScreeningCheck $check)
    {
        $request->validate([
            'document' => 'required|file|max:10240',
        ]);

        $screening = $check->screening;
        $file = $request->file('document');

        $doc = UserDocument::create([
            'agency_id'     => $screening->agency_id,
            'user_id'       => $screening->user_id,
            'document_type' => 'other',
            'file_path'     => $file->store("screening-docs/{$screening->agency_id}", 'local'),
            'file_name'     => $file->getClientOriginalName(),
            'file_size'     => $file->getSize(),
            'mime_type'     => $file->getMimeType(),
            'status'        => 'pending',
            'uploaded_by'   => Auth::id(),
            'notes'         => "Screening check: " . ($check->check_type ?? ''),
        ]);

        $check->update(['supporting_document_id' => $doc->id]);

        return response()->json(['success' => true, 'document_id' => $doc->id, 'file_name' => $doc->file_name]);
    }

    public function complete(Request $request, EmployeeScreening $screening)
    {
        $validated = $request->validate([
            'overall_result' => 'required|in:pass,concerns_flagged,fail',
            'summary_notes'  => 'nullable|string|max:5000',
        ]);

        // Verify all checks are completed
        $pendingCount = $screening->checks()->where('result', 'pending')->count();
        if ($pendingCount > 0) {
            return back()->with('error', "{$pendingCount} check(s) still pending.");
        }

        $screening->complete($validated['overall_result'], $validated['summary_notes'], Auth::user());

        return redirect()->route('compliance.screenings.show', $screening)
            ->with('success', 'Screening completed.');
    }

    public function flag(Request $request, EmployeeScreening $screening)
    {
        $validated = $request->validate([
            'summary_notes' => 'required|string|max:5000',
        ]);

        $screening->update([
            'status'        => 'flagged',
            'summary_notes' => $validated['summary_notes'],
        ]);

        $screening->user->update(['screening_status' => 'concerns_flagged']);

        Log::info('Employee screening flagged', [
            'screening_id' => $screening->id,
            'user_id'      => $screening->user_id,
            'flagged_by'   => Auth::id(),
        ]);

        return redirect()->route('compliance.screenings.show', $screening)
            ->with('success', 'Screening flagged for concerns.');
    }

    public function overdueReport()
    {
        $agencyId = Auth::user()->effectiveAgencyId();

        $overdueUsers = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('screening_status', ['never_screened', 'overdue', 'expired'])
            ->orderBy('screening_due_on')
            ->paginate(20);

        return view('compliance.screenings.overdue', compact('overdueUsers'));
    }

    /**
     * User-facing: view own screening history (read-only).
     */
    public function myScreenings()
    {
        $screenings = EmployeeScreening::where('user_id', Auth::id())
            ->with('checks')
            ->orderByDesc('initiated_on')
            ->paginate(20);

        return view('compliance.screenings.my-screenings', compact('screenings'));
    }
}
