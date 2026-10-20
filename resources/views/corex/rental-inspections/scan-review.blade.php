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

    AT-472 / §13.14 (Johan, 20 Oct 2026): every row also carries the item's note and photos, captured on this screen and saved as they
    are captured - through the capture screen's own /observations and /photos endpoints (public/js/corex-scan-review.js on top of
    public/js/corex-photo-batch-uploader.js), not tied to Apply. The note box starts with the AI-read handwriting when there is one.
    A signed or completed inspection makes the whole screen read-only.
--}}

@section('content')
<div class="p-6 max-w-3xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" data-qa="scan-errors">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    {{-- §64 - the upload was made on another inspection; its own form code says this one, so it was filed and read here. --}}
    @if(session('scan_routed'))
        @php $routed = session('scan_routed'); @endphp
        <div class="rounded-md p-3 text-xs" style="background: color-mix(in srgb, var(--ds-blue, #0ea5e9) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-blue, #0ea5e9) 35%, transparent); color: var(--text-secondary);" data-qa="scan-routed-banner">
            <span class="font-semibold">This was the form for inspection #{{ $routed['to'] }} - read here.</span>
            You uploaded it on inspection #{{ $routed['from'] }}, but the form's own page code says #{{ $routed['to'] }}, so it was filed against #{{ $routed['to'] }} and nothing was read against #{{ $routed['from'] }}.
            <a href="{{ route('corex.rental-inspections.show', $routed['to']) }}" class="underline" data-qa="routed-open-target">Open inspection #{{ $routed['to'] }}</a>
            &middot; <a href="{{ route('corex.rental-inspections.show', $routed['from']) }}" class="underline" data-qa="routed-open-source">Back to #{{ $routed['from'] }}</a>
        </div>
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

    {{-- AT-463 / §62 - what the reader made of EACH page, in plain words: a page that could not be read says why. --}}
    @if(! empty($scan->page_results))
        <div class="rounded-md p-3 text-xs space-y-1" style="background: var(--surface); border: 1px solid var(--border);" data-qa="scan-page-results">
            <p class="font-semibold" style="color: var(--text-secondary);">What was read from each page</p>
            @foreach($scan->page_results as $pr)
                @php $ok = ($pr['status'] ?? '') === 'read'; @endphp
                <div class="flex items-start gap-2" data-qa="scan-page-{{ $pr['page'] }}-{{ $ok ? 'read' : 'not-read' }}">
                    <span class="font-semibold" style="color: {{ $ok ? 'var(--ds-green)' : 'var(--ds-crimson)' }}; min-width: 5.5rem;">Page {{ $pr['page'] }}: {{ $ok ? 'read' : 'NOT read' }}</span>
                    <span style="color: var(--text-secondary);">
                        @if($ok)
                            Form page {{ $pr['form_page'] }}@if(!empty($pr['anchored_by'])), placed by its {{ $pr['anchored_by'] === 'qr' ? 'QR code' : 'corner marks' }}@endif
                            @if(isset($pr['rows'])) &middot; {{ $pr['rows'] }} rows, {{ $pr['proposed'] ?? 0 }} proposed{{ ($pr['flagged'] ?? 0) ? ', ' . $pr['flagged'] . ' to check' : '' }}@endif
                            @if(isset($pr['printed_scale']) && abs($pr['printed_scale'] - 1) > 0.025) &middot; printed at about {{ round($pr['printed_scale'] * 100) }}% of the form size (probably Letter paper) - read anyway @endif
                        @else
                            {{ $pr['reason'] ?? 'This page could not be read.' }}
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif

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
        <form method="POST" action="{{ route('corex.rental-inspections.scans.apply', [$inspection, $scan]) }}" class="space-y-3"
              id="scan-review"
              data-csrf="{{ csrf_token() }}"
              data-observations-url="{{ route('corex.rental-inspections.observations.store', $inspection) }}"
              data-photos-url="{{ route('corex.rental-inspections.photos.store', $inspection) }}"
              data-source="{{ $source }}"
              data-readonly="{{ $readOnly ? '1' : '0' }}">
            @csrf
            <input type="hidden" name="apply_key" value="{{ $applyKey }}">
            @php
                $groupedMarks = $scan->marks->groupBy(fn ($m) => $m->item?->room?->label ?? 'General');
                $stateLabel = collect($conditionStates)->pluck('label', 'key');
                $isLow = fn ($m) => ! $m->ambiguous && $m->detected_confidence !== null && (float) $m->detected_confidence < $lowConfidenceBelow;
                $open = $scan->marks->whereNull('applied_observation_id');
                $needAttention = $open->filter(fn ($m) => $m->ambiguous || $isLow($m))->count();
            @endphp
            @if($readOnly)
                <span class="ds-badge" data-qa="scan-read-only">Read-only</span>
            @elseif($open->isNotEmpty())
                <p class="text-xs" style="color: var(--text-secondary);" data-qa="scan-summary">
                    {{ $open->count() }} {{ $open->count() === 1 ? 'row' : 'rows' }} proposed from the ticked columns.
                    @if($needAttention > 0) <strong>{{ $needAttention }} {{ $needAttention === 1 ? 'needs' : 'need' }} your attention</strong> (left blank below).@endif
                    Only conditions wait for "Apply proposed marks".
                </p>
            @endif
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                @forelse($groupedMarks as $roomLabel => $marksInRoom)
                    <h2 class="text-xs font-bold uppercase tracking-wide pt-2" style="color: var(--text-secondary);">{{ $roomLabel }}</h2>
                    <div class="space-y-2">
                        @foreach($marksInRoom as $mark)
                            @php
                                $itemId = (int) $mark->rental_inspection_item_id;
                                $recordedCondition = $onScreen[$itemId] ?? '';
                                $recordedNote = $onScreenNotes[$itemId] ?? null;
                                $applied = $mark->applied_observation_id !== null;
                                // The condition the select starts on: what an applied row was confirmed as, else the reader's clear tick (never a guess).
                                $startKey = $applied
                                    ? ($recordedCondition !== '' ? $recordedCondition : (string) $mark->confirmed_condition_key)
                                    : ((! $mark->ambiguous && ! $isLow($mark)) ? (string) $mark->detected_condition_key : '');
                                // The note box starts on the AI-read handwriting on a row still to apply, else on the note already recorded.
                                $detectedNote = (! $applied && filled($mark->detected_note)) ? $mark->detected_note : null;
                                $startNote = $detectedNote ?? $recordedNote ?? '';
                                $rowPhotos = $photos[$itemId] ?? [];
                            @endphp
                            <div class="text-sm py-1 space-y-2" style="border-bottom: 1px solid var(--border);"
                                 data-scan-row data-mark-id="{{ $mark->id }}" data-item-id="{{ $itemId }}"
                                 data-recorded-condition="{{ $recordedCondition }}" data-recorded-note="{{ $recordedNote }}">
                                <div class="flex items-center justify-between gap-3">
                                    <span>{{ $mark->item?->label ?? 'Unknown item' }}
                                        <span class="text-xs" style="color: var(--text-muted);">p.{{ $mark->page_number }} &middot; on screen: {{ $recordedCondition !== '' ? ($stateLabel[$recordedCondition] ?? $recordedCondition) : 'not recorded' }}@if($recordedNote) &middot; &ldquo;{{ $recordedNote }}&rdquo;@endif</span>
                                    </span>
                                    <div class="flex items-center gap-2">
                                        @if($applied)
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
                                        @endif
                                        @unless($readOnly)
                                            <select name="condition_keys[{{ $mark->id }}]" class="text-xs rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-scan-condition>
                                                <option value="">— Confirm —</option>
                                                @foreach($conditionStates as $state)
                                                    <option value="{{ $state['key'] }}" @selected($startKey === $state['key'])>{{ $state['label'] }}</option>
                                                @endforeach
                                            </select>
                                        @endunless
                                    </div>
                                </div>
                                @if($readOnly)
                                    @if($rowPhotos)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($rowPhotos as $url)<img src="{{ $url }}" alt="" loading="lazy" class="rounded-md" style="width: 56px; height: 56px; object-fit: cover; border: 1px solid var(--border);">@endforeach
                                        </div>
                                    @endif
                                @else
                                    <div class="space-y-1" data-scan-controls @if($startKey === '') style="display: none;" @endif>
                                        <textarea name="notes[{{ $mark->id }}]" rows="2" maxlength="4000" class="text-xs rounded-md px-2 py-1 w-full" style="border: 1px solid var(--border);" data-scan-note data-qa="row-note">{{ $startNote }}</textarea>
                                        <div class="flex flex-wrap items-center gap-1">
                                            <span class="flex flex-wrap gap-1" data-scan-photos>
                                                @foreach($rowPhotos as $url)<img src="{{ $url }}" alt="" loading="lazy" class="rounded-md" style="width: 56px; height: 56px; object-fit: cover; border: 1px solid var(--border);">@endforeach
                                            </span>
                                            <button type="button" class="corex-btn-outline text-xs" data-scan-add-photo data-qa="row-add-photo">Add photo</button>
                                            <input type="file" accept="image/*" multiple class="hidden" data-scan-photo-input>
                                            @if($detectedNote && $mark->detected_note_confidence !== null)
                                                <span class="text-xs" style="color: var(--text-muted);" data-qa="row-note-read">Note read ({{ (int) round($mark->detected_note_confidence * 100) }}%)</span>
                                            @endif
                                            <span class="text-xs" style="color: var(--text-muted);" data-scan-status aria-live="polite"></span>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="text-xs" style="color: var(--text-muted);">Nothing on this scan matched an item on the current form.</p>
                @endforelse
            </div>

            @if(! $readOnly && $scan->marks->isNotEmpty())
                <button type="submit" class="corex-btn-outline text-xs" data-qa="apply-proposed">{{ $open->isNotEmpty() ? 'Apply proposed marks' : 'Save changes' }}</button>
            @endif
        </form>
        @unless($readOnly)
            <script src="{{ asset_v('js/corex-photo-batch-uploader.js') }}"></script>
            <script src="{{ asset_v('js/corex-scan-review.js') }}"></script>
        @endunless
    @endif
</div>
@endsection
