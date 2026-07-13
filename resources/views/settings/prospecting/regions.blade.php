@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5" data-tour="prospecting-regions">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ route('settings.prospecting.index') }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">← Back to Prospecting Setup</a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Regions</h1>
        <p class="text-sm text-white/60">
            Regions are official municipalities (Municipal Demarcation Board), assigned automatically from each area's location.
            Give a region your market's name — the alias shows everywhere; leave it blank to show the municipal name.
        </p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #10b981) 12%, var(--surface)); color: var(--text-primary); border: 1px solid color-mix(in srgb, var(--ds-green,#10b981) 35%, transparent);">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, #ef4444 10%, var(--surface)); color: var(--text-primary); border: 1px solid color-mix(in srgb, #ef4444 35%, transparent);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Region list --}}
    <div class="corex-card" style="padding: 0; overflow: hidden;">
        <div style="padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--border);">
            <h2 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary);">Your regions</h2>
            <p style="font-size: 0.78rem; color: var(--text-muted);">{{ $regions->count() }} municipalit{{ $regions->count() === 1 ? 'y' : 'ies' }} where you have prospecting stock.</p>
        </div>

        @if($regions->isEmpty())
            <div style="padding: 1.1rem; font-size: 0.85rem; color: var(--text-muted);">
                No regions yet. Once your prospecting stock has locations, running the municipal assignment places each area into its municipality automatically.
            </div>
        @else
            <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.84rem;">
                <thead>
                    <tr style="text-align: left; color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="padding: 0.6rem 1.1rem;">Municipality (official)</th>
                        <th style="padding: 0.6rem 1.1rem;">Display name (your market's name)</th>
                        <th style="padding: 0.6rem 1.1rem;">Towns</th>
                        <th style="padding: 0.6rem 1.1rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($regions as $region)
                    <tr style="border-top: 1px solid var(--border);">
                        <td style="padding: 0.7rem 1.1rem; vertical-align: top;">
                            <div style="font-weight: 600; color: var(--text-primary);">{{ $region->municipality }}</div>
                            <div style="font-size: 0.72rem; color: var(--text-muted);">shows as “{{ $region->displayName() }}”</div>
                        </td>
                        <td style="padding: 0.7rem 1.1rem; vertical-align: top;">
                            <form method="POST" action="{{ route('settings.prospecting.regions.update', ['municipality' => $region->municipality]) }}" style="display: flex; gap: 0.4rem; align-items: center;">
                                @csrf
                                @method('PUT')
                                <input type="text" name="alias" value="{{ $region->alias }}" maxlength="120"
                                       placeholder="{{ $region->municipality }}"
                                       class="corex-input" style="flex: 1 1 180px; font-size: 0.82rem; min-width: 160px;">
                                <button type="submit" class="corex-btn-outline" style="font-size: 0.78rem; padding: 0.35rem 0.8rem;">Save</button>
                            </form>
                            @if($region->alias_suggestion && $region->alias_suggestion !== $region->alias)
                                <div style="font-size: 0.72rem; color: var(--brand-icon, #0ea5e9); margin-top: 0.3rem;">
                                    Suggested: {{ $region->alias_suggestion }} <span style="color: var(--text-muted);">(P24 search term — not applied)</span>
                                </div>
                            @endif
                        </td>
                        <td style="padding: 0.7rem 1.1rem; vertical-align: top; color: var(--text-secondary);">
                            <div style="font-weight: 600;">{{ $region->town_count }} town{{ $region->town_count === 1 ? '' : 's' }} · {{ $region->suburb_count }} suburb{{ $region->suburb_count === 1 ? '' : 's' }}</div>
                            <div style="font-size: 0.72rem; color: var(--text-muted); max-width: 280px;">{{ $region->town_names }}</div>
                        </td>
                        <td style="padding: 0.7rem 1.1rem;"></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    {{-- Unmapped queue — towns that could not be placed (no location on their suburbs). --}}
    <div class="corex-card" style="padding: 0.9rem 1.1rem;">
        <h2 style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary);">Needs a location
            <span style="font-size: 0.72rem; font-weight: 600; color: var(--text-muted);">({{ $unmappedTowns->count() }})</span>
        </h2>
        @if($unmappedTowns->isEmpty())
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.3rem;">Every town has been placed in a municipality. Nothing to review.</p>
        @else
            <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0.3rem 0 0.6rem;">
                These towns have no geocoded stock yet, so they cannot be placed automatically. They gain a region once their listings carry a location, or you can map them by hand on the Towns tab.
            </p>
            <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                @foreach($unmappedTowns as $t)
                    <span style="font-size: 0.78rem; padding: 0.25rem 0.6rem; border: 1px dashed var(--border); border-radius: 999px; color: var(--text-secondary);">{{ $t->name }}</span>
                @endforeach
            </div>
        @endif
    </div>

</div>
@endsection
