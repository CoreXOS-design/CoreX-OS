<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentDistributionAuditEntry;
use App\Models\DocumentType;
use App\Services\DealV2\DocumentDistributionMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Deal Distribution Rules - "when a deal reaches a stage, send these documents to ..." (.ai/specs/signed-document-distribution.md
 * "The rules screen"). One row of deal_stage_document_rules = stage x document type x party -> delivery + automatic or by hand.
 * Lists and edits STAGE rules only; the "any stage" rows are the Deal Document Distribution matrix's ticks and are never touched here.
 * Gated by deals_v2.manage_distribution_rules; every add / edit / archive / restore writes an audit row.
 */
class DealDistributionRuleController extends Controller
{
    /** The party-role vocabulary (mirrors deal_v2_contacts.role). */
    public const PARTY_ROLES = [
        'seller', 'co_seller', 'buyer', 'co_buyer',
        'conveyancer', 'transfer_attorney', 'bond_attorney', 'bond_originator', 'originator',
        'electrician_coc', 'entomologist', 'service_provider', 'external_agency', 'other',
    ];

    public const MODES = [
        'secure_link'       => 'Secure link + PIN',
        'direct_attachment' => 'Email attachment',
    ];

    public const SORTS = ['stage', 'document', 'party', 'delivery', 'sent', 'status', 'changed'];

    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $this->authorizeManage();
        $agencyId = $this->agencyId();
        $f        = $this->filters($request);

        $query = $this->baseQuery($agencyId)
            ->join('document_types as dt', 'dt.id', '=', 'deal_stage_document_rules.document_type_id')
            ->leftJoin('deal_pipeline_steps as st', 'st.id', '=', 'deal_stage_document_rules.pipeline_step_id')
            ->leftJoin('deal_pipeline_templates as tp', 'tp.id', '=', 'st.pipeline_template_id')
            ->select('deal_stage_document_rules.*', 'dt.label as doc_label', 'st.name as stage_name', 'st.position as stage_pos', 'tp.name as template_name');

        $labels = $this->partyLabels();
        if ($f['q'] !== '') {
            $like      = '%' . addcslashes($f['q'], '%_\\') . '%';
            $roleMatch = collect($labels)->filter(fn ($l) => Str::contains(Str::lower($l), Str::lower($f['q'])))->keys()->all();
            $query->where(fn (Builder $w) => $w->where('dt.label', 'like', $like)->orWhere('st.name', 'like', $like)->orWhere('tp.name', 'like', $like)
                ->orWhereIn('deal_stage_document_rules.party_role', $roleMatch));
        }
        if ($f['stage'] !== '') {
            $query->where('deal_stage_document_rules.pipeline_step_id', (int) $f['stage']);
        }
        if ($f['party'] !== '') {
            $query->where('deal_stage_document_rules.party_role', $f['party']);
        }
        match ($f['status']) {
            'on'       => $query->whereNull('deal_stage_document_rules.deleted_at')->where('deal_stage_document_rules.is_active', true),
            'off'      => $query->whereNull('deal_stage_document_rules.deleted_at')->where('deal_stage_document_rules.is_active', false),
            'archived' => $query->whereNotNull('deal_stage_document_rules.deleted_at'),
            default    => $query->whereNull('deal_stage_document_rules.deleted_at'),
        };

        $dir = $f['dir'];
        match ($f['sort']) {
            'document' => $query->orderBy('dt.label', $dir),
            'party'    => $query->orderBy('deal_stage_document_rules.party_role', $dir),
            'delivery' => $query->orderBy('deal_stage_document_rules.delivery_mode', $dir),
            'sent'     => $query->orderBy('deal_stage_document_rules.auto_on_stage_tick', $dir),
            'status'   => $query->orderByRaw('(deal_stage_document_rules.deleted_at IS NOT NULL) ' . $dir)->orderBy('deal_stage_document_rules.is_active', $dir),
            'changed'  => $query->orderBy('deal_stage_document_rules.updated_at', $dir),
            default    => $query->orderBy('tp.name', $dir)->orderBy('st.position', $dir),
        };
        $query->orderBy('dt.label')->orderBy('deal_stage_document_rules.party_role')->orderBy('deal_stage_document_rules.id');

        $rules   = $query->paginate(self::PER_PAGE)->withQueryString();
        $matrix  = app(DocumentDistributionMatrix::class)->typeLevelCells($agencyId);
        $history = DocumentDistributionAuditEntry::query()->where('agency_id', $agencyId)->where('area', DocumentDistributionAuditEntry::AREA_STAGE_RULES)
            ->with('user:id,name')->latest('id')->limit(15)->get();

        return view('admin.deal-distribution-rules.index', [
            'rules'         => $rules,
            'f'             => $f,
            'labels'        => $labels,
            'modes'         => self::MODES,
            'steps'         => $this->steps($agencyId),
            'documentTypes' => DocumentType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('label')->get(['id', 'label']),
            'matrix'        => $matrix,
            'history'       => $history,
            'last'          => $history->first(),
            'totalRules'    => $this->baseQuery($agencyId)->whereNull('deal_stage_document_rules.deleted_at')->count(),
            'adhocEnabled'  => (bool) optional(Agency::withoutGlobalScopes()->find($agencyId))->adhoc_document_distribution_enabled,
        ]);
    }

    /**
     * Feature 2 - toggle the agency-level ad-hoc document-distribution switch (default off). When on, the "Send documents to any email"
     * affordance appears on Email Parties; when off, it is hidden.
     */
    public function toggleAdhoc(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $agency = Agency::withoutGlobalScopes()->findOrFail($this->agencyId());
        $agency->adhoc_document_distribution_enabled = $request->boolean('adhoc_document_distribution_enabled');
        $agency->save();

        return back()->with('status',
            'Ad-hoc document distribution ' . ($agency->adhoc_document_distribution_enabled ? 'enabled' : 'disabled') . '.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $agencyId = $this->agencyId();
        $data     = $this->validated($request, $agencyId);

        $existing = $this->match($agencyId, $data);
        if ($existing && ! $existing->trashed()) {
            return back()->withInput()->withErrors(['rule' => 'That rule already exists (' . $this->describe($existing) . '). Edit it instead of adding it again.']);
        }

        if ($existing) {   // an archived rule with the same stage, document and party comes back with the new settings
            $existing->restore();
            $existing->update($this->fields($data) + ['is_active' => true]);
            $this->audit($agencyId, 'Restored a rule: ' . $this->describe($existing), ['Restored: ' . $this->describe($existing)]);

            return back()->with('status', 'That rule was archived earlier, so it has been brought back with these settings.');
        }

        $rule = DealStageDocumentRule::withoutGlobalScopes()->create($this->fields($data) + [
            'agency_id' => $agencyId, 'pipeline_step_id' => $data['pipeline_step_id'], 'document_type_id' => $data['document_type_id'],
            'party_role' => $data['party_role'], 'is_active' => true, 'created_by_id' => auth()->id(),
        ]);
        $this->audit($agencyId, 'Added a rule: ' . $this->describe($rule), ['Added: ' . $this->describe($rule), 'Delivered as: ' . self::MODES[$rule->delivery_mode], 'Sent: ' . $this->sentLabel($rule)]);

        return back()->with('status', 'Rule added.');
    }

    public function update(Request $request, int $rule): RedirectResponse
    {
        $this->authorizeManage();
        $agencyId = $this->agencyId();
        $row      = $this->ownRule($rule, $agencyId);
        if ($row->trashed()) {
            return back()->withErrors(['rule' => 'This rule is archived. Restore it first, then edit it.']);
        }

        $data  = $this->validated($request, $agencyId) + ['is_active' => $request->boolean('is_active', true)];
        $clash = $this->match($agencyId, $data);
        if ($clash && (int) $clash->id !== (int) $row->id) {
            return back()->withInput()->withErrors(['rule' => $clash->trashed()
                ? 'An archived rule already covers that stage, document and party. Restore the archived rule instead.'
                : 'Another rule already covers that stage, document and party (' . $this->describe($clash) . ').']);
        }

        $before = $this->snapshot($row);
        $row->fill($this->fields($data) + [
            'pipeline_step_id' => $data['pipeline_step_id'], 'document_type_id' => $data['document_type_id'],
            'party_role' => $data['party_role'], 'is_active' => (bool) $data['is_active'],
        ]);
        $lines = $this->diff($before, $this->snapshot($row));
        if (! $lines) {
            return back()->with('status', 'Nothing was changed.');
        }
        $row->save();
        $this->audit($agencyId, 'Changed a rule: ' . $this->describe($row), $lines);

        return back()->with('status', 'Rule saved.');
    }

    /** Archive (soft delete). */
    public function destroy(int $rule): RedirectResponse
    {
        $this->authorizeManage();
        $agencyId = $this->agencyId();
        $row      = $this->ownRule($rule, $agencyId);
        if (! $row->trashed()) {
            $row->delete();
            $this->audit($agencyId, 'Archived a rule: ' . $this->describe($row), ['Archived: ' . $this->describe($row)]);
        }

        return back()->with('status', 'Rule archived. You can bring it back from the Archived filter.');
    }

    public function restore(int $rule): RedirectResponse
    {
        $this->authorizeManage();
        $agencyId = $this->agencyId();
        $row      = $this->ownRule($rule, $agencyId);
        if ($row->trashed()) {
            $row->restore();
            $this->audit($agencyId, 'Restored a rule: ' . $this->describe($row), ['Restored: ' . $this->describe($row)]);
        }

        return back()->with('status', 'Rule restored.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_distribution_rules'), 403);
    }

    private function agencyId(): int
    {
        return (int) (auth()->user()?->effectiveAgencyId() ?? auth()->user()?->agency_id ?? 0);
    }

    /** Stage rules of this agency only (archived included - callers filter). */
    private function baseQuery(int $agencyId): Builder
    {
        return DealStageDocumentRule::withTrashed()->withoutGlobalScopes([\App\Models\Scopes\AgencyScope::class])
            ->where('deal_stage_document_rules.agency_id', $agencyId)->whereNotNull('deal_stage_document_rules.pipeline_step_id');
    }

    private function ownRule(int $id, int $agencyId): DealStageDocumentRule
    {
        $row = $this->baseQuery($agencyId)->where('deal_stage_document_rules.id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    /** @return array{q:string, stage:string, party:string, status:string, sort:string, dir:string} */
    private function filters(Request $request): array
    {
        return [
            'q'      => trim((string) $request->query('q', '')),
            'stage'  => ctype_digit((string) $request->query('stage', '')) ? (string) (int) $request->query('stage') : '',
            'party'  => in_array($request->query('party'), self::PARTY_ROLES, true) ? (string) $request->query('party') : '',
            'status' => in_array($request->query('status'), ['on', 'off', 'archived'], true) ? (string) $request->query('status') : 'current',
            'sort'   => in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'stage',
            'dir'    => $request->query('dir') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /** @return array<string,string> party role => plain label (the matrix's names win; the rest are headlined) */
    private function partyLabels(): array
    {
        $out = [];
        foreach (self::PARTY_ROLES as $r) {
            $out[$r] = DocumentDistributionMatrix::PARTY_ROLES[$r] ?? Str::headline($r);
        }

        return $out;
    }

    /** The agency's own pipeline stages, in pipeline then stage order. */
    private function steps(int $agencyId)
    {
        return DealPipelineStep::query()->withoutGlobalScopes()->whereNull('deal_pipeline_steps.deleted_at')
            ->join('deal_pipeline_templates as tp', 'tp.id', '=', 'deal_pipeline_steps.pipeline_template_id')
            ->where('tp.agency_id', $agencyId)->whereNull('tp.deleted_at')
            ->orderBy('tp.name')->orderBy('deal_pipeline_steps.position')
            ->get(['deal_pipeline_steps.id', 'deal_pipeline_steps.name', 'tp.name as template_name']);
    }

    /** @return array{pipeline_step_id:int, document_type_id:int, party_role:string, delivery_mode:string, auto_on_stage_tick:bool} */
    private function validated(Request $request, int $agencyId): array
    {
        $data = $request->validate([
            'pipeline_step_id'   => ['required', 'integer'],
            'document_type_id'   => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'party_role'         => ['required', Rule::in(self::PARTY_ROLES)],
            'delivery_mode'      => ['required', Rule::in(array_keys(self::MODES))],
            'auto_on_stage_tick' => ['nullable', 'boolean'],
        ], [
            'pipeline_step_id.required' => 'Please choose the stage.',
            'document_type_id.required' => 'Please choose the document.',
            'party_role.required'       => 'Please choose who receives it.',
        ]);

        if (! $this->steps($agencyId)->contains('id', (int) $data['pipeline_step_id'])) {
            abort(404);   // another agency's stage is simply not there
        }
        $data['pipeline_step_id']   = (int) $data['pipeline_step_id'];
        $data['document_type_id']   = (int) $data['document_type_id'];
        $data['auto_on_stage_tick'] = $request->boolean('auto_on_stage_tick');

        return $data;
    }

    private function fields(array $data): array
    {
        return ['delivery_mode' => $data['delivery_mode'], 'auto_on_stage_tick' => (bool) $data['auto_on_stage_tick']];
    }

    /** The existing row (archived too) with the same stage + document + party. */
    private function match(int $agencyId, array $data): ?DealStageDocumentRule
    {
        return $this->baseQuery($agencyId)
            ->where('deal_stage_document_rules.pipeline_step_id', $data['pipeline_step_id'])
            ->where('deal_stage_document_rules.document_type_id', $data['document_type_id'])
            ->where('deal_stage_document_rules.party_role', $data['party_role'])
            ->first();
    }

    private function sentLabel(DealStageDocumentRule $r): string
    {
        return $r->auto_on_stage_tick ? 'automatically when the stage is completed' : 'offered to the agent by hand';
    }

    /** "Sale Agreement to Buyer at Offer Accepted" */
    private function describe(DealStageDocumentRule $r): string
    {
        $type  = DocumentType::query()->withoutGlobalScopes()->find($r->document_type_id)?->label ?? ('document #' . $r->document_type_id);
        $stage = DealPipelineStep::query()->withoutGlobalScopes()->withTrashed()->find($r->pipeline_step_id)?->name ?? ('stage #' . $r->pipeline_step_id);

        return $type . ' to ' . ($this->partyLabels()[$r->party_role] ?? Str::headline($r->party_role)) . ' at ' . $stage;
    }

    /** @return array<string,string> */
    private function snapshot(DealStageDocumentRule $r): array
    {
        return [
            'Stage'        => DealPipelineStep::query()->withoutGlobalScopes()->withTrashed()->find($r->pipeline_step_id)?->name ?? '-',
            'Document'     => DocumentType::query()->withoutGlobalScopes()->find($r->document_type_id)?->label ?? '-',
            'Goes to'      => $this->partyLabels()[$r->party_role] ?? Str::headline($r->party_role),
            'Delivered as' => self::MODES[$r->delivery_mode] ?? $r->delivery_mode,
            'Sent'         => ucfirst($this->sentLabel($r)),
            'Status'       => $r->is_active ? 'Switched on' : 'Switched off',
        ];
    }

    /** @return list<string> */
    private function diff(array $before, array $after): array
    {
        $lines = [];
        foreach ($after as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                $lines[] = $k . ': ' . ($before[$k] ?? '-') . ' -> ' . $v;
            }
        }

        return $lines;
    }

    private function audit(int $agencyId, string $summary, array $lines): void
    {
        DocumentDistributionAuditEntry::create([
            'agency_id' => $agencyId, 'user_id' => auth()->id(), 'area' => DocumentDistributionAuditEntry::AREA_STAGE_RULES,
            'summary' => mb_substr($summary, 0, 500), 'changes' => array_values($lines),
        ]);
    }
}
