<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalNoticeTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-portal-access.md §8/§10 — AT-445. Agency-owned
 * breach-notice / notice-to-vacate templates. Full CRUD + archive/restore,
 * search/sort/filter/pagination per BUILD_STANDARD §1a, agency-scoped via
 * BelongsToAgency + AgencyScope on the model. Mirrors
 * RentalFaultTypeController's shape exactly.
 */
class RentalNoticeTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $query = RentalNoticeTemplate::query();

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($type = $request->get('notice_type')) {
            $query->where('notice_type', $type);
        }

        $status = $request->get('status', 'active');
        if ($status === 'archived') {
            $query->onlyTrashed();
        } else {
            $query->where('is_active', true);
        }

        $sort = $request->get('sort', 'name');
        $allowedSorts = ['name', 'notice_type', 'created_at'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'name';
        }
        $query->orderBy($sort);

        $templates = $query->paginate(25)->withQueryString();

        return view('corex.rental-notice-templates.index', compact('templates', 'status', 'sort'));
    }

    public function create(): View
    {
        return view('corex.rental-notice-templates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $data = $this->withUploadedDocument($request, $data);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }

        $template = RentalNoticeTemplate::create($data + ['created_by_user_id' => $request->user()->id]);

        return redirect()->route('corex.rental-notice-templates.index')->with('success', "Template '{$template->name}' created.");
    }

    public function edit(RentalNoticeTemplate $rentalNoticeTemplate): View
    {
        return view('corex.rental-notice-templates.edit', ['template' => $rentalNoticeTemplate]);
    }

    public function update(Request $request, RentalNoticeTemplate $rentalNoticeTemplate): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $data = $this->withUploadedDocument($request, $data);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }
        $rentalNoticeTemplate->update($data);

        return redirect()->route('corex.rental-notice-templates.index')->with('success', "Template '{$rentalNoticeTemplate->name}' updated.");
    }

    public function archive(RentalNoticeTemplate $rentalNoticeTemplate): RedirectResponse
    {
        $rentalNoticeTemplate->delete();

        return back()->with('success', "Template '{$rentalNoticeTemplate->name}' archived.");
    }

    public function restore(int $id): RedirectResponse
    {
        $template = RentalNoticeTemplate::onlyTrashed()->findOrFail($id);
        $template->restore();

        return back()->with('success', "Template '{$template->name}' restored.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'notice_type' => ['required', 'in:' . implode(',', RentalNoticeTemplate::TYPES)],
            // the body is typed OR comes from an uploaded Word document (rental-notices.md §3)
            'body_html' => ['required_without:document', 'nullable', 'string', 'max:50000'],
            'document' => ['nullable', 'file', 'mimes:docx', 'max:10240'],
            'default_clause' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'body_html.required_without' => 'Upload your Word document, or type the notice text.',
            'document.mimes' => 'The template must be a Word document (.docx).',
        ]);
        unset($data['document']);

        return $data + ['is_active' => $request->boolean('is_active', true)];
    }

    /**
     * An uploaded Word document becomes the template body (the same converter the e-sign importer uses); the original is kept on the
     * template. When both are given the document wins - the agent can edit the converted text afterwards.
     */
    private function withUploadedDocument(Request $request, array $data): array
    {
        $file = $request->file('document');
        if (! $file) {
            $data['body_html'] = (string) ($data['body_html'] ?? '');

            return $data;
        }

        $converter = app(\App\Services\Rentals\NoticeDocxConverter::class);
        try {
            $html = $converter->toTemplateHtml($file->getRealPath());
        } catch (\Throwable $e) {
            throw new \RuntimeException('That document could not be read (' . $e->getMessage() . '). Save it as a .docx from Word and try again, or type the notice text instead.');
        }
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file->getClientOriginalName()) ?: 'template.docx';
        $path = 'rental-notice-templates/' . (int) $request->user()->effectiveAgencyId() . '/' . uniqid() . '-' . $name;
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        return ['body_html' => $html, 'source_file_name' => $name, 'source_file_path' => $path] + $data;
    }
}
