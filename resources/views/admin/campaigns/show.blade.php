@extends('layouts.corex')

@section('corex-content')
{{-- AT-534 — the campaign page (.ai/specs/campaigns.md §7.2): sticky header with status + actions, tabs List / Steps / Activity.
     Header, tabs and filters stay put; only the tab's table scrolls. --}}
@php
    $archived = $campaign->trashed();
    $tabUrl = fn ($t) => route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => $t]);
    $tabBase = 'padding: 8px 14px; font-size: 0.8125rem; font-weight: 500; border-bottom: 2px solid transparent; color: var(--text-muted);';
    $tabOn = 'padding: 8px 14px; font-size: 0.8125rem; font-weight: 600; border-bottom: 2px solid var(--brand-icon, #0ea5e9); color: var(--brand-icon, #0ea5e9);';
@endphp
<div class="w-full h-full flex flex-col">
    @include('admin.campaigns._head')

    <div class="rounded-md px-5 py-3 mt-2 corex-page-banner flex-shrink-0" data-campaign-header>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h1 class="text-base font-bold truncate" style="color: var(--text-primary);">{{ $campaign->name }}</h1>
                    @include('admin.campaigns._status', ['status' => $archived ? 'archived' : $campaign->status])
                </div>
                <div class="text-xs cmp-muted mt-0.5">
                    {{ $campaign->sender?->email ?? '—' }} · list <a class="cmp-link" href="{{ route('admin.campaigns.lists.show', $campaign->campaign_list_id) }}">{{ $campaign->list?->name ?? '—' }}</a>
                    · {{ $campaign->sendDaysLabel() }} {{ $campaign->timeLabel($campaign->send_from) }}–{{ $campaign->timeLabel($campaign->send_to) }}
                    · {{ $counts['today'] }}/{{ $campaign->daily_limit }} today
                    · next {{ $nextRun?->format('D d M H:i') ?? '—' }}
                    @if($creatorName) · by {{ $creatorName }} @endif
                </div>
                @if($campaign->paused_reason)
                    <div class="text-xs mt-1" style="color: var(--ds-crimson);" data-paused-reason>{{ $campaign->paused_reason }}</div>
                @endif
                @if($startBlocker)
                    <div class="text-xs mt-1 cmp-muted" data-start-blocker>{{ $startBlocker }}</div>
                @endif
            </div>
            <div class="flex items-center gap-4 text-xs">
                @foreach(['leads' => 'Leads', 'sent' => 'Sent', 'replied' => 'Replied', 'stopped' => 'Stopped'] as $k => $l)
                    <div class="text-center"><div class="text-base font-bold" style="color: var(--text-primary);">{{ $counts[$k] }}</div><div class="cmp-muted">{{ $l }}</div></div>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($archived)
                    <form method="POST" action="{{ route('admin.campaigns.restore', $campaign->id) }}">@csrf<button class="corex-btn-primary text-xs">Restore</button></form>
                @else
                    @if($campaign->status === 'draft')
                        <form method="POST" action="{{ route('admin.campaigns.start', $campaign->id) }}" onsubmit="return confirm('Start sending this campaign?')">@csrf<button class="corex-btn-primary text-xs" @disabled($startBlocker)>Start</button></form>
                    @elseif($campaign->status === 'running')
                        <form method="POST" action="{{ route('admin.campaigns.pause', $campaign->id) }}">@csrf<button class="corex-btn-primary text-xs">Pause</button></form>
                    @elseif($campaign->status === 'paused')
                        <form method="POST" action="{{ route('admin.campaigns.resume', $campaign->id) }}">@csrf<button class="corex-btn-primary text-xs">Resume</button></form>
                    @endif
                    @if($campaign->isEditable())
                        <a href="{{ route('admin.campaigns.edit', $campaign->id) }}" class="corex-btn-outline text-xs">Edit</a>
                    @endif
                    @if($campaign->status !== 'running')
                        <form method="POST" action="{{ route('admin.campaigns.archive', $campaign->id) }}" onsubmit="return confirm('Archive this campaign?')">@csrf @method('DELETE')<button class="corex-btn-outline text-xs">Archive</button></form>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <nav class="flex gap-1 mt-2 flex-shrink-0" style="border-bottom: 1px solid var(--border);" data-campaign-tabs>
        @foreach(['list' => 'List', 'steps' => 'Steps', 'activity' => 'Activity'] as $t => $label)
            <a href="{{ $tabUrl($t) }}" style="{{ $tab === $t ? $tabOn : $tabBase }}" @if($tab === $t) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab === 'list')
        <div class="mt-2 flex-shrink-0">
            @include('admin.campaigns._leads-filters', ['action' => route('admin.campaigns.show', $campaign->id), 'hidden' => ['tab' => 'list']])
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-2">
            @include('admin.campaigns._leads-table', [
                'leads' => $leads, 'manage' => false, 'archivedView' => false,
                'filtered' => request()->filled('q') || request()->filled('status') || request()->filled('from') || request()->filled('to'),
                'emptyText' => 'This list has no leads yet.',
            ])
        </div>
    @elseif($tab === 'steps')
        <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-2 space-y-3">
            @php $editable = $campaign->isEditable(); @endphp
            @foreach($steps as $step)
                @php $bag = $errors->getBag('step_' . $step->id); @endphp
                <div class="cmp-panel p-3" data-step="{{ $step->step_number }}">
                    @if($editable)
                        <form method="POST" action="{{ route('admin.campaigns.steps.update', [$campaign->id, $step->id]) }}" class="grid grid-cols-1 md:grid-cols-6 gap-2">
                            @csrf @method('PUT')
                            <div class="md:col-span-1"><span class="cmp-label">Step {{ $step->step_number }}</span>
                                @if($step->step_number > 1)
                                    <label class="cmp-label mt-1">Days after step {{ $step->step_number - 1 }}</label>
                                    <input type="number" name="day_gap" min="{{ $minGap }}" max="365" value="{{ $bag->any() ? old('day_gap') : $step->day_gap }}" class="cmp-input">
                                    @if($bag->has('day_gap'))<div class="cmp-err">{{ $bag->first('day_gap') }}</div>@endif
                                @else
                                    <div class="text-xs cmp-muted">Sent first</div>
                                @endif
                                <div class="text-xs cmp-muted mt-1">{{ $step->sends_count }} sent</div>
                            </div>
                            <div class="md:col-span-5 space-y-2">
                                <div><label class="cmp-label">Subject</label><input name="subject" maxlength="200" value="{{ $bag->any() ? old('subject') : $step->subject }}" class="cmp-input" required>
                                    @if($bag->has('subject'))<div class="cmp-err">{{ $bag->first('subject') }}</div>@endif</div>
                                <div><label class="cmp-label">Text — {first_name} {last_name} {company} {town}</label><textarea name="body" rows="7" class="cmp-input" required>{{ $bag->any() ? old('body') : $step->body }}</textarea>
                                    @if($bag->has('body'))<div class="cmp-err">{{ $bag->first('body') }}</div>@endif</div>
                                <div class="flex justify-end gap-2">
                                    <button class="corex-btn-primary text-xs">Save step</button>
                                </div>
                            </div>
                        </form>
                        @if($step->sends_count === 0)
                            <form method="POST" action="{{ route('admin.campaigns.steps.archive', [$campaign->id, $step->id]) }}" class="flex justify-end mt-1" onsubmit="return confirm('Archive step {{ $step->step_number }}?')">@csrf @method('DELETE')<button class="corex-btn-outline text-xs">Archive step</button></form>
                        @endif
                    @else
                        <div class="text-xs cmp-muted">Step {{ $step->step_number }} · {{ $step->step_number > 1 ? $step->day_gap . ' days after step ' . ($step->step_number - 1) : 'sent first' }} · {{ $step->sends_count }} sent</div>
                        <div class="text-sm font-semibold mt-1" style="color: var(--text-primary);">{{ $step->subject }}</div>
                        <pre class="text-sm mt-1 whitespace-pre-wrap" style="font-family: inherit; color: var(--text-primary);">{{ $step->body }}</pre>
                    @endif
                    @if($previewLead)
                        <details class="mt-2 text-xs"><summary class="cursor-pointer cmp-muted">As {{ $previewLead->fullName() ?: $previewLead->email }} sees it</summary>
                            @php $r = app(\App\Services\Campaigns\CampaignMergeRenderer::class); @endphp
                            <div class="mt-1 p-2 rounded-md" style="background: var(--surface-2);">
                                <div class="font-semibold">{{ $r->renderSubject($step->subject, $previewLead) }}</div>
                                <pre class="whitespace-pre-wrap mt-1" style="font-family: inherit;">{{ $r->render($step->body, $previewLead) }}@if($campaign->sender?->signature)

{{ $campaign->sender->signature }}@endif</pre>
                            </div>
                        </details>
                    @endif
                </div>
            @endforeach

            @if($editable && $steps->count() < \App\Models\Campaigns\Campaign::MAX_STEPS)
                @php $next = $steps->count() + 1; @endphp
                <form method="POST" action="{{ route('admin.campaigns.steps.store', $campaign->id) }}" class="cmp-panel p-3 grid grid-cols-1 md:grid-cols-6 gap-2" data-add-step>
                    @csrf
                    <div class="md:col-span-1"><span class="cmp-label">New step {{ $next }}</span>
                        @if($next > 1)
                            <label class="cmp-label mt-1">Days after step {{ $next - 1 }}</label>
                            <input type="number" name="day_gap" min="{{ $minGap }}" max="365" value="{{ old('day_gap', $minGap) }}" class="cmp-input">
                        @endif
                    </div>
                    <div class="md:col-span-5 space-y-2">
                        <div><label class="cmp-label">Subject</label><input name="subject" maxlength="200" value="{{ old('subject') }}" class="cmp-input" required></div>
                        <div><label class="cmp-label">Text — {first_name} {last_name} {company} {town}</label><textarea name="body" rows="7" class="cmp-input" required>{{ old('body') }}</textarea></div>
                        <div class="flex justify-end"><button class="corex-btn-primary text-xs">Add step</button></div>
                    </div>
                </form>
            @elseif(! $editable && $steps->isEmpty())
                <div class="cmp-panel p-6 text-center text-sm cmp-muted">No steps.</div>
            @elseif(! $editable)
                <div class="text-xs cmp-muted">Pause the campaign to change its steps.</div>
            @endif

            @if($archivedSteps->isNotEmpty())
                <div class="cmp-panel p-3 text-xs">
                    <div class="cmp-label">Archived steps</div>
                    @foreach($archivedSteps as $a)
                        <div class="flex items-center justify-between gap-2 py-1 cmp-row">
                            <span>{{ $a->subject }}</span>
                            @if($editable)
                                <form method="POST" action="{{ route('admin.campaigns.steps.restore', [$campaign->id, $a->id]) }}">@csrf<button class="corex-btn-outline text-xs">Restore</button></form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        @php $sort = $activity['sort']; $direction = $activity['direction']; @endphp
        <form method="GET" class="cmp-panel p-3 mt-2 flex-shrink-0 grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
            <input type="hidden" name="tab" value="activity">
            <div class="col-span-2"><label class="cmp-label">Search</label><input type="search" name="q" value="{{ request('q') }}" placeholder="Lead, email or subject" class="cmp-input"></div>
            <div><label class="cmp-label">Status</label>
                <select name="status" class="cmp-input">
                    <option value="">All</option>
                    @foreach(['sent' => 'Sent', 'sending' => 'Sending', 'failed' => 'Failed', 'unknown' => 'Unknown', 'replied' => 'Replied', 'bounced' => 'Bounced', 'unsubscribed' => 'Unsubscribed'] as $v => $l)
                        <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="cmp-label">From</label><input type="date" name="from" value="{{ request('from') }}" class="cmp-input"></div>
            <div><label class="cmp-label">To</label><input type="date" name="to" value="{{ request('to') }}" class="cmp-input"></div>
            <div class="flex gap-2"><button class="corex-btn-primary text-xs flex-1 justify-center">Apply</button><a href="{{ $tabUrl('activity') }}" class="corex-btn-outline text-xs">Reset</a></div>
        </form>
        <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-2">
            <div class="cmp-panel overflow-x-auto">
                <table class="w-full ds-table" data-activity-table>
                    <thead style="background: var(--surface-2);"><tr>
                        @include('admin.campaigns._sort', ['field' => 'when', 'label' => 'When'])
                        @include('admin.campaigns._sort', ['field' => 'lead', 'label' => 'Lead'])
                        @include('admin.campaigns._sort', ['field' => 'step', 'label' => 'Step', 'align' => 'right'])
                        <th class="cmp-th">Subject</th>
                        @include('admin.campaigns._sort', ['field' => 'status', 'label' => 'Status'])
                        <th class="cmp-th">Outcome</th>
                    </tr></thead>
                    <tbody>
                        @forelse($activity['rows'] as $s)
                            <tr class="cmp-row" data-send-row="{{ $s->id }}">
                                <td class="cmp-td cmp-muted whitespace-nowrap">{{ ($s->sent_at ?? $s->created_at)?->format('Y-m-d H:i') }}</td>
                                <td class="cmp-td">{{ $s->lead?->fullName() ?: '' }} <span class="cmp-muted">{{ $s->to_email }}</span></td>
                                <td class="cmp-td text-right">{{ $s->step_number }}</td>
                                <td class="cmp-td">{{ $s->subject }}</td>
                                <td class="cmp-td">@include('admin.campaigns._status', ['status' => $s->status])
                                    @if($s->error_reason)<span class="cmp-muted text-xs"> {{ $s->error_reason }} ({{ $s->attempts }}×)</span>@endif</td>
                                <td class="cmp-td text-xs whitespace-nowrap">
                                    @if($s->replied_at) Replied {{ $s->replied_at->format('Y-m-d H:i') }} @endif
                                    @if($s->bounced_at) Bounced {{ $s->bounced_at->format('Y-m-d H:i') }} @endif
                                    @if($s->unsubscribed_at) Unsubscribed {{ $s->unsubscribed_at->format('Y-m-d H:i') }} @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-10 text-center text-sm cmp-muted">
                                @if(request()->filled('q') || request()->filled('status') || request()->filled('from') || request()->filled('to'))
                                    No emails match these filters. <a href="{{ $tabUrl('activity') }}" class="cmp-link">Clear filters</a>
                                @else
                                    Nothing sent yet.
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $activity['rows']->links() }}</div>
        </div>
    @endif
</div>
@endsection
