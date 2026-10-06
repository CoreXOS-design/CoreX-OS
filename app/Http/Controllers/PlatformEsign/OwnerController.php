<?php

namespace App\Http\Controllers\PlatformEsign;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\PlatformEsign\Attachment;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Template;
use App\Services\PlatformEsign\EsignService;
use App\Services\PlatformEsign\MergeFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Platform E-Sign (owner side) — hub, templates, field editor, send, documents.
 * Owner-only (route middleware AND re-checked here). Spec: agency-timeline-and-platform-esign.md §3A.
 */
class OwnerController extends Controller
{
    public function __construct(private EsignService $svc, private MergeFields $merge)
    {
    }

    private function owner(Request $request)
    {
        $u = $request->user();
        abort_unless($u && $u->isOwnerRole(), 403);

        return $u;
    }

    // ── Hub ────────────────────────────────────────────────────────────────

    public function hub(Request $request)
    {
        $this->owner($request);
        $counts = Document::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return view('platform-esign.hub', [
            'counts'    => $counts,
            'templates' => Template::where('is_active', true)->count(),
            'awaiting'  => Document::with(['agency', 'signers'])->whereIn('status', Document::OPEN)->orderBy('sent_at')->limit(8)->get(),
            'recent'    => Document::with('agency')->where('status', 'completed')->orderByDesc('completed_at')->limit(5)->get(),
        ]);
    }

    // ── Templates ──────────────────────────────────────────────────────────

    public function templates(Request $request)
    {
        $this->owner($request);
        $q = trim((string) $request->query('q'));
        $state = $request->query('state', 'active');
        $sort = $request->query('sort', 'name');
        $dir = $request->query('dir', $sort === 'updated' ? 'desc' : 'asc') === 'desc' ? 'desc' : 'asc';

        $query = Template::query();
        if ($state === 'archived') {
            $query->onlyTrashed();
        } elseif ($state === 'inactive') {
            $query->where('is_active', false);
        }
        if ($q !== '') {
            $query->where('name', 'like', '%' . $q . '%');
        }
        if (($kind = $request->query('kind')) && isset(Template::KINDS[$kind])) {
            $query->where('kind', $kind);
        }
        if (in_array($source = $request->query('source'), ['web', 'pdf'], true)) {
            $query->where('source', $source);
        }
        $col = ['name' => 'name', 'kind' => 'kind', 'version' => 'version', 'updated' => 'updated_at'][$sort] ?? 'name';

        return view('platform-esign.templates.index', [
            'templates' => $query->withCount('fields')->orderBy($col, $dir)->paginate(25)->withQueryString(),
            'q' => $q, 'state' => $state, 'sort' => $sort, 'dir' => $dir,
        ]);
    }

    public function templateCreate(Request $request)
    {
        $this->owner($request);

        return view('platform-esign.templates.form', ['template' => new Template(['source' => $request->query('source') === 'pdf' ? 'pdf' : 'web', 'kind' => 'other', 'is_active' => true]), 'fields' => MergeFields::FIELDS]);
    }

    public function templateStore(Request $request)
    {
        $u = $this->owner($request);
        $data = $this->validateTemplate($request, true);
        try {
            $tpl = $this->svc->createTemplate($data, $request->file('pdf'), $u->id);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['template' => $e->getMessage()]);
        }

        return redirect()->route($tpl->isPdf() ? 'platform-esign.templates.fields' : 'platform-esign.templates.index', $tpl->isPdf() ? $tpl->id : [])
            ->with('success', $tpl->isPdf() ? 'Template created — now place the signature spots and fields on the pages.' : 'Template created.');
    }

    public function templateEdit(Request $request, Template $template)
    {
        $this->owner($request);

        return view('platform-esign.templates.form', ['template' => $template, 'fields' => MergeFields::FIELDS]);
    }

    public function templateUpdate(Request $request, Template $template)
    {
        $this->owner($request);
        $data = $this->validateTemplate($request, false, $template);
        $data['is_active'] = $request->boolean('is_active');
        try {
            $this->svc->updateTemplate($template, $data, $request->file('pdf'));
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['template' => $e->getMessage()]);
        }

        return redirect()->route('platform-esign.templates.index')->with('success', 'Template saved (version ' . $template->version . ').');
    }

    private function validateTemplate(Request $request, bool $creating, ?Template $existing = null): array
    {
        $isPdf = $creating ? $request->input('source') === 'pdf' : $existing->isPdf();
        $rules = [
            'name' => 'required|string|max:255',
            'kind' => 'required|in:' . implode(',', array_keys(Template::KINDS)),
            'roles' => 'required|array|min:1|max:6',
            'roles.*.label' => 'nullable|string|max:80',
        ];
        if ($creating) {
            $rules['source'] = 'required|in:web,pdf';
        }
        if ($isPdf) {
            $rules['pdf'] = ($creating ? 'required|' : 'nullable|') . 'file|mimes:pdf|max:20480';
        } else {
            $rules['body'] = 'required|string|max:200000';
        }

        $data = $request->validate($rules);
        if (!$creating) {
            $data['source'] = $existing->source;
        }
        if (!$isPdf) {
            foreach (array_diff($this->merge->used($data['body']), MergeFields::FIELDS) as $bad) {
                throw \Illuminate\Validation\ValidationException::withMessages(['body' => "Unknown merge field {{{$bad}}}."]);
            }
        }

        return $data;
    }

    public function templateDestroy(Request $request, Template $template)
    {
        $this->owner($request);
        $template->delete();

        return back()->with('success', 'Template archived. Documents already sent are unaffected; restore it any time.');
    }

    public function templateRestore(Request $request, int $id)
    {
        $this->owner($request);
        Template::onlyTrashed()->findOrFail($id)->restore();

        return back()->with('success', 'Template restored.');
    }

    public function templatePreview(Request $request, Template $template)
    {
        $this->owner($request);
        abort_if($template->isPdf(), 404);
        try {
            $html = $this->merge->render((string) $template->body, collect(MergeFields::FIELDS)->mapWithKeys(fn ($f) => [$f => '[' . $f . ']'])->all());
        } catch (\DomainException $e) {
            $html = '<p>' . e($e->getMessage()) . '</p>';
        }

        return view('platform-esign.templates.preview', ['template' => $template, 'html' => $html]);
    }

    // ── Field editor (PDF templates) ───────────────────────────────────────

    public function fields(Request $request, Template $template)
    {
        $this->owner($request);
        abort_unless($template->isPdf(), 404);

        return view('platform-esign.templates.fields', ['template' => $template, 'fields' => $template->fields()->get(['id', 'page_index', 'x', 'y', 'w', 'h', 'type', 'role_key', 'label', 'required'])]);
    }

    public function fieldsSave(Request $request, Template $template)
    {
        $this->owner($request);
        abort_unless($template->isPdf(), 404);
        $data = $request->validate([
            'fields' => 'present|array|max:300',
            'fields.*.page_index' => 'required|integer|min:0',
            'fields.*.x' => 'required|numeric', 'fields.*.y' => 'required|numeric',
            'fields.*.w' => 'required|numeric', 'fields.*.h' => 'required|numeric',
            'fields.*.type' => 'required|string', 'fields.*.role_key' => 'required|string',
            'fields.*.label' => 'nullable|string|max:120', 'fields.*.required' => 'nullable|boolean',
        ]);
        $this->svc->saveFields($template, $data['fields']);

        return response()->json(['ok' => true, 'version' => $template->fresh()->version]);
    }

    public function templatePage(Request $request, Template $template, int $page)
    {
        $this->owner($request);
        abort_unless($template->isPdf() && $page >= 0 && $page < $template->page_count, 404);
        $path = $this->svc->pagePath('platform-esign/templates/' . $template->id, $page);
        abort_unless(Storage::disk(EsignService::DISK)->exists($path), 404);

        return response()->file(Storage::disk(EsignService::DISK)->path($path), ['Cache-Control' => 'private, max-age=300']);
    }

    // ── Documents ──────────────────────────────────────────────────────────

    public function documents(Request $request)
    {
        $this->owner($request);
        $q = trim((string) $request->query('q'));
        $status = $request->query('status', '');
        $sort = $request->query('sort', 'sent');
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = Document::with(['agency', 'signers']);
        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif (isset(Document::STATUSES[$status])) {
            $query->where('status', $status);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%$q%")
                    ->orWhereHas('agency', fn ($a) => $a->where('name', 'like', "%$q%"))
                    ->orWhereHas('signers', fn ($s) => $s->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%"));
            });
        }
        if ($from = $request->query('from')) {
            $query->whereDate('sent_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('sent_at', '<=', $to);
        }
        $col = ['title' => 'title', 'status' => 'status', 'sent' => 'sent_at', 'completed' => 'completed_at'][$sort] ?? 'sent_at';
        $query->orderBy($col, $dir)->orderByDesc('id');

        return view('platform-esign.documents.index', [
            'docs' => $query->paginate(25)->withQueryString(),
            'q' => $q, 'status' => $status, 'sort' => $sort, 'dir' => $dir,
            'from' => $request->query('from'), 'to' => $request->query('to'),
        ]);
    }

    public function create(Request $request)
    {
        $this->owner($request);
        $templates = Template::where('is_active', true)->where('source', '!=', 'webdoc')->orderBy('name')->get();
        $selected = $templates->firstWhere('id', (int) $request->query('template')) ?? $templates->first();

        return view('platform-esign.documents.send', [
            'templates' => $templates, 'tpl' => $selected,
            'agencies' => Agency::orderBy('name')->get(['id', 'name']),
            'agencyId' => (int) ($request->old('agency_id') ?? $request->query('agency')),
            'usesFields' => $selected && !$selected->isPdf() ? $this->merge->used((string) $selected->body) : [],
        ]);
    }

    public function store(Request $request)
    {
        $u = $this->owner($request);
        $data = $request->validate([
            'template_id' => 'required|integer',
            'agency_id' => 'nullable|integer|exists:agencies,id',
            'title' => 'nullable|string|max:255',
            'sequential' => 'nullable|boolean',
            'expiry_days' => 'required|integer|min:1|max:90',
            'signers' => 'required|array',
            'signers.*.role_key' => 'required|string',
            'signers.*.name' => 'required|string|max:255',
            'signers.*.email' => 'required|email|max:255',
            'signers.*.id_number' => 'nullable|string|max:40',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf|max:15360',
        ], ['signers.*.name.required' => 'Every signer needs a name.', 'signers.*.email.required' => 'Every signer needs an email address.', 'signers.*.email.email' => 'Enter a valid email address for each signer.']);
        $data['sequential'] = $request->boolean('sequential');
        $tpl = Template::findOrFail($data['template_id']);
        abort_if($tpl->isWebdoc(), 404); // web documents are sent from the Subscription Agreement screen
        try {
            $doc = $this->svc->send($tpl, $data, $request->file('attachments', []), $u->id);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['send' => $e->getMessage()]);
        }
        $this->svc->inviteDue($doc, $u->id);

        return redirect()->route('platform-esign.documents.show', $doc->id)->with('success', 'Sent for signing.');
    }

    public function show(Request $request, int $id)
    {
        $this->owner($request);
        $doc = Document::withTrashed()->with(['agency', 'signers', 'events.actor', 'events.signer', 'attachments', 'template', 'creator'])->findOrFail($id);

        return view('platform-esign.documents.show', ['doc' => $doc]);
    }

    public function resend(Request $request, Document $document)
    {
        $u = $this->owner($request);
        try {
            if ($document->isWebdoc()) {
                $agreements = app(\App\Services\PlatformEsign\Agreement\AgreementService::class);
                $document->status === 'completed' ? $agreements->reissueAccess($document, $u->id) : $agreements->resend($document, $u->id);
            } else {
                $this->svc->resend($document, $u->id, (int) $request->input('expiry_days', 14));
            }
        } catch (\DomainException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('success', 'New link sent.');
    }

    public function void(Request $request, Document $document)
    {
        $u = $this->owner($request);
        $reason = $request->validate(['reason' => 'required|string|max:490'])['reason'];
        try {
            $this->svc->void($document, $reason, $u->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('success', 'Document voided. Its signing links no longer work.');
    }

    public function reseal(Request $request, Document $document)
    {
        $this->owner($request);
        try {
            $this->svc->reseal($document);
        } catch (\DomainException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('success', 'Sealed copy rebuilt.');
    }

    public function download(Request $request, Document $document)
    {
        $u = $this->owner($request);
        abort_unless($document->sealed_pdf_path && Storage::disk(EsignService::DISK)->exists($document->sealed_pdf_path), 404);
        if ($document->isWebdoc()) {
            $this->svc->log($document, 'signed_copy_downloaded', 'Signed PDF downloaded inside Platform E-Sign', null, $u->id, $request->ip());
        }

        return Storage::disk(EsignService::DISK)->download($document->sealed_pdf_path, Str::slug($document->title) . '-signed.pdf');
    }

    public function attachment(Request $request, Document $document, Attachment $attachment)
    {
        $this->owner($request);
        abort_unless($attachment->document_id === $document->id && Storage::disk(EsignService::DISK)->exists($attachment->stored_path), 404);

        return Storage::disk(EsignService::DISK)->download($attachment->stored_path, $attachment->original_name);
    }

    public function archive(Request $request, Document $document)
    {
        $this->owner($request);
        if ($document->isOpen()) {
            return back()->withErrors(['action' => 'Void the document before archiving it.']);
        }
        $document->delete();

        return redirect()->route('platform-esign.documents.index')->with('success', 'Document archived. Restore it from the Archived filter.');
    }

    public function restore(Request $request, int $id)
    {
        $this->owner($request);
        Document::onlyTrashed()->findOrFail($id)->restore();

        return redirect()->route('platform-esign.documents.show', $id)->with('success', 'Document restored.');
    }
}
