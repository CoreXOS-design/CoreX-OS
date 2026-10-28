<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use App\Services\Docuperfect\Release\AgencyDocumentAuthorisers;
use Illuminate\Http\Request;

/**
 * .ai/specs/esign-template-readiness.md — the read-only readiness report for one template. The gate is the same pair the web preview
 * uses (manage_templates + the template's own agency visibility); a template of another agency is a 404 by direct URL.
 */
class TemplateReadinessController extends Controller
{
    public function __construct(private readonly TemplateReadinessReport $report)
    {
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $template = Template::findOrFail($id);
        self::gate($user, $template);

        $result = $this->report->forTemplate($template);

        return view('docuperfect.templates.readiness', [
            'template' => $template,
            'result' => $result,
            'checks' => TemplateReadinessReport::sorted($result['checks']),
        ]);
    }

    /**
     * The one gate for the readiness and demo screens. An ordinary template: manage_templates + the template's own agency (404 for another agency).
     * A released copy awaiting approval is not the agency's to browse: only the CoreX owner and that agency's authorisers may look at it, so they
     * can check it before approving (esign-template-transfer.md §13.6).
     */
    public static function gate(\App\Models\User $user, Template $template): void
    {
        if ($template->isHeldBack()) {
            abort_unless($user->isOwnerRole() || AgencyDocumentAuthorisers::canAuthorise($user, (int) $template->agency_id), 404);

            return;
        }
        abort_unless($user->hasPermission('manage_templates'), 403);
        $template->assertAccessibleBy($user);
        if (! $user->isOwnerRole()) {
            $agencyId = $user->effectiveAgencyId();
            abort_unless($template->isVisibleToAgency($agencyId ? (int) $agencyId : null), 404);
        }
    }
}
