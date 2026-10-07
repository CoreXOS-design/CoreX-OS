{{-- Structured address matching — match-strictness settings (admin-only; NOT in the Setup Wizard). Spec: .ai/specs/structured-address-matching.md §9. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ route('settings.prospecting.index') }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">← Back to Prospecting Setup</a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Address matching</h1>
        <p class="text-sm text-white/60">How strictly CoreX decides that two records are the same property. The standard settings suit most agencies — change them only if you see too many, or too few, "possible match" questions. A different street number, unit or erf always means a different property, whatever you choose here.</p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#f0fdf4; color:#166534;">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-3 py-2 text-sm" style="background:#fef2f2; color:#991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.prospecting.address-matching.update') }}"
          class="rounded-md p-6 space-y-5" style="background:var(--surface); border:1px solid var(--border);">
        @csrf @method('PUT')

        <div>
            <div class="text-sm font-semibold mb-2" style="color:var(--text-secondary);">What counts as "the same property" automatically</div>
            @foreach([
                ['rule_erf_exact', 'The same erf (stand) number and portion in the same suburb', 'Freehold properties. The LPI code from the deeds office counts here too.'],
                ['rule_scheme_exact', 'The same sectional scheme and unit number in the same suburb', 'Flats and townhouses in a scheme.'],
                ['rule_street_exact', 'The same street number and street in the same suburb', 'The street type (road / drive) may be missing on one side.'],
            ] as [$name, $label, $help])
                <label class="flex items-start gap-2 mb-2 text-sm" style="color:var(--text-primary);">
                    <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $settings[$name])) class="mt-1">
                    <span>{{ $label }}<span class="block text-xs" style="color:var(--text-muted);">{{ $help }} Switched off, these matches are shown as "possible" for an agent to confirm instead.</span></span>
                </label>
            @endforeach
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Details that must agree for a "possible match"</label>
            <select name="possible_min_agreeing_columns" class="px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                @foreach([2 => '2 (standard)', 3 => '3 (stricter)', 4 => '4 (strictest)'] as $n => $label)
                    <option value="{{ $n }}" @selected((int) old('possible_min_agreeing_columns', $settings['possible_min_agreeing_columns']) === $n)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="text-xs mt-1" style="color:var(--text-muted);">How many of: erf, scheme, unit, suburb, street, street number must agree (with nothing contradicting) before CoreX asks the agent. Standard: 2.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Neighbouring suburbs (for example Uvongo and Uvongo Beach)</label>
            <select name="neighbour_suburb_credit" class="px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="possible" @selected(old('neighbour_suburb_credit', $settings['neighbour_suburb_credit']) === 'possible')>Ask the agent — "possible match" (standard)</option>
                <option value="ignore" @selected(old('neighbour_suburb_credit', $settings['neighbour_suburb_credit']) === 'ignore')>Treat as different suburbs — never a match</option>
            </select>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Two suburbs that share streets are never automatically the same.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">GPS closeness (metres)</label>
            <input type="number" name="gps_radius_m" min="5" max="100" required value="{{ old('gps_radius_m', $settings['gps_radius_m']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Two pins this close back up an address match. A pin on its own never makes two records the same. Standard: 25.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">A unit number on only one of the two records</label>
            <select name="unit_missing_on_one_side" class="px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="possible" @selected(old('unit_missing_on_one_side', $settings['unit_missing_on_one_side']) === 'possible')>Ask the agent — "possible match" (standard)</option>
                <option value="different" @selected(old('unit_missing_on_one_side', $settings['unit_missing_on_one_side']) === 'different')>Treat as a different property</option>
            </select>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="text-sm font-semibold px-5 py-2 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save address matching settings</button>
            <button type="submit" name="reset" value="1" formnovalidate class="text-sm px-4 py-2 rounded-md" style="background:transparent; border:1px solid var(--border); color:var(--text-primary);"
                    onclick="return confirm('Put address matching back on the standard settings?');">Back to standard settings</button>
        </div>
    </form>
</div>
@endsection
