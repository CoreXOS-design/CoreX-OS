@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4"
     x-data="{
        activeTab: '{{ $tab }}',
        wishlistDrawerOpen: false,
        wishlistEditingId: null,
        openAddDrawer() { this.wishlistEditingId = null; this.wishlistDrawerOpen = true; },
        openEditDrawer(id) { this.wishlistEditingId = id; this.wishlistDrawerOpen = true; },
        closeDrawer() { this.wishlistDrawerOpen = false; this.wishlistEditingId = null; },

        showViewingPicker: false,
        pickerProperties: @js($matched->take(6)->map(fn($mp) => ['id' => $mp['id'], 'address' => $mp['address'], 'suburb' => $mp['suburb'] ?? null, 'price' => $mp['price'] ?? null, 'match_score' => $mp['match_score'] ?? null])->values()),
        pickerSelected: @js($matched->take(6)->pluck('id')->values()),
        togglePickerProperty(id) {
            const i = this.pickerSelected.indexOf(id);
            if (i === -1) this.pickerSelected.push(id);
            else this.pickerSelected.splice(i, 1);
        },
        pickerAllChecked() { return this.pickerProperties.length > 0 && this.pickerSelected.length === this.pickerProperties.length; },
        pickerToggleAll() {
            this.pickerSelected = this.pickerAllChecked() ? [] : this.pickerProperties.map(p => p.id);
        },
        continueToSchedule() {
            const chosen = this.pickerProperties.filter(p => this.pickerSelected.includes(p.id))
                                              .map(p => ({ id: p.id, address: p.address }));
            // Pass the buyer as a prefill_attendees handoff so the calendar
            // chips render immediately — no fetch, no name lookup roundtrip.
            const attendees = [{
                id: {{ $buyer->id }},
                name: @js(trim(($buyer->first_name ?? '') . ' ' . ($buyer->last_name ?? '')) ?: ('Contact #' . $buyer->id)),
                type: 'contact',
                role: 'buyer_contact',
                phone: @js($buyer->phone),
                email: @js($buyer->email),
            }];
            const base = '{{ route('command-center.calendar') }}';
            const params = new URLSearchParams();
            params.set('view', 'day');
            params.set('prefill_class', 'viewing');
            params.set('prefill_contact_id', '{{ $buyer->id }}');
            params.set('prefill_attendees', JSON.stringify(attendees));
            if (chosen.length) params.set('prefill_properties', JSON.stringify(chosen));
            window.location.href = base + '?' + params.toString();
        },
     }">
    {{-- Back to Buyer Pipeline --}}
    <div>
        <a href="{{ route('command-center.buyers.pipeline') }}"
           class="inline-flex items-center gap-1 text-xs no-underline"
           style="color: var(--text-muted);">
            ← Back to Buyer Pipeline
        </a>
    </div>

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold text-white" style="background: #00d4aa;">
                    {{ strtoupper(substr($buyer->first_name ?? '', 0, 1) . substr($buyer->last_name ?? '', 0, 1)) }}
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">{{ $buyer->full_name }}</h1>
                    <div class="flex items-center gap-2 mt-1">
                        @php $statePill = match($buyer->buyer_state) { 'warm' => '#10b981', 'cold' => '#f59e0b', 'lost' => '#ef4444', default => '#3b82f6' }; @endphp
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold" style="background: {{ $statePill }}; color: #fff;">{{ ucfirst($buyer->buyer_state ?? 'New') }}</span>
                        <span class="text-xs text-white/60">Since {{ $buyer->buyer_pipeline_entered_at?->format('d M Y') ?? 'Unknown' }}</span>
                        <span class="text-xs text-white/60">· Last activity {{ $buyer->last_activity_at?->diffForHumans() ?? 'Never' }}</span>
                        <span class="text-xs text-white/60">· Agent: {{ $buyer->createdBy?->name ?? 'Unassigned' }}</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="showViewingPicker = true"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background: #00d4aa; color: #0f172a; border: none; cursor: pointer;">Schedule Viewing</button>
                <a href="{{ route('corex.contacts.show', $buyer) }}" class="text-xs font-semibold px-3 py-1.5 rounded-md no-underline" style="background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2);">Contact Record</a>
                @if($buyer->buyer_state !== 'lost')
                <button type="button" x-data x-on:click="$refs.lostModal.showModal()"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">Mark Lost</button>
                @else
                <button type="button" x-data x-on:click="$refs.reengageModal.showModal()"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3);">Re-engage Buyer</button>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex overflow-x-auto" style="border-bottom: 1px solid var(--border);">
        @php
            $upcomingViewings = $propertiesViewed['upcoming'] ?? collect();
            $pastViewings = $propertiesViewed['past'] ?? collect();
            $allViewingsFlat = $upcomingViewings->concat($pastViewings);
        @endphp
        @foreach(['overview' => 'Overview', 'timeline' => 'Activity', 'properties' => 'Viewings & Feedback', 'wishlists' => 'Wishlists', 'playbook' => 'Retention'] as $key => $label)
            <button @click="activeTab = '{{ $key }}'"
                    :class="activeTab === '{{ $key }}' ? 'border-b-2' : 'border-b-2 border-transparent'"
                    :style="activeTab === '{{ $key }}' ? 'color: #00d4aa; border-color: #00d4aa;' : 'color: var(--text-secondary);'"
                    class="px-4 py-3 text-xs font-semibold whitespace-nowrap">{{ $label }}</button>
        @endforeach
    </div>

    {{-- Overview Tab --}}
    <div x-show="activeTab === 'overview'" class="space-y-4">
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $allViewingsFlat->sum('view_count') }}</div>
                <div class="text-[10px] uppercase" style="color: var(--text-muted);">Total Viewings</div>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $allViewingsFlat->count() }}</div>
                <div class="text-[10px] uppercase" style="color: var(--text-muted);">Properties</div>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $preferences['viewing_intensity'] ?? '—' }}</div>
                <div class="text-[10px] uppercase" style="color: var(--text-muted);">Views/Week</div>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $buyer->buyer_pipeline_entered_at ? (int) $buyer->buyer_pipeline_entered_at->diffInDays(now()) : '—' }}</div>
                <div class="text-[10px] uppercase" style="color: var(--text-muted);">Days in Pipeline</div>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $buyer->last_activity_at ? (int) $buyer->last_activity_at->diffInDays(now()) : '—' }}</div>
                <div class="text-[10px] uppercase" style="color: var(--text-muted);">Days Inactive</div>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid {{ $risk['score'] > 60 ? '#ef4444' : ($risk['score'] > 30 ? '#f59e0b' : '#10b981') }};">
                <div class="text-xl font-bold" style="color: {{ $risk['score'] > 60 ? '#ef4444' : ($risk['score'] > 30 ? '#f59e0b' : '#10b981') }};">{{ $risk['score'] }}</div>
                <div class="text-[10px] uppercase" style="color: var(--text-muted);">Lost-Risk</div>
            </div>
        </div>

        {{-- Recent activity --}}
        @if($timeline->isNotEmpty())
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h3 class="text-xs font-semibold mb-2" style="color: var(--text-primary);">Recent Activity</h3>
            <div class="space-y-1">
                @foreach($timeline->take(5) as $entry)
                    <div class="flex items-center gap-2 text-xs py-1" style="color: var(--text-secondary);">
                        <span class="text-[10px] w-20 flex-shrink-0" style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($entry['date'])->diffForHumans() }}</span>
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-medium" style="background: var(--surface-2);">{{ str_replace('_', ' ', $entry['type']) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Buyer Portal Link section --}}
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h3 class="text-xs font-semibold mb-2" style="color: var(--text-primary);">Buyer Portal Link</h3>
            @php $portalLinks = DB::table('buyer_portal_links')->where('contact_id', $buyer->id)->orderByDesc('generated_at')->get(); @endphp
            @if($portalLinks->isNotEmpty())
                @foreach($portalLinks as $pl)
                    <div class="flex items-center justify-between px-3 py-2 rounded text-xs mb-1" style="background: var(--surface-2);">
                        <div>
                            <span style="color: var(--text-primary);">{{ $pl->revoked_at ? 'Revoked' : 'Active' }}</span>
                            <span class="ml-2" style="color: var(--text-muted);">Viewed {{ $pl->access_count }}x @if($pl->last_accessed_at) · Last: {{ \Carbon\Carbon::parse($pl->last_accessed_at)->diffForHumans() }} @endif</span>
                        </div>
                        @if(!$pl->revoked_at)
                        <div class="flex items-center gap-1">
                            <button type="button" onclick="navigator.clipboard.writeText('{{ url('/buyer/portal/' . $pl->token) }}'); this.textContent='Copied!';"
                                    class="text-[10px] font-medium px-2 py-0.5 rounded" style="color: #00d4aa; background: color-mix(in srgb, #00d4aa 10%, transparent);">Copy</button>
                            <a href="mailto:{{ $buyer->email }}?subject={{ urlencode('Your property matches') }}&body={{ urlencode("Hi " . ($buyer->first_name ?? '') . ",\n\nYour personalised property matches are ready:\n\n" . url('/buyer/portal/' . $pl->token) . "\n\nBest regards,\n" . (auth()->user()->name ?? 'Your Agent')) }}"
                               class="text-[10px] font-medium px-2 py-0.5 rounded no-underline" style="color: var(--brand-icon);">Email</a>
                            <form method="POST" action="{{ route('command-center.buyers.portal-links.revoke', $pl->id) }}" class="inline">@csrf
                                <button type="submit" class="text-[10px] font-medium px-2 py-0.5 rounded" style="color: var(--text-muted);">Revoke</button>
                            </form>
                        </div>
                        @endif
                    </div>
                @endforeach
            @endif
            @if(!$portalLinks->where('revoked_at', null)->count())
                <form method="POST" action="{{ route('command-center.buyers.portal-links.generate') }}">@csrf
                    <input type="hidden" name="contact_id" value="{{ $buyer->id }}">
                    <button type="submit" class="text-[10px] font-medium px-3 py-1 rounded" style="background: #00d4aa; color: #0f172a;">Generate Buyer Portal Link</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Activity Timeline Tab --}}
    <div x-show="activeTab === 'timeline'" x-cloak class="space-y-2">
        @forelse($timeline as $entry)
            <div class="flex items-center gap-3 px-4 py-2 rounded" style="background: var(--surface); border: 1px solid var(--border);">
                <span class="text-[10px] w-24 flex-shrink-0" style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($entry['date'])->format('d M Y H:i') }}</span>
                <span class="text-xs px-2 py-0.5 rounded font-medium" style="background: var(--surface-2); color: var(--text-primary);">{{ str_replace('_', ' ', ucfirst($entry['type'])) }}</span>
                @if($entry['property_id'])
                    <a href="{{ route('corex.properties.show', $entry['property_id']) }}" target="_blank" class="text-[10px] no-underline" style="color: var(--brand-icon);">Property →</a>
                @endif
            </div>
        @empty
            <p class="text-sm py-8 text-center" style="color: var(--text-muted);">No activity recorded yet.</p>
        @endforelse
    </div>

    {{-- Viewings & Feedback Tab --}}
    <div x-show="activeTab === 'properties'" x-cloak class="space-y-6">

        {{-- Upcoming Viewings --}}
        <div>
            <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Upcoming Viewings ({{ $upcomingViewings->count() }})</h3>
            @forelse($upcomingViewings as $pv)
                <div class="rounded-md p-4 mb-2" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('corex.properties.show', $pv['property_id']) }}" target="_blank"
                               class="text-sm font-semibold truncate block no-underline hover:underline" style="color: var(--text-primary);">{{ $pv['address'] }}</a>
                            <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">{{ $pv['suburb'] }} · R {{ number_format($pv['price'] ?? 0) }}</div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-[10px]" style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($pv['event_date'])->format('D, j M Y') }}</div>
                            <div class="text-[10px]" style="color: var(--text-muted);">Agent: {{ $pv['agent_name'] ?? '—' }}</div>
                            <span class="text-[9px] px-1.5 py-0.5 rounded mt-0.5 inline-block" style="background:rgba(59,130,246,.15); color:#2563eb;">Scheduled</span>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-xs py-3" style="color: var(--text-muted);">None</p>
            @endforelse
        </div>

        {{-- Past Viewings --}}
        <div>
            <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Past Viewings ({{ $pastViewings->count() }})</h3>
            @forelse($pastViewings as $pv)
                <div class="rounded-md p-4 mb-2" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('corex.properties.show', $pv['property_id']) }}" target="_blank"
                               class="text-sm font-semibold truncate block no-underline hover:underline" style="color: var(--text-primary);">{{ $pv['address'] }}</a>
                            <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">{{ $pv['suburb'] }} · R {{ number_format($pv['price'] ?? 0) }}</div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-[10px]" style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($pv['event_date'])->format('D, j M Y') }}</div>
                            <div class="text-[10px]" style="color: var(--text-muted);">Agent: {{ $pv['agent_name'] ?? '—' }}</div>
                        </div>
                    </div>
                    @if($pv['feedback'] ?? null)
                        <div class="mt-2 rounded px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                            @if($pv['feedback']['outcome_label'] ?? null)
                                <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded" style="background:rgba(16,185,129,.15); color:#059669;">{{ $pv['feedback']['outcome_label'] }}</span>
                            @endif
                            @if($pv['feedback']['seller_notes'] ?? null)
                                <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $pv['feedback']['seller_notes'] }}</p>
                            @endif
                            @if($pv['feedback']['internal_notes'] ?? null)
                                <p class="text-[11px] mt-1" style="color: var(--text-muted);"><span class="font-medium">Internal:</span> {{ $pv['feedback']['internal_notes'] }}</p>
                            @endif
                            <div class="text-[10px] mt-1" style="color: var(--text-muted);">Captured {{ \Carbon\Carbon::parse($pv['feedback']['captured_at'])->diffForHumans() }}</div>
                        </div>
                    @else
                        <div class="mt-2">
                            <span class="text-[10px] px-1.5 py-0.5 rounded" style="background:rgba(107,114,128,.15); color:#6b7280;">No feedback captured</span>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-xs py-3" style="color: var(--text-muted);">None</p>
            @endforelse
        </div>

    </div>

    {{-- Wishlists Tab (replaces legacy Matched + Preferences tabs) --}}
    <div x-show="activeTab === 'wishlists'" x-cloak class="space-y-4">

        {{-- Auto-derived patterns (read-only) — preserved from the old Preferences tab --}}
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Auto-Derived from Viewing History</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-xs">
                <div><span style="color: var(--text-muted);">Avg price viewed:</span> <span class="font-medium" style="color: var(--text-primary);">R {{ number_format($preferences['avg_price'] ?? 0) }}</span></div>
                <div><span style="color: var(--text-muted);">Properties viewed:</span> <span class="font-medium" style="color: var(--text-primary);">{{ $preferences['properties_viewed_count'] ?? 0 }}</span></div>
                <div><span style="color: var(--text-muted);">Viewing intensity:</span> <span class="font-medium" style="color: var(--text-primary);">{{ $preferences['viewing_intensity'] ?? '—' }}/week</span></div>
            </div>
            @if(!empty($preferences['top_areas']))
                <div class="mt-3">
                    <span class="text-xs" style="color: var(--text-muted);">Top areas:</span>
                    @foreach($preferences['top_areas'] as $area => $count)
                        <span class="text-[10px] px-1.5 py-0.5 rounded ml-1" style="background: var(--surface-2); color: var(--text-primary);">{{ $area }} ({{ $count }})</span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Wishlists header + Add button --}}
        <div class="flex items-center justify-between">
            <h3 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                Wishlists ({{ $buyer->matches->count() }})
            </h3>
            <button type="button" @click="openAddDrawer()"
                    class="text-xs font-semibold px-3 py-1.5 rounded text-white"
                    style="background: var(--brand-button);">
                + Add Wishlist
            </button>
        </div>

        @if($buyer->matches->isEmpty())
            <div class="rounded-md p-6 text-center" style="background: var(--surface); border: 1px dashed var(--border);">
                <p class="text-sm mb-3" style="color: var(--text-muted);">No wishlists yet. Add one to start matching properties to this buyer.</p>
                <button type="button" @click="openAddDrawer()"
                        class="text-xs font-semibold px-3 py-1.5 rounded text-white"
                        style="background: var(--brand-button);">
                    Add first wishlist
                </button>
            </div>
        @else
            <div class="grid gap-3">
                @foreach($buyer->matches as $wishlist)
                <div class="rounded-md p-4"
                     style="background: var(--surface); border: 1px solid {{ $wishlist->is_primary ? '#f59e0b' : 'var(--border)' }};">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0 space-y-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                @if($wishlist->is_primary)
                                    <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded"
                                          style="background: rgba(245,158,11,0.18); color: #b45309; border: 1px solid rgba(245,158,11,0.35);">
                                        ⭐ Primary
                                    </span>
                                @endif
                                <span class="text-xs px-2 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-secondary);">
                                    {{ $wishlist->listingTypeLabel() }}
                                </span>
                                @if($wishlist->price_min || $wishlist->price_max)
                                    <span class="text-sm font-bold" style="color: var(--text-primary);">{{ $wishlist->priceRangeLabel() }}</span>
                                @endif
                                <span class="text-[10px]" style="color: var(--text-muted);">Status: {{ $wishlist->status }}</span>
                            </div>

                            <div class="text-xs" style="color: var(--text-secondary);">
                                @if($wishlist->name)<span class="font-semibold">{{ $wishlist->name }}</span> · @endif
                                @if($wishlist->category){{ $wishlist->category }} · @endif
                                @php $types = $wishlist->propertyTypeList(); @endphp
                                @if(!empty($types))Types: {{ implode(', ', $types) }} · @endif
                                @php $suburbs = $wishlist->suburbList(); @endphp
                                @if(!empty($suburbs))Suburbs: {{ implode(', ', $suburbs) }}@endif
                            </div>

                            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-1 text-[10px]" style="color: var(--text-muted);">
                                @if($wishlist->beds_min !== null || $wishlist->bedrooms_max !== null)
                                    <div>Beds: {{ $wishlist->beds_min ?? '—' }}–{{ $wishlist->bedrooms_max ?? '—' }}</div>
                                @endif
                                @if(!empty($wishlist->must_have_features))
                                    <div>Must-have: {{ count($wishlist->must_have_features) }}</div>
                                @endif
                                @if(!empty($wishlist->deal_breakers))
                                    <div>Deal-breakers: {{ count($wishlist->deal_breakers) }}</div>
                                @endif
                                <div>Updated {{ $wishlist->updated_at->diffForHumans() }}</div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5 flex-shrink-0">
                            <button type="button" @click="openEditDrawer({{ $wishlist->id }})"
                                    class="text-[10px] font-semibold px-2.5 py-1 rounded"
                                    style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                                Edit
                            </button>
                            @if(!$wishlist->is_primary)
                            <form method="POST" action="{{ route('command-center.buyers.wishlists.primary', [$buyer, $wishlist]) }}"
                                  onsubmit="return confirm('Make this the primary wishlist? The current primary will be demoted.');">
                                @csrf
                                <button type="submit"
                                        class="w-full text-[10px] font-semibold px-2.5 py-1 rounded"
                                        style="background: rgba(245,158,11,0.10); color: #b45309; border: 1px solid rgba(245,158,11,0.25);">
                                    ⭐ Make Primary
                                </button>
                            </form>
                            @endif
                            <form method="POST" action="{{ route('command-center.buyers.wishlists.archive', [$buyer, $wishlist]) }}"
                                  onsubmit="return confirm('Archive this wishlist? It can be restored by an admin.');">
                                @csrf
                                <button type="submit"
                                        class="text-[10px] font-semibold px-2.5 py-1 rounded"
                                        style="background: rgba(239,68,68,0.10); color: #b91c1c; border: 1px solid rgba(239,68,68,0.25);">
                                    Archive
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- "Matched properties for primary wishlist" — replaces the legacy Matched tab as a contextual drilldown --}}
            @if($matched->isNotEmpty())
            <div class="mt-4">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Top Matches for Primary Wishlist</h3>
                    <button type="button" @click="showViewingPicker = true"
                            class="text-xs font-semibold px-3 py-1 rounded" style="background: #00d4aa; color: #0f172a; border: none; cursor: pointer;">
                        Schedule Viewing
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    @foreach($matched->take(6) as $mp)
                        <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-semibold truncate" style="color: var(--text-primary);">{{ $mp['address'] }}</span>
                                <span class="text-[10px] px-1.5 py-0.5 rounded font-bold" style="background: {{ $mp['match_score'] >= 90 ? '#10b981' : ($mp['match_score'] >= 75 ? '#f59e0b' : '#ef4444') }}20; color: {{ $mp['match_score'] >= 90 ? '#10b981' : ($mp['match_score'] >= 75 ? '#f59e0b' : '#ef4444') }};">{{ $mp['match_score'] }}%</span>
                            </div>
                            <div class="text-[10px]" style="color: var(--text-muted);">{{ $mp['suburb'] }} · R {{ number_format($mp['price'] ?? 0) }} · {{ $mp['days_on_market'] ?? '?' }}d</div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif
        @endif
    </div>

    {{-- Property-Picker Modal: opened by either "Schedule Viewing" button.
         Submits via JS to /calendar with prefill_class=viewing, prefill_contact_id,
         and prefill_properties=<JSON array of {id, address}>. The calendar's
         handlePrefill() reads these and pre-populates the create-event form. --}}
    <div x-show="showViewingPicker" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center"
         style="background: rgba(0,0,0,0.55);"
         @keydown.escape.window="showViewingPicker = false">
        <div class="rounded-md w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6"
             style="background: var(--surface); border: 1px solid var(--border);"
             @click.outside="showViewingPicker = false">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <h2 class="text-base font-bold" style="color: var(--text-primary);">Schedule Viewing</h2>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                        Select the properties to include. You can add more on the next screen.
                    </p>
                </div>
                <button type="button" @click="showViewingPicker = false"
                        class="text-xl leading-none px-2 py-0"
                        style="color: var(--text-muted); background: none; border: none; cursor: pointer;">×</button>
            </div>

            @if($matched->isEmpty())
                <div class="rounded-md p-6 text-center" style="background: var(--surface-2); border: 1px dashed var(--border);">
                    <p class="text-sm mb-3" style="color: var(--text-muted);">
                        No matching properties yet for this buyer's primary wishlist.
                    </p>
                    <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $buyer->id, 'prefill_class' => 'viewing']) }}"
                       class="text-xs font-semibold px-3 py-1.5 rounded no-underline inline-block"
                       style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">
                        Schedule manually →
                    </a>
                </div>
            @else
                <div class="space-y-2 mb-4">
                    <template x-for="p in pickerProperties" :key="p.id">
                        <label class="flex items-start gap-3 p-3 rounded cursor-pointer"
                               style="background: var(--surface-2); border: 1px solid var(--border);">
                            <input type="checkbox"
                                   :checked="pickerSelected.includes(p.id)"
                                   @change="togglePickerProperty(p.id)"
                                   class="mt-1">
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-semibold truncate" style="color: var(--text-primary);" x-text="p.address"></div>
                                <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                                    <span x-text="p.suburb"></span>
                                    <span> · R </span>
                                    <span x-text="(p.price || 0).toLocaleString()"></span>
                                    <template x-if="p.match_score !== null && p.match_score !== undefined">
                                        <span> · <span x-text="p.match_score"></span>% match</span>
                                    </template>
                                </div>
                            </div>
                        </label>
                    </template>
                </div>

                <div class="flex items-center justify-between pt-3" style="border-top: 1px solid var(--border);">
                    <label class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--text-secondary);">
                        <input type="checkbox" :checked="pickerAllChecked()" @change="pickerToggleAll()">
                        Select all
                    </label>
                    <div class="flex gap-2">
                        <button type="button" @click="showViewingPicker = false"
                                class="text-xs font-semibold px-3 py-1.5 rounded"
                                style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); cursor: pointer;">Cancel</button>
                        <button type="button"
                                @click="continueToSchedule()"
                                :disabled="pickerSelected.length === 0"
                                :style="pickerSelected.length === 0 ? 'background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); cursor: not-allowed;' : 'background: #00d4aa; color: #0f172a; border: none; cursor: pointer;'"
                                class="text-xs font-semibold px-3 py-1.5 rounded">
                            Continue to schedule (<span x-text="pickerSelected.length"></span>)
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Drawer: add / edit wishlist (renders one form per existing wishlist as hidden panels + one for "add") --}}
    <div x-show="wishlistDrawerOpen" x-cloak
         class="fixed inset-0 z-50 flex justify-end"
         style="background: rgba(0,0,0,0.55);"
         @keydown.escape.window="closeDrawer()">
        <div class="bg-white h-full overflow-y-auto p-6 w-full max-w-3xl"
             style="background: var(--surface);"
             @click.outside="closeDrawer()">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold" style="color: var(--text-primary);">
                    <span x-show="wishlistEditingId === null">New Wishlist</span>
                    <span x-show="wishlistEditingId !== null">Edit Wishlist</span>
                </h2>
                <button type="button" @click="closeDrawer()"
                        class="text-xl leading-none px-2 py-0"
                        style="color: var(--text-muted);">×</button>
            </div>

            {{-- "Add" form: shown when wishlistEditingId is null --}}
            <div x-show="wishlistEditingId === null">
                @include('corex.contacts._match-form', [
                    'contact'    => $buyer,
                    'match'      => null,
                    'formAction' => route('command-center.buyers.wishlists.add', $buyer),
                ])
            </div>

            {{-- Per-wishlist edit forms: one hidden panel per existing wishlist --}}
            @foreach($buyer->matches as $wishlist)
                <div x-show="wishlistEditingId === {{ $wishlist->id }}" x-cloak>
                    @include('corex.contacts._match-form', [
                        'contact'    => $buyer,
                        'match'      => $wishlist,
                        'formAction' => route('command-center.buyers.wishlists.update', [$buyer, $wishlist]),
                    ])
                </div>
            @endforeach
        </div>
    </div>

    {{-- Retention Playbook Tab --}}
    <div x-show="activeTab === 'playbook'" x-cloak class="space-y-4">
        {{-- Risk score breakdown --}}
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid {{ $risk['score'] > 60 ? '#ef4444' : ($risk['score'] > 30 ? '#f59e0b' : '#10b981') }};">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Lost-Risk Score: {{ $risk['score'] }}/100</h3>
                <span class="text-[10px] px-2 py-0.5 rounded-full font-bold"
                      style="background: {{ $risk['score'] > 60 ? '#ef444420' : ($risk['score'] > 30 ? '#f59e0b20' : '#10b98120') }}; color: {{ $risk['score'] > 60 ? '#ef4444' : ($risk['score'] > 30 ? '#f59e0b' : '#10b981') }};">
                    {{ $risk['score'] > 60 ? 'Intervene Now' : ($risk['score'] > 30 ? 'Watch' : 'Healthy') }}
                </span>
            </div>
            <div class="space-y-1">
                @foreach($risk['factors'] as $factor => $data)
                    <div class="flex items-center justify-between text-xs">
                        <span style="color: var(--text-secondary);">{{ str_replace('_', ' ', ucfirst($factor)) }}</span>
                        <span class="font-medium" style="color: var(--text-primary);">{{ $data['points'] }}/{{ $data['max'] }} pts</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Suggested actions --}}
        @if(!empty($playbook))
        <div class="space-y-2">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Suggested Actions</h3>
            @foreach($playbook as $action)
                <div class="rounded-md p-3" style="background: color-mix(in srgb, #00d4aa 5%, var(--surface)); border: 1px solid color-mix(in srgb, #00d4aa 20%, var(--border));">
                    <div class="flex items-start gap-3">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color: #00d4aa;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                        <div class="flex-1">
                            <div class="text-xs font-semibold" style="color: var(--text-primary);">{{ $action['title'] }}</div>
                            <div class="text-[11px] mt-0.5" style="color: var(--text-secondary);">{{ $action['reasoning'] }}</div>
                        </div>
                    </div>
                    <div class="mt-2 pt-2" style="border-top: 1px solid var(--border);">
                        <form method="POST" action="{{ route('command-center.buyers.playbook-action', $buyer) }}" class="flex items-center gap-2">
                            @csrf
                            <input type="hidden" name="action_code" value="{{ $action['code'] }}">
                            <input type="text" name="notes" placeholder="Notes (optional)…"
                                   class="flex-1 rounded px-2 py-1 text-[10px]" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded text-white" style="background: #00d4aa;">Mark Action Taken</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Mark Lost Modal --}}
    @if($buyer->buyer_state !== 'lost')
    <dialog x-ref="lostModal" class="rounded-lg p-0 w-full max-w-md backdrop:bg-black/50" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        <form method="POST" action="{{ route('command-center.buyers.mark-lost', $buyer) }}" class="p-5 space-y-4">
            @csrf
            <h3 class="text-sm font-semibold">Why is this buyer being marked as lost?</h3>
            @php $reasons = DB::table('agency_lost_deal_reasons')->where('agency_id', $buyer->agency_id ?? 1)->where('applies_to_buyers', true)->where('active', true)->orderBy('display_order')->get(); @endphp
            <div class="space-y-1 max-h-48 overflow-y-auto">
                @foreach($reasons as $reason)
                    <label class="flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer text-xs" style="color: var(--text-primary);">
                        <input type="radio" name="reason_code" value="{{ $reason->code }}" required class="w-3 h-3">
                        <span>{{ $reason->label }}</span>
                        <span class="text-[10px] ml-auto" style="color: var(--text-muted);">{{ $reason->category }}</span>
                    </label>
                @endforeach
            </div>
            <div>
                <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                <textarea name="notes" rows="2" placeholder="Additional context…" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
            </div>
            <div>
                <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">What did the buyer say? (optional)</label>
                <textarea name="outcome" rows="2" placeholder="Buyer's actual words…" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="this.closest('dialog').close()" class="text-xs px-3 py-1.5 rounded" style="color: var(--text-muted);">Cancel</button>
                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded text-white" style="background: #ef4444;">Mark Lost</button>
            </div>
        </form>
    </dialog>
    @endif

    {{-- Re-engage Modal --}}
    @if($buyer->buyer_state === 'lost')
    <dialog x-ref="reengageModal" class="rounded-lg p-0 w-full max-w-sm backdrop:bg-black/50" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        <form method="POST" action="{{ route('command-center.buyers.reengage', $buyer) }}" class="p-5 space-y-4">
            @csrf
            <h3 class="text-sm font-semibold">Re-engage {{ $buyer->first_name }}?</h3>
            <p class="text-xs" style="color: var(--text-secondary);">This will bring the buyer back into the active pipeline (state: Warm).</p>
            <div>
                <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Why has the buyer come back? (optional)</label>
                <textarea name="notes" rows="2" placeholder="e.g. Saw new listing on portal, called us back…" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="this.closest('dialog').close()" class="text-xs px-3 py-1.5 rounded" style="color: var(--text-muted);">Cancel</button>
                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded text-white" style="background: #10b981;">Re-engage</button>
            </div>
        </form>
    </dialog>
    @endif
</div>
@endsection
