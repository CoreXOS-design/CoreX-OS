<?php

namespace App\Http\Controllers\Admin\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Campaigns\Campaign;
use App\Models\Campaigns\CampaignList;
use App\Models\Campaigns\CampaignSend;
use App\Models\Campaigns\CampaignSender;
use App\Models\Campaigns\CampaignStep;
use App\Services\Campaigns\CampaignMergeRenderer;
use App\Services\Campaigns\CampaignSendService;
use App\Services\Campaigns\CampaignSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * AT-534 — System Developer → Campaigns: the campaigns list, the campaign page (List / Steps / Activity), start / pause / resume,
 * archive / restore and the steps. Owner-only and DELIBERATELY no permission key. Spec: .ai/specs/campaigns.md §7.1–§7.2.
 */
class CampaignController extends Controller
{
    use GuardsCampaigns;

    public function __construct(
        private CampaignSendService $sends,
        private CampaignSettings $settings,
        private CampaignMergeRenderer $renderer,
    ) {
    }

    public function index(Request $request): View
    {
        $this->guard($request);

        [$sortKey, $sortCol, $dir] = $this->sortFrom($request, [
            'name' => 'campaigns.name', 'list' => 'list_name', 'sender' => 'sender_email', 'steps' => 'steps_count', 'sent' => 'sent_count',
            'replied' => 'replied_count', 'stopped' => 'stopped_count', 'status' => 'campaigns.status', 'created' => 'campaigns.created_at',
        ], 'created', 'desc');

        $query = Campaign::query()
            ->select('campaigns.*')
            ->selectSub(CampaignList::withTrashed()->select('name')->whereColumn('campaign_lists.id', 'campaigns.campaign_list_id'), 'list_name')
            ->selectSub(CampaignSender::withTrashed()->select('email')->whereColumn('campaign_senders.id', 'campaigns.campaign_sender_id'), 'sender_email')
            ->withCount([
                'steps',
                'sends as sent_count' => fn ($q) => $q->where('status', CampaignSend::STATUS_SENT),
                'sends as replied_count' => fn ($q) => $q->whereNotNull('replied_at'),
            ])
            ->selectSub(
                DB::table('campaign_leads')->selectRaw('COUNT(*)')->whereColumn('campaign_leads.campaign_list_id', 'campaigns.campaign_list_id')
                    ->whereNull('campaign_leads.deleted_at')->whereIn('campaign_leads.status', ['stopped', 'bounced']),
                'stopped_count'
            );

        $status = (string) $request->query('status', '');
        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif (in_array($status, Campaign::STATUSES, true)) {
            $query->where('campaigns.status', $status);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = $this->likeTerm($q);
            $query->where(fn ($w) => $w->where('campaigns.name', 'like', $like)
                ->orWhereIn('campaigns.campaign_list_id', CampaignList::withTrashed()->where('name', 'like', $like)->select('id')));
        }
        $this->applyDateRange($query, $request, 'campaigns.created_at');

        $campaigns = $query->orderBy($sortCol, $dir)->orderByDesc('campaigns.id')->paginate(25)->withQueryString();
        $nextRuns = [];
        foreach ($campaigns as $campaign) {
            $nextRuns[$campaign->id] = $this->sends->nextRun($campaign);
        }

        $inboxProblems = CampaignSender::whereNotNull('last_inbox_error')
            ->whereIn('id', Campaign::whereIn('status', [Campaign::STATUS_RUNNING, Campaign::STATUS_PAUSED])->select('campaign_sender_id'))
            ->get(['id', 'email', 'last_inbox_error']);

        return view('admin.campaigns.index', [
            'campaigns' => $campaigns,
            'nextRuns' => $nextRuns,
            'sort' => $sortKey,
            'direction' => $dir,
            'filtered' => $q !== '' || $status !== '' || $request->filled('from') || $request->filled('to'),
            'inboxProblems' => $inboxProblems,
        ]);
    }

    public function create(Request $request): View
    {
        $this->guard($request);
        $defaults = $this->settings->all();
        $campaign = new Campaign([
            'send_days' => $defaults['send_days'], 'send_from' => $defaults['send_from'], 'send_to' => $defaults['send_to'],
            'daily_limit' => $defaults['daily_limit'], 'campaign_sender_id' => CampaignSender::where('is_default', true)->value('id'),
        ]);

        return view('admin.campaigns.form', $this->formData($campaign));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $this->validateCampaign($request, null);
        $campaign = Campaign::create($data + ['status' => Campaign::STATUS_DRAFT, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return redirect()->route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps'])->with('success', 'Campaign created. Add its email steps.');
    }

    /** The email exactly as the first prospect of the list would receive it (spec 9.4). Shows the SAVED text. */
    public function previewStep(Request $request, int $campaign, int $step, CampaignSendService $sends)
    {
        $this->guard($request);
        $campaign = Campaign::withTrashed()->with(['list', 'sender'])->findOrFail($campaign);
        $step = CampaignStep::where('campaign_id', $campaign->id)->findOrFail($step);
        abort_if($campaign->sender === null, 404);
        $lead = $campaign->list?->leads()->orderBy('id')->first();

        return view('admin.campaigns.email-preview', $sends->previewEmail($campaign->sender, $step->subject, $step->body, $lead) + [
            'back' => route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps']),
            'backLabel' => 'Back to the campaign',
            'note' => ($lead ? 'Shown for the first person on the list (' . ($lead->fullName() ?: $lead->email) . ').' : 'The list has no one yet, so a made-up prospect is shown.')
                . ' This is the saved text. Save your changes first to see them here.',
        ]);
    }

    public function show(Request $request, int $campaign): View
    {
        $this->guard($request);
        $campaign = Campaign::withTrashed()->with(['list', 'sender'])->findOrFail($campaign);
        $tab = in_array($request->query('tab'), ['list', 'steps', 'activity'], true) ? $request->query('tab') : 'list';

        $data = [
            'campaign' => $campaign,
            'tab' => $tab,
            'counts' => [
                'leads' => $campaign->list ? $campaign->list->leads()->count() : 0,
                'sent' => $campaign->sends()->where('status', CampaignSend::STATUS_SENT)->count(),
                'replied' => $campaign->sends()->whereNotNull('replied_at')->count(),
                'stopped' => $campaign->list ? $campaign->list->leads()->whereIn('status', ['stopped', 'bounced'])->count() : 0,
                'today' => $this->sends->countedToday($campaign, now()),
            ],
            'nextRun' => $this->sends->nextRun($campaign),
            'creatorName' => \App\Models\User::withoutGlobalScopes()->whereKey($campaign->created_by)->value('name'),
            'startBlocker' => $campaign->status === Campaign::STATUS_DRAFT ? $this->sends->startBlocker($campaign) : null,
        ];

        if ($tab === 'list') {
            $data['leads'] = $this->leadsTable($request, (int) $campaign->campaign_list_id);
        } elseif ($tab === 'steps') {
            $data['steps'] = $campaign->steps()->withCount('sends')->get();
            $data['archivedSteps'] = CampaignStep::onlyTrashed()->where('campaign_id', $campaign->id)->orderBy('step_number')->get();
            $data['minGap'] = $this->settings->all()['min_day_gap'];
            $data['previewLead'] = $campaign->list?->leads()->orderBy('id')->first();
        } else {
            $data['activity'] = $this->activity($request, $campaign);
        }

        return view('admin.campaigns.show', $data);
    }

    public function edit(Request $request, int $campaign): View|RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        if (! $campaign->isEditable()) {
            return redirect()->route('admin.campaigns.show', $campaign->id)->with('error', 'Pause the campaign before editing it.');
        }

        return view('admin.campaigns.form', $this->formData($campaign));
    }

    public function update(Request $request, int $campaign): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        if (! $campaign->isEditable()) {
            return redirect()->route('admin.campaigns.show', $campaign->id)->with('error', 'Pause the campaign before editing it.');
        }
        $data = $this->validateCampaign($request, $campaign);
        if ($campaign->status !== Campaign::STATUS_DRAFT && (int) $data['campaign_list_id'] !== (int) $campaign->campaign_list_id) {
            throw ValidationException::withMessages(['campaign_list_id' => 'The list cannot change once the campaign has started.']);
        }
        $campaign->fill($data + ['updated_by' => $request->user()->id])->save();

        return redirect()->route('admin.campaigns.show', $campaign->id)->with('success', 'Campaign saved.');
    }

    public function start(Request $request, int $campaign): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        if ($blocker = $this->sends->startBlocker($campaign)) {
            return back()->with('error', $blocker);
        }
        $this->sends->start($campaign, $request->user()->id);

        return back()->with('success', 'Campaign started. Emails go out inside the send window, up to the daily limit.');
    }

    public function pause(Request $request, int $campaign): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        if ($campaign->status !== Campaign::STATUS_RUNNING) {
            return back()->with('error', 'Only a running campaign can be paused.');
        }
        $this->sends->pause($campaign, null, $request->user()->id);

        return back()->with('success', 'Campaign paused. Nothing more is sent until you resume it.');
    }

    public function resume(Request $request, int $campaign): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::with('sender')->findOrFail($campaign);
        if ($campaign->status !== Campaign::STATUS_PAUSED) {
            return back()->with('error', 'Only a paused campaign can be resumed.');
        }
        if (! $campaign->sender || $campaign->sender->trashed()) {
            return back()->with('error', 'Choose a sending address that is not archived (Edit), then resume.');
        }
        if (! $campaign->steps()->exists()) {
            return back()->with('error', 'Add at least one email step before resuming.');
        }
        $this->sends->resume($campaign, $request->user()->id);

        return back()->with('success', 'Campaign resumed.');
    }

    public function archive(Request $request, int $campaign): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        if ($campaign->status === Campaign::STATUS_RUNNING) {
            return back()->with('error', 'Pause the campaign before archiving it.');
        }
        $campaign->forceFill(['updated_by' => $request->user()->id])->save();
        $campaign->delete();

        return redirect()->route('admin.campaigns.index')->with('success', 'Campaign archived. Find it under Status → Archived to restore it.');
    }

    public function restore(Request $request, int $campaign): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::onlyTrashed()->findOrFail($campaign);
        if (Campaign::where('campaign_list_id', $campaign->campaign_list_id)->exists()) {
            return back()->with('error', 'Its list is now used by another campaign, so this one cannot be restored.');
        }
        $campaign->restore();

        return redirect()->route('admin.campaigns.show', $campaign->id)->with('success', 'Campaign restored.');
    }

    // ── Steps (§7.2 Steps tab) ───────────────────────────────────────────────────────────────

    public function storeStep(Request $request, int $campaign): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        $this->assertStepsEditable($campaign);
        $count = $campaign->steps()->count();
        if ($count >= Campaign::MAX_STEPS) {
            return back()->with('error', 'A campaign has at most ' . Campaign::MAX_STEPS . ' steps.');
        }
        $data = $this->validateStep($request, $count + 1);

        // A double-click sends this twice at once. The campaign row is locked while the step is numbered, and the count is taken again
        // under the lock, so two requests can never both become "step 2".
        $added = DB::transaction(function () use ($campaign, $count, $data, $request) {
            Campaign::whereKey($campaign->id)->lockForUpdate()->first();
            if ($campaign->steps()->count() !== $count) {
                return false;
            }
            CampaignStep::create($data + [
                'campaign_id' => $campaign->id, 'step_number' => $count + 1,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);

            return true;
        });
        if (! $added) {
            return redirect()->route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps'])
                ->with('error', 'The steps changed while you were adding this one. Check the Steps tab and add it again if it is missing.');
        }

        return redirect()->route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps'])->with('success', 'Step added.');
    }

    public function updateStep(Request $request, int $campaign, int $step): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        $this->assertStepsEditable($campaign);
        $step = CampaignStep::where('campaign_id', $campaign->id)->findOrFail($step);
        $data = $this->validateStep($request, $step->step_number, "step_{$step->id}");
        $step->fill($data + ['updated_by' => $request->user()->id])->save();

        return redirect()->route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps'])->with('success', 'Step ' . $step->step_number . ' saved.');
    }

    public function archiveStep(Request $request, int $campaign, int $step): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        $this->assertStepsEditable($campaign);
        $step = CampaignStep::where('campaign_id', $campaign->id)->findOrFail($step);
        if ($step->sends()->exists()) {
            return back()->with('error', 'This step has already been sent to leads, so it stays. You can still edit it.');
        }
        DB::transaction(function () use ($step, $campaign) {
            $step->delete();
            $this->renumber($campaign);
        });

        return redirect()->route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps'])->with('success', 'Step archived.');
    }

    public function restoreStep(Request $request, int $campaign, int $step): RedirectResponse
    {
        $this->guard($request);
        $campaign = Campaign::findOrFail($campaign);
        $this->assertStepsEditable($campaign);
        $step = CampaignStep::onlyTrashed()->where('campaign_id', $campaign->id)->findOrFail($step);
        if ($campaign->steps()->count() >= Campaign::MAX_STEPS) {
            return back()->with('error', 'A campaign has at most ' . Campaign::MAX_STEPS . ' steps. Archive one first.');
        }
        DB::transaction(function () use ($step, $campaign) {
            $step->restore();
            $step->forceFill(['step_number' => Campaign::MAX_STEPS + 1])->save(); // goes last, then renumbered
            $this->renumber($campaign);
        });
        $step->refresh();
        if ($step->step_number > 1 && $step->day_gap < $this->settings->all()['min_day_gap']) {
            $step->forceFill(['day_gap' => $this->settings->all()['min_day_gap']])->save();
        }

        return redirect()->route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps'])->with('success', 'Step restored as step ' . $step->step_number . '.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function activity(Request $request, Campaign $campaign): array
    {
        [$sortKey, $sortCol, $dir] = $this->sortFrom($request, [
            'when' => 'campaign_sends.created_at', 'lead' => 'campaign_sends.to_email', 'step' => 'step_number', 'status' => 'campaign_sends.status',
        ], 'when', 'desc');

        $query = CampaignSend::query()
            ->where('campaign_sends.campaign_id', $campaign->id)
            ->join('campaign_steps', 'campaign_steps.id', '=', 'campaign_sends.campaign_step_id')
            ->select('campaign_sends.*', 'campaign_steps.step_number')
            ->with('lead:id,first_name,last_name,email,status');

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = $this->likeTerm($q);
            $query->where(fn ($w) => $w->where('campaign_sends.to_email', 'like', $like)->orWhere('campaign_sends.subject', 'like', $like)
                ->orWhereIn('campaign_sends.campaign_lead_id', \App\Models\Campaigns\CampaignLead::withTrashed()
                    ->where(fn ($n) => $n->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]))
                    ->select('id')));
        }
        $status = (string) $request->query('status', '');
        if (in_array($status, CampaignSend::STATUSES, true)) {
            $query->where('campaign_sends.status', $status);
        } elseif (in_array($status, ['replied', 'bounced', 'unsubscribed'], true)) {
            $query->whereNotNull('campaign_sends.' . $status . '_at');
        }
        $this->applyDateRange($query, $request, 'campaign_sends.created_at');

        return [
            'rows' => $query->orderBy($sortCol, $dir)->orderByDesc('campaign_sends.id')->paginate(50)->withQueryString(),
            'sort' => $sortKey,
            'direction' => $dir,
        ];
    }

    private function renumber(Campaign $campaign): void
    {
        foreach (CampaignStep::where('campaign_id', $campaign->id)->orderBy('step_number')->orderBy('id')->get()->values() as $i => $s) {
            if ($s->step_number !== $i + 1) {
                $s->forceFill(['step_number' => $i + 1])->save();
            }
        }
    }

    private function assertStepsEditable(Campaign $campaign): void
    {
        if (! $campaign->isEditable()) {
            abort(redirect()->route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps'])
                ->with('error', 'Pause the campaign before changing its steps.'));
        }
    }

    private function validateStep(Request $request, int $stepNumber, string $bag = 'default'): array
    {
        $minGap = $this->settings->all()['min_day_gap'];
        $request->merge([
            'subject' => trim((string) $request->input('subject')),
            'body' => rtrim(str_replace("\r\n", "\n", (string) $request->input('body'))),
        ]);
        $data = $request->validateWithBag($bag, [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'day_gap' => $stepNumber > 1 ? ['required', 'integer', 'min:' . $minGap, 'max:365'] : ['nullable'],
        ], [
            'subject.required' => 'Enter a subject.',
            'body.required' => 'Enter the email text.',
            'day_gap.required' => 'Enter how many days after the previous email this one goes.',
            'day_gap.min' => 'Wait at least ' . $minGap . ' days after the previous email.',
            'day_gap.max' => 'Wait at most 365 days.',
            'day_gap.integer' => 'Enter a whole number of days.',
        ]);

        $errors = [];
        foreach (['subject', 'body'] as $field) {
            $unknown = $this->renderer->unknownTokens($data[$field]);
            if ($unknown !== []) {
                $errors[$field] = '{' . $unknown[0] . '} is not a merge field — use {first_name}, {last_name}, {company} or {town}.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors)->errorBag($bag);
        }

        return ['subject' => $data['subject'], 'body' => $data['body'], 'day_gap' => $stepNumber > 1 ? (int) $data['day_gap'] : 0];
    }

    private function validateCampaign(Request $request, ?Campaign $campaign): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $max = (int) config('campaigns.max_daily_limit');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'campaign_sender_id' => ['required', 'integer', Rule::exists('campaign_senders', 'id')->whereNull('deleted_at')],
            'campaign_list_id' => ['required', 'integer', Rule::exists('campaign_lists', 'id')->whereNull('deleted_at')],
            'send_days' => ['required', 'array', 'min:1'],
            'send_days.*' => ['integer', 'between:1,7'],
            'send_from' => ['required', 'date_format:H:i'],
            'send_to' => ['required', 'date_format:H:i', 'after:send_from'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:' . $max],
        ], [
            'name.required' => 'Give the campaign a name.',
            'campaign_sender_id.required' => 'Choose a sending address.',
            'campaign_sender_id.exists' => 'Choose a sending address that is not archived.',
            'campaign_list_id.required' => 'Choose a list.',
            'campaign_list_id.exists' => 'Choose a list that is not archived.',
            'send_days.required' => 'Choose at least one send day.',
            'send_days.min' => 'Choose at least one send day.',
            'send_from.date_format' => 'Enter a time like 08:00.',
            'send_to.date_format' => 'Enter a time like 16:00.',
            'send_to.after' => 'The end time must be after the start time.',
            'daily_limit.min' => 'The daily limit must be at least 1.',
            'daily_limit.max' => 'The daily limit can be at most ' . $max . '.',
        ]);

        $taken = Campaign::where('campaign_list_id', $data['campaign_list_id'])
            ->when($campaign, fn ($q) => $q->whereKeyNot($campaign->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['campaign_list_id' => 'That list already belongs to another campaign.']);
        }
        $days = array_values(array_unique(array_map('intval', $data['send_days'])));
        sort($days);
        $data['send_days'] = $days;

        return $data;
    }

    private function formData(Campaign $campaign): array
    {
        $taken = Campaign::when($campaign->exists, fn ($q) => $q->whereKeyNot($campaign->id))->pluck('campaign_list_id');

        return [
            'campaign' => $campaign,
            'senders' => CampaignSender::orderByDesc('is_default')->orderBy('email')->get(),
            'lists' => CampaignList::whereNotIn('id', $taken)->withCount('leads')->orderBy('name')->get(),
            'maxDailyLimit' => (int) config('campaigns.max_daily_limit'),
        ];
    }
}
