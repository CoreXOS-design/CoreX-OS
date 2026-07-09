{{--
    Syndication panel — the FULL control surface for one property:
    per-website toggle/refresh/deactivate, Private Property + Property24
    panels, and the live-preview step.

    Shared by:
      - the property page (show.blade.php) — rendered inline
      - the Properties index — fetched per property via
        GET /api/v1/properties/{property}/syndication-panel and injected
        into the shared modal (Alpine.initTree re-binds the components)

    There is exactly ONE syndication surface in CoreX. Both callers render
    this file, so a change here reaches every screen at once.

    Requires in scope:
      - $property        the property (must exist)
      - synStep          Alpine state on an ancestor: 'main' | 'preview'
    Everything else is derived below, so a caller only has to pass $property.
    Values the caller already computed (show.blade.php does) are reused.
--}}
@php
    $synPpEnabled  = $synPpEnabled  ?? (bool) \App\Models\PerformanceSetting::get('syndication_pp_enabled', 1);
    $synP24Enabled = $synP24Enabled ?? (bool) \App\Models\PerformanceSetting::get('syndication_p24_enabled', 1);

    $websiteKeys = $websiteKeys ?? \App\Models\AgencyApiKey::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
        ->where('agency_id', $property->agency_id)
        ->whereNull('revoked_at')
        ->orderBy('name')->get();

    $websiteState = $websiteState ?? \App\Models\PropertyWebsiteSyndication::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
        ->where('property_id', $property->id)
        ->get()->keyBy('agency_api_key_id');

    // Portal feed readiness — drives the "fields need attention" lists.
    $ppMissingFields  = $ppMissingFields  ?? app(\App\Services\PrivateProperty\PrivatePropertyListingMapper::class)->checkReadiness($property);
    $p24MissingFields = $p24MissingFields ?? app(\App\Services\Syndication\Property24\Property24ListingMapper::class)->checkReadiness($property);
@endphp
                {{-- Step: main --}}
                <div x-show="synStep === 'main'" class="p-4 space-y-4">

                    @if($websiteKeys->isNotEmpty())
                    {{-- Website portals — one panel per agency website (API key). Mirrors the
                         Property24 panel exactly: header card with toggle + status badge, status
                         line, and a View · Refresh · Deactivate action row. Spec §6.5.2 --}}
                    <div class="space-y-3">
                        <p class="text-[0.6875rem] font-bold uppercase tracking-wider" style="color:var(--text-muted);">Websites</p>
                        @foreach($websiteKeys as $wk)
                        @php
                            $wState = $websiteState[$wk->id] ?? null;
                            $wConfig = [
                                'propertyId'  => $property->id,
                                'name'        => $wk->name,
                                'enabled'     => (bool) optional($wState)->enabled,
                                'status'      => optional($wState)->status ?? '',
                                'lastSynced'  => optional(optional($wState)->last_synced_at)->diffForHumans() ?? '',
                                'lastError'   => optional($wState)->last_error ?? '',
                                // Canonical public-website URL for this listing
                                // ({base}/property/{slug}-{id}, resolved by id). The
                                // "View on website" button below renders only while the
                                // listing is active/submitted on the site, so this links
                                // straight to the live property page once it's been sent.
                                'publicUrl'   => $property->public_url,
                                'csrfToken'   => csrf_token(),
                                'urls'        => [
                                    'activate'   => route('corex.properties.website-syndication.activate', [$property, $wk]),
                                    'deactivate' => route('corex.properties.website-syndication.deactivate', [$property, $wk]),
                                    'refresh'    => route('corex.properties.website-syndication.refresh', [$property, $wk]),
                                ],
                            ];
                        @endphp
                        <div x-data="websiteSyndication({{ Js::from($wConfig) }})" @click.stop class="space-y-2 mt-2">
                            {{-- Header card — click toggles active/deactivated (enable = active). --}}
                            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md cursor-pointer"
                                 @click="toggleEnabled()"
                                 :style="enabled ? 'background:color-mix(in srgb, var(--brand-button) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);' : 'background:var(--surface-2); border:1px solid var(--border);'">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" :style="enabled ? 'color:var(--brand-button)' : 'color:var(--text-muted)'">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                    </svg>
                                    <span class="text-xs font-semibold" style="color:var(--text-primary);" x-text="name"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full transition-colors duration-200"
                                         :style="enabled ? 'background:var(--brand-button)' : 'background:var(--border)'"
                                         role="switch" :aria-checked="enabled">
                                        <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                              style="background:#fff; margin-top:2px;"
                                              :style="enabled ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                                    </div>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.6875rem] font-bold uppercase tracking-wide"
                                          :style="statusBadgeStyle()" x-text="statusLabel()"></span>
                                </div>
                            </div>

                            {{-- Status line --}}
                            <div x-show="status && status !== ''" x-cloak class="text-xs px-1" style="color:var(--text-secondary);">
                                <template x-if="status === 'active'"><span x-text="statusLabel()"></span></template>
                                <template x-if="status === 'submitted'"><span>Submitted, awaiting activation...</span></template>
                                <template x-if="status === 'pending'"><span>Ready to submit</span></template>
                                <template x-if="status === 'error'"><span style="color:var(--ds-crimson);" x-text="'Error: ' + lastError"></span></template>
                                <template x-if="status === 'deactivated'"><span style="color:var(--text-muted);">Deactivated</span></template>
                            </div>

                            {{-- Active listing actions: View · Refresh · Deactivate --}}
                            <div x-show="enabled && (status === 'active' || status === 'submitted')" x-cloak class="flex flex-wrap gap-2">
                                <a x-show="publicUrl" :href="publicUrl" target="_blank"
                                   class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold no-underline transition-opacity hover:opacity-85"
                                   style="background:var(--brand-button); color:#fff;">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    View on website
                                </a>
                                <button type="button" @click.stop="post(urls.refresh)" :disabled="loading"
                                        :class="publicUrl ? '' : 'flex-1'"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    <span x-text="loading ? 'Syncing...' : 'Refresh'"></span>
                                </button>
                                <button type="button" @click.stop="post(urls.deactivate)" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Deactivate
                                </button>
                            </div>

                            {{-- Last synced timestamp --}}
                            <div x-show="enabled && lastSynced" x-cloak class="text-[0.6875rem]" style="color:var(--text-muted);">
                                Synced <span x-text="lastSynced"></span>
                            </div>

                            {{-- Toast message --}}
                            <div x-show="message && messageType === 'success'" x-cloak x-transition
                                 class="px-3 py-2 rounded-md text-xs font-medium"
                                 style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                 x-text="message"></div>

                            {{-- Error panel --}}
                            <div x-show="errorMsg" x-cloak class="rounded-md px-2 py-1.5 text-[0.6875rem]" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);" x-text="errorMsg"></div>
                        </div>
                        @endforeach
                    </div>
                    @endif

                    @if($synPpEnabled || $synP24Enabled)
                    {{-- Portal Syndication section --}}
                    <div>
                        <p class="text-[0.6875rem] font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Portal Syndication</p>

                        @if($synPpEnabled)
                        @php
                            $ppConfig = [
                                'propertyId'      => $property->id,
                                'enabled'         => (bool) $property->pp_syndication_enabled,
                                'status'          => $property->pp_syndication_status ?? '',
                                'ppRef'           => $property->pp_ref ?? '',
                                'lastSubmitted'   => $property->pp_last_submitted_at ? $property->pp_last_submitted_at->format('d M Y H:i') : '',
                                'lastError'       => $property->pp_last_error ?? '',
                                'exclusiveDays'   => (int) ($property->pp_exclusive_days ?? 0),
                                'mandateType'     => $property->mandate_type ?? '',
                                'activatedAt'     => $property->pp_activated_at ? $property->pp_activated_at->format('d M Y H:i') : '',
                                'csrfToken'       => csrf_token(),
                                'missingFields'   => $ppMissingFields ?? [],
                                'hideStreetName'  => (bool) ($property->pp_hide_street_name ?? false),
                                'hideStreetNumber'=> (bool) ($property->pp_hide_street_number ?? false),
                                'hideComplexName' => (bool) ($property->pp_hide_complex_name ?? false),
                                'hideUnitNumber'  => (bool) ($property->pp_hide_unit_number ?? false),
                                'youtubeVideoId'  => $property->youtube_video_id ?? '',
                                'matterportId'    => $property->matterport_id ?? '',
                                'ppDelayUntil'    => $property->pp_delay_until ? $property->pp_delay_until->format('d M Y') : '',
                                'ppDelayUntilRaw' => $property->pp_delay_until ? $property->pp_delay_until->toIso8601String() : '',
                                // A.2.1 — single source of truth for the public URL lives on Property.
                                'publicUrl'       => $property->publicListingUrls()['pp'] ?? '',
                            ];
                        @endphp
                        <div x-data="ppSyndication({{ Js::from($ppConfig) }})" @click.stop class="space-y-2 mt-2">

                            {{-- Private Property toggle row --}}
                            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md cursor-pointer"
                                 style="background:var(--surface-2); border:1px solid var(--border);"
                                 @click="toggleEnabled()"
                                 :style="enabled ? 'background:color-mix(in srgb, var(--brand-button) 8%, transparent); border-color:color-mix(in srgb, var(--brand-button) 25%, transparent);' : 'background:var(--surface-2); border-color:var(--border);'">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" :style="enabled ? 'color:var(--brand-button)' : 'color:var(--text-muted)'">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                    </svg>
                                    <span class="text-xs font-semibold" style="color:var(--text-primary);">Private Property</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    {{-- Toggle switch --}}
                                    <div class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full transition-colors duration-200"
                                         :style="enabled ? 'background:var(--brand-button)' : 'background:var(--border)'"
                                         role="switch"
                                         :aria-checked="enabled">
                                        <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                              style="background:#fff; margin-top:2px;"
                                              :style="enabled ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                                    </div>
                                    {{-- Status badge --}}
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.6875rem] font-bold uppercase tracking-wide"
                                          :style="statusBadgeStyle()" x-text="statusLabel()"></span>
                                </div>
                            </div>

                            {{-- Status line --}}
                            <div x-show="status && status !== ''" x-cloak class="text-xs px-1" style="color:var(--text-secondary);">
                                <template x-if="ppRef">
                                    <span>PP Ref: <strong x-text="ppRef" style="color:var(--text-primary);"></strong> &mdash; <span x-text="statusLabel()"></span></span>
                                </template>
                                <template x-if="!ppRef && status === 'submitted'">
                                    <span>Submitted, awaiting activation...</span>
                                </template>
                                <template x-if="!ppRef && status === 'pending'">
                                    <span>Ready to submit</span>
                                </template>
                                <template x-if="status === 'error'">
                                    <span style="color:var(--ds-crimson);" x-text="'Error: ' + lastError"></span>
                                </template>
                                <template x-if="status === 'deactivated'">
                                    <span style="color:var(--text-muted);">Deactivated</span>
                                </template>
                            </div>

                            {{-- PP Exclusive listing warning --}}
                            <div x-show="isPpExclusiveActive()" x-cloak
                                 class="rounded-md px-3 py-2.5 space-y-1"
                                 style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
                                <p class="text-xs font-semibold" style="color:var(--ds-amber);">
                                    PP Exclusive listing — do not publish elsewhere until <span x-text="ppDelayUntil"></span>
                                </p>
                                <p class="text-[0.6875rem]" style="color:var(--ds-amber);">
                                    <span x-text="ppDelayDaysRemaining()"></span> days remaining
                                </p>
                            </div>

                            {{-- Missing fields warning --}}
                            <div x-show="enabled && missingFields.length > 0" x-cloak
                                 class="rounded-md px-3 py-2.5 space-y-1.5"
                                 style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
                                <p class="text-xs font-semibold" style="color:var(--ds-amber);">Cannot submit — missing required fields:</p>
                                <ul class="space-y-0.5 m-0 pl-3" style="list-style:disc;">
                                    <template x-for="(f, idx) in missingFields" :key="idx">
                                        <li class="text-xs" style="color:var(--ds-amber);">
                                            <span x-text="f.label"></span>
                                            <span class="opacity-60" x-text="'(' + f.tab + ' tab)'"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>

                            {{-- Exclusive days auto-calculated from Listed Date → Expiry Date for sole mandates --}}
                            @if(in_array(strtolower($property->mandate_type ?? ''), ['sole', 'sole mandate']) && ($property->listing_type ?? 'sale') === 'sale' && $property->listed_date && $property->expiry_date)
                            <div x-show="enabled" x-cloak class="flex items-center gap-2">
                                <span class="text-xs" style="color:var(--text-secondary);">Exclusive:</span>
                                <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $property->listed_date->diffInDays($property->expiry_date) }} days</span>
                                <span class="text-[0.6875rem]" style="color:var(--text-muted);">({{ $property->listed_date->format('d M') }} – {{ $property->expiry_date->format('d M Y') }})</span>
                            </div>
                            @endif

                            {{-- Submit button — only shown before first successful submission --}}
                            <div x-show="enabled && !ppRef && status !== 'active' && status !== 'submitted'" x-cloak class="flex flex-wrap gap-2">
                                <button type="button"
                                        @click.stop="submitListing()"
                                        :disabled="loading || missingFields.length > 0"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        :style="missingFields.length > 0 ? 'background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border); cursor:not-allowed;' : 'background:var(--brand-button); color:#fff;'"
                                        :class="missingFields.length === 0 ? 'hover:opacity-85' : ''">
                                    <svg x-show="!loading" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                                    <svg x-show="loading" x-cloak class="w-3.5 h-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="loading ? 'Submitting...' : 'Submit to PP'"></span>
                                </button>
                                {{-- Reactivate (for deactivated, no ref yet edge case) --}}
                                <button type="button" x-show="status === 'deactivated'" @click.stop="reactivateListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Reactivate
                                </button>
                            </div>

                            {{-- Active listing actions: View · Refresh · Deactivate --}}
                            <div x-show="enabled && ppRef && (status === 'active' || status === 'submitted')" x-cloak class="flex flex-wrap gap-2">
                                <a :href="ppListingUrl()" target="_blank"
                                   class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold no-underline transition-opacity hover:opacity-85"
                                   style="background:var(--brand-button); color:#fff;">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    View on PP
                                </a>
                                <button type="button" @click.stop="refreshListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    <span x-text="loading ? 'Syncing...' : 'Refresh'"></span>
                                </button>
                                <button type="button" @click.stop="deactivateListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Deactivate
                                </button>
                            </div>

                            {{-- Deactivated listing actions: Reactivate --}}
                            <div x-show="enabled && ppRef && status === 'deactivated'" x-cloak class="flex flex-wrap gap-2">
                                <button type="button" @click.stop="reactivateListing()" :disabled="loading"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Reactivate
                                </button>
                            </div>

                            {{-- Last submitted timestamp --}}
                            <div x-show="lastSubmitted" x-cloak class="text-[0.6875rem]" style="color:var(--text-muted);">
                                Last submitted: <span x-text="lastSubmitted"></span>
                            </div>

                            {{-- Toast message (success only) --}}
                            <div x-show="message && messageType === 'success'" x-cloak
                                 x-transition
                                 class="px-3 py-2 rounded-md text-xs font-medium"
                                 style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                 x-text="message"></div>

                            {{-- Debug error panel --}}
                            <div x-show="showDebug && debugErrors.length > 0" x-cloak
                                 x-transition
                                 class="rounded-md space-y-2"
                                 style="background:color-mix(in srgb, var(--ds-crimson) 6%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); padding:10px 12px;">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-bold" style="color:var(--ds-crimson);">Submission Failed</p>
                                    <button type="button" @click.stop="showDebug = false; debugErrors = []"
                                            class="text-[0.6875rem] px-1.5 py-0.5 rounded"
                                            style="color:var(--text-muted); background:var(--surface-2);">
                                        Dismiss
                                    </button>
                                </div>
                                <ul class="space-y-1 m-0 pl-3" style="list-style:disc;">
                                    <template x-for="(err, i) in debugErrors" :key="i">
                                        <li class="text-xs break-words" style="color:var(--ds-crimson); word-break:break-word;"
                                            x-text="err"></li>
                                    </template>
                                </ul>
                            </div>

                        </div>
                        @endif

                        @if($synP24Enabled)
                        {{-- Property24 Syndication Panel --}}
                        @php
                            $resolvedP24AgencyId    = $property->resolveP24AgencyId();
                            $resolvedP24AgencyLabel = $property->agency?->p24_agency_label;
                            $p24Config = [
                                'propertyId'      => $property->id,
                                'enabled'         => (bool) $property->p24_syndication_enabled,
                                'status'          => $property->p24_syndication_status ?? '',
                                'p24Ref'          => $property->p24_ref ?? '',
                                'lastSubmitted'   => $property->p24_last_submitted_at ? $property->p24_last_submitted_at->format('d M Y H:i') : '',
                                'lastError'       => $property->p24_last_error ?? '',
                                'activatedAt'     => $property->p24_activated_at ? $property->p24_activated_at->format('d M Y H:i') : '',
                                'csrfToken'       => csrf_token(),
                                'isSandbox'       => (bool) config('services.property24_syndication.sandbox'),
                                'suburb'          => $property->suburb ?? '',
                                'city'            => $property->town ?? $property->city ?? '',
                                'province'        => $property->province ?? 'kwazulu-natal',
                                'suburbId'        => $property->pp_suburb_id ? (\App\Models\P24Suburb::find($property->pp_suburb_id)?->p24_id ?? '') : (\App\Models\P24Suburb::lookup($property->suburb ?? '')?->p24_id ?? ''),
                                'listingType'     => strtolower($property->listing_type ?? 'sale'),
                                'missingFields'   => $p24MissingFields ?? [],
                                'ppDelayUntilRaw' => $property->pp_delay_until ? $property->pp_delay_until->toIso8601String() : '',
                                'ppDelayUntil'    => $property->pp_delay_until ? $property->pp_delay_until->format('d M Y') : '',
                                'resolvedP24AgencyId'    => $resolvedP24AgencyId ?? '',
                                'resolvedP24AgencyLabel' => $resolvedP24AgencyLabel ?? '',
                                // A.2.1 — single source of truth for the public URL lives on Property.
                                'publicUrl'              => $property->publicListingUrls()['p24'] ?? '',
                            ];
                        @endphp
                        <div x-data="p24Syndication({{ Js::from($p24Config) }})" @click.stop class="space-y-2 mt-2">
                            {{-- P24 exclusive lock warning --}}
                            <div x-show="isPpExclusiveLocked()" x-cloak
                                 class="rounded-md px-3 py-2 text-xs font-medium"
                                 style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent); color:var(--ds-amber);">
                                Cannot enable P24 syndication during PP exclusive period (until <span x-text="ppDelayUntil"></span>)
                            </div>
                            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md"
                                 style="background:var(--surface-2); border:1px solid var(--border);"
                                 :class="isPpExclusiveLocked() ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'"
                                 @click="!isPpExclusiveLocked() && toggleEnabled()"
                                 :style="enabled ? 'background:color-mix(in srgb, var(--brand-button) 8%, transparent); border-color:color-mix(in srgb, var(--brand-button) 25%, transparent);' : 'background:var(--surface-2); border-color:var(--border);'">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" :style="enabled ? 'color:var(--brand-button)' : 'color:var(--text-muted)'">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                    </svg>
                                    <span class="text-xs font-semibold" style="color:var(--text-primary);">Property24</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full transition-colors duration-200"
                                         :style="enabled ? 'background:var(--brand-button)' : 'background:var(--border)'"
                                         role="switch" :aria-checked="enabled">
                                        <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                              style="background:#fff; margin-top:2px;"
                                              :style="enabled ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                                    </div>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.6875rem] font-bold uppercase tracking-wide"
                                          :style="statusBadgeStyle()" x-text="statusLabel()"></span>
                                </div>
                            </div>
                            {{-- Status line --}}
                            <div x-show="status && status !== ''" x-cloak class="text-xs px-1" style="color:var(--text-secondary);">
                                <template x-if="p24Ref"><span>P24 Ref: <strong x-text="p24Ref" style="color:var(--text-primary);"></strong> &mdash; <span x-text="statusLabel()"></span></span></template>
                                <template x-if="!p24Ref && status === 'submitting'"><span>Syncing to Property24… this can take up to a minute.</span></template>
                                <template x-if="!p24Ref && status === 'submitted'"><span>Submitted, awaiting activation...</span></template>
                                <template x-if="!p24Ref && status === 'pending'"><span>Ready to submit</span></template>
                                <template x-if="status === 'error'"><span style="color:var(--ds-crimson);" x-text="'Error: ' + lastError"></span></template>
                                <template x-if="status === 'deactivated'"><span style="color:var(--text-muted);">Deactivated</span></template>
                            </div>

                            {{-- Deferred-sync note: the listing is LIVE on P24 (has a ref,
                                 status active/submitted) but the last push didn't land
                                 because Property24 was temporarily unavailable. Shown amber
                                 (not the crimson hard-'error' line) so the agent keeps every
                                 recovery button and knows to tap Refresh to re-push. --}}
                            <div x-show="p24Ref && lastError && (status === 'active' || status === 'submitted')" x-cloak
                                 class="text-xs px-1" style="color:var(--ds-amber);" x-text="lastError"></div>

                            <div x-show="enabled && !resolvedP24AgencyId" x-cloak class="text-xs px-1" style="color:var(--ds-amber);">
                                No Property24 agency ID configured on branch or agency.
                            </div>

                            {{-- Missing fields warning --}}
                            <div x-show="enabled && !p24Ref && missingFields.length > 0" x-cloak
                                 class="rounded-md px-3 py-2.5 space-y-1.5"
                                 style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
                                <p class="text-xs font-semibold" style="color:var(--ds-amber);">Cannot submit — missing required fields:</p>
                                <ul class="space-y-0.5 m-0 pl-3" style="list-style:disc;">
                                    <template x-for="(f, idx) in missingFields" :key="idx">
                                        <li class="text-xs" style="color:var(--ds-amber);" x-text="f.label"></li>
                                    </template>
                                </ul>
                            </div>

                            {{-- Submit button — only shown before first successful submission --}}
                            <div x-show="enabled && !p24Ref && status !== 'active' && status !== 'submitted'" x-cloak class="flex flex-wrap gap-2">
                                <button type="button"
                                        @click.stop="submitListing()"
                                        :disabled="loading || missingFields.length > 0"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        :style="missingFields.length > 0 ? 'background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border); cursor:not-allowed;' : 'background:var(--brand-button); color:#fff;'"
                                        :class="missingFields.length === 0 ? 'hover:opacity-85' : ''">
                                    <svg x-show="!loading" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                                    <svg x-show="loading" x-cloak class="w-3.5 h-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="loading ? 'Submitting...' : 'Submit to P24'"></span>
                                </button>
                                {{-- Reactivate (for deactivated, no ref yet edge case) --}}
                                <button type="button" x-show="status === 'deactivated'" @click.stop="reactivateListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Reactivate
                                </button>
                            </div>

                            {{-- Active listing actions: View · Refresh · Deactivate --}}
                            <div x-show="enabled && p24Ref && (status === 'active' || status === 'submitted' || status === 'submitting')" x-cloak class="flex flex-wrap gap-2">
                                <a :href="p24ListingUrl()" target="_blank"
                                   class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold no-underline transition-opacity hover:opacity-85"
                                   style="background:var(--brand-button); color:#fff;">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    View on P24
                                </a>
                                <button type="button" @click.stop="refreshListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    <span x-text="loading ? 'Syncing...' : 'Refresh'"></span>
                                </button>
                                <button type="button" @click.stop="deactivateListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Deactivate
                                </button>
                            </div>

                            {{-- Deactivated listing actions: Reactivate --}}
                            <div x-show="enabled && p24Ref && status === 'deactivated'" x-cloak class="flex flex-wrap gap-2">
                                <button type="button" @click.stop="reactivateListing()" :disabled="loading"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Reactivate
                                </button>
                            </div>

                            {{-- P24 listing number — confirms the property is linked to a
                                 specific P24 listing (so pushes update it, not duplicate). --}}
                            <div x-show="p24Ref" x-cloak class="text-[0.6875rem] flex items-center gap-1.5" style="color:var(--text-muted);">
                                <span>P24 Listing</span>
                                <span class="font-mono font-semibold px-1.5 py-0.5 rounded" style="background:var(--surface-2);color:var(--text-primary);" x-text="'#' + p24Ref"></span>
                            </div>

                            {{-- Last submitted timestamp --}}
                            <div x-show="lastSubmitted" x-cloak class="text-[0.6875rem]" style="color:var(--text-muted);">
                                Last submitted: <span x-text="lastSubmitted"></span>
                            </div>

                            {{-- Toast message --}}
                            <div x-show="message && messageType === 'success'" x-cloak x-transition
                                 class="px-3 py-2 rounded-md text-xs font-medium"
                                 style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                                 x-text="message"></div>

                            {{-- Error panel --}}
                            <div x-show="showDebug && debugErrors.length > 0" x-cloak x-transition
                                 class="rounded-md space-y-2"
                                 style="background:color-mix(in srgb, var(--ds-crimson) 6%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); padding:10px 12px;">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-bold" style="color:var(--ds-crimson);">Submission Failed</p>
                                    <button type="button" @click.stop="showDebug = false; debugErrors = []" class="text-[0.6875rem] px-1.5 py-0.5 rounded" style="color:var(--text-muted); background:var(--surface-2);">Dismiss</button>
                                </div>
                                <ul class="space-y-1 m-0 pl-3" style="list-style:disc;">
                                    <template x-for="(err, i) in debugErrors" :key="i">
                                        <li class="text-xs break-words" style="color:var(--ds-crimson); word-break:break-word;" x-text="err"></li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                        @endif
                    </div>
                    @endif

                    {{-- Live preview — see the listing exactly as the public does.
                         In the panel itself so every caller gets it, not just the
                         property page's sidebar action. --}}
                    <div class="pt-3" style="border-top:1px solid var(--border);">
                        <button type="button" @click.stop="synStep = 'preview'"
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                                onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                            </svg>
                            Live preview
                        </button>
                    </div>
                </div>

                {{-- Step: preview agent choice --}}
                <div x-show="synStep === 'preview'" x-cloak class="p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <button type="button" @click="synStep = 'main'"
                                class="flex-shrink-0 p-0.5 rounded transition-colors"
                                style="color:var(--text-muted);"
                                onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                        </button>
                        <p class="text-xs font-semibold" style="color:var(--text-secondary);">Show contact info for:</p>
                    </div>
                    <a href="{{ route('corex.properties.preview', [$property, \Illuminate\Support\Str::slug($property->title)]) }}?agent=me"
                       target="_blank"
                       class="flex items-center gap-2 px-3 py-2 rounded-md text-xs font-semibold no-underline"
                       style="background:color-mix(in srgb, var(--brand-button) 10%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);"
                       onmouseover="this.style.background='color-mix(in srgb, var(--brand-button) 18%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--brand-button) 10%, transparent)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        Show my info
                    </a>
                    <a href="{{ route('corex.properties.preview', [$property, \Illuminate\Support\Str::slug($property->title)]) }}?agent=listing"
                       target="_blank"
                       class="flex items-center gap-2 px-3 py-2 rounded-md text-xs font-semibold no-underline"
                       style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                       onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='var(--surface-2)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                        Show listing agent info
                    </a>
                </div>
