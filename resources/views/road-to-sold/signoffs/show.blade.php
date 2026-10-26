{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-454 v2 phase 4 - one agent's level: the weeks on target, what each needed task did, the history, and the decision. Needs $row (RtsAgentLevel), $progress
     (AgentLevelService::progress), $ledger, $viewer, $track, $own. --}}
@extends('layouts.corex-app')
@section('title', 'Level sign-off')

@section('corex-content')
@php
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $params = \App\Services\RoadToSold\Track::params($track);
    $awaiting = $row->status === 'awaiting_signoff' && ! $row->trashed();
    $decisions = ['requested' => 'Put forward', 'approved' => 'Signed off', 'returned' => 'Sent back'];
@endphp

<div class="w-full space-y-4 rts-page" x-data="{ sending: false }">
    <div class="rounded-md px-6 py-3 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $row->agent?->name }}</h1>
                <span class="ds-badge ds-badge-info">{{ $row->level?->name }}</span>
                <span class="text-xs" style="color: var(--text-primary);">{{ \App\Models\RoadToSold\RtsAgentLevel::STATUSES[$row->status] ?? $row->status }}</span>
                @if($row->trashed())<span class="ds-badge ds-badge-default">Archived</span>@endif
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('road-to-sold.signoffs.index', $params) }}" class="corex-btn-outline corex-btn-xs">All sign-offs</a>
                <a href="{{ route('road-to-sold.index', ['agent' => $row->user_id] + $params) }}" class="corex-btn-outline corex-btn-xs">Their Road to Sold</a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($errors->all() as $m)<div>{{ $m }}</div>@endforeach
        </div>
    @endif

    @if($awaiting)
        <div class="rounded-md px-4 py-3 flex items-start gap-3 flex-wrap" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex-1 text-xs" style="color: var(--text-primary); min-width: 16rem;">
                <strong>{{ $row->agent?->name }}</strong> has been on target for {{ $progress['needed'] }} {{ \Illuminate\Support\Str::plural('week', $progress['needed']) }} and is waiting for you.
                @if($progress['next'])Signing off moves them to <strong>{{ $progress['next']->name }}</strong>.@else This is the top level.@endif
            </div>
            @if($own)
                <div class="text-xs" style="color: var(--text-muted);">Another manager signs off your own level.</div>
            @else
                <form method="POST" action="{{ route('road-to-sold.signoffs.approve', $row->id) }}" class="flex items-start gap-2 flex-wrap">
                    @csrf
                    <label class="sr-only" for="so-approve-comment">Comment (optional)</label>
                    <input id="so-approve-comment" type="text" name="comment" maxlength="2000" placeholder="A word for the agent (optional)" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 16rem;">
                    <button type="submit" class="corex-btn-primary corex-btn-xs">Sign off</button>
                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="sending = !sending">Send back</button>
                </form>
            @endif
        </div>
        @if(! $own)
            <form method="POST" action="{{ route('road-to-sold.signoffs.send-back', $row->id) }}" x-show="sending" x-cloak class="rounded-md px-4 py-3 flex items-start gap-2 flex-wrap" style="background: var(--surface); border: 1px solid var(--border);">
                @csrf
                <label class="sr-only" for="so-back-comment">What the agent should work on</label>
                <textarea id="so-back-comment" name="comment" rows="3" maxlength="2000" required placeholder="What should they work on? The agent sees this, and the weeks start counting again from today." class="rounded-md px-3 py-2 text-xs flex-1" style="{{ $inputStyle }} min-width: 22rem;"></textarea>
                <button type="submit" class="corex-btn-primary corex-btn-xs">Send back</button>
                <button type="button" class="corex-btn-outline corex-btn-xs" @click="sending = false">Cancel</button>
            </form>
        @endif
    @endif

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 text-xs font-bold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">
            Weeks on target
            @if($progress['required'] !== [])
                <span class="font-normal" style="color: var(--text-muted);"> - {{ min($progress['streak'], $progress['needed']) }} of {{ $progress['needed'] }} in a row</span>
            @endif
        </div>
        @if($progress['required'] === [])
            <div class="px-4 py-6 text-xs" style="color: var(--text-muted);">
                No task on {{ $row->level?->name }} is marked "needed for sign-off", so there is nothing to measure.
                @if($viewer->hasPermission('road_to_sold.manage_recipe') && \Illuminate\Support\Facades\Route::has('road-to-sold.settings.recipe.index'))
                    <a href="{{ route('road-to-sold.settings.recipe.index') }}" class="font-semibold underline">Mark the tasks on the Recipe tab</a>.
                @endif
            </div>
        @elseif($progress['weeks'] === [])
            <div class="px-4 py-6 text-xs" style="color: var(--text-muted);">
                Counting starts with the first whole week after {{ \App\Support\AppDate::dateTime($progress['baseline']) }}.
            </div>
        @else
            <table class="w-full text-xs ds-table">
                <thead>
                    <tr style="color: var(--text-muted);">
                        <th class="px-3 py-2 text-left">Week of</th>
                        <th class="px-3 py-2 text-left">Result</th>
                        @foreach($progress['required'] as $t)<th class="px-3 py-2 text-left">{{ $t['label'] }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                @foreach($progress['weeks'] as $w)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-3 py-2 whitespace-nowrap" style="color: var(--text-primary);">{{ $w['start']->format('j M Y') }}@if($w['current']) <span style="color: var(--text-muted);">(this week)</span>@endif</td>
                        <td class="px-3 py-2" style="color: var(--text-primary);">
                            @if($w['state'] === 'met')<span class="ds-badge ds-badge-success">On target</span>
                            @elseif($w['state'] === 'short')<span class="ds-badge ds-badge-{{ $w['current'] ? 'default' : 'warning' }}">{{ $w['current'] ? 'Running' : 'Short' }}</span>
                            @else<span class="ds-badge ds-badge-default">No working days</span>@endif
                        </td>
                        @foreach($w['tasks'] as $t)
                            <td class="px-3 py-2" style="color: var(--text-primary);">{{ $w['state'] === 'neutral' ? '-' : $t['actual'] . ' of ' . $t['need'] }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 text-xs font-bold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">History</div>
        @if($ledger->isEmpty())
            <div class="px-4 py-6 text-xs" style="color: var(--text-muted);">Nothing has been decided for this agent yet.</div>
        @else
            <table class="w-full text-xs ds-table">
                <tbody>
                @foreach($ledger as $e)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-3 py-2 whitespace-nowrap" style="color: var(--text-primary);">{{ \App\Support\AppDate::dateTime($e->decided_at) }}</td>
                        <td class="px-3 py-2" style="color: var(--text-primary);">
                            {{ $decisions[$e->decision] ?? $e->decision }}
                            @if($e->decision === 'approved' && $e->toLevel) to <strong>{{ $e->toLevel->name }}</strong>@elseif($e->decision === 'approved') at the top level@endif
                            <span style="color: var(--text-muted);">from {{ $e->fromLevel?->name }}</span>
                        </td>
                        <td class="px-3 py-2" style="color: var(--text-primary);">{{ $e->automatic ? 'Automatically' : ($e->decidedBy?->name ?? 'CoreX') }}</td>
                        <td class="px-3 py-2" style="color: var(--text-primary); max-width: 28rem;">{{ $e->comment ?: '-' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
