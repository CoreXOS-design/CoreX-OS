<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
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
        abort_unless($user && $user->hasPermission('manage_templates'), 403);

        $template = Template::findOrFail($id);
        $template->assertAccessibleBy($user);
        if (! $user->isOwnerRole()) {
            $agencyId = $user->effectiveAgencyId();
            abort_unless($template->isVisibleToAgency($agencyId ? (int) $agencyId : null), 404);
        }

        $result = $this->report->forTemplate($template);

        return view('docuperfect.templates.readiness', [
            'template' => $template,
            'result' => $result,
            'checks' => TemplateReadinessReport::sorted($result['checks']),
        ]);
    }
}
