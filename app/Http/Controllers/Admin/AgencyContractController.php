<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Platform\PlatformContractAttachment;
use App\Models\Platform\PlatformContractEnvelope;
use App\Models\Platform\PlatformContractTemplate;
use App\Models\User;
use App\Services\Platform\PlainDocRenderer;
use App\Services\Platform\PlatformContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * System Developer → Agency Contracts (owner-only, outside every agency).
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §6.
 */
class AgencyContractController extends Controller
{
    public function __construct(private PlatformContractService $svc)
    {
    }

    private function owner(Request $r): User
    {
        $u = $r->user();
        abort_unless($u && $u->isOwnerRole(), 403, 'This area is restricted to System Owners.');

        return $u;
    }

    // ── Contracts list ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $this->owner($request);

        $sorts = ['sent' => 'sent_at', 'status' => 'status', 'signed' => 'signed_at', 'created' => 'created_at'];
        $sort = $request->get('sort', 'sent');
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $q = PlatformContractEnvelope::query()->with(['agency', 'template'])
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('signatory_name', 'like', $term)
                    ->orWhere('signatory_email', 'like', $term)
                    ->orWhereIn('agency_id', Agency::where('name', 'like', $term)->select('id')));
            })
            ->when(in_array($request->get('status'), PlatformContractEnvelope::STATUSES, true), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('agency_id'), fn ($q) => $q->where('agency_id', (int) $request->get('agency_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('sent_at', '>=', $request->get('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('sent_at', '<=', $request->get('to')));

        if ($sort === 'agency') {
            $q->orderBy(Agency::select('name')->whereColumn('agencies.id', 'platform_contract_envelopes.agency_id'), $dir);
        } else {
            $q->orderBy($sorts[$sort] ?? 'sent_at', $dir);
        }

        return view('admin.agency-contracts.index', [
            'envelopes' => $q->orderByDesc('id')->paginate(25)->withQueryString(),
            'agencies'  => Agency::orderBy('name')->get(['id', 'name']),
            'statuses'  => PlatformContractEnvelope::STATUSES,
            'sort' => $sort, 'dir' => $dir,
        ]);
    }

    // ── Send ───────────────────────────────────────────────────────────────

    public function create(Request $request)
    {
        $this->owner($request);
        $agency = $request->filled('agency_id') ? Agency::find((int) $request->get('agency_id')) : null;

        return view('admin.agency-contracts.send', [
            'agencies'  => Agency::orderBy('name')->get(['id', 'name']),
            'templates' => PlatformContractTemplate::where('is_active', true)->orderBy('name')->get(),
            'agency'    => $agency,
            'defaults'  => $agency ? $this->defaultSignatory($agency) : ['name' => '', 'email' => ''],
        ]);
    }

    /** The agency's first Admin (the user created with the agency), else the agency email. */
    private function defaultSignatory(Agency $agency): array
    {
        $adminId = AgencyOnboardingSetup::queryWithoutAgencyScope()->where('agency_id', $agency->id)->value('admin_user_id');
        $admin = $adminId ? User::withoutGlobalScopes()->find($adminId) : null;

        return ['name' => $admin?->name ?? '', 'email' => $admin?->email ?? (string) $agency->email];
    }

    public function store(Request $request)
    {
        $user = $this->owner($request);
        $data = $request->validate([
            'agency_id'       => 'required|integer|exists:agencies,id',
            'template_id'     => 'required|integer|exists:platform_contract_templates,id',
            'title'           => 'nullable|string|max:255',
            'signatory_name'  => 'required|string|max:255',
            'signatory_email' => 'required|email|max:255',
            'signatory_role'  => 'nullable|string|max:100',
            'expiry_days'     => 'required|integer|min:1|max:90',
            'attachments'     => 'nullable|array|max:5',
            'attachments.*'   => 'file|mimes:pdf|max:10240',
        ]);

        $agency = Agency::findOrFail($data['agency_id']);
        $template = PlatformContractTemplate::where('is_active', true)->findOrFail($data['template_id']);

        try {
            $env = $this->svc->createAndSend($agency, $template, $data, $request->file('attachments', []), $user->id);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['template_id' => $e->getMessage()]);
        }

        return redirect()->route('admin.agency-contracts.show', $env)->with('success', 'Contract emailed to ' . $env->signatory_email . '.');
    }

    // ── One contract ───────────────────────────────────────────────────────

    private function envelopeOf(int $id): PlatformContractEnvelope
    {
        return PlatformContractEnvelope::withTrashed()->with(['agency', 'attachments'])->findOrFail($id);
    }

    public function show(Request $request, int $envelope)
    {
        $this->owner($request);
        $env = $this->envelopeOf($envelope);

        return view('admin.agency-contracts.show', [
            'env'    => $env,
            'events' => $env->events()->orderByDesc('id')->get(),
            'actors' => User::withoutGlobalScopes()->whereIn('id', $env->events()->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id'),
        ]);
    }

    public function resend(Request $request, int $envelope)
    {
        $user = $this->owner($request);
        $env = $this->envelopeOf($envelope);
        $days = (int) $request->validate(['expiry_days' => 'nullable|integer|min:1|max:90'])['expiry_days'] ?: 14;
        try {
            $this->svc->resend($env, $user->id, $days);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Re-sent with a new link. The previous link no longer works.');
    }

    public function void(Request $request, int $envelope)
    {
        $user = $this->owner($request);
        $data = $request->validate(['void_reason' => 'required|string|max:500']);
        try {
            $this->svc->void($this->envelopeOf($envelope), $data['void_reason'], $user->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Contract voided. The link no longer works.');
    }

    public function download(Request $request, int $envelope)
    {
        $user = $this->owner($request);
        $env = $this->envelopeOf($envelope);
        abort_unless($env->sealed_pdf_path && Storage::disk('local')->exists($env->sealed_pdf_path), 404);
        $this->svc->logEvent($env, 'downloaded', 'Signed PDF downloaded', $user->id, $request->ip());

        return Storage::disk('local')->download($env->sealed_pdf_path, \Illuminate\Support\Str::slug($env->title . ' ' . $env->agency?->name) . '-signed.pdf');
    }

    public function attachment(Request $request, int $envelope, int $attachment)
    {
        $this->owner($request);
        $att = PlatformContractAttachment::where('envelope_id', $envelope)->findOrFail($attachment);
        abort_unless(Storage::disk('local')->exists($att->stored_path), 404);

        return Storage::disk('local')->download($att->stored_path, $att->original_name);
    }

    public function archive(Request $request, int $envelope)
    {
        $this->owner($request);
        $env = $this->envelopeOf($envelope);
        abort_if(in_array($env->status, ['sent', 'viewed'], true), 422, 'Void this contract before archiving it.');
        $env->delete();

        return redirect()->route('admin.agency-contracts.index')->with('success', 'Archived. Use "Show archived" to restore it.');
    }

    public function restore(Request $request, int $envelope)
    {
        $this->owner($request);
        $this->envelopeOf($envelope)->restore();

        return back()->with('success', 'Restored.');
    }

    // ── Templates ──────────────────────────────────────────────────────────

    public function templates(Request $request)
    {
        $this->owner($request);
        $sort = $request->get('sort', 'name');
        $dir = $request->get('dir') === 'desc' ? 'desc' : 'asc';

        $q = PlatformContractTemplate::query()
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->string('q') . '%'))
            ->when($request->filled('kind') && array_key_exists($request->get('kind'), PlatformContractTemplate::KINDS), fn ($q) => $q->where('kind', $request->get('kind')))
            ->when($request->get('active') === '1', fn ($q) => $q->where('is_active', true))
            ->when($request->get('active') === '0', fn ($q) => $q->where('is_active', false))
            ->orderBy($sort === 'updated' ? 'updated_at' : 'name', $dir);

        return view('admin.agency-contracts.templates', ['templates' => $q->paginate(25)->withQueryString(), 'sort' => $sort, 'dir' => $dir]);
    }

    public function templateCreate(Request $request)
    {
        $this->owner($request);

        return view('admin.agency-contracts.template-form', ['template' => new PlatformContractTemplate(['kind' => 'subscription_agreement', 'is_active' => true]), 'fields' => PlatformContractService::MERGE_FIELDS]);
    }

    private function templateRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'kind' => ['required', Rule::in(array_keys(PlatformContractTemplate::KINDS))],
            'body' => 'required|string|max:200000',
        ];
    }

    public function templateStore(Request $request)
    {
        $user = $this->owner($request);
        $data = $request->validate($this->templateRules());
        $t = PlatformContractTemplate::create($data + ['is_active' => $request->boolean('is_active', true), 'created_by' => $user->id]);

        return redirect()->route('admin.agency-contracts.templates.edit', $t->id)->with('success', 'Template created.');
    }

    public function templateEdit(Request $request, int $template)
    {
        $this->owner($request);

        return view('admin.agency-contracts.template-form', ['template' => PlatformContractTemplate::withTrashed()->findOrFail($template), 'fields' => PlatformContractService::MERGE_FIELDS]);
    }

    public function templateUpdate(Request $request, int $template)
    {
        $this->owner($request);
        $t = PlatformContractTemplate::withTrashed()->findOrFail($template);
        $data = $request->validate($this->templateRules());
        $data['is_active'] = $request->boolean('is_active');
        if ($data['body'] !== $t->body) {
            $data['version'] = $t->version + 1; // contracts already sent keep their own frozen snapshot
        }
        $t->update($data);

        return back()->with('success', 'Template saved.' . (isset($data['version']) ? " Now version {$t->version}. Contracts already sent are unchanged." : ''));
    }

    public function templateDestroy(Request $request, int $template)
    {
        $this->owner($request);
        PlatformContractTemplate::findOrFail($template)->delete();

        return redirect()->route('admin.agency-contracts.templates')->with('success', 'Template archived. Contracts already sent are unaffected.');
    }

    public function templateRestore(Request $request, int $template)
    {
        $this->owner($request);
        PlatformContractTemplate::onlyTrashed()->findOrFail($template)->restore();

        return back()->with('success', 'Template restored.');
    }

    /** Preview with obviously-sample data (unknown fields still show as a refusal). */
    public function templatePreview(Request $request, int $template)
    {
        $this->owner($request);
        $t = PlatformContractTemplate::withTrashed()->findOrFail($template);
        $sample = [
            'agency_name' => 'Sample Realty (Pty) Ltd', 'agency_trading_name' => 'Sample Realty', 'agency_reg_no' => '2020/123456/07',
            'agency_vat_no' => '4123456789', 'agency_address' => '1 Example Street, Margate, 4275', 'signatory_name' => 'Jane Principal',
            'signatory_email' => 'jane@example.co.za', 'today' => now()->format('j F Y'), 'go_live_date' => now()->addDays(30)->format('j F Y'),
            'billing_start_date' => now()->addDays(31)->format('j F Y'),
        ];
        try {
            $html = $this->svc->renderBody($t->body, $sample);
            $error = null;
        } catch (\DomainException $e) {
            $html = PlainDocRenderer::render($t->body);
            $error = $e->getMessage();
        }

        return view('admin.agency-contracts.preview', ['template' => $t, 'html' => $html, 'error' => $error]);
    }
}
