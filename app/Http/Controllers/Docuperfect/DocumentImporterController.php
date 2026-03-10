<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Services\Docuperfect\DocxParserService;
use App\Services\Docuperfect\DocumentTemplateGenerator;
use Illuminate\Http\Request;

class DocumentImporterController extends Controller
{
    /**
     * Show the upload form.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        return view('docuperfect.importer.index');
    }

    /**
     * Parse an uploaded .docx file and return results to the review screen.
     */
    public function parse(Request $request, DocxParserService $parser)
    {
        \Log::info('DocxParser controller: parse() called');
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $request->validate([
            'docx_file' => ['required', 'file', 'max:10240'],
            'template_name' => ['required', 'string', 'max:255'],
        ]);

        $file = $request->file('docx_file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['docx'])) {
            return back()->withErrors(['docx_file' => 'Please upload a .docx file.']);
        }

        try {
            set_time_limit(60);
            $parsed = $parser->parse($file->getRealPath());
        } catch (\Exception $e) {
            return back()->withErrors([
                'docx_file' => 'Failed to parse document: ' . $e->getMessage(),
            ]);
        }

        // Store parsed data in session for the generate step
        session()->put('docx_import', [
            'parsed' => $parsed,
            'template_name' => $request->input('template_name'),
            'original_filename' => $file->getClientOriginalName(),
        ]);

        return view('docuperfect.importer.review', [
            'parsed' => $parsed,
            'templateName' => $request->input('template_name'),
            'fields' => $parsed['fields'],
        ]);
    }

    /**
     * Generate a template from confirmed field mappings.
     */
    public function generate(Request $request, DocumentTemplateGenerator $generator)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $importData = session('docx_import');
        if (!$importData) {
            return redirect()->route('docuperfect.import.index')
                ->with('error', 'No import data found. Please upload a document first.');
        }

        $request->validate([
            'template_name' => ['required', 'string', 'max:255'],
            'fields' => ['required', 'array'],
            'fields.*.key' => ['required', 'string'],
            'fields.*.label' => ['required', 'string'],
            'fields.*.pillar' => ['required', 'string'],
            'fields.*.assigned_to' => ['required', 'string', 'in:agent,lessor,lessee,buyer,seller'],
            'fields.*.field_type' => ['nullable', 'string', 'in:text,date,number'],
        ]);

        $fieldMappings = $request->input('fields');
        $templateName = $request->input('template_name', $importData['template_name']);

        $template = $generator->generate(
            $importData['parsed'],
            $fieldMappings,
            $templateName,
            $user->id
        );

        // Clear session data
        session()->forget('docx_import');

        return redirect()->route('docuperfect.templates.index')
            ->with('success', 'Template "' . $template->name . '" imported successfully.');
    }
}
