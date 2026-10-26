<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\CdsReimportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Re-import from Word on an existing CDS template. Spec: .ai/specs/cds-reimport-from-word.md.
 * Gate: the builder's own — manage_templates + the template is accessible to the caller.
 */
class TemplateReimportController extends Controller
{
    public function __construct(private readonly CdsReimportService $reimport)
    {
    }

    public function store(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('manage_templates'), 403);

        $template = Template::findOrFail($id);
        $template->assertAccessibleBy($user);

        // Client extension, not sniffed MIME: a real .docx often sniffs as application/zip
        // (same reasoning as DocumentImporterController::generateCdsTemplate).
        $request->validate(['document' => 'required|file|extensions:docx|max:51200'], [
            'document.required' => 'Choose the corrected Word file (.docx) to re-import.',
            'document.extensions' => 'That is not a Word .docx file.',
            'document.max' => 'That file is too large (50 MB limit).',
        ]);

        if ($template->template_type !== 'cds') {
            return back()->withErrors(['document' => 'Only a template built from a Word file can be re-imported.']);
        }

        try {
            $staged = $this->reimport->stage($template, $request->file('document'), $user);
        } catch (\Throwable $e) {
            Log::warning('CDS reimport failed', ['template_id' => $template->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['document' => $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException
                ? $e->getMessage()
                : 'That file could not be read. Check it is a Word .docx and try again.']);
        }

        return redirect()->route('docuperfect.cds.builder', $staged['draft'])
            ->with('status', 'Word file read. Nothing has changed on the template yet — check the match report, then Save.');
    }
}
