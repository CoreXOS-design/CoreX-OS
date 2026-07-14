{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-246 (Johan-signed) — ONE region screen. suburb→town is P24 (read-only);
     region is assigned per TOWN (MDB municipality, auto where confident, dropdown to
     override); the agency alias renames a region for its market. MIC reads this. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Regions &amp; P24 Towns</h1>
                <p class="text-sm text-white/60">Suburbs come from Property24 (read-only). Each town is placed in its municipality automatically; give a region your market's name.</p>
            </div>
            <a href="{{ route('corex.settings') }}" class="corex-btn-outline text-sm" style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">← Settings</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, #ef4444 10%, transparent); border:1px solid color-mix(in srgb,#ef4444 30%, transparent); color:var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    {{-- Aliases: rename a municipality for the market (Ray Nkonyeni → "Hibiscus Coast") --}}
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div style="padding:0.85rem 1.1rem; border-bottom:1px solid var(--border);">
            <h2 style="font-size:0.95rem; font-weight:700; color:var(--text-primary);">Region display names</h2>
            <p style="font-size:0.78rem; color:var(--text-muted);">Regions are official municipalities. Rename one for your market — the alias shows everywhere; blank shows the municipal name.</p>
        </div>
        @if($municipalitiesWithStock->isEmpty())
            <div style="padding:1rem 1.1rem; font-size:0.85rem; color:var(--text-muted);">No regions yet — assign a town below.</div>
        @else
        <div style="padding:0.5rem 1.1rem;">
            @foreach($municipalitiesWithStock as $m)
                <form method="POST" action="{{ route('admin.p24-suburbs.alias', ['municipality' => $m]) }}" style="display:flex; align-items:center; gap:0.6rem; padding:0.4rem 0;">
                    @csrf @method('PUT')
                    <span style="min-width:180px; font-weight:600; color:var(--text-primary); font-size:0.85rem;">{{ $m }}</span>
                    <span style="color:var(--text-muted);">→</span>
                    <input type="text" name="alias" value="{{ $aliases[$m] ?? '' }}" maxlength="120" placeholder="{{ $m }} (municipal name)"
                           class="rounded-md px-3 py-1.5 text-sm" style="flex:1 1 220px; max-width:280px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <button type="submit" class="corex-btn-outline" style="font-size:0.78rem; padding:0.35rem 0.8rem;">Save</button>
                </form>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Search --}}
    <form method="GET" action="{{ route('admin.p24-suburbs.index') }}" class="rounded-md p-3" style="background:var(--surface); border:1px solid var(--border);">
        <input type="text" name="q" value="{{ $search }}" placeholder="Search a town or suburb…" class="w-full rounded-md px-3 py-2 text-sm"
               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
    </form>

    {{-- Towns: P24 town → region dropdown; suburbs read-only underneath --}}
    <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border); overflow:hidden;">
        <div style="padding:0.85rem 1.1rem; border-bottom:1px solid var(--border);">
            <h2 style="font-size:0.95rem; font-weight:700; color:var(--text-primary);">Towns &amp; their region</h2>
            <p style="font-size:0.78rem; color:var(--text-muted);">{{ $towns->count() }} P24 town{{ $towns->count() === 1 ? '' : 's' }} with stock. Suburbs are Property24's — read-only.</p>
        </div>
        @forelse($towns as $t)
            <div x-data="{ open:false }" style="border-top:1px solid var(--border);">
                <div style="display:flex; align-items:center; gap:0.75rem; padding:0.7rem 1.1rem; flex-wrap:wrap;">
                    <div style="min-width:190px;">
                        <div style="font-weight:600; color:var(--text-primary); font-size:0.9rem;">{{ $t->name }}</div>
                        <button type="button" @click="open=!open" style="font-size:0.72rem; color:var(--brand-icon,#0ea5e9); background:none; border:none; cursor:pointer; padding:0;">
                            <span x-text="open ? 'hide' : 'show'"></span> {{ count($t->suburbs) }} suburb{{ count($t->suburbs) === 1 ? '' : 's' }}
                        </button>
                    </div>
                    <form method="POST" action="{{ route('admin.p24-suburbs.town-region', ['townId' => $t->id]) }}" style="display:flex; align-items:center; gap:0.5rem;">
                        @csrf @method('PUT')
                        <label style="font-size:0.72rem; color:var(--text-muted);">Region</label>
                        <select name="region" class="rounded-md px-2 py-1.5 text-sm" style="min-width:200px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="">— not assigned —</option>
                            @foreach($allMunicipalities as $m)
                                <option value="{{ $m }}" @selected((string) $t->region === (string) $m)>{{ $m }}{{ ($aliases[$m] ?? null) ? ' — “'.$aliases[$m].'”' : '' }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="corex-btn-outline" style="font-size:0.78rem; padding:0.35rem 0.8rem;">Save</button>
                        @if(!$t->region)<span style="font-size:0.72rem; color:var(--ds-amber,#b45309);">needs a region</span>@endif
                    </form>
                </div>
                <div x-show="open" x-cloak style="padding:0 1.1rem 0.7rem; display:flex; flex-wrap:wrap; gap:0.35rem;">
                    @foreach($t->suburbs as $s)
                        <span style="font-size:0.75rem; padding:0.2rem 0.55rem; border:1px solid var(--border); border-radius:999px; color:var(--text-secondary);">{{ $s }}</span>
                    @endforeach
                </div>
            </div>
        @empty
            <div style="padding:1.1rem; font-size:0.85rem; color:var(--text-muted);">
                No towns yet. Run <code>prospecting:assign-municipalities</code> once your prospecting stock has locations, and P24's towns appear here with their municipalities.
            </div>
        @endforelse
    </div>
</div>
@endsection
