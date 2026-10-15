@extends('layouts.corex')

{{--
    .ai/specs/rental-inspection-form.md §13 — the review screen. Item 5 of
    the build brief: never a silent write. The agent sees, per item, what
    the reader detected and with what confidence, and confirms or corrects
    every row before anything reaches the inspection. Ambiguous rows (two
    marks on one row, none, or a mark below confidence) are never
    pre-filled with a guess — the select simply starts blank.

    Plain server-rendered form (no Alpine) — matching this screen family's
    existing convention (show.blade.php) and sidestepping any risk of the
    static-style/:style conflict entirely for this build.
--}}

@section('content')
<div class="p-6 max-w-3xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">Review scan — {{ $scan->original_filename }}</h1>
            <span class="text-xs" style="color: var(--text-muted);">
                {{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}
                · uploaded by {{ $scan->uploadedBy?->name }} on {{ $scan->created_at?->format('Y-m-d H:i') }}
            </span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.rental-inspections.scans.download', [$inspection, $scan, 'view' => 1]) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs" data-qa="open-original">Open original</a>
            <a href="{{ route('corex.rental-inspections.show', $inspection) }}" class="corex-btn-outline text-xs">&larr; Back to inspection</a>
        </div>
    </div>

    @if(! empty($scan->read_notes))
        <div class="rounded-md p-3 text-xs space-y-1" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-secondary);" data-qa="scan-read-notes">
            @foreach($scan->read_notes as $note)
                <p>{{ $note }}</p>
            @endforeach
        </div>
    @endif

    @if(in_array($scan->status, ['failed', 'version_mismatch'], true))
        <div class="rounded-md p-4" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
            <p class="text-sm font-semibold" style="color: var(--ds-crimson);">
                {{ $scan->status === 'version_mismatch' ? 'This scan does not match the inspection\'s current form.' : 'This scan could not be read.' }}
            </p>
            <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $scan->failure_reason }}</p>
            <p class="text-xs mt-2" style="color: var(--text-muted);">The original file is retained — <a href="{{ route('corex.rental-inspections.scans.download', [$inspection, $scan]) }}" class="underline">download it</a> to check by hand.</p>
        </div>
    @else
        <form method="POST" action="{{ route('corex.rental-inspections.scans.apply', [$inspection, $scan]) }}" class="space-y-3">
            @csrf
            @php
                $groupedMarks = $scan->marks->groupBy(fn ($m) => $m->item?->room?->label ?? 'General');
                $stateLabel = collect($conditionStates)->pluck('label', 'key');
                $isLow = fn ($m) => ! $m->ambiguous && $m->detected_confidence !== null && (float) $m->detected_confidence < $lowConfidenceBelow;
                $open = $scan->marks->whereNull('applied_observation_id');
                $needAttention = $open->filter(fn ($m) => $m->ambiguous || $isLow($m))->count();
            @endphp
            @if($open->isNotEmpty())
                <p class="text-xs" style="color: var(--text-secondary);" data-qa="scan-summary">
                    {{ $open->count() }} {{ $open->count() === 1 ? 'row' : 'rows' }} proposed from the ticked columns.
                    @if($needAttention > 0) <strong>{{ $needAttention }} {{ $needAttention === 1 ? 'needs' : 'need' }} your attention</strong> (left blank below).@endif
                    Nothing changes on the inspection until you press "Apply proposed marks".
                </p>
            @endif
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                @forelse($groupedMarks as $roomLabel => $marksInRoom)
                    <h2 class="text-xs font-bold uppercase tracking-wide pt-2" style="color: var(--text-secondary);">{{ $roomLabel }}</h2>
                    <div class="space-y-2">
                        @foreach($marksInRoom as $mark)
                            <div class="text-sm flex items-center justify-between gap-3 py-1" style="border-bottom: 1px solid var(--border);">
                                <span>{{ $mark->item?->label ?? 'Unknown item' }}
                                    <span class="text-xs" style="color: var(--text-muted);">p.{{ $mark->page_number }} &middot; on screen: {{ isset($onScreen[$mark->rental_inspection_item_id]) ? ($stateLabel[$onScreen[$mark->rental_inspection_item_id]] ?? $onScreen[$mark->rental_inspection_item_id]) : 'not recorded' }}</span>
                                </span>
                                <div class="flex items-center gap-2">
                                    @if($mark->applied_observation_id)
                                        <span class="ds-badge ds-badge-success">Applied — {{ ucfirst(str_replace('_', ' ', $mark->confirmed_condition_key)) }}</span>
                                        <span class="text-xs" style="color: var(--text-muted);">by {{ $mark->confirmedBy?->name }}</span>
                                    @else
                                        <span class="text-xs" style="color: var(--text-muted);">
                                            @if($mark->ambiguous)
                                                <span data-qa="row-unclear">No clear mark</span>
                                            @elseif($isLow($mark))
                                                <span data-qa="row-low-confidence" style="color: var(--ds-amber); font-weight: 600;">Check this one: read {{ $stateLabel[$mark->detected_condition_key] ?? ucfirst(str_replace('_', ' ', $mark->detected_condition_key)) }} ({{ (int) round($mark->detected_confidence * 100) }}%)</span>
                                            @else
                                                Read: {{ $stateLabel[$mark->detected_condition_key] ?? ucfirst(str_replace('_', ' ', $mark->detected_condition_key)) }} ({{ (int) round($mark->detected_confidence * 100) }}%)
                                            @endif
                                        </span>
                                        <select name="condition_keys[{{ $mark->id }}]" class="text-xs rounded-md px-2 py-1" style="border: 1px solid var(--border);">
                                            <option value="">— Confirm —</option>
                                            @foreach($conditionStates as $state)
                                                <option value="{{ $state['key'] }}" @selected(!$mark->ambiguous && !$isLow($mark) && $mark->detected_condition_key === $state['key'])>{{ $state['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="text-xs" style="color: var(--text-muted);">Nothing on this scan matched an item on the current form.</p>
                @endforelse
            </div>

            @if($scan->marks->whereNull('applied_observation_id')->isNotEmpty())
                <button type="submit" class="corex-btn-outline text-xs" data-qa="apply-proposed">Apply proposed marks</button>
            @endif
        </form>
    @endif
</div>
@endsection
