{{--
    MIC Phase D4 — Opportunities tab detail page.
    Folds the Tracked-Property detail (Phase C3) under the MIC unified URL.
    Reuses the C3 edit-address + add-alternative modals via their existing
    /corex/tracked-properties/{tp}/address/* POST endpoints (unchanged).

    Spec: .ai/specs/mic-complete-spec.md §5.4.4.
--}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1640px; margin: 0 auto; padding: 0 20px;">

    @include('corex.market-intelligence.partials.tabs')

    {{-- Breadcrumb + header --}}
    <div style="margin-bottom: 16px; padding: 14px 18px; border-radius: 6px;
                background: var(--brand-default, #0b2a4a); color: #fff;">
        <nav style="font-size: 0.6875rem; margin-bottom: 3px; color: rgba(255,255,255,0.75);">
            <a href="{{ route('market-intelligence.opportunities') }}"
               style="text-decoration: none; color: rgba(255,255,255,0.85);">
                ← All opportunities
            </a>
        </nav>
        <h1 style="font-size: 1.125rem; font-weight: 600; margin: 0; line-height: 1.2;">
            {{ $tp->displayAddress() }}
        </h1>
        <div style="font-size: 0.75rem; margin-top: 3px; color: rgba(255,255,255,0.75);">
            @if($tp->isPromoted())
                Promoted to agency stock
            @else
                Tracked property — not yet mandated
            @endif
            · external_id: <code style="font-size: 0.625rem; background: rgba(255,255,255,0.10); padding: 1px 4px; border-radius: 2px;">{{ $tp->external_id }}</code>
        </div>
    </div>

    {{-- Flash + validation errors --}}
    @if(session('status'))
        <div style="margin-bottom: 10px; padding: 8px 12px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent);
                    color: var(--ds-green, #10b981); border: 1px solid var(--ds-green, #10b981); border-radius: 4px;">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div style="margin-bottom: 10px; padding: 8px 12px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent);
                    color: var(--ds-crimson, #dc2626); border: 1px solid var(--ds-crimson, #dc2626); border-radius: 4px;">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div style="margin-bottom: 10px; padding: 8px 12px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent);
                    color: var(--ds-crimson, #dc2626); border: 1px solid var(--ds-crimson, #dc2626); border-radius: 4px;">
            <strong>Please fix:</strong>
            <ul style="margin: 4px 0 0 16px;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Action bar --}}
    <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px;">
        @permission('mic.edit_address')
            <button type="button" x-data @click="$dispatch('open-edit-address')"
                    style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                           background: var(--brand-button); color: #fff;
                           border: none; border-radius: 4px; cursor: pointer;">
                Edit primary address
            </button>
            <button type="button" x-data @click="$dispatch('open-add-alternative')"
                    style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                           background: var(--surface); color: var(--text-primary);
                           border: 1px solid var(--border); border-radius: 4px; cursor: pointer;">
                Add alternative address
            </button>
        @endpermission
        @permission('outreach.compose')
            @if(!$tp->isPromoted())
                <form method="POST" action="{{ route('corex.tracked-properties.promote', $tp) }}" style="margin: 0;">
                    @csrf
                    <button type="submit"
                            onclick="return confirm('Promote this Tracked Property to Agency Stock?\n\nA Property record will be created and linked. The full source attribution is preserved here.');"
                            style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                                   background: var(--ds-green, #10b981); color: #fff;
                                   border: none; border-radius: 4px; cursor: pointer;">
                        Promote to Stock
                    </button>
                </form>
            @endif
        @endpermission
        @permission('mic.merge_duplicates')
            <a href="{{ route('corex.tracked-properties.merge', $tp) }}"
               style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500;
                      background: var(--surface); color: var(--text-secondary);
                      border: 1px solid var(--border); border-radius: 4px;
                      text-decoration: none;">
                Merge duplicate…
            </a>
        @endpermission
    </div>

    {{-- Snapshot cards --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 16px;">

        <div style="padding: 12px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 6px;">Identity</div>
            <div style="font-size: 0.8125rem; color: var(--text-primary);">
                <div style="margin-bottom: 4px;">
                    <span style="font-size: 0.6875rem; color: var(--text-muted);">Address</span><br>
                    {{ $tp->displayAddress() }}
                </div>
                @if($tp->suburb)
                    <div style="margin-bottom: 4px;">
                        <span style="font-size: 0.6875rem; color: var(--text-muted);">Suburb</span><br>
                        {{ $tp->suburb }}{{ $tp->town ? ', ' . $tp->town : '' }}
                    </div>
                @endif
                @if($tp->erf_number)
                    <div style="margin-bottom: 4px;">
                        <span style="font-size: 0.6875rem; color: var(--text-muted);">Erf</span><br>
                        {{ $tp->erf_number }}
                    </div>
                @endif
                @if($tp->title_deed_number)
                    <div style="margin-bottom: 4px;">
                        <span style="font-size: 0.6875rem; color: var(--text-muted);">Title deed</span><br>
                        <code style="font-size: 0.6875rem;">{{ $tp->title_deed_number }}</code>
                    </div>
                @endif
            </div>
        </div>

        <div style="padding: 12px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 6px;">Evaluation</div>
            <div style="font-size: 0.8125rem; color: var(--text-primary);">
                @if($tp->municipal_valuation)
                    <div style="margin-bottom: 4px;">
                        <span style="font-size: 0.6875rem; color: var(--text-muted);">Municipal{{ $tp->municipal_valuation_year ? ' (' . $tp->municipal_valuation_year . ')' : '' }}</span><br>
                        <span style="font-weight: 600;">R {{ number_format((float) $tp->municipal_valuation, 0, '.', ',') }}</span>
                    </div>
                @endif
                @if($tp->last_known_asking_price)
                    <div style="margin-bottom: 4px;">
                        <span style="font-size: 0.6875rem; color: var(--text-muted);">Last known asking</span><br>
                        R {{ number_format((float) $tp->last_known_asking_price, 0, '.', ',') }}
                    </div>
                @endif
                @if(!$tp->municipal_valuation && !$tp->last_known_asking_price)
                    <span style="font-size: 0.75rem; font-style: italic; color: var(--text-muted);">No evaluation accumulated yet.</span>
                @endif
            </div>
        </div>

        <div style="padding: 12px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 6px;">Status</div>
            @if($tp->isPromoted())
                <div style="color: var(--ds-green, #10b981); font-weight: 600; font-size: 0.875rem;">Promoted to agency stock</div>
                <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 4px;">
                    on {{ optional($tp->promoted_at)->format('j M Y') ?? '—' }}
                    @if($tp->promotedBy) · by {{ $tp->promotedBy->name }} @endif
                </div>
                <a href="{{ route('corex.properties.show', $tp->promoted_to_property_id) }}"
                   style="display: inline-block; margin-top: 6px; padding: 4px 10px; font-size: 0.6875rem;
                          background: var(--ds-green, #10b981); color: #fff;
                          border-radius: 4px; text-decoration: none;">
                    Open in stock →
                </a>
            @else
                <div style="color: var(--brand-button); font-weight: 600; font-size: 0.875rem;">Active opportunity</div>
                <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 4px;">
                    Tracked but not mandated.
                </div>
            @endif
            @if($tp->last_enriched_at)
                <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 8px; padding-top: 6px; border-top: 1px solid var(--border);">
                    Last enriched {{ $tp->last_enriched_at->diffForHumans() }}
                    @if($tp->last_enrichment_source) by {{ strtoupper($tp->last_enrichment_source) }} @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Address section (port of C3) --}}
    @php
        $primaryAddr = $tp->primaryAddress;
        $historyAddrs = $tp->addresses->where('is_primary', false);
        $confidenceColors = [
            'verified' => ['bg' => '#0d9488', 'text' => '#fff'],
            'high'     => ['bg' => '#16a34a', 'text' => '#fff'],
            'medium'   => ['bg' => '#d97706', 'text' => '#fff'],
            'low'      => ['bg' => 'var(--surface-2)', 'text' => 'var(--text-secondary)'],
        ];
        $primaryColor = $primaryAddr ? ($confidenceColors[$primaryAddr->confidence] ?? $confidenceColors['low']) : null;
    @endphp
    <section style="margin-bottom: 16px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0 0 10px 0;">Address</h2>

        @if($primaryAddr)
            <div style="padding-left: 12px; border-left: 4px solid var(--brand-button);">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                    <span style="font-weight: 500; color: var(--text-primary); font-size: 0.875rem;">
                        {{ $primaryAddr->formatted_address ?? '(no street address)' }}
                    </span>
                    <span style="padding: 2px 6px; font-size: 0.625rem; font-weight: 700; border-radius: 3px;
                                 background: {{ $primaryColor['bg'] }}; color: {{ $primaryColor['text'] }};">
                        {{ strtoupper($primaryAddr->confidence) }}
                    </span>
                    @if($primaryAddr->verified_by_user_id)
                        <span style="font-size: 0.625rem; color: var(--text-muted);">
                            Verified by {{ $primaryAddr->verifier?->name ?? 'agent' }}
                            @if($primaryAddr->verified_at) on {{ $primaryAddr->verified_at->format('j M Y') }} @endif
                        </span>
                    @endif
                </div>
                @if($primaryAddr->notes)
                    <p style="font-size: 0.75rem; font-style: italic; color: var(--text-secondary); margin: 0;">{{ $primaryAddr->notes }}</p>
                @endif
                <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 4px;">
                    Source: {{ ucfirst(str_replace('_', ' ', $primaryAddr->source_type)) }}
                    @if($primaryAddr->source_ref) · ref <code style="font-size: 0.625rem;">{{ $primaryAddr->source_ref }}</code> @endif
                    @if($primaryAddr->last_seen_at) · last seen {{ $primaryAddr->last_seen_at->diffForHumans() }} @endif
                </div>
            </div>
        @else
            <div style="font-size: 0.8125rem; font-style: italic; color: var(--text-muted);">No primary address recorded.</div>
        @endif

        @if($historyAddrs->isNotEmpty())
            <div x-data="{ open: false }" style="margin-top: 14px;">
                <button type="button" @click="open = !open"
                        style="background: none; border: none; padding: 0; cursor: pointer;
                               font-size: 0.75rem; font-weight: 500; color: var(--text-secondary);
                               display: inline-flex; align-items: center; gap: 4px;">
                    <span x-text="open ? '▼' : '▶'"></span>
                    Address history ({{ $historyAddrs->count() }})
                </button>
                <div x-show="open" x-cloak style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">
                    @foreach($historyAddrs as $addr)
                        @php $col = $confidenceColors[$addr->confidence] ?? $confidenceColors['low']; @endphp
                        <div style="padding: 6px 0 6px 10px; border-left: 2px solid var(--border);">
                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span style="font-size: 0.8125rem; color: var(--text-primary);">
                                    {{ $addr->formatted_address ?? '(no street)' }}
                                </span>
                                <span style="padding: 1px 5px; font-size: 0.6125rem; font-weight: 700; border-radius: 3px;
                                             background: {{ $col['bg'] }}; color: {{ $col['text'] }};">
                                    {{ strtoupper($addr->confidence) }}
                                </span>
                            </div>
                            <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 2px;">
                                {{ ucfirst(str_replace('_', ' ', $addr->source_type)) }}
                                @if($addr->source_ref) · <code style="font-size: 0.625rem;">{{ $addr->source_ref }}</code> @endif
                                @if($addr->last_seen_at) · {{ $addr->last_seen_at->diffForHumans() }} @endif
                                @permission('mic.edit_address')
                                    · <button type="button"
                                              onclick="window.setPrimaryAddress({{ $tp->id }}, {{ $addr->id }})"
                                              style="background: none; border: none; padding: 0; color: var(--brand-button);
                                                     font-size: 0.625rem; cursor: pointer; text-decoration: underline;">
                                        Make primary
                                    </button>
                                @endpermission
                            </div>
                            @if($addr->notes)
                                <p style="font-size: 0.625rem; font-style: italic; color: var(--text-secondary); margin: 2px 0 0 0;">{{ $addr->notes }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    {{-- Source chain --}}
    <section style="margin-bottom: 16px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0 0 10px 0;">
            Source chain ({{ count($sourceChain) }})
        </h2>
        @if(empty($sourceChain))
            <div style="font-size: 0.8125rem; font-style: italic; color: var(--text-muted);">No source chain entries.</div>
        @else
            <div style="display: flex; flex-direction: column; gap: 6px;">
                @foreach(collect($sourceChain)->reverse() as $entry)
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 6px 10px;
                                background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;">
                        <span style="padding: 2px 6px; font-size: 0.625rem; font-weight: 700;
                                     background: var(--brand-default, #0b2a4a); color: #fff; border-radius: 3px; flex-shrink: 0;">
                            {{ strtoupper($entry['type'] ?? '?') }}
                        </span>
                        <div style="flex: 1; min-width: 0; font-size: 0.6875rem; color: var(--text-secondary);">
                            @if(!empty($entry['ref']))
                                <span style="color: var(--text-muted);">Ref:</span>
                                <code style="font-size: 0.625rem;">{{ $entry['ref'] }}</code>
                            @endif
                            @if(!empty($entry['date']))
                                <span style="margin-left: 6px; color: var(--text-muted);">·</span>
                                {{ \Carbon\Carbon::parse($entry['date'])->format('j M Y H:i') }}
                            @endif
                            @if(!empty($entry['fields_contributed']) && is_array($entry['fields_contributed']))
                                <div style="margin-top: 2px;">
                                    <span style="color: var(--text-muted);">Contributed:</span>
                                    <span style="color: var(--brand-button);">{{ implode(', ', $entry['fields_contributed']) }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Linked portal listings --}}
    @if($linkedListings->isNotEmpty())
        <section style="margin-bottom: 16px;">
            <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0 0 8px 0;">
                Linked portal listings ({{ $linkedListings->count() }})
            </h2>
            <div style="display: flex; flex-direction: column; gap: 6px;">
                @foreach($linkedListings as $listing)
                    @php $isP24 = strtolower((string) $listing->portal_source) === 'p24'; @endphp
                    <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 4px;">
                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 4px;">
                            <span style="padding: 2px 6px; font-size: 0.6125rem; font-weight: 700; color: #fff; border-radius: 3px;
                                         background: {{ $isP24 ? '#1e40af' : '#059669' }};">
                                {{ strtoupper((string) $listing->portal_source) }}
                            </span>
                            <span style="font-size: 0.8125rem; font-weight: 500; color: var(--text-primary);">
                                Ref {{ $listing->portal_ref }}
                            </span>
                            @if(!$listing->is_active)
                                <span style="padding: 1px 5px; font-size: 0.6125rem; background: rgba(107,114,128,0.15); color: var(--text-muted); border-radius: 2px;">inactive</span>
                            @endif
                            @if($listing->portal_url)
                                <a href="{{ $listing->portal_url }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer"
                                   style="margin-left: auto; font-size: 0.6875rem; color: var(--brand-icon); text-decoration: none;">
                                    Open on portal ↗
                                </a>
                            @endif
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                            {{ $listing->address ?: 'Address not available' }}{{ $listing->suburb ? ', ' . $listing->suburb : '' }}
                            @if($listing->price) · R {{ number_format((float) $listing->price, 0, '.', ',') }} @endif
                            @if($listing->bedrooms) · {{ $listing->bedrooms }} bed @endif
                            @if($listing->bathrooms) · {{ $listing->bathrooms }} bath @endif
                            @if($listing->property_type) · {{ $listing->property_type }} @endif
                        </div>
                        @if($listing->first_seen_at)
                            <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 2px;">
                                First seen {{ \Carbon\Carbon::parse($listing->first_seen_at)->diffForHumans() }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- External refs --}}
    @if($externalRefsBySource->isNotEmpty())
        <section style="margin-bottom: 16px;">
            <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0 0 8px 0;">
                External references
            </h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px;">
                @foreach($externalRefsBySource as $source => $refs)
                    <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 4px;">
                        <div style="font-size: 0.6125rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 6px;">
                            {{ strtoupper($source) }} ({{ $refs->count() }})
                        </div>
                        @foreach($refs as $ref)
                            <div style="font-size: 0.6875rem; color: var(--text-secondary); margin-bottom: 2px;">
                                <code style="font-size: 0.6125rem;">{{ $ref->source_ref }}</code>
                                · first seen {{ optional($ref->first_seen_at)->diffForHumans() ?? 'unknown' }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Market data placeholder for Phase F --}}
    <section style="margin-bottom: 24px; padding: 12px 16px; background: color-mix(in srgb, var(--brand-button) 5%, transparent);
                    border: 1px dashed var(--brand-button); border-radius: 6px;">
        <h2 style="font-size: 0.8125rem; font-weight: 600; color: var(--text-primary); margin: 0 0 4px 0;">Market data</h2>
        <p style="font-size: 0.75rem; color: var(--text-secondary); margin: 0;">
            CMA comparable sales + absorption analysis will populate from the
            <code style="font-size: 0.6875rem;">market_data_points</code> table in Phase F.
            For now: no market data attached to this tracked property.
        </p>
    </section>

    {{-- C3 modals (route URLs unchanged) --}}
    @permission('mic.edit_address')
        @include('corex.tracked-properties.partials.edit-address-modal', ['tp' => $tp])
        @include('corex.tracked-properties.partials.add-alternative-modal', ['tp' => $tp])

        <script>
            window.setPrimaryAddress = function (tpId, addressId) {
                if (!confirm('Make this the primary address?\n\nThe current primary will move to history.')) return;
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                fetch(`/corex/tracked-properties/${tpId}/address/${addressId}/set-primary`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token || '',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }).then(r => {
                    if (r.ok || r.redirected) window.location.reload();
                    else alert('Could not change primary address (HTTP ' + r.status + ').');
                }).catch(err => alert('Network error: ' + (err?.message || 'unknown')));
            };
        </script>
    @endpermission

</div>
@endsection
