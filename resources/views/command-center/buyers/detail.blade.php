@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4" x-data="{ activeTab: '{{ $tab }}' }">
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
                <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $buyer->id, 'prefill_class' => 'viewing']) }}"
                   class="text-xs font-semibold px-3 py-1.5 rounded-md no-underline" style="background: #00d4aa; color: #0f172a;">Schedule Viewing</a>
                <a href="{{ route('corex.contacts.show', $buyer) }}" class="text-xs font-semibold px-3 py-1.5 rounded-md no-underline" style="background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2);">Contact Record</a>
                @if($buyer->buyer_state !== 'lost')
                <button type="button" x-data x-on:click="$refs.lostModal.showModal()"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3);">Mark Lost</button>
                @else
                <button type="button" x-data x-on:click="$refs.reengageModal.showModal()"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3);">Re-engage Buyer</button>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex overflow-x-auto" style="border-bottom: 1px solid var(--border);">
        @foreach(['overview' => 'Overview', 'timeline' => 'Activity', 'properties' => 'Properties Viewed', 'matched' => 'Matched', 'preferences' => 'Preferences', 'playbook' => 'Retention'] as $key => $label)
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
                <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $propertiesViewed->sum('view_count') }}</div>
                <div class="text-[10px] uppercase" style="color: var(--text-muted);">Total Viewings</div>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $propertiesViewed->count() }}</div>
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

    {{-- Properties Viewed Tab --}}
    <div x-show="activeTab === 'properties'" x-cloak class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        @forelse($propertiesViewed as $pv)
            <a href="{{ route('corex.properties.show', $pv['property_id']) }}" target="_blank"
               class="rounded-md p-4 no-underline hover:opacity-80 transition" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ $pv['address'] }}</div>
                <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">{{ $pv['suburb'] }} · R {{ number_format($pv['price'] ?? 0) }}</div>
                <div class="flex items-center justify-between mt-2 text-[10px]" style="color: var(--text-muted);">
                    <span>{{ $pv['view_count'] }} viewing(s)</span>
                    <span>Last: {{ $pv['last_viewed_at'] ? \Carbon\Carbon::parse($pv['last_viewed_at'])->diffForHumans() : '—' }}</span>
                </div>
            </a>
        @empty
            <p class="col-span-full text-sm py-8 text-center" style="color: var(--text-muted);">No properties viewed yet.</p>
        @endforelse
    </div>

    {{-- Matched Properties Tab --}}
    <div x-show="activeTab === 'matched'" x-cloak class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        @forelse($matched as $mp)
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ $mp['address'] }}</span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded font-bold" style="background: {{ $mp['match_score'] >= 90 ? '#10b981' : ($mp['match_score'] >= 75 ? '#f59e0b' : '#ef4444') }}20; color: {{ $mp['match_score'] >= 90 ? '#10b981' : ($mp['match_score'] >= 75 ? '#f59e0b' : '#ef4444') }};">{{ $mp['match_score'] }}%</span>
                </div>
                <div class="text-[10px]" style="color: var(--text-muted);">{{ $mp['suburb'] }} · R {{ number_format($mp['price'] ?? 0) }} · {{ $mp['days_on_market'] ?? '?' }}d</div>
                <div class="mt-2">
                    <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $buyer->id, 'prefill_class' => 'viewing']) }}"
                       class="text-[10px] font-medium no-underline" style="color: #00d4aa;">Schedule Viewing →</a>
                </div>
            </div>
        @empty
            <p class="col-span-full text-sm py-8 text-center" style="color: var(--text-muted);">No matching properties found. Update preferences to improve matches.</p>
        @endforelse
    </div>

    {{-- Preferences Tab --}}
    <div x-show="activeTab === 'preferences'" x-cloak class="space-y-4">
        {{-- Auto-derived patterns (read-only) --}}
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

        {{-- Stated Preferences (editable) --}}
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Stated Preferences (Agent Input)</h3>
            <form method="POST" action="{{ route('command-center.buyers.preferences', $buyer) }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Budget Min (R)</label>
                        <input type="number" name="budget_min" value="{{ $statedPrefs->budget_min ?? '' }}" placeholder="e.g. 1500000"
                               class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Budget Max (R)</label>
                        <input type="number" name="budget_max" value="{{ $statedPrefs->budget_max ?? '' }}" placeholder="e.g. 3000000"
                               class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Bedrooms Min</label>
                        <input type="number" name="bedrooms_min" value="{{ $statedPrefs->bedrooms_min ?? '' }}" min="0" max="20"
                               class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Bedrooms Max</label>
                        <input type="number" name="bedrooms_max" value="{{ $statedPrefs->bedrooms_max ?? '' }}" min="0" max="20"
                               class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Preferred Areas (comma-separated)</label>
                    @php $prefAreas = json_decode($statedPrefs->preferred_areas ?? '[]', true); @endphp
                    <input type="text" name="preferred_areas[]" value="{{ implode(', ', $prefAreas) }}" placeholder="e.g. Margate, Uvongo, Shelly Beach"
                           class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                {{-- Pre-approval Status --}}
                <div class="pt-3 mt-2" style="border-top: 1px solid var(--border);">
                    <h4 class="text-[10px] font-semibold uppercase tracking-wider mb-2" style="color: var(--text-muted);">Pre-approval Status</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Pre-approved Amount (R)</label>
                            <input type="number" name="preapproval_amount" value="{{ $statedPrefs->preapproval_amount ?? '' }}" placeholder="e.g. 2500000" step="1000"
                                   class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Expires</label>
                            <input type="date" name="preapproval_expires_at" value="{{ $statedPrefs->preapproval_expires_at ?? '' }}"
                                   class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Institution</label>
                            <select name="preapproval_institution" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="">— Select —</option>
                                @foreach(['Standard Bank', 'Nedbank', 'FNB', 'ABSA', 'Investec', 'Capitec', 'SA Home Loans', 'ooba', 'BetterBond', 'Other'] as $bank)
                                    <option value="{{ $bank }}" {{ ($statedPrefs->preapproval_institution ?? '') === $bank ? 'selected' : '' }}>{{ $bank }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @if(!empty($statedPrefs->preapproval_amount))
                        @php
                            $preExpiry = $statedPrefs->preapproval_expires_at ? \Carbon\Carbon::parse($statedPrefs->preapproval_expires_at) : null;
                            $preExpired = $preExpiry && $preExpiry->isPast();
                            $preExpiring = $preExpiry && !$preExpired && $preExpiry->diffInDays(now()) <= 30;
                        @endphp
                        <div class="mt-2 px-2 py-1.5 rounded text-xs inline-flex items-center gap-1.5"
                             style="{{ $preExpired ? 'background:rgba(239,68,68,0.1);color:#ef4444;' : ($preExpiring ? 'background:rgba(245,158,11,0.1);color:#f59e0b;' : 'background:rgba(16,185,129,0.1);color:#10b981;') }}">
                            <span class="w-1.5 h-1.5 rounded-full" style="{{ $preExpired ? 'background:#ef4444;' : ($preExpiring ? 'background:#f59e0b;' : 'background:#10b981;') }}"></span>
                            Pre-approved R {{ number_format($statedPrefs->preapproval_amount) }}
                            @if($statedPrefs->preapproval_institution) via {{ $statedPrefs->preapproval_institution }}@endif
                            @if($preExpiry) · {{ $preExpired ? 'Expired ' . $preExpiry->format('d M Y') : 'Expires ' . $preExpiry->format('d M Y') }}@endif
                        </div>
                    @endif
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded text-white" style="background: var(--brand-button);">Save Preferences</button>
                </div>
            </form>
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
