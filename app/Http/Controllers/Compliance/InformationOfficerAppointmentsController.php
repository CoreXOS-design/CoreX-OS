<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\InformationOfficerAppointment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 9c-2 — POPIA s55 Information Officer admin actions.
 * Mirrors FicaOfficerAppointmentsController.
 */
class InformationOfficerAppointmentsController extends Controller
{
    /** Appoint a new primary IO. Auto-ends the current primary via the model boot hook. */
    public function savePrimary(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('manage_information_officer'), 403);

        $validated = $request->validate([
            'user_id'              => 'nullable|exists:users,id',
            'full_name'            => 'required|string|max:200',
            'id_number'            => 'nullable|string|max:20',
            'cell'                 => 'nullable|string|max:50',
            'email'                => 'nullable|email|max:255',
            'appointed_on'            => 'required|date',
            'regulator_registered_on' => 'nullable|date',
            'appointment_letter'      => 'nullable|file|mimes:pdf|max:10240',
            'notes'                => 'nullable|string|max:2000',
        ]);

        $agencyId = Auth::user()->effectiveAgencyId();

        $letterPath = null;
        if ($request->hasFile('appointment_letter')) {
            $letterPath = $request->file('appointment_letter')
                ->store("information-officers/{$agencyId}", 'local');
        }

        InformationOfficerAppointment::create([
            'agency_id'               => $agencyId,
            'branch_id'               => null,
            'user_id'                 => $validated['user_id'],
            'role'                    => InformationOfficerAppointment::ROLE_PRIMARY,
            'full_name'               => $validated['full_name'],
            'id_number'               => $validated['id_number'],
            'cell'                    => $validated['cell'],
            'email'                   => $validated['email'],
            'title'                   => 'Information Officer',
            'appointed_on'            => $validated['appointed_on'],
            'regulator_registered_on' => $validated['regulator_registered_on'] ?? null,
            'appointed_by'            => Auth::id(),
            'appointment_letter_path' => $letterPath,
            'notes'                   => $validated['notes'],
        ]);

        return back()->with('success', "{$validated['full_name']} appointed as Primary Information Officer.")
            ->with('tab', 'user');
    }

    /** Save deputy IO list — ends removed, creates new (mirrors MLRO list pattern). */
    public function saveDeputies(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('manage_information_officer'), 403);

        $validated = $request->validate([
            'deputy_user_ids'   => 'nullable|array',
            'deputy_user_ids.*' => 'exists:users,id',
        ]);

        $agencyId = Auth::user()->effectiveAgencyId();
        $newIds = $validated['deputy_user_ids'] ?? [];

        $currentDeputies = InformationOfficerAppointment::where('agency_id', $agencyId)
            ->deputies()
            ->active()
            ->get();

        // End deputies removed from list.
        foreach ($currentDeputies as $deputy) {
            if ($deputy->user_id && !in_array($deputy->user_id, $newIds)) {
                $deputy->update(['ended_on' => now()->toDateString()]);
            }
        }

        // Add deputies not already active.
        $existingUserIds = $currentDeputies->pluck('user_id')->filter()->toArray();
        foreach ($newIds as $userId) {
            if (in_array($userId, $existingUserIds)) {
                continue;
            }
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            InformationOfficerAppointment::create([
                'agency_id'    => $agencyId,
                'branch_id'    => $user->branch_id,
                'user_id'      => $userId,
                'role'         => InformationOfficerAppointment::ROLE_DEPUTY,
                'full_name'    => $user->name,
                'email'        => $user->email,
                'title'        => 'Deputy Information Officer',
                'appointed_on' => now()->toDateString(),
                'appointed_by' => Auth::id(),
            ]);
        }

        return back()->with('success', 'Deputy Information Officers updated.')
            ->with('tab', 'user');
    }

    /** End an appointment (soft — sets ended_on). */
    public function endAppointment(InformationOfficerAppointment $appointment)
    {
        abort_unless(Auth::user()->hasPermission('manage_information_officer'), 403);

        $appointment->update(['ended_on' => now()->toDateString()]);

        return back()->with('success', "{$appointment->full_name}'s appointment ended.")
            ->with('tab', 'user');
    }
}
