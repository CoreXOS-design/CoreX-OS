{{--
    AT-466 - the Notices tab bar: Breaches | Notices received | Notices sent | Templates. Shown at the top of each of those screens, so the four
    read as one "Notices" screen under Rentals > Leases. A user sees only the tabs they may open (NoticesTabs). Receives $active (the tab key).
--}}
@php $ntTabs = \App\Support\Rentals\NoticesTabs::forUser(auth()->user()); @endphp
@if(count($ntTabs) > 0)
    <nav class="flex flex-wrap gap-1" style="border-bottom: 1px solid var(--border);" data-notices-tabs aria-label="Notices">
        @foreach($ntTabs as $ntKey => $nt)
            <a href="{{ $nt['url'] }}" data-notices-tab="{{ $ntKey }}" @if($ntKey === ($active ?? null)) aria-current="page" @endif
               class="px-4 py-2 text-sm font-medium"
               style="margin-bottom: -1px; text-decoration: none; border-bottom: 2px solid {{ $ntKey === ($active ?? null) ? 'var(--accent, #00b4d8)' : 'transparent' }}; color: {{ $ntKey === ($active ?? null) ? 'var(--text)' : 'var(--text-muted)' }};">{{ $nt['label'] }}</a>
        @endforeach
    </nav>
@endif
