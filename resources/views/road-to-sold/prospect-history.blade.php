@extends('layouts.corex-app')
@section('title', 'Prospect history')

@section('corex-content')
@php
    $tp = $prospect->trackedProperty;
    $address = $tp ? trim(implode(' ', array_filter([$tp->street_number, $tp->street_name, $tp->suburb]))) : '';
    $kindWords = ['placed' => 'Put on your list', 'stage' => 'Moved on', 'outcome' => 'Call result', 'next_action' => 'Next action set', 'park' => 'Set aside', 'unpark' => 'Brought back', 'release' => 'Released', 'close' => 'Closed'];
@endphp
<div class="max-w-2xl mx-auto space-y-3">
    <div>
        <h1 class="text-base font-bold" style="color: var(--text-primary);">History</h1>
        <div class="text-xs" style="color: var(--text-muted);">{{ $address !== '' ? $address : 'Property' }} &middot; now at {{ $stageLabel($prospect->stage) }}</div>
    </div>
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        @forelse($events as $e)
            <div class="px-4 py-2 text-xs" style="border-top: 1px solid var(--border); color: var(--text-primary);">
                <span class="font-semibold">{{ $kindWords[$e->kind] ?? ucfirst($e->kind) }}</span>
                @if($e->kind === 'stage')<span>: {{ $stageLabel($e->from_stage) }} &rarr; {{ $stageLabel($e->to_stage) }}</span>@endif
                <span style="color: var(--text-muted);"> &middot; {{ $e->occurred_at?->format('j M Y H:i') }}</span>
                @if($e->note)<div style="color: var(--text-muted);">{{ $e->note }}</div>@endif
            </div>
        @empty
            <div class="px-4 py-8 text-center text-xs" style="color: var(--text-muted);">Nothing has happened to this property yet.</div>
        @endforelse
    </div>
</div>
@endsection
