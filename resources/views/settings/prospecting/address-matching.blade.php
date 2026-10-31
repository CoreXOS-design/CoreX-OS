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
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Neighbouring suburbs (for example a suburb and its beach or extension)</label>
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

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Link automatically from</label>
            <select name="auto_merge_min_confidence" class="px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="high" @selected(old('auto_merge_min_confidence', $settings['auto_merge_min_confidence']) === 'high')>Confident matches and certain ones (standard)</option>
                <option value="exact" @selected(old('auto_merge_min_confidence', $settings['auto_merge_min_confidence']) === 'exact')>Only certain matches (same advert number or SG code)</option>
            </select>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Anything less certain is never linked on its own. It is kept as a "possible match" for an agent to confirm.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Claim a Market Intelligence property for the agent who captures it, from</label>
            <select name="auto_claim_min_confidence" data-qa="auto-claim-min" class="px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="high" @selected(old('auto_claim_min_confidence', $settings['auto_claim_min_confidence']) === 'high')>Confident matches and certain ones (standard)</option>
                <option value="exact" @selected(old('auto_claim_min_confidence', $settings['auto_claim_min_confidence']) === 'exact')>Only certain matches (same advert number or SG code)</option>
            </select>
            <p class="text-xs mt-1" style="color:var(--text-muted);">When an agent captures a property (a deeds capture or a property added by hand) and it is the same property as one already in Market Intelligence, that property is claimed for them automatically so it counts in Road to Sold. Anything less certain is never claimed on its own; it waits as a "possible match" for an agent to confirm.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Loose-match score worth keeping (60-100)</label>
            <input type="number" name="fuzzy_threshold" min="60" max="100" required value="{{ old('fuzzy_threshold', $settings['fuzzy_threshold']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">A "same street, nothing else agrees" candidate below this score is not even kept as a possible match. Standard: 85.</p>
        </div>

        <div>
            <input type="hidden" name="same_address_sweep_present" value="1">
            <label class="inline-flex items-center gap-2 text-sm font-semibold" style="color:var(--text-secondary);">
                <input type="checkbox" name="same_address_sweep" value="1" data-qa="same-address-sweep" @checked(old('same_address_sweep_present') ? old('same_address_sweep') : $settings['same_address_sweep'])>
                Tidy up same-address properties after every import
            </label>
            <p class="text-xs mt-1" style="color:var(--text-muted);">After an import, houses and stands that share the same street number, street, unit and suburb are merged into one property (nothing is deleted; the older record is kept as a duplicate). Flats with no unit number, and anything less certain, are never merged: they wait on the Possible matches list for an agent to decide. Standard: on.</p>
        </div>

        <div class="pt-2 text-sm font-bold" style="color:var(--text-primary);">Same property advertised more than once</div>
        <input type="hidden" name="matching_rules_present" value="1">
        <div>
            <label class="inline-flex items-center gap-2 text-sm font-semibold" style="color:var(--text-secondary);">
                <input type="checkbox" name="portal_group_link_enabled" value="1" data-qa="portal-group-link-enabled" @checked(old('matching_rules_present') ? old('portal_group_link_enabled') : $settings['portal_group_link_enabled'])>
                Join the adverts Property24 itself groups as one property
            </label>
            <p class="text-xs mt-1" style="color:var(--text-muted);">When Property24 says several adverts ("Listed by 3 Estate Agencies") are one property, they are put on one property file in your Market Intelligence, as long as nothing contradicts it (a different street number, unit, bedrooms or type, or a price spread above the limit below). Contradictions wait on the Possible matches list. Standard: on.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Largest price gap inside a Property24 group (times, 1.1-3)</label>
            <input type="number" name="portal_group_price_spread" min="1.1" max="3" step="0.1" value="{{ old('portal_group_price_spread', $settings['portal_group_price_spread']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">If the dearest advert in a group is more than this many times the cheapest, they are not joined automatically. Standard: 1.5.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Join a Property24 group automatically from</label>
            <select name="portal_group_auto_link_min_confidence" class="px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="high" @selected(old('portal_group_auto_link_min_confidence', $settings['portal_group_auto_link_min_confidence']) === 'high')>Confident groups (standard)</option>
                <option value="exact" @selected(old('portal_group_auto_link_min_confidence', $settings['portal_group_auto_link_min_confidence']) === 'exact')>Never automatically - every group waits for an agent</option>
            </select>
        </div>
        <div>
            <label class="inline-flex items-center gap-2 text-sm font-semibold" style="color:var(--text-secondary);">
                <input type="checkbox" name="twin_match_enabled" value="1" data-qa="twin-match-enabled" @checked(old('matching_rules_present') ? old('twin_match_enabled') : $settings['twin_match_enabled'])>
                Look for the same agency advertising on both portals
            </label>
            <p class="text-xs mt-1" style="color:var(--text-muted);">An advert with no address on one portal and an advert with an address on the other, from the same agency with the same rooms, type and price, may be one property. These always wait on the Possible matches list unless automatic joining below is on. Standard: on.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Price difference allowed between the two adverts (%, 0-5)</label>
            <input type="number" name="twin_price_tolerance_pct" min="0" max="5" step="1" value="{{ old('twin_price_tolerance_pct', $settings['twin_price_tolerance_pct']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Standard: 1.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Matching signs needed to join twins automatically (1-4)</label>
            <input type="number" name="twin_min_corroboration" min="1" max="4" step="1" value="{{ old('twin_min_corroboration', $settings['twin_min_corroboration']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Same agent, same advert wording, same first photo, same floor or land size. Standard: 2.</p>
        </div>
        <div>
            <label class="inline-flex items-center gap-2 text-sm font-semibold" style="color:var(--text-secondary);">
                <input type="checkbox" name="twin_auto_link" value="1" data-qa="twin-auto-link" @checked(old('matching_rules_present') ? old('twin_auto_link') : $settings['twin_auto_link'])>
                Join same-agency twins automatically
            </label>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Off until the agent, wording and photo signs are being captured; with it off every twin waits for an agent. Standard: off.</p>
        </div>
        <div>
            <label class="inline-flex items-center gap-2 text-sm font-semibold" style="color:var(--text-secondary);">
                <input type="checkbox" name="complex_group_enabled" value="1" data-qa="complex-group-enabled" @checked(old('matching_rules_present') ? old('complex_group_enabled') : $settings['complex_group_enabled'])>
                Group flats that share the same complex
            </label>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Flats with no address that sit on the same land size in the same suburb are grouped as one complex (never merged). Standard: on.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Smallest land size that counts as a complex (m2)</label>
            <input type="number" name="complex_min_land_m2" min="100" max="100000" step="100" value="{{ old('complex_min_land_m2', $settings['complex_min_land_m2']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">A single flat on a small stand is not a complex. Standard: 1000.</p>
        </div>
        <div>
            <label class="inline-flex items-center gap-2 text-sm font-semibold" style="color:var(--text-secondary);">
                <input type="checkbox" name="map_point_check_enabled" value="1" data-qa="map-point-check-enabled" @checked(old('matching_rules_present') ? old('map_point_check_enabled') : $settings['map_point_check_enabled'])>
                Check the portal map point against the address
            </label>
            <p class="text-xs mt-1" style="color:var(--text-muted);">When a portal shows a map point, it is compared with the property pin; a big gap is flagged. Standard: on.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Gap that is flagged (metres, 25-1000)</label>
            <input type="number" name="map_point_distance_m" min="25" max="1000" step="25" value="{{ old('map_point_distance_m', $settings['map_point_distance_m']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Standard: 150.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">A property with this many adverts or more may be several flats (3-30)</label>
            <input type="number" name="unit_split_min_adverts" min="3" max="30" step="1" value="{{ old('unit_split_min_adverts', $settings['unit_split_min_adverts']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Together with the price gap below, a property with this many adverts is offered on the Possible matches list for splitting into one property per flat. Nothing is split until an agent presses the button. Standard: 5.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">...and the dearest advert is this many times the cheapest (1.2-10)</label>
            <input type="number" name="unit_split_price_spread" min="1.2" max="10" step="0.1" value="{{ old('unit_split_price_spread', $settings['unit_split_price_spread']) }}"
                   class="w-28 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Standard: 2.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="text-sm font-semibold px-5 py-2 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save address matching settings</button>
            <button type="submit" name="reset" value="1" formnovalidate class="text-sm px-4 py-2 rounded-md" style="background:transparent; border:1px solid var(--border); color:var(--text-primary);"
                    onclick="return confirm('Put address matching back on the standard settings?');">Back to standard settings</button>
        </div>
    </form>
</div>
@endsection
