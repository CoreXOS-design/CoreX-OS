<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\User;
use App\Services\Compliance\FicaReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FicaOfficerAppointmentsController extends Controller
{
    /**
     * Appoint a new primary compliance officer for the agency.
     * Auto-ends the current primary via model observer.
     */
    public function savePrimary(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $agencyId = Auth::user()->effectiveAgencyId();

        $validated = $request->validate([
            // AT-security fix — a foreign-agency user_id must never be appointable
            // here: the acting admin's `manage_compliance_officer` permission is
            // scoped to their own agency, so the target user must be too (mirrors
            // DealV2Controller/PayrollEmployeeController's scoped-exists idiom).
            'user_id'                => ['nullable', Rule::exists('users', 'id')->where('agency_id', $agencyId)],
            'full_name'              => 'required|string|max:200',
            'id_number'              => 'nullable|string|max:20',
            'cell'                   => 'nullable|string|max:50',
            'email'                  => 'nullable|email|max:255',
            'appointed_on'           => 'required|date',
            'appointment_letter'     => 'nullable|file|mimes:pdf|max:10240',
            'notes'                  => 'nullable|string|max:2000',
        ]);

        $letterPath = null;
        if ($request->hasFile('appointment_letter')) {
            $letterPath = $request->file('appointment_letter')
                ->store("fica-officers/{$agencyId}", 'local');
        }

        FicaOfficerAppointment::create([
            'agency_id'              => $agencyId,
            'branch_id'              => null,
            'user_id'                => $validated['user_id'],
            'role'                   => FicaOfficerAppointment::ROLE_PRIMARY,
            'full_name'              => $validated['full_name'],
            'id_number'              => $validated['id_number'],
            'cell'                   => $validated['cell'],
            'email'                  => $validated['email'],
            'title'                  => 'FICA Compliance Officer',
            'appointed_on'           => $validated['appointed_on'],
            'appointed_by'           => Auth::id(),
            'appointment_letter_path' => $letterPath,
            'notes'                  => $validated['notes'],
        ]);

        // AT-269 — the CO who receives referrals just changed: re-route any open
        // escalations to the new recipient (or return them if none now resolves).
        app(FicaReferralService::class)->reconcileOpenReferrals((int) $agencyId);

        return back()->with('success', "{$validated['full_name']} appointed as Primary Compliance Officer.")
            ->with('tab', 'user');
    }

    /**
     * Save MLRO list — ends removed, creates new.
     */
    public function saveMlros(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $agencyId = Auth::user()->effectiveAgencyId();

        $validated = $request->validate([
            'mlro_user_ids'   => 'nullable|array',
            // AT-security fix — scoped to the acting admin's own agency (see savePrimary above).
            'mlro_user_ids.*' => Rule::exists('users', 'id')->where('agency_id', $agencyId),
        ]);
        $newIds = $validated['mlro_user_ids'] ?? [];

        // Current active MLROs for this agency
        $currentMlros = FicaOfficerAppointment::where('agency_id', $agencyId)
            ->mlro()
            ->active()
            ->get();

        // End MLROs no longer in the list
        foreach ($currentMlros as $mlro) {
            if ($mlro->user_id && !in_array($mlro->user_id, $newIds)) {
                $mlro->update(['ended_on' => now()->toDateString()]);
            }
        }

        // Create new MLROs not already active
        $existingUserIds = $currentMlros->pluck('user_id')->filter()->toArray();
        foreach ($newIds as $userId) {
            if (in_array($userId, $existingUserIds)) {
                continue;
            }

            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            FicaOfficerAppointment::create([
                'agency_id'    => $agencyId,
                'branch_id'    => $user->branch_id,
                'user_id'      => $userId,
                'role'         => FicaOfficerAppointment::ROLE_MLRO,
                'full_name'    => $user->name,
                'email'        => $user->email,
                'title'        => 'Money Laundering Reporting Officer',
                'appointed_on' => now()->toDateString(),
                'appointed_by' => Auth::id(),
            ]);
        }

        // AT-269 — an MLRO may be the configured referral recipient; re-resolve.
        app(FicaReferralService::class)->reconcileOpenReferrals((int) $agencyId);

        return back()->with('success', 'MLROs updated.')
            ->with('tab', 'user');
    }

    /**
     * End an appointment (soft — sets ended_on).
     */
    public function endAppointment(FicaOfficerAppointment $appointment)
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);

        $appointment->update(['ended_on' => now()->toDateString()]);

        // AT-269 — ending an officer may leave open referrals without a recipient;
        // re-route to whoever now resolves, or return them to their referrers.
        app(FicaReferralService::class)->reconcileOpenReferrals((int) $appointment->agency_id);

        return back()->with('success', "{$appointment->full_name}'s appointment ended.")
            ->with('tab', 'user');
    }

    /**
     * AT-236 — save the agency's Refer-to-CO settings (feature on/off + recipient CO).
     * Defaults are ON / primary CO; a chosen recipient must be an active officer, else
     * it falls back to the primary. The boolean is only written when the form actually
     * rendered it (§6.1 — a subset post must never silently wipe the toggle).
     */
    public function saveReferralSettings(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('manage_compliance_officer'), 403);
        $agencyId = (int) (Auth::user()->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 403);

        $validated = $request->validate([
            'fica_referral_recipient_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $recipientId = $validated['fica_referral_recipient_user_id'] ?? null;
        if ($recipientId) {
            $isOfficer = FicaOfficerAppointment::where('agency_id', $agencyId)
                ->where('user_id', $recipientId)->active()->exists();
            if (! $isOfficer) {
                $recipientId = null; // only an active officer may receive referrals
            }
        }

        $update = ['fica_referral_recipient_user_id' => $recipientId];
        if ($request->has('fica_referral_settings_present')) {
            $update['fica_referral_enabled'] = $request->boolean('fica_referral_enabled');
        }

        \App\Models\Agency::withoutGlobalScopes()->whereKey($agencyId)->update($update);

        // AT-269 — the referral recipient may have just changed; re-route open packs.
        app(FicaReferralService::class)->reconcileOpenReferrals((int) $agencyId);

        return back()->with('success', 'Refer-to-CO settings saved.')->with('tab', 'user');
    }
}
