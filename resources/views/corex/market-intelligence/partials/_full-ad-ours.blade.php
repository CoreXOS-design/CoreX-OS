{{-- MIC "Show full ad" (mic-work-tab.md 13.16b item 5), RIGHT column below the card's own intelligence block: our sections in the popover layout (_advert-pop, flat = no inner scroll box).
     Input: $r = MicWorkList::forFiles() record. --}}
@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $fakeListing = (object) ['id' => (int) (($r['adverts'][0]['id'] ?? 0))];
    $ph = 'font-weight:700; color: var(--text-primary); font-size:.75rem; padding: 2px 8px 4px;';
    $dim = 'color: var(--text-secondary);';
    $row = fn (int $i) => 'padding: 7px 8px;' . ($i > 0 ? ' border-top: 1px solid var(--border);' : '');
    $hist = [];
    foreach ($r['claims'] ?? [] as $c) {
        $hist[] = ['Claim' . (($c['count'] ?? 1) > 1 ? ' ×' . $c['count'] : '') . ' · ' . ($c['who'] ?? 'agent'), ($c['at'] ?? '') . ' · ' . str_replace('_', ' ', $c['status'] ?? '') . (!empty($c['released']) ? ' · released ' . $c['released'] : '') . (!empty($c['reason']) ? ' (' . $c['reason'] . ')' : '')];
    }
    if (!empty($r['work_status'])) { $hist[] = ['Status · ' . ucfirst(str_replace('_', ' ', $r['work_status'])), $r['work_reason'] ?? '']; }
    if ($mandate = \App\Services\Prospecting\MicWork\SoleMandateLabel::for($r)) { $hist[] = [$mandate['text'], $mandate['title'] ?? '']; }
    foreach ($r['mandates'] ?? [] as $mm) {
        $hist[] = ['Mandate · ' . ($mm['type'] ? ucfirst($mm['type']) : '-') . ' · ' . ucfirst(str_replace('_', ' ', (string) $mm['status'])), trim(($mm['expiry'] ? 'expires ' . $mm['expiry'] : '') . ($mm['price'] ? ' · ' . $money($mm['price']) : ''), ' ·')];
    }
    foreach ($r['file_notes'] ?? [] as $n) { $hist[] = ['Note · ' . ($n['date'] ?? ''), $n['text'] ?? '']; }
    $sec = 'border-top: 1px solid var(--border); margin-top: 6px; padding-top: 6px;';
@endphp
<div data-qa="full-ad-ours">
    @foreach(['adverts', 'price', 'alerts', 'buyers', 'market'] as $kind)
        <div data-qa="ours-{{ $kind }}" style="{{ $sec }}">@include('corex.market-intelligence._advert-pop', ['kind' => $kind, 'adv' => $r, 'listing' => $fakeListing, 'money' => $money, 'flat' => true])</div>
    @endforeach
    <div data-qa="ours-history" style="{{ $sec }}">
        <div style="{{ $ph }}">Our history &amp; comments</div>
        @forelse($hist as $i => [$h1, $h2])
            <div data-qa="pop-entry" style="{{ $row($i) }} font-size:.75rem;"><div style="font-weight:700; color: var(--text-primary);">{{ $h1 }}</div>@if($h2 !== '')<div style="{{ $dim }}">{{ $h2 }}</div>@endif</div>
        @empty
            <div data-qa="pop-entry" style="{{ $row(0) }} font-size:.75rem; {{ $dim }}">Nothing yet.</div>
        @endforelse
    </div>
    <div style="{{ $sec }} padding: 8px;">
        @if(!empty($r['tp_id']))<a href="{{ route('market-intelligence.opportunities.show', ['tp' => $r['tp_id']]) }}" style="font-size:.8125rem; font-weight:700; color: var(--text-primary); text-decoration: underline; text-decoration-color: var(--brand-icon);">Open the property page →</a>@else<span style="font-size:.75rem; {{ $dim }}">Not on a property file yet.</span>@endif
    </div>
</div>
