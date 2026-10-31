{{-- MIC Settings (mic-work-tab.md section 9, step 6.5). One form, one Save. Per agency. --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $in = 'padding: 4px 8px; font-size: .8125rem; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text-primary);';
    $lbl = 'font-size: .8125rem; font-weight: 600; color: var(--text-primary);';
    $help = 'font-size: .75rem; color: var(--text-muted); margin: 2px 0 0 0;';
    $box = 'background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 12px 14px; margin-bottom: 12px;';
    $w = $values['score_weights'];
@endphp
<div style="width: 100%;">
    <x-mic-page-header title="Settings" subtitle="How the Work tab behaves for your agency. Changes apply to everyone in the agency." />
    @include('corex.market-intelligence.partials.tabs')

    @if(session('status'))<div style="margin-bottom: 10px; padding: 8px 12px; font-size: .8125rem; border: 1px solid var(--ds-green, #10b981); color: var(--ds-green, #10b981); border-radius: 4px;" data-qa="settings-saved">{{ session('status') }}</div>@endif
    @if($errors->any())<div style="margin-bottom: 10px; padding: 8px 12px; font-size: .8125rem; border: 1px solid var(--ds-crimson, #dc2626); color: var(--ds-crimson, #dc2626); border-radius: 4px;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ route('market-intelligence.settings.update') }}" data-qa="mic-settings-form" style="max-width: 980px;">
        @csrf @method('PUT')

        <div style="{{ $box }}">
            <div style="{{ $lbl }}">Areas</div>
            <p style="{{ $help }}">The suburbs you work. Market data freshness and the weekly portal reads are tracked for these. Tick the ones you work, or type suburb names (comma separated, exact names).</p>
            <div style="display:flex; flex-wrap:wrap; gap: 4px 16px; margin-top: 8px; max-height: 150px; overflow-y: auto;" data-qa="areas-list">
                @forelse($suburbs as $sub)
                    <label style="font-size:.8125rem;"><input type="checkbox" name="areas[]" value="{{ $sub->id }}" @checked(in_array((int) $sub->id, array_map('intval', old('areas', $values['areas']))))> {{ $sub->name }}</label>
                @empty
                    <span style="font-size:.8125rem; color: var(--text-muted);">No suburbs yet - type them below.</span>
                @endforelse
            </div>
            <input type="text" name="add_areas" value="{{ old('add_areas') }}" placeholder="Add suburbs by name, e.g. Margate, Uvongo" style="{{ $in }} width: 360px; margin-top: 8px;">
            {{-- AT-519 (cc5): used only while no area is ticked - a suburb then counts as ours once it has this many of our stock, claims or leads in the last 12 months. --}}
            <div style="margin-top: 10px; font-size:.8125rem;" data-qa="working-suburb-min-count">
                <label>Count a suburb as ours from <input type="number" name="working_suburb_min_count" min="1" max="100" value="{{ old('working_suburb_min_count', $values['working_suburb_min_count'] ?? 3) }}" style="{{ $in }} width: 70px;"> listings/claims/leads</label>
                <span style="color: var(--text-muted);"> (in the last 12 months; only used when no area is ticked above)</span>
            </div>
        </div>

        <div style="{{ $box }}">
            <div style="{{ $lbl }}">Tiles shown</div>
            <p style="{{ $help }}">Which of the eleven tiles appear at the top of the Work tab.</p>
            <div style="display:flex; flex-wrap:wrap; gap: 4px 16px; margin-top: 8px;">
                @foreach($tiles as $n => $t)
                    <label style="font-size:.8125rem;"><input type="checkbox" name="tiles_shown[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', old('tiles_shown', $values['tiles_shown']))))> {{ $t[3] }}</label>
                @endforeach
            </div>
        </div>

        <div style="{{ $box }}">
            <div style="{{ $lbl }}">Market data refresh</div>
            <p style="{{ $help }}">How often each suburb should be fully read from Property24 and Private Property. The "Data refreshed" tile turns red past this.</p>
            <label style="font-size:.8125rem;">Every <input type="number" name="refresh_interval_days" min="1" max="60" value="{{ old('refresh_interval_days', $values['refresh_interval_days']) }}" style="{{ $in }} width: 70px;"> days</label>
            <label style="font-size:.8125rem; margin-left: 18px;">"Under offer / sold" tile looks back <input type="number" name="gone_tile_days" min="1" max="365" value="{{ old('gone_tile_days', $values['gone_tile_days']) }}" style="{{ $in }} width: 70px;"> days</label>
        </div>

        <div style="{{ $box }}">
            <div style="{{ $lbl }}">Opportunity score weights</div>
            <p style="{{ $help }}">How much each signal counts toward a property's score. Must add up to 100.</p>
            <div style="display:flex; flex-wrap:wrap; gap: 10px 20px; margin-top: 8px; font-size:.8125rem;">
                @foreach(['seller' => 'Seller signals', 'buyer' => 'Buyer demand', 'pocket' => 'Pocket heat', 'known' => 'What we know', 'reachable' => 'Reachable'] as $k => $l)
                    <label>{{ $l }} <input type="number" name="weights[{{ $k }}]" min="0" max="100" value="{{ old('weights.' . $k, $w[$k]) }}" style="{{ $in }} width: 64px;"> %</label>
                @endforeach
            </div>
        </div>

        <div style="{{ $box }}">
            <div style="{{ $lbl }}">Neighbours of a sale</div>
            <p style="{{ $help }}">After a registration, sale or under-offer, the homes around it stay on the list for this long, then drop off.</p>
            <label style="font-size:.8125rem;">Keep for <input type="number" name="neighbour_months" min="1" max="6" value="{{ old('neighbour_months', $values['neighbour_months']) }}" style="{{ $in }} width: 60px;"> months (up to 6)</label>
            <label style="font-size:.8125rem; margin-left: 18px;">Homes on each side <input type="number" name="neighbour_each_side" min="1" max="15" value="{{ old('neighbour_each_side', $values['neighbour_each_side']) }}" style="{{ $in }} width: 60px;"> (plus the ones across the road)</label>
        </div>

        <div style="{{ $box }}">
            <div style="{{ $lbl }}">Browser extension reads</div>
            <p style="{{ $help }}">How many pages the extension may open in one session. More is more complete, but slower for the agent.</p>
            <label style="font-size:.8125rem;">Advert pages <input type="number" name="detail_pages_per_session" min="1" max="200" value="{{ old('detail_pages_per_session', $values['detail_pages_per_session']) }}" style="{{ $in }} width: 70px;"></label>
            <label style="font-size:.8125rem; margin-left: 18px;">Agency pages per run <input type="number" name="area_agency_pages_per_run" min="1" max="100" value="{{ old('area_agency_pages_per_run', $values['area_agency_pages_per_run']) }}" style="{{ $in }} width: 70px;"></label>
            <label style="font-size:.8125rem; margin-left: 18px;">Street pages per run <input type="number" name="area_street_pages_per_run" min="1" max="1000" value="{{ old('area_street_pages_per_run', $values['area_street_pages_per_run']) }}" style="{{ $in }} width: 70px;"></label>
        </div>

        <div style="{{ $box }}">
            <label style="{{ $lbl }}"><input type="checkbox" name="opt_out_registry_check" value="1" @checked(old('opt_out_registry_check', $values['opt_out_registry_check']))> Check the do-not-contact registry before contacting an owner</label>
            <p style="{{ $help }}">Off for now, until the regulator's guidance is clear. When on, a call or message to an owner is checked against the registry first.</p>
        </div>

        <button type="submit" class="corex-btn" data-qa="mic-settings-save">Save settings</button>
    </form>

    <div style="{{ $box }} max-width: 980px; margin-top: 16px;" data-qa="matching-summary">
        <div style="{{ $lbl }}">Matching rules <span style="font-weight:400; color: var(--text-muted);">(set on the Address matching page)</span></div>
        <p style="{{ $help }}">These decide how adverts are joined into one property. They are kept in one place so they can never disagree.</p>
        <ul style="font-size:.8125rem; color: var(--text-secondary); margin: 6px 0 0 16px; list-style: disc;">
            <li>Join two adverts that share the same address after an import: <strong>{{ $matching['same_address_sweep'] ? 'On' : 'Off' }}</strong></li>
            <li>Link Property24 "listed by N agencies" groups: <strong>{{ $matching['portal_group_link_enabled'] ? 'On' : 'Off' }}</strong>, price spread limit <strong>{{ $matching['portal_group_price_spread'] }}x</strong></li>
            <li>Join the same agency's Property24 and Private Property twin automatically: <strong>{{ $matching['twin_auto_link'] ? 'On' : 'Off (review only)' }}</strong></li>
        </ul>
        <a href="{{ route('settings.prospecting.address-matching.edit') }}" style="font-size:.8125rem; color: var(--brand-icon); text-decoration: none;">Change on the Address matching page →</a>
    </div>
</div>
@endsection
