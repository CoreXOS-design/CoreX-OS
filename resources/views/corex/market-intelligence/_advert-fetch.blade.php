{{-- MIC Adverts card (mic-work-tab.md 13.16 / 13.16b): the level label + the one-click "Get full details" button, on the agent / reference line. ONE partial; the card includes it with a
     single @include. Reads $adv['details'] (MicWorkList::detailsOf): level search | full, read_on, read_by, stale, target. The view only renders - the rule for "changed since" lives in
     MicAdvertFacts::changedSinceRead. The page script (public/js/mic-advert-fetch.js) does the clicking; this markup carries everything it needs in data- attributes. --}}
@php
    $d = $adv['details'] ?? ['level' => 'search', 'target' => null];
    $full = ($d['level'] ?? 'search') === 'full';
    $stale = $full && !empty($d['stale']);
    $t = $d['target'] ?? null;
    $label = $full ? ($stale ? 'Details from ' . $d['read_on'] . ' · changed since' : 'Full details · ' . $d['read_on'] . (!empty($d['read_by']) ? ' · ' . $d['read_by'] : '')) : 'Search view';
    $labelColour = $stale ? 'var(--ds-amber, #b45309)' : ($full ? 'var(--ds-green, #059669)' : 'var(--text-secondary, #9ca3af)');
    $btn = 'background:none; border:none; padding:0; margin:0; font:inherit; cursor:pointer; text-decoration:underline; text-decoration-color: var(--brand-icon); color: var(--text-primary);';
@endphp
<span data-advert-fetch-wrap data-qa="advert-level" data-level="{{ $stale ? 'changed' : ($full ? 'full' : 'search') }}" data-listing="{{ $t['id'] ?? '' }}" data-file="{{ $adv['tp_id'] ?? '' }}"
      data-card-listing="{{ $listing->id }}" data-ext-help="{{ route('agent.portal') }}#tools" style="white-space:nowrap; flex-shrink:0;">
    @if($full)
        <button type="button" data-advert-open data-qa="level-label" title="Open the full ad" style="{{ $btn }} color: {{ $labelColour }}; font-weight:600; text-decoration:none;">{{ $label }}</button>
        @if($stale && $t)<span style="color: var(--text-secondary);"> · </span><button type="button" data-advert-fetch data-portal="{{ $t['portal'] }}" data-ref="{{ $t['ref'] }}" data-url="{{ $t['url'] }}" data-qa="refresh-details" style="{{ $btn }}">Refresh details</button>@endif
    @else
        <span data-qa="level-label" title="Only what the search results show. Press Get full details to read the whole advert." style="color: {{ $labelColour }};">{{ $label }}</span>
        @if($t)<span style="color: var(--text-secondary);"> · </span><button type="button" data-advert-fetch data-portal="{{ $t['portal'] }}" data-ref="{{ $t['ref'] }}" data-url="{{ $t['url'] }}" data-qa="get-full-details" style="{{ $btn }}">Get full details</button>@endif
    @endif
    <span data-advert-status aria-live="polite" style="display:none;"></span>
</span>
