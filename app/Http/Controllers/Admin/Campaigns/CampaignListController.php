<?php

namespace App\Http\Controllers\Admin\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Campaigns\Campaign;
use App\Models\Campaigns\CampaignLead;
use App\Models\Campaigns\CampaignList;
use App\Models\Campaigns\CampaignSuppression;
use App\Services\Campaigns\CampaignImportException;
use App\Services\Campaigns\CampaignImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * AT-534 — Campaigns → Lists: lists, the CSV/XLSX import with column mapping, and leads (add / edit / archive / restore / reset).
 * Owner-only and DELIBERATELY no permission key. Spec: .ai/specs/campaigns.md §5, §7.3.
 */
class CampaignListController extends Controller
{
    use GuardsCampaigns;

    private const IMPORT_DIR = 'campaign-imports';

    public function __construct(private CampaignImportService $importer)
    {
    }

    public function index(Request $request): View
    {
        $this->guard($request);
        [$sortKey, $sortCol, $dir] = $this->sortFrom($request, [
            'name' => 'campaign_lists.name', 'leads' => 'leads_count', 'imported' => 'campaign_lists.last_imported_at', 'created' => 'campaign_lists.created_at',
        ], 'imported', 'desc');

        $query = CampaignList::query()->select('campaign_lists.*')->withCount([
            'leads',
            'leads as new_count' => fn ($q) => $q->whereIn('status', ['new', 'queued']),
            'leads as sent_count' => fn ($q) => $q->where('status', 'sent'),
            'leads as replied_count' => fn ($q) => $q->where('status', 'replied'),
            'leads as stopped_count' => fn ($q) => $q->whereIn('status', ['stopped', 'bounced', 'suppressed']),
        ])->with('campaign:id,campaign_list_id,name,status');
        if ($request->query('archived') === '1') {
            $query->onlyTrashed();
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('campaign_lists.name', 'like', $this->likeTerm($q));
        }
        $this->applyDateRange($query, $request, 'campaign_lists.created_at');

        return view('admin.campaigns.lists.index', [
            'lists' => $query->orderBy($sortCol, $dir)->orderByDesc('campaign_lists.id')->paginate(25)->withQueryString(),
            'sort' => $sortKey,
            'direction' => $dir,
            'filtered' => $q !== '' || $request->filled('from') || $request->filled('to') || $request->query('archived') === '1',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $name = $this->validateListName($request, null);
        $list = CampaignList::create(['name' => $name, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return redirect()->route('admin.campaigns.lists.show', $list->id)->with('success', 'List created. Import a file or add leads.');
    }

    public function show(Request $request, int $list): View
    {
        $this->guard($request);
        $list = CampaignList::withTrashed()->with('campaign:id,campaign_list_id,name,status')->findOrFail($list);

        return view('admin.campaigns.lists.show', [
            'list' => $list,
            'leads' => $this->leadsTable($request, $list->id, true),
            'filtered' => $request->filled('q') || $request->filled('status') || $request->filled('from') || $request->filled('to'),
            'importResult' => session('campaign_import_result'),
        ]);
    }

    public function update(Request $request, int $list): RedirectResponse
    {
        $this->guard($request);
        $list = CampaignList::findOrFail($list);
        $list->forceFill(['name' => $this->validateListName($request, $list), 'updated_by' => $request->user()->id])->save();

        return back()->with('success', 'List renamed.');
    }

    public function archive(Request $request, int $list): RedirectResponse
    {
        $this->guard($request);
        $list = CampaignList::findOrFail($list);
        $campaign = Campaign::where('campaign_list_id', $list->id)->first();
        if ($campaign && $campaign->status === Campaign::STATUS_RUNNING) {
            return back()->with('error', 'Campaign "' . $campaign->name . '" is running on this list. Pause it first.');
        }
        $list->forceFill(['updated_by' => $request->user()->id])->save();
        $list->delete();

        return redirect()->route('admin.campaigns.lists.index')->with('success', 'List archived. Its leads stay as they are; restore it any time.');
    }

    public function restore(Request $request, int $list): RedirectResponse
    {
        $this->guard($request);
        $list = CampaignList::onlyTrashed()->findOrFail($list);
        if (CampaignList::where('name', $list->name)->exists()) {
            return back()->with('error', 'Another list is now called "' . $list->name . '". Rename that one first.');
        }
        $list->restore();

        return redirect()->route('admin.campaigns.lists.show', $list->id)->with('success', 'List restored.');
    }

    // ── Import (§5) ──────────────────────────────────────────────────────────────────────────

    public function upload(Request $request, int $list): RedirectResponse
    {
        $this->guard($request);
        $list = CampaignList::findOrFail($list);
        $maxKb = (int) config('campaigns.max_import_mb') * 1024;
        $request->validate([
            'file' => ['required', 'file', 'max:' . $maxKb],
        ], [
            'file.required' => 'Choose a .csv or .xlsx file.',
            'file.max' => 'The file is larger than ' . config('campaigns.max_import_mb') . ' MB. Split it into smaller files.',
            'file.uploaded' => 'The file did not upload. Try again.',
        ]);

        $file = $request->file('file');
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === 'xls') {
            throw ValidationException::withMessages(['file' => '.xls is not supported — save it as .xlsx or .csv.']);
        }
        if (! in_array($ext, ['csv', 'xlsx', 'txt'], true)) {
            throw ValidationException::withMessages(['file' => 'Choose a .csv or .xlsx file.']);
        }
        $ext = $ext === 'txt' ? 'csv' : $ext;

        $token = Str::random(32);
        $stored = $file->storeAs(self::IMPORT_DIR, $token . '.' . $ext, 'local');
        $path = Storage::disk('local')->path($stored);

        try {
            $headers = $this->importer->readHeaders($path, $ext);
        } catch (CampaignImportException $e) {
            Storage::disk('local')->delete($stored);
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        if (array_filter($headers, fn ($h) => $h !== '') === []) {
            Storage::disk('local')->delete($stored);
            throw ValidationException::withMessages(['file' => 'This file has no header row. The first row must name the columns.']);
        }

        session(['campaign_import.' . $list->id => [
            'token' => $token, 'ext' => $ext, 'stored' => $stored, 'filename' => $file->getClientOriginalName(), 'headers' => $headers,
        ]]);

        return redirect()->route('admin.campaigns.lists.import.map', $list->id);
    }

    public function mapColumns(Request $request, int $list): View|RedirectResponse
    {
        $this->guard($request);
        $list = CampaignList::findOrFail($list);
        $pending = session('campaign_import.' . $list->id);
        if (! $pending || ! Storage::disk('local')->exists($pending['stored'])) {
            return redirect()->route('admin.campaigns.lists.show', $list->id)->with('error', 'Choose the file to import again.');
        }

        return view('admin.campaigns.lists.map-columns', [
            'list' => $list,
            'pending' => $pending,
            'fields' => CampaignImportService::FIELDS,
            'suggested' => $this->importer->suggestMapping($pending['headers']),
        ]);
    }

    public function runImport(Request $request, int $list): RedirectResponse
    {
        $this->guard($request);
        $list = CampaignList::findOrFail($list);
        $pending = session('campaign_import.' . $list->id);
        if (! $pending || $request->input('token') !== $pending['token'] || ! Storage::disk('local')->exists($pending['stored'])) {
            return redirect()->route('admin.campaigns.lists.show', $list->id)->with('error', 'Choose the file to import again.');
        }

        $columnCount = count($pending['headers']);
        $mapping = [];
        foreach (array_keys(CampaignImportService::FIELDS) as $field) {
            $v = $request->input("column.{$field}");
            if ($v !== null && $v !== '' && ctype_digit((string) $v) && (int) $v < $columnCount) {
                $mapping[$field] = (int) $v;
            }
        }
        if (! isset($mapping['email'])) {
            return back()->with('error', 'Choose which column holds the email address.');
        }

        try {
            $result = $this->importer->import($list, Storage::disk('local')->path($pending['stored']), $pending['ext'], $mapping, $request->user()->id);
        } catch (CampaignImportException $e) {
            return back()->with('error', 'Nothing was imported — ' . $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Campaigns: import failed', ['list_id' => $list->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Nothing was imported — something went wrong while saving. No rows were added; try again.');
        }

        $list->forceFill(['source_filename' => Str::limit((string) $pending['filename'], 250, '')])->save();
        Storage::disk('local')->delete($pending['stored']);
        session()->forget('campaign_import.' . $list->id);

        return redirect()->route('admin.campaigns.lists.show', $list->id)->with('campaign_import_result', $result);
    }

    // ── Leads (§7.3) ─────────────────────────────────────────────────────────────────────────

    public function storeLead(Request $request, int $list): RedirectResponse
    {
        $this->guard($request);
        $list = CampaignList::findOrFail($list);
        $data = $this->validateLead($request, null, 'newLead');
        $campaign = Campaign::where('campaign_list_id', $list->id)->first();
        $suppressed = CampaignSuppression::isSuppressed($data['email']);
        $status = $suppressed ? CampaignLead::STATUS_SUPPRESSED
            : ($campaign && in_array($campaign->status, [Campaign::STATUS_RUNNING, Campaign::STATUS_PAUSED], true) ? CampaignLead::STATUS_QUEUED : CampaignLead::STATUS_NEW);

        CampaignLead::create($data + [
            'campaign_list_id' => $list->id, 'status' => $status, 'status_reason' => $suppressed ? 'suppressed' : null,
            'status_changed_at' => now(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', $suppressed ? 'Lead added — the address is on the suppressed list, so it will not be emailed.' : 'Lead added.');
    }

    public function updateLead(Request $request, int $lead): RedirectResponse
    {
        $this->guard($request);
        $lead = CampaignLead::findOrFail($lead);
        $data = $this->validateLead($request, $lead, "lead_{$lead->id}");
        if ($data['email'] !== $lead->email && $lead->sends()->exists()) {
            throw ValidationException::withMessages(['email' => 'This lead has already been emailed; add the new address as a new lead.'])->errorBag("lead_{$lead->id}");
        }
        $lead->fill($data + ['updated_by' => $request->user()->id])->save();

        return back()->with('success', 'Lead saved.');
    }

    public function archiveLead(Request $request, int $lead): RedirectResponse
    {
        $this->guard($request);
        $lead = CampaignLead::findOrFail($lead);
        $lead->forceFill(['updated_by' => $request->user()->id])->save();
        $lead->delete();

        return back()->with('success', 'Lead archived. It will not be emailed.');
    }

    public function restoreLead(Request $request, int $lead): RedirectResponse
    {
        $this->guard($request);
        $lead = CampaignLead::onlyTrashed()->findOrFail($lead);
        $other = CampaignLead::with('list:id,name')->where('email', $lead->email)->first();
        if ($other) {
            return back()->with('error', $lead->email . ' is now a lead on list "' . ($other->list?->name ?? 'another list') . '", so this one cannot be restored.');
        }
        $lead->restore();

        return back()->with('success', 'Lead restored.');
    }

    /** A suppressed / stopped / bounced / replied lead back to "new" — only once the address is no longer suppressed. */
    public function resetLead(Request $request, int $lead): RedirectResponse
    {
        $this->guard($request);
        $lead = CampaignLead::findOrFail($lead);
        if (CampaignSuppression::isSuppressed($lead->email)) {
            return back()->with('error', $lead->email . ' is still on the suppressed list. Lift it there first.');
        }
        $campaign = Campaign::where('campaign_list_id', $lead->campaign_list_id)->first();
        $status = $campaign && in_array($campaign->status, [Campaign::STATUS_RUNNING, Campaign::STATUS_PAUSED], true)
            ? ($lead->sends()->where('status', 'sent')->exists() ? CampaignLead::STATUS_SENT : CampaignLead::STATUS_QUEUED)
            : CampaignLead::STATUS_NEW;
        $lead->forceFill(['status' => $status, 'status_reason' => 'manual', 'status_changed_at' => now(), 'updated_by' => $request->user()->id])->save();

        return back()->with('success', 'Lead reset. Steps it has already had are never sent again.');
    }

    // ── validation ───────────────────────────────────────────────────────────────────────────

    private function validateListName(Request $request, ?CampaignList $list): string
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('campaign_lists', 'name')->whereNull('deleted_at')->ignore($list?->id)],
        ], [
            'name.required' => 'Give the list a name.',
            'name.unique' => 'Another list already has that name.',
        ]);

        return $data['name'];
    }

    private function validateLead(Request $request, ?CampaignLead $lead, string $bag): array
    {
        $trimmed = [];
        foreach (['first_name', 'last_name', 'company', 'town', 'phone', 'notes'] as $f) {
            $trimmed[$f] = trim((string) $request->input($f, ''));
        }
        $trimmed['email'] = CampaignLead::normaliseEmail($request->input('email'));
        $request->merge($trimmed);

        $data = $request->validateWithBag($bag, [
            'first_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'town' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'email.required' => 'Enter an email address.',
            'email.email' => 'That is not a valid email address.',
        ]);

        $dupe = CampaignLead::with('list:id,name')->where('email', $data['email'])->when($lead, fn ($q) => $q->whereKeyNot($lead->id))->first();
        if ($dupe) {
            throw ValidationException::withMessages(['email' => $data['email'] . ' is already a lead on list "' . ($dupe->list?->name ?? 'another list') . '".'])->errorBag($bag);
        }

        return [
            'first_name' => (string) ($data['first_name'] ?? ''), 'last_name' => (string) ($data['last_name'] ?? ''),
            'company' => (string) ($data['company'] ?? ''), 'town' => (string) ($data['town'] ?? ''), 'email' => $data['email'],
            'phone' => (string) ($data['phone'] ?? ''), 'notes' => ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
        ];
    }
}
