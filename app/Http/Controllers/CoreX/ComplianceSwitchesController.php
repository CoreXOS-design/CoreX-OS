<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Services\Compliance\ComplianceGateSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-509 — Settings → Agency → Compliance Switches (.ai/specs/property-compliance-gate-settings.md §7.1).
 *
 * update() is ALSO the Setup Wizard's saver for the Properties & listings step (spec §11). Per
 * .ai/specs/agency-onboarding-setup.md §6.1 it does nothing unless the form's marker field was posted, and
 * the service only reads the gate keys that were posted — a step that never rendered a switch can never
 * change it.
 */
class ComplianceSwitchesController extends Controller
{
    public function __construct(private ComplianceGateSettingsService $gates)
    {
    }

    public function edit(Request $request): View
    {
        abort_unless($request->user()->hasPermission('compliance_switches.manage'), 403);

        $agencyId = (int) $request->user()->effectiveAgencyId();
        abort_unless($agencyId > 0, 404);

        return view('corex.settings.compliance-switches', self::formData($this->gates, $agencyId));
    }

    public function update(Request $request): RedirectResponse
    {
        if (! $request->has('compliance_switches_submitted')) {
            return back();
        }

        abort_unless($request->user()->hasPermission('compliance_switches.manage'), 403);

        $agencyId = (int) $request->user()->effectiveAgencyId();
        abort_unless($agencyId > 0, 404);

        $request->validate([
            'gates'                    => ['required', 'array'],
            'gates.*'                  => ['in:0,1'],
            'compliance_disclaimer_accepted' => ['nullable', 'string', 'max:32'],
        ]);

        $posted = array_map(fn ($v) => (string) $v === '1', (array) $request->input('gates'));

        $changes = $this->gates->apply(
            $agencyId,
            self::agencyName($agencyId),
            $posted,
            $request->user(),
            $request,
            $request->input('compliance_disclaimer_accepted'),
        );

        $message = $changes === []
            ? 'No compliance switches changed.'
            : 'Compliance switches saved. Checks switched on apply to every listing that is not live yet; listings already live keep the checks they went live with.';

        return back()->with('success', $message);
    }

    /**
     * The data the shared form partial needs. Used by this page AND the Setup Wizard's
     * Properties & listings step, so both render exactly the same switches and disclaimer.
     */
    public static function formData(ComplianceGateSettingsService $gates, int $agencyId): array
    {
        $agencyName = self::agencyName($agencyId);

        return [
            'complianceGates'      => $gates->gatesFor($agencyId),
            'complianceLastChange' => $gates->lastChangeFor($agencyId),
            'complianceDisclaimer' => [
                'version'      => $gates->disclaimerVersion(),
                'agency'       => $agencyName,
                'template'     => $gates->renderDisclaimer($agencyName, ['{gates}']),
                'accept_label' => strtr((string) config('corex-compliance.gate_disclaimer.accept_label'), ['{agency}' => $agencyName]),
            ],
        ];
    }

    private static function agencyName(int $agencyId): string
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);

        return (string) ($agency?->trading_name ?: $agency?->name ?: 'the agency');
    }
}
