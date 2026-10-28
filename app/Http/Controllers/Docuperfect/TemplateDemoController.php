<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Demo\TemplateDemoRenderer;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use Illuminate\Http\Request;

/**
 * .ai/specs/esign-template-demo-preview.md — the demo view of one template: sample data, every blank, every place to sign marked for its
 * signer, the page count the signing link would show. GET only, read only, nothing stored or sent. Same gate as the readiness screen
 * (manage_templates + the template's own agency, 404 across agencies; a released copy awaiting approval is open to the owner and that agency's
 * authorisers).
 */
class TemplateDemoController extends Controller
{
    public function __construct(private readonly TemplateDemoRenderer $renderer, private readonly TemplateReadinessReport $readiness)
    {
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $template = Template::findOrFail($id);
        TemplateReadinessController::gate($user, $template);

        $demo = $this->renderer->render($template);
        $result = $this->readiness->forTemplate($template);

        return view('docuperfect.templates.demo', [
            'template' => $template,
            'demo' => $demo,
            'readiness' => $result,
            'readinessChecks' => array_values(array_filter(TemplateReadinessReport::sorted($result['checks']), fn ($c) => in_array($c['status'], ['fail', 'warn'], true))),
        ]);
    }
}
