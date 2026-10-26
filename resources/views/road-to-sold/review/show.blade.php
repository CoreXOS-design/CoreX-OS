{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-454 v2 phase 6 - one task completion, its evidence, and its review history. Needs $c, $links, $names, $review, $viewer, $track. --}}
@extends('layouts.corex-app')
@section('title', 'Review a captured task')

@section('corex-content')
@php
    $states = \App\Services\RoadToSold\CompletionListService::STATES;
    $doneBy = \App\Services\RoadToSold\CompletionListService::DONE_BY;
    $sources = \App\Services\RoadToSold\CompletionListService::SOURCES;
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $reviewable = $review->reviewable($c);
    $own = $review->isOwnWork($viewer, $c);
    $canApprove = $reviewable && ! $own && in_array($c->state, ['provisional', 'returned'], true);
    $canReturn = $reviewable && ! $own && in_array($c->state, ['provisional', 'confirmed'], true);
    $row = fn (string $label, $value) => [$label, $value];
@endphp

<div class="w-full space-y-4 rts-page">
    <div class="rounded-md px-6 py-3 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $c->task?->label ?? 'Task' }} - {{ $c->agent?->name }}</h1>
            <a href="{{ route('road-to-sold.review.index', \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Back to the list</a>
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

    <div class="rounded-md p-5 space-y-3 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" x-data="{ returning: false }">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
            <div><dt class="text-xs font-semibold" style="color: var(--text-muted);">When</dt><dd>{{ \App\Support\AppDate::dateTime($c->occurred_at) }}</dd></div>
            <div><dt class="text-xs font-semibold" style="color: var(--text-muted);">Step</dt><dd>{{ $c->step?->label ?? '-' }}</dd></div>
            <div><dt class="text-xs font-semibold" style="color: var(--text-muted);">Points</dt><dd>{{ rtrim(rtrim(number_format((float) $c->points_awarded, 2), '0'), '.') }}</dd></div>
            <div><dt class="text-xs font-semibold" style="color: var(--text-muted);">How it was made</dt><dd>{{ $sources[$c->source] ?? $c->source }}</dd></div>
            <div><dt class="text-xs font-semibold" style="color: var(--text-muted);">Done by</dt><dd>{{ $doneBy[$c->recorded_by_kind] ?? $c->recorded_by_kind }}@if($c->recorded_by_kind === 'assistant') ({{ $c->recordedBy?->name }})@endif</dd></div>
            <div><dt class="text-xs font-semibold" style="color: var(--text-muted);">Status</dt><dd>{{ $states[$c->state] ?? $c->state }}@if($c->trashed()) (archived)@endif</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs font-semibold" style="color: var(--text-muted);">Note</dt><dd>{{ $c->evidence_note ?: '-' }}</dd></div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-semibold" style="color: var(--text-muted);">Found</dt>
                <dd>
                    @forelse($links as [$label, $url])
                        <div>@if($url)<a href="{{ $url }}" class="underline">{{ $label }}</a>@else{{ $label }}@endif</div>
                    @empty
                        -
                    @endforelse
                    @foreach($c->evidence['photos'] ?? [] as $n => $photo)
                        <div><a href="{{ route('road-to-sold.captures.photo', [$c->id, $n]) }}" class="underline" target="_blank" rel="noopener">Photo {{ $n + 1 }}</a></div>
                    @endforeach
                </dd>
            </div>
            @if($c->state === 'returned' && $c->review_comment)
                <div class="sm:col-span-2"><dt class="text-xs font-semibold" style="color: var(--text-muted);">Sent back with</dt><dd>"{{ $c->review_comment }}"</dd></div>
            @endif
        </dl>

        <div class="flex items-center gap-2 flex-wrap pt-2">
            @if($canApprove && ! $c->trashed())
                <form method="POST" action="{{ route('road-to-sold.review.approve', $c->id) }}">@csrf<button type="submit" class="corex-btn-primary corex-btn-xs">Approve</button></form>
            @endif
            @if($canReturn && ! $c->trashed())
                <button type="button" class="corex-btn-outline corex-btn-xs" @click="returning = true">Send back</button>
            @endif
            @if($reviewable && $own)<span class="text-xs" style="color: var(--text-muted);">Another manager reviews your own work.</span>@endif
            @if(! $reviewable)<span class="text-xs" style="color: var(--text-muted);">Only captured tasks are reviewed - this one is the record of something that really happened.</span>@endif
        </div>
        @if($canReturn && ! $c->trashed())
            <form method="POST" action="{{ route('road-to-sold.review.send-back', $c->id) }}" class="flex items-start gap-2 flex-wrap" x-show="returning" x-cloak>
                @csrf
                <label class="sr-only" for="rv-comment">What needs to change</label>
                <textarea id="rv-comment" name="comment" rows="3" maxlength="2000" required placeholder="What needs to change? The agent sees this." class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }} min-width: 22rem;"></textarea>
                <button type="submit" class="corex-btn-primary corex-btn-xs">Send back</button>
                <button type="button" class="corex-btn-outline corex-btn-xs" @click="returning = false">Cancel</button>
            </form>
        @endif
    </div>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-2 text-xs font-semibold" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">History</div>
        <table class="w-full text-xs ds-table">
            <tbody>
            @forelse(array_reverse((array) ($c->override_audit ?? [])) as $h)
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-3 py-2 whitespace-nowrap" style="color: var(--text-primary);">{{ \App\Support\AppDate::dateTime(\Illuminate\Support\Carbon::parse($h['at'])) }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $names[$h['by']] ?? ('#' . $h['by']) }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ ['approved' => 'Approved', 'returned' => 'Sent back', 'resubmitted' => 'Sent for review again'][$h['action']] ?? $h['action'] }}@if(! empty($h['comment'])): "{{ $h['comment'] }}"@endif</td>
                </tr>
            @empty
                <tr><td class="px-6 py-8 text-center" style="color: var(--text-muted);">No review decisions yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
