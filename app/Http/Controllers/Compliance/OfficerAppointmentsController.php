<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\OfficerAppointment;
use App\Services\Compliance\OfficerRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Company Settings › Compliance officers — the per-module RO / CO sections and the two agency
 * switches. Spec .ai/specs/esign-compliance-approval-gate.md §7 / §7.1.
 *
 * Every boolean write is guarded by a `_present` marker (onboarding spec §6.1): a form that did
 * not render a control never wipes it. Every user id is validated against the acting admin's own
 * agency (the FICA officer controller's scoped-exists idiom).
 */
class OfficerAppointmentsController extends Controller
{
    public function __construct(private OfficerRegistry $registry) {}

    /** POST /settings/officers/{module}/co — appoint the module's CO (empty = end with no replacement). */
    public function saveCo(Request $request, string $module)
    {
        [$agencyId] = $this->gate($module);

        $validated = $request->validate([
            'co_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('agency_id', $agencyId)],
        ]);

        $userId = (int) ($validated['co_user_id'] ?? 0);
        if ($userId > 0) {
            $row = $this->registry->appointCo($agencyId, $module, $userId, Auth::id());
            $msg = "{$row->full_name} appointed as " . $this->moduleLabel($module) . ' Compliance Officer.';
        } else {
            $this->registry->endCo($agencyId, $module);
            $msg = $this->moduleLabel($module) . ' Compliance Officer appointment ended.';
        }

        return back()->with('success', $msg)->with('tab', 'user');
    }

    /** POST /settings/officers/{module}/ros — diff-set the RO list. */
    public function saveRos(Request $request, string $module)
    {
        [$agencyId] = $this->gate($module);

        $validated = $request->validate([
            'ro_user_ids'   => 'nullable|array',
            'ro_user_ids.*' => ['integer', Rule::exists('users', 'id')->where('agency_id', $agencyId)],
        ]);

        $this->registry->saveRos($agencyId, $module, $validated['ro_user_ids'] ?? [], Auth::id());

        return back()->with('success', $this->moduleLabel($module) . ' Reporting Officers updated.')->with('tab', 'user');
    }

    /** POST /settings/esign-approval-route */
    public function saveEsignRoute(Request $request)
    {
        [$agencyId] = $this->gate(OfficerAppointment::MODULE_ESIGN);

        if ($request->has('esign_route_present')) {
            $validated = $request->validate([
                'esign_approval_route' => ['required', Rule::in([OfficerRegistry::ESIGN_ROUTE_FULL_STATUS, OfficerRegistry::ESIGN_ROUTE_RO_CO])],
            ]);
            $this->registry->setEsignRoute($agencyId, $validated['esign_approval_route']);
        }

        return back()->with('success', 'E-sign approval route saved.')->with('tab', 'user');
    }

    /** POST /settings/whistleblow-submit-policy */
    public function saveWhistleblowSubmitPolicy(Request $request)
    {
        [$agencyId] = $this->gate(OfficerAppointment::MODULE_WHISTLEBLOW);

        if ($request->has('whistleblow_submit_policy_present')) {
            $this->registry->setWhistleblowRosMaySubmit($agencyId, $request->boolean('whistleblow_ro_can_submit'));
        }

        return back()->with('success', 'Compliance reporting policy saved.')->with('tab', 'user');
    }

    /**
     * The Setup Wizard's narrow saver (spec §7.1 / Non-negotiable #10a). Writes ONLY what the step
     * posted; every list and boolean is guarded by its own `_present` marker.
     */
    public function onboardingSave(Request $request)
    {
        abort_unless(Auth::user()?->hasPermission('manage_compliance_officer'), 403);
        $agencyId = (int) (Auth::user()->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 403);

        $scoped = Rule::exists('users', 'id')->where('agency_id', $agencyId);
        $validated = $request->validate([
            'esign_co_user_id'          => ['nullable', 'integer', $scoped],
            'esign_ro_user_ids'         => 'nullable|array',
            'esign_ro_user_ids.*'       => ['integer', $scoped],
            'whistleblow_co_user_id'    => ['nullable', 'integer', $scoped],
            'whistleblow_ro_user_ids'   => 'nullable|array',
            'whistleblow_ro_user_ids.*' => ['integer', $scoped],
            'esign_approval_route'      => ['nullable', Rule::in([OfficerRegistry::ESIGN_ROUTE_FULL_STATUS, OfficerRegistry::ESIGN_ROUTE_RO_CO])],
        ]);

        $route = $request->has('esign_route_present') ? (string) ($validated['esign_approval_route'] ?? '') : '';

        // Order matters, both ways. Switching the route OFF goes first so the same post may also end
        // the CO (the end-CO guard only bites while the route is on); switching it ON goes last so
        // it can see the CO the same post appointed.
        if ($route === OfficerRegistry::ESIGN_ROUTE_FULL_STATUS) {
            $this->registry->setEsignRoute($agencyId, $route);
        }

        foreach ([OfficerAppointment::MODULE_ESIGN => 'esign', OfficerAppointment::MODULE_WHISTLEBLOW => 'whistleblow'] as $module => $prefix) {
            if ($request->has("{$prefix}_co_present")) {
                $coId = (int) ($validated["{$prefix}_co_user_id"] ?? 0);
                if ($coId > 0) {
                    $this->registry->appointCo($agencyId, $module, $coId, Auth::id());
                } elseif ($this->registry->currentCo($agencyId, $module)) {
                    $this->registry->endCo($agencyId, $module);
                }
            }
            if ($request->has("{$prefix}_ros_present")) {
                $this->registry->saveRos($agencyId, $module, $validated["{$prefix}_ro_user_ids"] ?? [], Auth::id());
            }
        }

        if ($route === OfficerRegistry::ESIGN_ROUTE_RO_CO) {
            $this->registry->setEsignRoute($agencyId, $route);
        }

        if ($request->has('whistleblow_submit_policy_present')) {
            $this->registry->setWhistleblowRosMaySubmit($agencyId, $request->boolean('whistleblow_ro_can_submit'));
        }

        return back();
    }

    /** @return array{0:int} */
    private function gate(string $module): array
    {
        abort_unless(in_array($module, OfficerAppointment::MODULES, true), 404);
        abort_unless(Auth::user()?->hasPermission('manage_compliance_officer'), 403);
        $agencyId = (int) (Auth::user()->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 403);

        return [$agencyId];
    }

    private function moduleLabel(string $module): string
    {
        return $module === OfficerAppointment::MODULE_ESIGN ? 'E-sign' : 'Compliance reporting';
    }
}
