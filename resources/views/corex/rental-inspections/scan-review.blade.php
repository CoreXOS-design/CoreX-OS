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

    §13.15 (Johan, 20 Oct 2026, scan #25): every row's tick is in one of THREE states - read with confidence / picked up but unsure (agent
    must confirm) / not picked up at all (agent must set it) - with one count line on top; a detected note is ALWAYS visible (only saving waits
    for a condition), marked "Read from scan" until the agent edits it; a note area with ink the AI could not read says so at the note box.

    §13.16 (Johan, 20 Oct 2026): the response and the next action come first and stay visible - ONE sticky header carries the title, the process
    actions (Open original, Back, Apply), the flash/server response, the live count line and what still blocks Apply. Every blocker and every
    error is a link that scrolls to its row and focuses its control. Apply is disabled, with the reason shown, while a blocker remains. Nothing
    sits at the bottom of the page.
--}}

@section('corex-content')
@include('corex.rentals.partials._page-styles')
@php
    $stateLabel = collect($conditionStates)->pluck('label', 'key');
    $pct = fn ($v) => (int) round($v * 100);
    // One place decides what a row starts as, so the header's blockers and the row itself can never disagree.
    $rowView = function ($mark) use ($onScreen, $onScreenNotes, $unsureBelow, $noteMinConfidence, $stateLabel) {
        $itemId = (int) $mark->rental_inspection_item_id;
        $recordedCondition = $onScreen[$itemId] ?? '';
        $recordedNote = $onScreenNotes[$itemId] ?? null;
        $applied = $mark->applied_observation_id !== null;
        $tick = $mark->tickState($unsureBelow);
        $startKey = $applied
            ? ($recordedCondition !== '' ? $recordedCondition : (string) $mark->confirmed_condition_key)
            : ($tick === 'read' ? (string) $mark->detected_condition_key : '');
        $usableNote = (! $applied && $recordedNote === null) ? $mark->usableDetectedNote($noteMinConfidence) : null;
        $noteUnreadable = (! $applied && $recordedNote === null && $usableNote === null) && $mark->noteUnreadable($noteMinConfidence);

        return [
            'itemId' => $itemId, 'recordedCondition' => $recordedCondition, 'recordedNote' => $recordedNote, 'applied' => $applied, 'tick' => $tick,
            'startKey' => $startKey, 'usableNote' => $usableNote, 'noteUnreadable' => $noteUnreadable, 'startNote' => $recordedNote ?? $usableNote ?? '',
        ];
    };
    $open = $scan->marks->whereNull('applied_observation_id');
    $tickCounts = ['read' => 0, 'unsure' => 0, 'missed' => 0];
    foreach ($open as $m) { $tickCounts[$m->tickState($unsureBelow)]++; }
    // What blocks Apply right now: an open row with no condition; a condition that needs a note with none.
    $blockNoCondition = 0;
    $blockNeedsNote = [];   // condition key => rows
    foreach ($scan->marks as $m) {
        $v = $rowView($m);
        if (! $v['applied'] && $v['startKey'] === '') { $blockNoCondition++; }
        elseif ($v['startKey'] !== '' && in_array($v['startKey'], $notesRequiredKeys, true) && trim($v['startNote']) === '') { $blockNeedsNote[$v['startKey']] = ($blockNeedsNote[$v['startKey']] ?? 0) + 1; }
    }
    $hasBlockers = $blockNoCondition > 0 || $blockNeedsNote !== [];
    $failed = in_array($scan->status, ['failed', 'version_mismatch'], true);
    $refused = session('scan_refused', []);
@endphp
{{-- The sticky scan header sits flush at the top and runs edge to edge like the standard page header (same negative
     margins that cancel <main>'s padding, same inner gutter); the review content below keeps its narrow reading width. --}}
<style>
  /* top: sticky positions against the inside of <main>'s padding, so it needs the same negative offset to stay flush */
  .scan-page > #scan-header{margin:-1rem -1rem 0;padding:.875rem 1.5rem !important;top:-1rem !important}
  @media (min-width:1024px){.scan-page > #scan-header{margin:-1.5rem -1.5rem 0;top:-1.5rem !important}}
  .scan-page > :not(#scan-header){max-width:48rem}
</style>
<div class="rx-page scan-page w-full space-y-4">
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

    <div id="scan-header" class="space-y-2" style="position: sticky; top: 0; z-index: 30; background: var(--bg); padding: 0.5rem 0; border-bottom: 1px solid var(--border);" data-qa="scan-header" data-tour="rmnt-scanr-header">
        @if(session('success'))
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);" data-qa="scan-success">{{ session('success') }}</div>
        @endif
        @if($refused || $errors->any())
            <div class="text-xs p-2 rounded space-y-1" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);" data-qa="scan-errors">
                @forelse($refused as $r)
                    <p><a href="#scan-row-{{ $r['mark'] }}" class="underline font-semibold" data-scan-goto="{{ $r['mark'] }}" data-focus="{{ $r['focus'] }}">{{ $r['text'] }}</a></p>
                @empty
                    @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                @endforelse
            </div>
        @endif
        <div class="flex items-center justify-between gap-2 flex-wrap">
            <div data-tour="rmnt-scanr-title">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Review scan — {{ $scan->original_filename }}</h1>
                <span class="text-xs" style="color: var(--text-muted);">
                    {{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}
                    · uploaded by {{ $scan->uploadedBy?->name }} on {{ $scan->created_at?->format('Y-m-d H:i') }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('corex.rental-inspections.scans.download', [$inspection, $scan, 'view' => 1]) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs" data-qa="open-original" data-tour="rmnt-scanr-original">Open original</a>
                <a href="{{ route('corex.rental-inspections.show', $inspection) }}" class="corex-btn-outline text-xs">&larr; Back to inspection</a>
                @if(! $failed && ! $readOnly && $scan->marks->isNotEmpty())
                    <button type="submit" form="scan-review" class="corex-btn-outline text-xs" data-qa="apply-proposed" data-tour="rmnt-scanr-apply" data-scan-apply @disabled($hasBlockers) @if($hasBlockers) style="opacity: 0.5; cursor: not-allowed;" @endif>{{ $open->isNotEmpty() ? 'Apply proposed marks' : 'Save changes' }}</button>
                @endif
            </div>
        </div>
        @if($readOnly && ! $failed)
            <span class="ds-badge" data-qa="scan-read-only">Read-only</span>
        @elseif(! $failed && ! $readOnly && $scan->marks->isNotEmpty())
            <p class="text-xs flex flex-wrap items-center gap-x-3 gap-y-1" data-qa="scan-status" data-tour="rmnt-scanr-status">
                <span class="font-semibold" style="color: var(--text-secondary);" data-qa="scan-summary" data-scan-count>{{ $tickCounts['read'] }} read, {{ $tickCounts['unsure'] }} to check, {{ $tickCounts['missed'] }} not picked up</span>
                <span class="flex flex-wrap items-center gap-x-3" data-scan-blockers data-qa="scan-blockers">
                    @if($blockNoCondition > 0)
                        <a href="#" class="underline font-semibold" style="color: var(--ds-crimson);" data-scan-next="condition">{{ $blockNoCondition }} {{ $blockNoCondition === 1 ? 'row needs' : 'rows need' }} a condition</a>
                    @endif
                    @foreach($blockNeedsNote as $key => $n)
                        <a href="#" class="underline font-semibold" style="color: var(--ds-crimson);" data-scan-next="note:{{ $key }}">{{ $n }} {{ $stateLabel[$key] ?? $key }} {{ $n === 1 ? 'row needs' : 'rows need' }} a note</a>
                    @endforeach
                </span>
            </p>
        @endif
    </div>

    {{-- AT-463 / §62 - what the reader made of EACH page, in plain words: a page that could not be read says why. --}}
    @if(! empty($scan->page_results))
        <div class="rounded-md p-3 text-xs space-y-1" style="background: var(--surface); border: 1px solid var(--border);" data-qa="scan-page-results" data-tour="rmnt-scanr-pages">
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

    @if($failed)
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
              data-notes-required="{{ implode(',', $notesRequiredKeys) }}"
              data-condition-labels="{{ json_encode($stateLabel) }}"
              data-readonly="{{ $readOnly ? '1' : '0' }}">
            @csrf
            <input type="hidden" name="apply_key" value="{{ $applyKey }}">
            @php $groupedMarks = $scan->marks->groupBy(fn ($m) => $m->item?->room?->label ?? 'General'); @endphp
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                @forelse($groupedMarks as $roomLabel => $marksInRoom)
                    <h2 class="text-xs font-bold uppercase tracking-wide pt-2" style="color: var(--text-secondary);" data-tour="rmnt-scanr-room">{{ $roomLabel }}</h2>
                    <div class="space-y-2">
                        @foreach($marksInRoom as $mark)
                            @php
                                extract($rowView($mark));
                                $rowPhotos = $photos[$itemId] ?? [];
                                $detectedLabel = $mark->detected_condition_key ? ($stateLabel[$mark->detected_condition_key] ?? ucfirst(str_replace('_', ' ', $mark->detected_condition_key))) : null;
                            @endphp
                            <div class="text-sm py-1 space-y-2" style="border-bottom: 1px solid var(--border);"
                                 id="scan-row-{{ $mark->id }}" data-scan-row data-tour="rmnt-scanr-row" data-mark-id="{{ $mark->id }}" data-item-id="{{ $itemId }}" data-applied="{{ $applied ? '1' : '0' }}" data-tick="{{ $tick }}"
                                 data-recorded-condition="{{ $recordedCondition }}" data-recorded-note="{{ $recordedNote }}">
                                <div class="flex items-center justify-between gap-3">
                                    <span>{{ $mark->item?->label ?? 'Unknown item' }}
                                        <span class="text-xs" style="color: var(--text-muted);">p.{{ $mark->page_number }} &middot; on screen: {{ $recordedCondition !== '' ? ($stateLabel[$recordedCondition] ?? $recordedCondition) : 'not recorded' }}@if($recordedNote) &middot; &ldquo;{{ $recordedNote }}&rdquo;@endif</span>
                                    </span>
                                    <div class="flex items-center gap-2" data-tour="rmnt-scanr-state">
                                        @if($applied)
                                            <span class="ds-badge ds-badge-success">Applied — {{ ucfirst(str_replace('_', ' ', $mark->confirmed_condition_key)) }}</span>
                                            <span class="text-xs" style="color: var(--text-muted);">by {{ $mark->confirmedBy?->name }}</span>
                                        @else
                                            @if($tick === 'read')
                                                <span class="ds-badge ds-badge-success" data-qa="row-state-read">Read: {{ $detectedLabel }} ({{ $pct($mark->detected_confidence) }}%)</span>
                                            @elseif($tick === 'unsure')
                                                @if($mark->ambiguous)
                                                    <span class="ds-badge ds-badge-warning" data-qa="row-state-unsure"><span data-qa="row-unclear">Check: {{ ($mark->tick_boxes ?? 0) >= 2 ? 'two boxes ticked' : 'unclear' }}</span></span>
                                                @else
                                                    <span class="ds-badge ds-badge-warning" data-qa="row-state-unsure"><span data-qa="row-low-confidence">Check: read {{ $detectedLabel }} ({{ $pct($mark->detected_confidence) }}%)</span></span>
                                                @endif
                                            @else
                                                <span class="ds-badge ds-badge-danger" data-qa="row-state-missed">Not picked up</span>
                                            @endif
                                        @endif
                                        @unless($readOnly)
                                            <select name="condition_keys[{{ $mark->id }}]" class="text-xs rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-scan-condition data-tour="rmnt-scanr-condition">
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
                                    <div class="space-y-1" data-scan-note-area data-tour="rmnt-scanr-note">
                                        @if($usableNote !== null)
                                            <span class="ds-badge ds-badge-info" data-scan-read-badge data-qa="row-note-read">Read from scan ({{ $pct($mark->detected_note_confidence) }}%)</span>
                                        @elseif($noteUnreadable)
                                            <p class="text-xs font-semibold" style="color: var(--ds-amber);" data-scan-unreadable data-qa="row-note-unreadable">Couldn't read the handwritten note. Type it in.</p>
                                        @endif
                                        <textarea name="notes[{{ $mark->id }}]" rows="2" maxlength="4000" class="text-xs rounded-md px-2 py-1 w-full" style="border: 1px solid var(--border);" data-scan-note data-qa="row-note">{{ $startNote }}</textarea>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1" data-scan-controls @if($startKey === '') style="display: none;" @endif>
                                        <span class="flex flex-wrap gap-1" data-scan-photos>
                                            @foreach($rowPhotos as $url)<img src="{{ $url }}" alt="" loading="lazy" class="rounded-md" style="width: 56px; height: 56px; object-fit: cover; border: 1px solid var(--border);">@endforeach
                                        </span>
                                        <button type="button" class="corex-btn-outline text-xs" data-scan-add-photo data-qa="row-add-photo">Add photo</button>
                                        <input type="file" accept="image/*" multiple class="hidden" data-scan-photo-input>
                                        <span class="text-xs" style="color: var(--text-muted);" data-scan-status aria-live="polite"></span>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="text-xs" style="color: var(--text-muted);">Nothing on this scan matched an item on the current form.</p>
                @endforelse
            </div>

        </form>
        @unless($readOnly)
            <script src="{{ asset_v('js/corex-photo-batch-uploader.js') }}"></script>
            <script src="{{ asset_v('js/corex-scan-review.js') }}"></script>
        @endunless
    @endif
</div>
@endsection
