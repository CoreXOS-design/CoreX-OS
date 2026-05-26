@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6 flex flex-col eval-app" style="height:calc(100vh - 56px);" x-data="evalApp()" x-init="init()">

    {{-- TOP BAR --}}
    <div class="flex-shrink-0 flex items-center gap-2 px-3 py-2 eval-topbar">

        {{-- Mode toggle --}}
        <div class="eval-mode-toggle">
            <button @click="mode='search'" :class="mode==='search' ? 'is-active' : ''" class="eval-mode-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="eval-icon-xs"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                Search
            </button>
            <button @click="switchToMap()" :class="mode==='map' ? 'is-active' : ''" class="eval-mode-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="eval-icon-xs"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z"/></svg>
                Prospecting
            </button>
        </div>

        <div class="eval-divider"></div>

        {{-- Search bar --}}
        <div class="relative eval-search-wrap">
            <svg class="eval-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            <input type="text" x-model="searchQuery"
                @input.debounce.350ms="runSearch()"
                @keydown.escape="searchQuery='';results=[]"
                placeholder="Search by address, ERF number, suburb or owner..."
                class="eval-search-input">
            <button x-show="searchQuery" @click="searchQuery='';results=[]" class="eval-search-clear" type="button">×</button>
        </div>

        {{-- Search type pills --}}
        <div x-show="mode==='search'" class="flex items-center gap-1 flex-shrink-0">
            @foreach(['Full Title','Person','ERF','Suburb','Street','Transfer'] as $t)
            <button @click="searchType='{{ strtolower($t) }}';runSearch()"
                :class="searchType==='{{ strtolower($t) }}' ? 'is-active' : ''"
                class="eval-pill">{{ $t }}</button>
            @endforeach
        </div>

        {{-- Map tab pills --}}
        <div x-show="mode==='map'" class="flex items-center gap-1 flex-shrink-0">
            @foreach(['Suburb / Province','Sales & Transfers','Prospecting','Documents'] as $t)
            <button @click="mapTab='{{ $t }}'"
                :class="mapTab==='{{ $t }}' ? 'is-active' : ''"
                class="eval-pill">{{ $t }}</button>
            @endforeach
        </div>
    </div>

    {{-- MAIN BODY --}}
    <div class="flex flex-1 overflow-hidden eval-body">

        {{-- ── SEARCH MODE ── --}}
        <div x-show="mode==='search'" class="flex flex-col flex-1 overflow-hidden">

            {{-- Empty state --}}
            <div x-show="!searchQuery" class="flex flex-col items-center justify-center flex-1 gap-4">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="eval-empty-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                <div class="text-center">
                    <div class="eval-empty-title">Search for a property</div>
                    <div class="eval-empty-sub">Enter an address, ERF number, suburb or owner name</div>
                </div>
                <div class="flex flex-wrap gap-2 justify-center eval-suggest-wrap">
                    @foreach(['198 John Dory Drive','Newlands East','ERF 1438','Palm Beach','Ramsgate','Shelly Beach'] as $q)
                    <button @click="searchQuery='{{ $q }}';runSearch()" class="eval-suggest-pill">{{ $q }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Loading --}}
            <div x-show="searchQuery && loading" class="flex items-center justify-center flex-1 gap-2">
                <div class="eval-spinner"></div>
                <span class="eval-loading-text">Searching...</span>
            </div>

            {{-- Results --}}
            <div x-show="searchQuery && !loading && results.length > 0" class="flex-1 overflow-y-auto p-3">
                <div class="flex items-center justify-between mb-2 px-1">
                    <span class="eval-result-meta" x-text="results.length + ' properties found'"></span>
                    <span class="eval-result-meta">KZN South Coast</span>
                </div>
                <template x-for="(r,i) in results" :key="i">
                    <div @click="selectedProperty=r"
                        :class="selectedProperty && selectedProperty.id===r.id ? 'is-selected' : ''"
                        class="eval-result-card">
                        <div class="eval-result-head">
                            <div class="eval-result-info">
                                <div class="eval-result-addr" x-text="r.address"></div>
                                <div class="eval-result-sub" x-text="r.suburb+' · ERF '+r.erf+' · '+r.size"></div>
                            </div>
                            <div class="eval-result-price">
                                <div class="eval-result-amount" x-text="r.lastSale"></div>
                                <div class="eval-result-year" x-text="r.saleYear"></div>
                            </div>
                        </div>
                        <div class="eval-result-meta-row">
                            <span class="eval-meta-light"><span class="eval-meta-strong" x-text="r.muniValue"></span> muni</span>
                            <span class="eval-meta-light" x-text="r.titleDeed"></span>
                            <span class="eval-bond-flag" :class="r.bond==='R 0' ? 'is-free' : 'is-amber'" x-text="r.bond==='R 0' ? 'Bond free' : 'Bond '+r.bond"></span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- No results --}}
            <div x-show="searchQuery && !loading && results.length===0" class="flex flex-col items-center justify-center flex-1 gap-2">
                <div class="eval-noresult-title">No properties found</div>
                <div class="eval-noresult-sub" x-text="'Searched for: '+searchQuery"></div>
            </div>
        </div>

        {{-- ── MAP / PROSPECTING MODE ── --}}
        <div x-show="mode==='map'" class="relative flex-1 eval-map-wrap">
            <div id="evaluation-map" class="w-full h-full"></div>

            {{-- Left toolbar --}}
            <div class="eval-map-toolbar" x-data="{lyr:false,ovr:false,cad:false}">

                <button title="Street View" type="button" onclick="alert('Street View — requires Google Maps API key')" class="eval-tool-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="eval-icon-sm"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
                </button>

                <div class="relative">
                    <button title="Switch Layer" type="button" @click="lyr=!lyr;ovr=false;cad=false" class="eval-tool-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="eval-icon-sm"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3"/></svg>
                    </button>
                    <div x-show="lyr" x-cloak @click.outside="lyr=false" class="eval-tool-flyout">
                        <div class="eval-tool-section-label">Base Layer</div>
                        @foreach(['Streets Light'=>'streets_light','Streets Dark'=>'streets_dark','Satellite'=>'satellite','Terrain'=>'terrain'] as $lbl=>$lkey)
                        <button type="button" onclick="setLayer('{{ $lkey }}')" id="ldot-btn-{{ $lkey }}" class="eval-tool-row">
                            <span id="ldot-{{ $lkey }}" class="eval-tool-dot {{ $lkey==='streets_light' ? 'is-active' : '' }}"></span>
                            {{ $lbl }}
                        </button>
                        @endforeach
                    </div>
                </div>

                <div class="relative">
                    <button title="Overlays" type="button" @click="ovr=!ovr;lyr=false;cad=false" class="eval-tool-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="eval-icon-sm"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                    </button>
                    <div x-show="ovr" x-cloak @click.outside="ovr=false"
                        x-data="{erf:true,str:false,srv:false,sub:true}" class="eval-tool-flyout">
                        <div class="eval-tool-section-label">Overlays</div>
                        @foreach(['erf'=>'ERF Numbers','str'=>'Street Numbers','srv'=>'Servitudes','sub'=>'Suburb Boundaries'] as $m=>$lbl)
                        <button type="button" @click="{{ $m }}=!{{ $m }}" class="eval-tool-row eval-tool-row-toggle">
                            <span>{{ $lbl }}</span>
                            <span class="eval-toggle" :class="{{ $m }} ? 'is-on' : ''">
                                <span class="eval-toggle-knob"></span>
                            </span>
                        </button>
                        @endforeach
                    </div>
                </div>

                <div class="eval-tool-sep"></div>

                <div class="relative">
                    <button title="Cadastral Info" type="button" @click="cad=!cad;lyr=false;ovr=false" class="eval-tool-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="eval-icon-sm"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
                    </button>
                    <div x-show="cad" x-cloak @click.outside="cad=false" class="eval-tool-flyout eval-tool-flyout-accent">
                        <div class="eval-tool-section-label is-accent">Cadastral</div>
                        @foreach(['ERF'=>'1438','Area'=>'363 m²','Perimeter'=>'76.4 m','Township'=>'Newlands Ext 16','Portion'=>'0'] as $k=>$v)
                        <div class="eval-cad-row">
                            <span class="eval-cad-label">{{ $k }}</span>
                            <span class="eval-cad-value">{{ $v }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>

                <button title="Measure" type="button" onclick="alert('Measure tool — next build')" class="eval-tool-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="eval-icon-sm"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.59 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z"/></svg>
                </button>

                <button title="Vicinity Radius" type="button" id="btn-radius" onclick="toggleRadius()" class="eval-tool-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="eval-icon-sm"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg>
                </button>
            </div>

            {{-- Map layer toggles --}}
            <div x-data="{s:true,l:true,v:false,t:false}" class="eval-map-legend">
                <div class="eval-tool-section-label">Map Layers</div>
                @foreach([
                    ['s','Recent Sales','#3b82f6','sales','17'],
                    ['l','Active Listings','#22c55e','listings','22'],
                    ['v','Listed vs Sold','#f97316','soldlisted','8'],
                    ['t','Transfers / Bonds','#a855f7','transfers','5'],
                ] as $row)
                <button type="button" @click="{{ $row[0] }}=!{{ $row[0] }};toggleMapLayer('{{ $row[3] }}',{{ $row[0] }})" class="eval-legend-row">
                    <span class="eval-legend-dot" :class="{{ $row[0] }} ? 'is-on' : ''" :style="{{ $row[0] }} ? 'background:{{ $row[2] }};box-shadow:0 0 5px {{ $row[2] }}66;' : ''"></span>
                    <span class="eval-legend-label">{{ $row[1] }}</span>
                    <span class="eval-legend-count">{{ $row[4] }}</span>
                </button>
                @endforeach
            </div>
        </div>

        {{-- ── RIGHT PANEL ── --}}
        <div class="flex flex-col overflow-hidden flex-shrink-0 eval-right-panel">

            {{-- No property selected --}}
            <div x-show="!selectedProperty" class="flex flex-col items-center justify-center flex-1 gap-3 eval-noselect">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="eval-noselect-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819"/></svg>
                <div class="eval-noselect-text">Select a property<br>to view its details</div>
            </div>

            {{-- Property detail --}}
            <div x-show="selectedProperty" class="flex flex-col h-full overflow-hidden">

                {{-- Title bar --}}
                <div class="eval-detail-title">
                    <div class="eval-detail-title-row">
                        <div class="min-w-0">
                            <div class="eval-detail-addr" x-text="selectedProperty?.address"></div>
                            <div class="eval-detail-sub" x-text="(selectedProperty?.suburb??'')+' · ERF '+(selectedProperty?.erf??'')+' · '+(selectedProperty?.size??'')"></div>
                        </div>
                        <div class="flex gap-1 flex-shrink-0">
                            <button type="button" class="eval-mini-btn is-primary">Vicinity</button>
                            <button type="button" class="eval-mini-btn">Docs</button>
                        </div>
                    </div>
                </div>

                {{-- KPI cards --}}
                <div class="eval-kpi-grid">
                    <div class="eval-kpi-cell">
                        <div class="eval-kpi-label">Muni Value</div>
                        <div class="eval-kpi-value" x-text="selectedProperty?.muniValue"></div>
                        <div class="eval-kpi-meta">2017</div>
                    </div>
                    <div class="eval-kpi-cell">
                        <div class="eval-kpi-label">Last Sale</div>
                        <div class="eval-kpi-value" x-text="selectedProperty?.lastSale"></div>
                        <div class="eval-kpi-meta" x-text="selectedProperty?.saleYear"></div>
                    </div>
                    <div class="eval-kpi-cell is-last">
                        <div class="eval-kpi-label">Stand</div>
                        <div class="eval-kpi-value" x-text="selectedProperty?.size"></div>
                        <div class="eval-kpi-meta eval-bond-flag" :class="selectedProperty?.bond==='R 0' ? 'is-free' : 'is-amber'" x-text="selectedProperty?.bond==='R 0' ? 'Bond free' : 'Bond '+selectedProperty?.bond"></div>
                    </div>
                </div>

                {{-- Accordion --}}
                <div class="flex-1 overflow-y-auto" x-data="{open:'property'}">
                    @php
                    $secs=[
                        ['property','Property Information',[['Province','KwaZulu-Natal'],['Suburb','Newlands East'],['Street','198 John Dory Drive'],['ERF','1438'],['Stand Size','363 m²'],['Extension','Newlands Ext 16'],['Portion','0']]],
                        ['sale','Sale Information',[['Title Deed','T4788/1997'],['Sale Date','22 Aug 1996'],['Transfer Date','21 Feb 1997'],['Purchase Amount','R 12 900'],['Bond Amount','R 0'],['Bond Holder','—']]],
                        ['muni','Municipal Valuation',[['Valuation','R 750 000'],['Valuation Date','1 Jul 2017']]],
                        ['serv','Servitudes / Endorsements',[]],
                        ['transfer','Transfer History',[['Date','22 Aug 1996'],['Price','R 12 900'],['Bond','R 0'],['Title Deed','T4788/1997']]],
                        ['street','Street Summary',[['2019 — 2 sales','Avg R 787 500'],['2020 — 0 sales','—'],['2021 — 2 sales','Avg R 622 500']]],
                        ['suburb','Suburb Summary',[['2017 — 16 sales','Med R 550 000'],['2018 — 13 sales','Med R 630 000'],['2019 — 22 sales','Med R 652 500'],['2020 — 18 sales','Med R 629 000'],['2021 — 24 sales','Med R 765 000']]],
                        ['accom','Accommodation / Zoning',[['Zoning','Residential'],['Type','Freehold'],['Sectional Title','No']]],
                    ];
                    @endphp
                    @foreach($secs as [$key,$title,$rows])
                    <div class="eval-acc-section">
                        <button type="button" @click="open=open==='{{ $key }}' ? null : '{{ $key }}'" class="eval-acc-header">
                            <span class="eval-acc-title">{{ $title }}</span>
                            <span x-text="open==='{{ $key }}' ? '▾' : '▸'"
                                :class="open==='{{ $key }}' ? 'is-open' : ''"
                                class="eval-acc-chevron"></span>
                        </button>
                        <div x-show="open==='{{ $key }}'" class="eval-acc-body">
                            @if(empty($rows))
                                <p class="eval-acc-empty">No data registered.</p>
                            @else
                                @foreach($rows as $row)
                                <div class="eval-acc-row">
                                    <span class="eval-acc-key">{{ $row[0] }}</span>
                                    <span class="eval-acc-val">{{ $row[1] }}</span>
                                </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- CTA --}}
                <div class="eval-cta-wrap">
                    <a href="{{ route('presentations.create') }}" class="corex-btn-primary w-full justify-center text-center">
                        Generate Evaluation Report
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Leaflet --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
@keyframes corex-spin { to { transform:rotate(360deg); } }
@keyframes corex-pulse {
    0%   { box-shadow:0 0 0 0 rgba(0,212,170,.5); }
    70%  { box-shadow:0 0 0 8px rgba(0,212,170,0); }
    100% { box-shadow:0 0 0 0 rgba(0,212,170,0); }
}

/* ── Evaluation page (token-driven, dark-mode aware) ── */
.eval-app { color: var(--text-primary); }
.eval-topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    position: relative; z-index: 1000;
}
.eval-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
.eval-icon-xs { width: 12px; height: 12px; flex-shrink: 0; }
.eval-icon-sm { width: 16px; height: 16px; flex-shrink: 0; }

/* Mode toggle */
.eval-mode-toggle {
    display: flex; align-items: center; flex-shrink: 0;
    background: var(--surface-2); border-radius: 6px; padding: 3px; gap: 2px;
}
.eval-mode-btn {
    display: flex; align-items: center; gap: 5px;
    padding: 5px 12px; border: none; cursor: pointer;
    border-radius: 6px; font-size: 11px; font-weight: 700;
    background: transparent; color: var(--text-secondary);
    transition: all 150ms ease;
}
.eval-mode-btn.is-active {
    background: var(--brand-icon, #00d4aa);
    color: #fff;
}

/* Search */
.eval-search-wrap { flex: 1; max-width: 520px; }
.eval-search-icon {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    width: 13px; height: 13px; color: var(--text-muted); flex-shrink: 0;
}
.eval-search-input {
    width: 100%;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 7px 32px 7px 30px;
    font-size: 12px;
    color: var(--text-primary);
    outline: none;
    transition: border-color 150ms ease, box-shadow 150ms ease;
}
.eval-search-input:focus {
    border-color: var(--brand-button);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent);
}
.eval-search-clear {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--text-muted); font-size: 16px; line-height: 1; padding: 0;
}

/* Pills */
.eval-pill {
    padding: 4px 9px; font-size: 10px; font-weight: 600;
    border: 1px solid var(--border); border-radius: 6px;
    background: var(--surface); color: var(--text-secondary);
    cursor: pointer; white-space: nowrap; transition: all 150ms ease;
}
.eval-pill:hover { border-color: var(--border-hover); }
.eval-pill.is-active {
    background: var(--brand-icon, #00d4aa);
    color: #fff;
    border-color: var(--brand-icon, #00d4aa);
}

/* Body */
.eval-body { background: var(--bg); }

/* Empty state */
.eval-empty-icon { width: 52px; height: 52px; color: var(--text-muted); }
.eval-empty-title { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
.eval-empty-sub { font-size: 12px; color: var(--text-muted); }
.eval-suggest-wrap { max-width: 460px; }
.eval-suggest-pill {
    padding: 5px 12px; font-size: 11px;
    border: 1px solid var(--border); border-radius: 9999px;
    background: var(--surface); color: var(--text-secondary); cursor: pointer;
    transition: all 150ms ease;
}
.eval-suggest-pill:hover { border-color: var(--border-hover); color: var(--text-primary); }

/* Spinner */
.eval-spinner {
    width: 16px; height: 16px;
    border: 2px solid var(--border);
    border-top-color: var(--brand-icon, #00d4aa);
    border-radius: 50%;
    animation: corex-spin 700ms linear infinite;
    flex-shrink: 0;
}
.eval-loading-text { font-size: 12px; color: var(--text-secondary); }

/* Result list */
.eval-result-meta { font-size: 11px; color: var(--text-muted); }
.eval-result-card {
    border: 1px solid var(--border);
    background: var(--surface);
    border-radius: 6px;
    padding: 10px 12px; margin-bottom: 6px;
    cursor: pointer; transition: all 150ms ease;
}
.eval-result-card:hover { border-color: var(--border-hover); }
.eval-result-card.is-selected {
    border-color: var(--brand-icon, #00d4aa);
    background: color-mix(in srgb, var(--brand-icon, #00d4aa) 8%, var(--surface));
}
.eval-result-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.eval-result-info { min-width: 0; flex: 1; }
.eval-result-addr {
    font-size: 13px; font-weight: 700; color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.eval-result-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.eval-result-price { text-align: right; flex-shrink: 0; }
.eval-result-amount { font-size: 12px; font-weight: 700; color: var(--brand-icon, #00d4aa); }
.eval-result-year { font-size: 10px; color: var(--text-muted); }
.eval-result-meta-row { display: flex; align-items: center; gap: 12px; margin-top: 6px; }
.eval-meta-light { font-size: 10px; color: var(--text-secondary); }
.eval-meta-strong { font-weight: 600; color: var(--text-primary); }

/* Bond flag — never red, uses green/amber */
.eval-bond-flag { font-size: 10px; font-weight: 700; margin-left: auto; white-space: nowrap; }
.eval-bond-flag.is-free { color: var(--ds-green, #059669); }
.eval-bond-flag.is-amber { color: var(--ds-amber, #f59e0b); }

/* No results */
.eval-noresult-title { font-size: 13px; color: var(--text-secondary); }
.eval-noresult-sub { font-size: 11px; color: var(--text-muted); }

/* Map area */
.eval-map-wrap { min-width: 0; }

/* Map toolbar */
.eval-map-toolbar {
    position: absolute; left: 10px; top: 10px; z-index: 1000;
    display: flex; flex-direction: column; gap: 3px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px; padding: 4px;
    box-shadow: 0 1px 8px rgba(0,0,0,0.08);
}
.eval-tool-btn {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    background: transparent; border: none; cursor: pointer;
    border-radius: 6px;
    color: var(--text-secondary);
    transition: all 150ms ease;
}
.eval-tool-btn:hover {
    color: var(--brand-icon, #00d4aa);
    background: color-mix(in srgb, var(--brand-icon, #00d4aa) 10%, transparent);
}
.eval-tool-flyout {
    position: absolute; left: 38px; top: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px; min-width: 165px; z-index: 1001;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.eval-tool-flyout-accent { border-color: color-mix(in srgb, var(--brand-icon, #00d4aa) 30%, transparent); padding: 10px 12px; min-width: 170px; }
.eval-tool-section-label {
    font-size: 9px; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.08em;
    padding: 2px 4px 5px; font-weight: 600;
}
.eval-tool-section-label.is-accent { color: var(--brand-icon, #00d4aa); margin-bottom: 7px; padding: 0; font-weight: 700; }
.eval-tool-row {
    display: flex; align-items: center; gap: 8px; width: 100%;
    padding: 5px 4px; background: transparent; border: none; cursor: pointer;
    border-radius: 6px; font-size: 11px; color: var(--text-primary); text-align: left;
    transition: background 150ms ease;
}
.eval-tool-row:hover { background: var(--surface-2); }
.eval-tool-row-toggle { justify-content: space-between; }
.eval-tool-dot { width: 8px; height: 8px; border-radius: 50%; border: 1.5px solid var(--text-muted); flex-shrink: 0; }
.eval-tool-dot.is-active { background: var(--brand-icon, #00d4aa); border: none; }
.eval-toggle {
    width: 26px; height: 14px; border-radius: 7px;
    display: flex; align-items: center; padding: 2px; flex-shrink: 0;
    background: var(--text-muted);
    justify-content: flex-start;
    transition: background 150ms ease, justify-content 150ms ease;
}
.eval-toggle.is-on { background: var(--brand-icon, #00d4aa); justify-content: flex-end; }
.eval-toggle-knob { width: 10px; height: 10px; border-radius: 50%; background: #fff; display: block; flex-shrink: 0; }
.eval-tool-sep { height: 1px; background: var(--border); margin: 2px 0; }

/* Cadastral rows */
.eval-cad-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
.eval-cad-label { font-size: 10px; color: var(--text-muted); }
.eval-cad-value { font-size: 10px; color: var(--text-primary); font-weight: 600; }

/* Map legend */
.eval-map-legend {
    position: absolute; bottom: 16px; left: 10px; z-index: 1000;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 8px 10px; min-width: 185px;
    box-shadow: 0 1px 8px rgba(0,0,0,0.08);
}
.eval-legend-row {
    display: flex; align-items: center; gap: 7px; width: 100%;
    padding: 3px 0; background: transparent; border: none; cursor: pointer;
}
.eval-legend-dot {
    width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0;
    background: var(--text-muted);
    transition: background 150ms ease;
}
.eval-legend-label { font-size: 11px; color: var(--text-primary); flex: 1; text-align: left; }
.eval-legend-count { font-size: 10px; color: var(--text-muted); }

/* Right panel */
.eval-right-panel {
    width: 380px;
    background: var(--surface);
    border-left: 1px solid var(--border);
}
.eval-noselect { padding: 32px; color: var(--text-muted); }
.eval-noselect-icon { width: 40px; height: 40px; color: var(--text-muted); }
.eval-noselect-text { font-size: 12px; text-align: center; color: var(--text-muted); }

/* Detail title */
.eval-detail-title { padding: 10px 14px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.eval-detail-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.eval-detail-addr {
    font-size: 13px; font-weight: 700; color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.eval-detail-sub { font-size: 10px; color: var(--text-muted); margin-top: 1px; }

.eval-mini-btn {
    padding: 3px 8px; font-size: 10px; font-weight: 600;
    border-radius: 6px;
    border: 1px solid var(--border);
    color: var(--text-secondary);
    background: var(--surface);
    cursor: pointer; transition: all 150ms ease;
}
.eval-mini-btn:hover { border-color: var(--border-hover); color: var(--text-primary); }
.eval-mini-btn.is-primary {
    border-color: color-mix(in srgb, var(--brand-icon, #00d4aa) 40%, transparent);
    color: var(--brand-icon, #00d4aa);
    background: color-mix(in srgb, var(--brand-icon, #00d4aa) 8%, transparent);
}

/* KPI grid */
.eval-kpi-grid {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.eval-kpi-cell { padding: 10px 12px; text-align: center; border-right: 1px solid var(--border); }
.eval-kpi-cell.is-last { border-right: none; }
.eval-kpi-label {
    font-size: 9px; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 3px;
    font-weight: 600;
}
.eval-kpi-value { font-size: 13px; font-weight: 700; color: var(--brand-icon, #00d4aa); }
.eval-kpi-meta { font-size: 9px; color: var(--text-muted); }

/* Accordion */
.eval-acc-section { border-bottom: 1px solid var(--border); }
.eval-acc-header {
    width: 100%; display: flex; align-items: center; justify-content: space-between;
    padding: 9px 14px; background: transparent; border: none; cursor: pointer; text-align: left;
    transition: background 150ms ease;
}
.eval-acc-header:hover { background: var(--surface-2); }
.eval-acc-title { font-size: 11px; font-weight: 600; color: var(--text-primary); }
.eval-acc-chevron { font-size: 12px; flex-shrink: 0; line-height: 1; color: var(--text-muted); }
.eval-acc-chevron.is-open { color: var(--brand-icon, #00d4aa); }
.eval-acc-body { padding: 2px 14px 10px; }
.eval-acc-empty { font-size: 11px; color: var(--text-muted); font-style: italic; margin: 0; }
.eval-acc-row {
    display: flex; justify-content: space-between; align-items: baseline;
    padding: 3px 0; border-bottom: 1px solid var(--border);
}
.eval-acc-row:last-child { border-bottom: none; }
.eval-acc-key { font-size: 10px; color: var(--text-muted); }
.eval-acc-val { font-size: 11px; font-weight: 600; color: var(--text-primary); }

/* CTA */
.eval-cta-wrap { padding: 12px; border-top: 1px solid var(--border); flex-shrink: 0; }

/* Strip Leaflet popup chrome completely */
.cx-popup .leaflet-popup-content-wrapper { background: transparent !important; box-shadow: none !important; padding: 0 !important; }
.cx-popup .leaflet-popup-tip-container { display: none !important; }
.cx-popup .leaflet-popup-content { margin: 0 !important; }
.cx-popup .leaflet-popup-close-button { display: none !important; }
</style>

<script>
function evalApp() {
    return {
        mode: 'search',
        mapTab: 'Suburb / Province',
        searchQuery: '',
        searchType: 'full title',
        loading: false,
        results: [],
        selectedProperty: null,

        init() {},

        switchToMap() {
            this.mode = 'map';
            this.$nextTick(() => {
                if (!window.evalMapReady) { initEvalMap(); window.evalMapReady = true; }
                else if (window.evalMap) { window.evalMap.invalidateSize(); }
            });
        },

        runSearch() {
            if (!this.searchQuery || this.searchQuery.length < 2) { this.results = []; return; }
            this.loading = true;
            setTimeout(() => {
                var q = this.searchQuery.toLowerCase();
                var all = [
                    {id:1,address:'198 John Dory Drive',suburb:'Newlands East',erf:'1438',size:'363 m²',lastSale:'R 12 900',saleYear:'1996',muniValue:'R 750 000',bond:'R 0',titleDeed:'T4788/1997'},
                    {id:2,address:'167 John Dory Drive',suburb:'Newlands East',erf:'1421',size:'387 m²',lastSale:'R 799 000',saleYear:'2021',muniValue:'R 820 000',bond:'R 560 000',titleDeed:'T1234/2021'},
                    {id:3,address:'32 Bream Close',suburb:'Newlands East',erf:'1502',size:'302 m²',lastSale:'R 980 000',saleYear:'2021',muniValue:'R 900 000',bond:'R 0',titleDeed:'T5566/2021'},
                    {id:4,address:'10 Shiner Place',suburb:'Newlands East',erf:'1389',size:'501 m²',lastSale:'R 385 000',saleYear:'2020',muniValue:'R 410 000',bond:'R 308 000',titleDeed:'T9988/2020'},
                    {id:5,address:'33 Chimeara Place',suburb:'Newlands East',erf:'1610',size:'382 m²',lastSale:'R 1 500 000',saleYear:'2020',muniValue:'R 1 350 000',bond:'R 0',titleDeed:'T7711/2020'},
                ];
                this.results = all.filter(r => r.address.toLowerCase().includes(q) || r.suburb.toLowerCase().includes(q) || r.erf.includes(q));
                this.loading = false;
            }, 350);
        }
    };
}

// ── MAP ──
window.evalMapReady = false;
window.evalMap = null;
window.radiusActive = false;
var radiusCircle = null;
var activeBase = null;
var salesLayer, listingsLayer, soldListedLayer, transfersLayer;

var tileSets = {
    streets_light: L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',   {attribution:'© CartoDB'}),
    streets_dark:  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',    {attribution:'© CartoDB'}),
    satellite:     L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {attribution:'© Esri'}),
    terrain:       L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',                 {attribution:'© OpenTopoMap'}),
};

function initEvalMap() {
    window.evalMap = L.map('evaluation-map', {zoomControl:true}).setView([-30.9347, 30.0930], 16);
    activeBase = tileSets.streets_light;
    activeBase.addTo(window.evalMap);

    L.polygon([[-30.9340,30.0920],[-30.9334,30.0930],[-30.9340,30.0940],[-30.9347,30.0930]],
        {color:'#00d4aa',weight:2,fillColor:'#00d4aa',fillOpacity:.15}).addTo(window.evalMap);

    L.marker([-30.9347,30.0930], {icon:L.divIcon({
        className:'',
        html:'<div style="width:14px;height:14px;background:var(--brand-icon);border-radius:50%;border:2px solid #fff;animation:corex-pulse 2s infinite;box-shadow:0 0 0 0 rgba(0,212,170,.5);"></div>',
        iconSize:[14,14],iconAnchor:[7,7]
    })}).addTo(window.evalMap);

    salesLayer      = L.layerGroup();
    listingsLayer   = L.layerGroup();
    soldListedLayer = L.layerGroup();
    transfersLayer  = L.layerGroup();

    function mkPin(label, color, shape) {
        var r = shape==='sq' ? '6px' : '50%';
        return '<div style="background:'+color+';color:#fff;font-size:9px;font-weight:700;width:20px;height:20px;border-radius:'+r+';display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.9);box-shadow:0 2px 6px '+color+'66;">'+label+'</div>';
    }

    function mkPopup(type, addr, priceHtml, detail, sub) {
        return '<div style="background:#1e293b;border-radius:6px;padding:10px 12px;min-width:175px;font-family:inherit;box-shadow:0 4px 16px rgba(0,0,0,0.25);">'
            +'<div style="font-size:9px;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">'+type+'</div>'
            +'<div style="font-size:12px;font-weight:700;color:#fff;margin-bottom:5px;">'+addr+'</div>'
            +'<div style="font-size:12px;margin-bottom:3px;">'+priceHtml+'</div>'
            +'<div style="font-size:10px;color:rgba(255,255,255,.45);">'+detail+'</div>'
            +(sub ? '<div style="font-size:10px;color:rgba(255,255,255,.3);margin-top:2px;">'+sub+'</div>' : '')
            +'</div>';
    }

    [{lat:-30.9328,lng:30.0918,l:'A',addr:'32 Bream Close',price:'R 980 000',date:'Aug 2021',sqm:'302 m²',dist:'0.3 km'},
     {lat:-30.9355,lng:30.0925,l:'B',addr:'153 Sawfish Rd',price:'R 150 000',date:'Dec 2019',sqm:'300 m²',dist:'0.3 km'},
     {lat:-30.9332,lng:30.0944,l:'C',addr:'124 John Dory Dr',price:'R 650 000',date:'Dec 2020',sqm:'516 m²',dist:'0.3 km'},
     {lat:-30.9360,lng:30.0910,l:'D',addr:'33 Chimeara Pl',price:'R 1 500 000',date:'Jun 2020',sqm:'382 m²',dist:'0.2 km'},
     {lat:-30.9322,lng:30.0933,l:'E',addr:'10 Shiner Pl',price:'R 385 000',date:'Aug 2020',sqm:'501 m²',dist:'0.2 km'},
    ].forEach(d => {
        L.marker([d.lat,d.lng],{icon:L.divIcon({className:'',html:mkPin(d.l,'#3b82f6','circle'),iconSize:[20,20],iconAnchor:[10,10]})})
         .bindPopup(mkPopup('Recent Sale',d.addr,'<span style="color:#60a5fa;font-weight:700;">'+d.price+'</span>',d.sqm+' · '+d.dist,'Sold '+d.date),{className:'cx-popup'})
         .addTo(salesLayer);
    });
    salesLayer.addTo(window.evalMap);

    [{lat:-30.9315,lng:30.0935,addr:'437 Sir Frances Drake',price:'R 1 335 000',info:'4 bed · 42 DOM'},
     {lat:-30.9370,lng:30.0945,addr:'629 Dick King Rd',price:'R 1 195 000',info:'2 bed · 18 DOM'},
     {lat:-30.9342,lng:30.0900,addr:'99 Lord Macartney Rd',price:'R 820 000',info:'3 bed · 67 DOM'},
    ].forEach(d => {
        L.marker([d.lat,d.lng],{icon:L.divIcon({className:'',html:mkPin('L','#22c55e','sq'),iconSize:[20,20],iconAnchor:[10,10]})})
         .bindPopup(mkPopup('Active Listing',d.addr,'<span style="color:#4ade80;font-weight:700;">Listed '+d.price+'</span>',d.info,''),{className:'cx-popup'})
         .addTo(listingsLayer);
    });
    listingsLayer.addTo(window.evalMap);

    [{lat:-30.9338,lng:30.0955,addr:'167 John Dory Dr',listed:'R 799 000',sold:'R 730 000',dom:'47 DOM'},
     {lat:-30.9358,lng:30.0938,addr:'30 Bream Close',listed:'R 1 050 000',sold:'R 980 000',dom:'31 DOM'},
    ].forEach(d => {
        var body = '<div style="background:#1e293b;border-radius:6px;padding:10px 12px;min-width:190px;box-shadow:0 4px 16px rgba(0,0,0,0.25);">'
            +'<div style="font-size:12px;font-weight:700;color:#fff;margin-bottom:7px;">'+d.addr+'</div>'
            +'<div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="font-size:10px;color:rgba(255,255,255,.4);">Listed</span><span style="font-size:11px;color:#fb923c;font-weight:700;">'+d.listed+'</span></div>'
            +'<div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="font-size:10px;color:rgba(255,255,255,.4);">Sold</span><span style="font-size:11px;color:#4ade80;font-weight:700;">'+d.sold+'</span></div>'
            +'<div style="font-size:10px;color:rgba(255,255,255,.3);">'+d.dom+'</div></div>';
        L.marker([d.lat,d.lng],{icon:L.divIcon({className:'',html:mkPin('S','#f97316','sq'),iconSize:[20,20],iconAnchor:[10,10]})})
         .bindPopup(body,{className:'cx-popup'}).addTo(soldListedLayer);
    });

    [{lat:-30.9325,lng:30.0912,addr:'21 Shiner Pl',price:'R 790 000',info:'Bond R 632 000 · Nedbank',date:'Oct 2020'}
    ].forEach(d => {
        L.marker([d.lat,d.lng],{icon:L.divIcon({className:'',html:mkPin('T','#a855f7','circle'),iconSize:[20,20],iconAnchor:[10,10]})})
         .bindPopup(mkPopup('Transfer',d.addr,'<span style="color:#c084fc;font-weight:700;">'+d.price+'</span>',d.info,'Transferred '+d.date),{className:'cx-popup'})
         .addTo(transfersLayer);
    });
}

window.setLayer = function(key) {
    if (!window.evalMap) return;
    if (activeBase) window.evalMap.removeLayer(activeBase);
    activeBase = tileSets[key] || tileSets.streets_light;
    activeBase.addTo(window.evalMap);
    document.querySelectorAll('[id^="ldot-"]').forEach(el => {
        if (el.id.startsWith('ldot-btn-')) return;
        el.classList.remove('is-active');
    });
    var dot = document.getElementById('ldot-'+key);
    if (dot) { dot.classList.add('is-active'); }
};

window.toggleMapLayer = function(name, on) {
    if (!window.evalMap) return;
    var layers = {sales:salesLayer,listings:listingsLayer,soldlisted:soldListedLayer,transfers:transfersLayer};
    if (!layers[name]) return;
    if (on) layers[name].addTo(window.evalMap);
    else window.evalMap.removeLayer(layers[name]);
};

window.toggleRadius = function() {
    if (!window.evalMap) return;
    var btn = document.getElementById('btn-radius');
    if (radiusCircle) {
        window.evalMap.removeLayer(radiusCircle); radiusCircle = null; window.radiusActive = false;
        btn.classList.remove('is-active');
    } else {
        radiusCircle = L.circle([-30.9347,30.0930],{radius:500,color:'#00d4aa',weight:1.5,dashArray:'5 4',fillOpacity:.04}).addTo(window.evalMap);
        window.radiusActive = true;
        btn.classList.add('is-active');
    }
};
</script>
@endsection
