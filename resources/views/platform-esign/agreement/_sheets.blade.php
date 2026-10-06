{{-- The paginated agreement. $pages (from AgreementPdf::pages), $total, $versionLabel; $interactive (initial button); $staticIni = [page => 'AB · CD'] for read-only views. --}}
@php $company = \App\Services\PlatformEsign\Agreement\AgreementCompany::for($doc ?? null); $letterhead = $company->letterhead(); @endphp
@foreach($pages as $p)
    <section class="sheet" id="page-{{ $p['no'] }}" data-page="{{ $p['no'] }}" aria-label="Page {{ $p['no'] }} of {{ $total }}">
        <div class="sheet-head">
            <img src="{{ $company->logoUrl() }}" alt="{{ $company->brand() }}" class="sheet-logo">
            <div class="co"><b>{{ $letterhead['name'] }}</b><br>{{ $letterhead['address'] }}<br>{{ $letterhead['contact'] }}</div>
        </div>
        <div class="sheet-body agr">{!! implode("\n", $p['blocks']) !!}</div>
        <div class="sheet-foot">
            <span>CoreX OS Subscription Agreement &middot; {{ $versionLabel }} &middot; Page {{ $p['no'] }} of {{ $total }}</span>
            <span class="ini-box">
                @if(!empty($interactive))<button type="button" class="btn sm" data-initial-page="{{ $p['no'] }}">Initial this page</button>@endif
                @isset($staticIni)<span class="ini-chip {{ !empty($staticIni[$p['no']]) ? 'done' : '' }}">{{ $staticIni[$p['no']] ?? '—' }}</span>
                @else<span class="ini-chip">—</span>@endisset
            </span>
        </div>
    </section>
@endforeach
