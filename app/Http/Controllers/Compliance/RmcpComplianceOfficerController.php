<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\RmcpComplianceOfficer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @deprecated 2026-04-21 — Replaced by FicaOfficerAppointmentsController.
 * Routes removed. CO management moved to Settings → Users → FICA Officers.
 */
class RmcpComplianceOfficerController extends Controller
{
    public function index()
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $agencyId = Auth::user()->effectiveAgencyId();

        $currentOfficer = RmcpComplianceOfficer::where('agency_id', $agencyId)
            ->current()
            ->first();

        $historicalOfficers = RmcpComplianceOfficer::where('agency_id', $agencyId)
            ->whereNotNull('ended_on')
            ->orderByDesc('ended_on')
            ->get();

        return view('compliance.officer.index', compact('currentOfficer', 'historicalOfficers'));
    }

    public function create()
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $agencyId = Auth::user()->effectiveAgencyId();
        $users = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('compliance.officer.create', compact('users'));
    }

    public function store(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $validated = $request->validate([
            'user_id'           => 'nullable|exists:users,id',
            'full_name'         => 'required|string|max:200',
            'id_number'         => 'nullable|string|max:20',
            'cell'              => 'nullable|string|max:50',
            'email'             => 'nullable|email|max:255',
            'title'             => 'nullable|string|max:100',
            'appointed_on'      => 'required|date',
            'appointment_notes' => 'nullable|string|max:2000',
        ]);

        $agencyId = Auth::user()->effectiveAgencyId();

        // End the current officer
        RmcpComplianceOfficer::where('agency_id', $agencyId)
            ->whereNull('ended_on')
            ->update(['ended_on' => now()->subDay()->toDateString()]);

        RmcpComplianceOfficer::create([
            'agency_id'        => $agencyId,
            'user_id'          => $validated['user_id'],
            'full_name'        => $validated['full_name'],
            'id_number'        => $validated['id_number'],
            'cell'             => $validated['cell'],
            'email'            => $validated['email'],
            'title'            => $validated['title'] ?? 'FICA Compliance Officer',
            'appointed_on'     => $validated['appointed_on'],
            'appointed_by'     => Auth::id(),
            'appointment_notes' => $validated['appointment_notes'],
        ]);

        return redirect()->route('compliance.officer.index')
            ->with('success', "{$validated['full_name']} appointed as compliance officer.");
    }

    public function edit(RmcpComplianceOfficer $officer)
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $agencyId = Auth::user()->effectiveAgencyId();
        $users = User::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('compliance.officer.edit', compact('officer', 'users'));
    }

    public function update(Request $request, RmcpComplianceOfficer $officer)
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $validated = $request->validate([
            'full_name'         => 'required|string|max:200',
            'id_number'         => 'nullable|string|max:20',
            'cell'              => 'nullable|string|max:50',
            'email'             => 'nullable|email|max:255',
            'title'             => 'nullable|string|max:100',
            'appointment_notes' => 'nullable|string|max:2000',
        ]);

        $officer->update($validated);

        return redirect()->route('compliance.officer.index')
            ->with('success', 'Compliance officer updated.');
    }

    public function end(RmcpComplianceOfficer $officer)
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $officer->update(['ended_on' => now()->toDateString()]);

        return redirect()->route('compliance.officer.index')
            ->with('success', "{$officer->full_name}'s appointment ended.");
    }
}
