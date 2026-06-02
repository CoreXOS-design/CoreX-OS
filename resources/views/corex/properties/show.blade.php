@extends('layouts.corex')

@section('corex-content')
@php $isNew = !$property->exists; @endphp
<div class="w-full space-y-4"
     x-data="{ activeTab: '{{ $isNew ? 'info' : $activeTab }}', synOpen: false, synStep: 'main', sbCollapsed: (localStorage.getItem('hfc.propSidebar.collapsed') === '1'), wbReportOpen: false, complianceModalOpen: false, contactRequiredModalOpen: false }"
     @corex:contact-required.window="contactRequiredModalOpen = true"
     @corex:contact-added.window="contactRequiredModalOpen = false; activeTab = 'info';"
     @corex:switch-tab.window="activeTab = $event.detail"
     x-effect="localStorage.setItem('hfc.propSidebar.collapsed', sbCollapsed ? '1' : '0')">

    {{-- Top bar: back + flash --}}
    <div class="flex items-center gap-4 flex-wrap">
        <a href="{{ route('corex.properties.index') }}"
           class="inline-flex items-center gap-1.5 text-sm no-underline flex-shrink-0"
           style="color:var(--text-secondary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Back
        </a>
        @if(session('success'))
        <div class="flex-1 rounded-md border px-4 py-2 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border-color:color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color:var(--ds-green, #059669);">
            {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div class="flex-1 rounded-md border px-4 py-2 text-sm font-medium" style="background:color-mix(in srgb, #dc2626 10%, transparent); border-color:color-mix(in srgb, #dc2626 30%, transparent); color:#dc2626;">
            {{ session('error') }}
        </div>
        @endif
        @if($errors->any())
        <div class="flex-1 rounded-md border px-4 py-2 text-sm" style="background:color-mix(in srgb, #dc2626 10%, transparent); border-color:color-mix(in srgb, #dc2626 30%, transparent); color:#dc2626;">
            {{ $errors->first() }}
        </div>
        @endif
    </div>

    {{-- Readiness bar removed --}}

    {{-- Two-column layout on large screens --}}
    <div class="flex gap-5 items-start" style="min-height:0;">

        {{-- LEFT: sticky property summary panel --}}
        @php
        $thumb = $property->gallery_images_json[0] ?? ($property->dawn_images_json[0] ?? null);
        $statusColors = [
            'active'    => 'var(--ds-green)',
            'draft'     => 'var(--text-muted)',
            'sold'      => 'var(--ds-navy)',
            'withdrawn' => 'var(--ds-amber)',
        ];
        $statusBadgeVariants = [
            'active'    => 'ds-badge-success',
            'draft'     => 'ds-badge-default',
            'sold'      => 'ds-badge-info',
            'withdrawn' => 'ds-badge-warning',
        ];
        $sc = $statusColors[$property->status] ?? 'var(--text-muted)';
        $scBadge = $statusBadgeVariants[$property->status] ?? 'ds-badge-default';
        $sbPpEnabled      = (bool) \App\Models\PerformanceSetting::get('syndication_pp_enabled', 1);
        $sbP24Enabled     = (bool) \App\Models\PerformanceSetting::get('syndication_p24_enabled', 1);
        // Agency Public API — per-(property × website) portals (used by the sidebar
        // summary AND the syndication modal). Spec: agency-public-api.md §6.5.2.
        $websiteKeys = $isNew ? collect() : \App\Models\AgencyApiKey::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $property->agency_id)
            ->whereNull('revoked_at')
            ->orderBy('name')->get();
        $websiteState = $isNew ? collect() : \App\Models\PropertyWebsiteSyndication::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('property_id', $property->id)
            ->get()->keyBy('agency_api_key_id');
        @endphp

        {{-- Collapsed rail --}}
        <aside x-show="sbCollapsed" x-cloak
               class="hidden lg:flex flex-col items-center gap-2 flex-shrink-0 py-2"
               style="width:40px; position:sticky; top:0;">
            <button type="button" @click="sbCollapsed = false"
                    title="Expand sidebar"
                    class="w-8 h-8 rounded-md flex items-center justify-center transition-colors"
                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);"
                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='var(--surface)'">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </button>
        </aside>

        {{-- Expanded sidebar --}}
        <aside x-show="!sbCollapsed"
               class="hidden lg:flex flex-col gap-3 flex-shrink-0" style="width:280px; position:sticky; top:0;">

            {{-- Collapse toggle (above identity strip) --}}
            <div class="flex justify-end">
                <button type="button" @click="sbCollapsed = true"
                        title="Collapse sidebar"
                        class="w-7 h-7 rounded-md flex items-center justify-center transition-colors"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);"
                        onmouseover="this.style.color='var(--text-primary)'; this.style.background='var(--surface-2)'" onmouseout="this.style.color='var(--text-muted)'; this.style.background='var(--surface)'">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                </button>
            </div>

            {{-- Identity strip (compact) --}}
            <div class="rounded-md p-3 flex items-center gap-3" style="background:var(--surface); border:1px solid var(--border);">
                @if($thumb)
                    <img src="{{ $thumb }}" alt="" class="w-12 h-12 rounded object-cover flex-shrink-0">
                @else
                    <div class="w-12 h-12 rounded flex items-center justify-center flex-shrink-0" style="background:var(--surface-2);">
                        <svg class="w-5 h-5" style="color:var(--text-muted);opacity:.4;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
                    </div>
                @endif
                <div class="min-w-0 flex-1">
                    @php
                        $listingTypeLabel = match(strtolower((string) ($property->listing_type ?? 'sale'))) {
                            'rental' => 'For Rent',
                            default  => 'For Sale',
                        };
                        $statusLabel = ucwords(str_replace('_', ' ', (string) ($property->status ?: 'Draft')));
                        $brandPillStyle = 'background:var(--brand-default); color:#fff; border:none;';
                    @endphp
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <span class="text-sm px-2.5 py-1 rounded-full font-semibold" style="{{ $brandPillStyle }}">{{ $listingTypeLabel }}</span>
                        <span class="text-sm px-2.5 py-1 rounded-full font-semibold" style="{{ $brandPillStyle }}">{{ $statusLabel }}</span>
                        @if($property->isPublished())
                            <span class="ds-badge ds-badge-success">Published</span>
                        @endif
                    </div>
                    <div class="text-sm font-bold mt-1 truncate" style="color:var(--text-primary);" title="{{ $property->title }}">{{ $property->title ?: 'New Property' }}</div>
                    @php
                        $sbAddrParts = [];
                        if (!empty($property->unit_number)) $sbAddrParts[] = 'Unit ' . $property->unit_number;
                        if (!empty($property->complex_name)) $sbAddrParts[] = $property->complex_name;
                        if (!empty($property->street_number) && !empty($property->street_name)) {
                            $sbAddrParts[] = $property->street_number . ' ' . $property->street_name;
                        } elseif (!empty($property->street_name)) {
                            $sbAddrParts[] = $property->street_name;
                        } elseif (!empty($property->address)) {
                            $sbAddrParts[] = $property->address;
                        }
                        if (!empty($property->suburb)) $sbAddrParts[] = $property->suburb;
                        if (!empty($property->city) && strtolower($property->city) !== strtolower($property->suburb ?? '')) {
                            $sbAddrParts[] = $property->city;
                        }
                    @endphp
                    @if(count($sbAddrParts))
                    <div class="text-[11px] mt-0.5 leading-snug" style="color:var(--text-muted); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;" title="{{ implode(', ', $sbAddrParts) }}">{{ implode(', ', $sbAddrParts) }}</div>
                    @endif
                </div>
            </div>

            {{-- Action stack --}}
            @if(!$isNew)
            <div class="rounded-md p-3 space-y-2" style="background:var(--surface); border:1px solid var(--border);">
                <p class="text-[0.6875rem] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Actions</p>

                <button type="submit" form="prop-update-form" data-prop-save
                        class="prop-action-btn prop-action-btn-success">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    <span class="prop-save-label">Save Changes</span>
                </button>

                @php
                    $isMarketable = ($readinessReport->snapshotAt !== null) || $readinessReport->ready;
                    $cmpLive    = $readinessReport->snapshotAt !== null;
                    $cmpReady   = $readinessReport->ready && !$cmpLive;
                    $cmpLabel   = $cmpLive ? 'LIVE' : ($cmpReady ? 'READY' : 'BLOCKED');
                    $cmpPillBg  = $cmpLive ? '#10b981' : ($cmpReady ? 'rgba(0,212,170,.18)' : 'rgba(245,158,11,.18)');
                    $cmpPillFg  = $cmpLive ? '#ffffff' : ($cmpReady ? '#047857' : '#b45309');
                @endphp

                <button type="button" @click="complianceModalOpen = true"
                        class="prop-action-btn prop-action-btn-neutral justify-between"
                        title="View compliance gates and go-live status">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z"/></svg>
                        Compliance Status
                    </span>
                    <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded" style="background:{{ $cmpPillBg }}; color:{{ $cmpPillFg }};">{{ $cmpLabel }}</span>
                </button>

                <button type="button"
                        @click="{{ $isMarketable ? "synOpen=true; synStep='main'" : 'complianceModalOpen = true' }}"
                        class="prop-action-btn prop-action-btn-neutral {{ !$isMarketable ? 'opacity-50 cursor-not-allowed' : '' }}"
                        title="{{ !$isMarketable ? 'Marketing blocked — open Compliance Status to resolve' : 'Manage portal syndication' }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z"/></svg>
                    Syndication
                </button>

                <button type="button"
                        @click="{{ $isMarketable ? "synOpen=true; synStep='preview'" : 'complianceModalOpen = true' }}"
                        class="prop-action-btn prop-action-btn-neutral {{ !$isMarketable ? 'opacity-50 cursor-not-allowed' : '' }}"
                        title="{{ !$isMarketable ? 'Marketing blocked — open Compliance Status to resolve' : 'Open public listing preview' }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                    Live Preview
                </button>

                @if($isMarketable)
                <a href="{{ route('corex.properties.ad', $property) }}" class="prop-action-btn prop-action-btn-brand">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Ad Builder
                </a>
                @else
                <button type="button" @click="complianceModalOpen = true"
                        class="prop-action-btn prop-action-btn-brand opacity-50 cursor-not-allowed"
                        title="Marketing blocked — open Compliance Status to resolve">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Ad Builder
                </button>
                @endif

                @if(\Illuminate\Support\Facades\Route::has('corex.properties.marketing.index') && \App\Models\PerformanceSetting::get('marketing_enabled', 1))
                    @if($isMarketable)
                    <a href="{{ route('corex.properties.marketing.index', $property) }}"
                       class="prop-action-btn prop-action-btn-fb"
                       title="Social media marketing">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535"/></svg>
                        Market Property
                    </a>
                    @else
                    <button type="button" @click="complianceModalOpen = true"
                            class="prop-action-btn prop-action-btn-fb opacity-50 cursor-not-allowed"
                            title="Marketing blocked — open Compliance Status to resolve">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535"/></svg>
                        Market Property
                    </button>
                    @endif
                @endif

                @if(auth()->user()->hasPermission('outreach.compose'))
                <a href="{{ route('seller-outreach.entry.from-property', $property) }}"
                   class="prop-action-btn prop-action-btn-neutral"
                   title="Compose a WhatsApp/Email pitch to the seller linked to this property">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/></svg>
                    Pitch Seller
                </a>
                @endif

                {{-- Presentations V2 — one-button generator (Phase 1) + coverage badge + asking-price modal (Phase 2) --}}
                @if(auth()->user()->hasPermission('create_presentations'))
                <div x-data="presentationGenerator({
                        propertyId: {{ $property->id }},
                        coverageUrl: '{{ route('corex.properties.presentation-coverage', $property) }}',
                        generateUrl: '{{ route('corex.properties.generate-presentation', $property) }}',
                        csrf: '{{ csrf_token() }}',
                        hasListedPrice: {{ $property->price ? 'true' : 'false' }},
                        listedPrice: {{ $property->price ? (int) $property->price : 'null' }},
                    })"
                    x-init="loadCoverage()"
                    class="space-y-2">

                    {{-- Coverage badge (loaded async; placeholder shown until coverage resolves) --}}
                    <div class="rounded-md px-2.5 py-2 text-[11px] leading-snug"
                         x-show="coverage"
                         x-cloak
                         :style="badgeStyle">
                        <div class="font-semibold uppercase tracking-wider text-[9px] mb-0.5" x-text="badgeHeading"></div>
                        <div x-text="coverage?.recommendation"></div>
                    </div>
                    <div class="rounded-md px-2.5 py-2 text-[11px]" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);"
                         x-show="!coverage && !coverageError" x-cloak>
                        Checking comp coverage…
                    </div>
                    <div class="rounded-md px-2.5 py-2 text-[11px]" style="background:color-mix(in srgb, #dc2626 10%, transparent); border:1px solid color-mix(in srgb, #dc2626 30%, transparent); color:#dc2626;"
                         x-show="coverageError" x-cloak
                         x-text="coverageError"></div>

                    {{-- Generate button --}}
                    <button type="button"
                            @click="onClickGenerate()"
                            class="prop-action-btn prop-action-btn-brand w-full"
                            :disabled="generating"
                            :class="generating ? 'opacity-60 cursor-wait' : ''"
                            title="Generate a market-analytics presentation pack for this property">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" x-show="!generating">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/>
                        </svg>
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" x-show="generating" x-cloak>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
                        </svg>
                        <span x-text="generating ? 'Generating…' : 'Generate Presentation'"></span>
                    </button>

                    {{-- Asking-price modal (shown when property has no listed price) --}}
                    <template x-teleport="body">
                        <div x-show="modalOpen" x-cloak
                             class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                             x-transition.opacity>
                            <div class="absolute inset-0" style="background:rgba(0,0,0,0.45);" @click="modalOpen = false"></div>
                            <div class="relative rounded-md w-full max-w-md p-5 shadow-xl"
                                 style="background:var(--surface); border:1px solid var(--border);"
                                 @click.stop>
                                <h3 class="text-base font-bold mb-1" style="color:var(--text-primary);">What price are you testing?</h3>
                                <p class="text-xs mb-3" style="color:var(--text-secondary);">
                                    This property has no listed price. Enter a price you want the analysis to test.
                                </p>
                                <div class="mb-2 text-xs" style="color:var(--text-muted);" x-show="suggestedPrice">
                                    Suggestion based on suburb data:
                                    <span class="font-semibold" style="color:var(--text-primary);"
                                          x-text="formatZar(suggestedPrice)"></span>
                                </div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Asking price (ZAR)</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-sm pointer-events-none" style="color:var(--text-muted);">R</span>
                                    <input type="number" min="0" step="1000" x-model.number="askingPrice"
                                           class="w-full rounded-md pl-7 pr-3 py-2 text-sm"
                                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                           placeholder="e.g. 1500000">
                                </div>

                                {{-- Phase 3b — comp scope override at generation time --}}
                                <div class="mt-4 pt-3" style="border-top:1px solid var(--border);">
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-muted);">Comparable scope</label>
                                    <div class="flex items-center gap-3 text-xs">
                                        <label class="flex items-center gap-1.5 cursor-pointer">
                                            <input type="radio" name="comp_scope" value="radius_all" x-model="compScope">
                                            <span style="color:var(--text-primary);">Radius</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer">
                                            <input type="radio" name="comp_scope" value="suburb_only" x-model="compScope">
                                            <span style="color:var(--text-primary);">Suburb only</span>
                                        </label>
                                        <div class="flex items-center gap-1 ml-3" x-show="compScope === 'radius_all'" x-cloak>
                                            <input type="number" min="50" max="5000" step="50" x-model.number="compRadiusM"
                                                   class="w-20 rounded-md px-2 py-1 text-xs"
                                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                            <span class="text-[11px]" style="color:var(--text-muted);">m</span>
                                        </div>
                                    </div>
                                    <p class="text-[11px] mt-1" style="color:var(--text-muted);">Defaults to your agency setting. Override applies to this presentation only.</p>
                                </div>

                                <p class="text-[11px] mt-1" style="color:var(--text-muted);" x-show="modalError" x-text="modalError"></p>

                                {{-- Popup-blocked fallback: the browser blocked the new tab.
                                     We deliberately do NOT navigate the current window (the
                                     prior implementation did, which caused the double-load
                                     bug). The agent clicks this anchor to open the
                                     presentation in a new tab themselves, or re-enables
                                     popups and clicks Generate again. --}}
                                <div x-show="popupBlockedUrl" x-cloak class="mt-3 rounded-md px-3 py-2 text-xs"
                                     style="background:color-mix(in srgb, var(--ds-amber) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent); color:var(--text-primary);">
                                    <div class="font-semibold mb-1">Popup blocked by browser</div>
                                    <a :href="popupBlockedUrl" target="_blank" rel="noopener noreferrer"
                                       class="underline font-semibold"
                                       style="color:var(--brand);"
                                       @click="modalOpen = false; popupBlockedUrl = null;">
                                        Click to open the presentation in a new tab
                                    </a>
                                </div>

                                <div class="mt-4 flex items-center justify-end gap-2">
                                    <button type="button" @click="modalOpen = false; popupBlockedUrl = null;"
                                            class="px-3 py-1.5 text-sm font-medium rounded-md"
                                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                                        Cancel
                                    </button>
                                    <button type="button" @click="submitFromModal()"
                                            :disabled="generating"
                                            class="prop-action-btn prop-action-btn-brand"
                                            :class="generating ? 'opacity-60 cursor-wait' : ''">
                                        <span x-text="generating ? 'Generating…' : 'Generate'"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <script>
                if (typeof window.presentationGenerator !== 'function') {
                    window.presentationGenerator = function(config) {
                        return {
                            coverage: null,
                            coverageError: null,
                            modalOpen: false,
                            modalError: null,
                            askingPrice: null,
                            suggestedPrice: null,
                            generating: false,
                            compScope: 'radius_all',   // Phase 3b — initialised from coverage response
                            compRadiusM: 1000,
                            // When the browser blocks the new-tab popup, the
                            // genuine block is detected (window.open returned
                            // null) and this URL surfaces an inline click-
                            // through anchor inside the modal. The CURRENT
                            // window is NEVER navigated by the handler.
                            popupBlockedUrl: null,

                            get badgeStyle() {
                                const state = this.coverage?.state;
                                const palettes = {
                                    rich:     { bg: 'color-mix(in srgb, var(--ds-green) 10%, transparent)',  border: 'color-mix(in srgb, var(--ds-green) 35%, transparent)',  fg: 'var(--ds-green)' },
                                    moderate: { bg: 'color-mix(in srgb, var(--ds-amber) 12%, transparent)',  border: 'color-mix(in srgb, var(--ds-amber) 35%, transparent)',  fg: 'var(--ds-amber)' },
                                    thin:     { bg: 'color-mix(in srgb, var(--ds-amber) 12%, transparent)',  border: 'color-mix(in srgb, var(--ds-amber) 35%, transparent)',  fg: 'var(--ds-amber)' },
                                    none:     { bg: 'color-mix(in srgb, #dc2626 10%, transparent)',          border: 'color-mix(in srgb, #dc2626 30%, transparent)',          fg: '#dc2626' },
                                };
                                const p = palettes[state] ?? palettes.none;
                                return `background:${p.bg}; border:1px solid ${p.border}; color:${p.fg};`;
                            },
                            get badgeHeading() {
                                const labels = { rich: 'Strong data', moderate: 'Moderate data', thin: 'Thin data', none: 'No data' };
                                return labels[this.coverage?.state] ?? 'Coverage';
                            },
                            formatZar(n) {
                                if (n === null || n === undefined) return '—';
                                return 'R ' + Number(n).toLocaleString('en-ZA');
                            },
                            async loadCoverage() {
                                try {
                                    const r = await fetch(config.coverageUrl, { headers: { 'Accept': 'application/json' } });
                                    if (!r.ok) {
                                        this.coverageError = 'Coverage check failed (' + r.status + ').';
                                        return;
                                    }
                                    this.coverage = await r.json();
                                    // Sync modal defaults with agency-resolved scope.
                                    if (this.coverage?.comp_scope) this.compScope = this.coverage.comp_scope;
                                    if (this.coverage?.comp_radius_m) this.compRadiusM = this.coverage.comp_radius_m;
                                } catch (e) {
                                    this.coverageError = 'Coverage check failed: ' + e.message;
                                }
                            },
                            onClickGenerate() {
                                if (this.generating) return;
                                // Phase 3b — always open the modal so the agent sees the
                                // comp-scope toggle. Pre-fill price from listed_price
                                // when available (one-click intent preserved — agent can
                                // just hit Generate inside the modal without changes).
                                this.suggestedPrice = config.listedPrice || null;
                                this.askingPrice = this.suggestedPrice;
                                this.modalError = null;
                                this.popupBlockedUrl = null;
                                this.modalOpen = true;
                            },
                            submitFromModal() {
                                // Allow blank price when the property has a listed_price —
                                // server falls back to property->price. Negative is always invalid.
                                if (this.askingPrice !== null && this.askingPrice !== '' && this.askingPrice < 0) {
                                    this.modalError = 'Price cannot be negative.';
                                    return;
                                }
                                if (!config.hasListedPrice && (this.askingPrice === null || this.askingPrice === '')) {
                                    this.modalError = 'Please enter a price (this property has no listed price).';
                                    return;
                                }
                                this.fireGenerate(this.askingPrice);
                            },
                            async fireGenerate(askingPrice) {
                                this.generating = true;
                                const body = new FormData();
                                body.append('_token', config.csrf);
                                if (askingPrice !== null && askingPrice !== undefined && askingPrice !== '') {
                                    body.append('asking_price', String(askingPrice));
                                }
                                if (this.compScope) body.append('comp_scope', this.compScope);
                                if (this.compRadiusM) body.append('comp_radius_m', String(this.compRadiusM));
                                try {
                                    const r = await fetch(config.generateUrl, {
                                        method: 'POST',
                                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                        body,
                                    });
                                    const data = await r.json().catch(() => null);
                                    if (!r.ok) {
                                        this.modalError = (data && data.error) || ('Generation failed (' + r.status + ').');
                                        this.generating = false;
                                        return;
                                    }
                                    // Open the review screen in a NEW TAB so the agent
                                    // never loses their place on the property page.
                                    //
                                    // Why no `'noopener'` in the features arg: per the HTML
                                    // spec, `window.open(url, '_blank', 'noopener')` returns
                                    // null even on SUCCESS, which made the `if (!win)`
                                    // popup-block check fire every time and navigate the
                                    // CURRENT window via window.location.href — the
                                    // double-load bug. We keep the noopener security
                                    // property by best-effort clearing `win.opener = null`
                                    // after the open. Modern browsers also imply noopener
                                    // for `target=_blank` cross-origin opens by default.
                                    const reviewUrl = data?.review_url || data?.redirect_url;
                                    if (!reviewUrl) {
                                        this.modalError = 'Generation succeeded but no review URL returned.';
                                        this.generating = false;
                                        return;
                                    }
                                    const win = window.open(reviewUrl, '_blank');
                                    if (win) {
                                        try { win.opener = null; } catch (e) { /* best effort */ }
                                        // Happy path — agent stays on the property; review
                                        // tab is theirs to drive. Close the modal.
                                        this.modalOpen = false;
                                        this.popupBlockedUrl = null;
                                        this.generating = false;
                                    } else {
                                        // Genuine popup block — show an inline click-through
                                        // link inside the modal. Do NOT hijack the current
                                        // window (the previous fallback did, which combined
                                        // with the always-null return made every click a
                                        // double-load). Agent re-enables popups or clicks
                                        // the inline link.
                                        this.popupBlockedUrl = reviewUrl;
                                        this.modalError = null;
                                        this.generating = false;
                                    }
                                } catch (e) {
                                    this.modalError = 'Generation failed: ' + e.message;
                                    this.generating = false;
                                }
                            },
                        };
                    };
                }
                </script>
                @endif

                <form method="POST" action="{{ route('corex.properties.duplicate', $property) }}" onsubmit="return confirm('Duplicate this property?')">
                    @csrf
                    <button type="submit" class="prop-action-btn prop-action-btn-neutral">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75"/></svg>
                        Duplicate
                    </button>
                </form>

                <form method="POST" action="{{ route('corex.properties.destroy', $property) }}" onsubmit="return confirm('Archive this property? It will be soft-deleted and recoverable by admin.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="prop-action-btn prop-action-btn-danger">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                        Archive
                    </button>
                </form>

                @permission('compliance.whistleblow.create')
                <button type="button" @click="wbReportOpen = true" class="prop-action-btn prop-action-btn-neutral">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                    Report Non-Compliance
                </button>
                @endpermission
            </div>
            @endif

            {{-- Readiness panel --}}
            @if(!$isNew)
            @php
                $readinessChecks = [
                    'Title'         => !empty($property->title),
                    'Price'         => !empty($property->price),
                    'Status'        => !empty($property->status),
                    'Suburb'        => !empty($property->suburb),
                    'Description'   => !empty($property->description),
                    'Beds'          => $property->beds > 0,
                    'Baths'         => $property->baths > 0,
                    'Listing Agent' => !empty($property->agent_id),
                    'Photos'        => count($property->allImages()) > 0,
                    'Listed Date'   => !empty($property->listed_date),
                ];
                $readinessTotal = count($readinessChecks);
                $readinessDone  = count(array_filter($readinessChecks));
                $readinessPct   = (int) round(($readinessDone / $readinessTotal) * 100);
                // Spec §1.5 + Strict Rule 3: never red for neutral scores. Use amber for low/mid, green for high.
                $readinessColorVar = $readinessPct >= 80 ? 'var(--ds-green)' : 'var(--ds-amber)';
                $readinessBarClass = $readinessPct >= 80 ? 'ds-bar-green' : 'ds-bar-amber';
                $readinessMissing = array_keys(array_filter($readinessChecks, fn($v) => !$v));
            @endphp
            <div class="rounded-md p-3 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center justify-between">
                    <p class="text-[0.6875rem] font-bold uppercase tracking-wider" style="color:var(--text-muted);">Readiness</p>
                    <span class="text-sm font-extrabold" style="color:{{ $readinessColorVar }};">{{ number_format($readinessPct) }}%</span>
                </div>
                <div class="ds-progress-track">
                    <div class="ds-progress-bar {{ $readinessBarClass }}" style="width:{{ $readinessPct }}%"></div>
                </div>
                @if(count($readinessMissing))
                    <div class="space-y-1">
                        <p class="text-[0.6875rem] font-semibold" style="color:var(--text-muted);">Missing</p>
                        @foreach(array_slice($readinessMissing, 0, 5) as $m)
                            <div class="text-xs flex items-center gap-1.5" style="color:var(--text-secondary);">
                                <span class="w-1 h-1 rounded-full flex-shrink-0" style="background:{{ $readinessColorVar }};"></span>{{ $m }}
                            </div>
                        @endforeach
                        @if(count($readinessMissing) > 5)
                            <div class="text-[0.6875rem]" style="color:var(--text-muted);">+ {{ number_format(count($readinessMissing) - 5) }} more</div>
                        @endif
                    </div>
                @endif

                @php
                    $portals = [];
                    foreach ($websiteKeys as $wk) {
                        $portals[] = [$wk->name, (bool) (optional($websiteState[$wk->id] ?? null)->enabled), []];
                    }
                    if ($sbPpEnabled) {
                        $portals[] = ['Private Property', ($property->pp_syndication_status ?? '') === 'active', $ppMissingFields ?? []];
                    }
                    if ($sbP24Enabled) {
                        $portals[] = ['Property24', ($property->p24_syndication_status ?? '') === 'active', $p24MissingFields ?? []];
                    }
                @endphp
                @if(count($portals))
                <div class="pt-2 space-y-1.5" style="border-top:1px solid var(--border);" x-data="{ openPortal: null }">
                    <p class="text-[0.6875rem] font-semibold" style="color:var(--text-muted);">Portals</p>
                    @foreach($portals as $pIdx => [$pName, $pLive, $pMissingArr])
                        @php
                            $pMissingCount = count($pMissingArr);
                            $pDotColor    = $pLive ? 'var(--ds-green)' : ($pMissingCount > 0 ? 'var(--ds-amber)' : 'var(--text-muted)');
                            $pTextColor   = $pLive ? 'var(--ds-green)' : 'var(--text-muted)';
                            $pStateLabel  = $pLive ? 'Live' : ($pMissingCount > 0 ? number_format($pMissingCount).' fix' : 'Off');
                        @endphp
                        <button type="button"
                                @click="openPortal = openPortal === {{ $pIdx }} ? null : {{ $pIdx }}"
                                class="w-full flex items-center justify-between text-xs px-1 py-0.5 rounded transition-colors"
                                onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'"
                                style="background:transparent; border:0; cursor:pointer;">
                            <span style="color:var(--text-secondary);">{{ $pName }}</span>
                            <span class="flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full" style="background:{{ $pDotColor }};"></span>
                                <span style="color:{{ $pTextColor }};">{{ $pStateLabel }}</span>
                            </span>
                        </button>
                    @endforeach

                    {{-- Click popover — overlays the panel area, click outside or close button to dismiss --}}
                    <template x-teleport="body">
                        <div x-show="openPortal !== null" x-cloak
                             class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                             x-transition.opacity>
                            <div class="absolute inset-0" style="background:rgba(0,0,0,0.45);" @click="openPortal = null"></div>
                            @foreach($portals as $pIdx2 => [$pName2, $pLive2, $pMissingArr2])
                                <div x-show="openPortal === {{ $pIdx2 }}" x-cloak
                                     class="relative rounded-md p-5 w-full max-w-sm"
                                     style="background:var(--surface); border:1px solid var(--border); box-shadow:0 10px 30px rgba(0,0,0,0.18);"
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0 scale-95"
                                     x-transition:enter-end="opacity-100 scale-100">
                                    <div class="flex items-start justify-between gap-3 mb-3">
                                        <div>
                                            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $pName2 }}</div>
                                            <div class="text-xs mt-0.5" style="color:var(--text-muted);">
                                                {{ $pLive2 ? 'Currently live on this portal.' : (count($pMissingArr2) > 0 ? number_format(count($pMissingArr2)).' field(s) need attention before this portal can publish.' : 'Not published. Toggle on from the Syndication panel.') }}
                                            </div>
                                        </div>
                                        <button type="button" @click="openPortal = null"
                                                class="text-xs font-bold rounded p-1"
                                                style="color:var(--text-muted); background:transparent; border:0;">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    @if(count($pMissingArr2))
                                        <div class="rounded-md p-3" style="background:color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
                                            <p class="text-xs font-semibold mb-2" style="color:var(--ds-amber);">Missing fields</p>
                                            <ul class="space-y-1 m-0 pl-0" style="list-style:none;">
                                                @foreach($pMissingArr2 as $f)
                                                    <li class="text-xs flex items-start gap-2" style="color:var(--text-primary);">
                                                        <span class="w-1 h-1 rounded-full mt-1.5 flex-shrink-0" style="background:var(--ds-amber);"></span>
                                                        {{ $f['label'] ?? $f['field'] ?? '' }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @elseif($pLive2)
                                        <div class="rounded-md p-3" style="background:color-mix(in srgb, var(--ds-green) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 25%, transparent);">
                                            <p class="text-xs font-semibold" style="color:var(--ds-green);">All fields complete · listing is live.</p>
                                        </div>
                                    @else
                                        <div class="rounded-md p-3" style="background:var(--surface-2); border:1px solid var(--border);">
                                            <p class="text-xs" style="color:var(--text-secondary);">No missing fields, but this portal is not currently active. Use the Syndication action to enable and publish.</p>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </template>
                </div>
                @endif
            </div>
            @endif

            {{-- Compliance evidence flags panel --}}
            @if(!$isNew && isset($propertyComplianceComplaints) && $propertyComplianceComplaints->count() > 0)
            <div class="rounded-md p-3 space-y-2" style="background:color-mix(in srgb, var(--ds-amber) 6%, var(--surface)); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, var(--border));">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 flex-shrink-0" style="color:var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                    <p class="text-[0.6875rem] font-bold uppercase tracking-wider" style="color:var(--ds-amber);">Compliance Flags &mdash; {{ $propertyComplianceComplaints->count() }} report{{ $propertyComplianceComplaints->count() > 1 ? 's' : '' }}</p>
                </div>
                @foreach($propertyComplianceComplaints as $wbC)
                @php
                    $wbTierBadge = match($wbC->tier) { 'tier_1' => 'ds-badge-warning', 'tier_2' => 'ds-badge-info', 'tier_3' => 'ds-badge-danger', default => '' };
                    $wbTierLabel = match($wbC->tier) { 'tier_1' => 'Paperwork breach', 'tier_2' => 'No FFC displayed', 'tier_3' => 'Unregistered practitioner', default => $wbC->tier };
                    $wbStatusLabel = str_replace('_', ' ', ucfirst($wbC->status));
                @endphp
                <div class="rounded p-2" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-mono font-bold" style="color:var(--text-primary);">HFC-WB-{{ $wbC->id }}</span>
                        <span class="ds-badge {{ $wbTierBadge }}" style="font-size:0.6rem;">{{ $wbTierLabel }}</span>
                    </div>
                    <div class="text-[0.6875rem] mt-1" style="color:var(--text-secondary);">
                        {{ $wbC->created_at->format('d M Y') }} &middot; {{ $wbC->reporter?->name ?? 'Unknown' }} &middot; {{ $wbStatusLabel }}
                    </div>
                    @permission('compliance.whistleblow.view')
                    <a href="{{ route('compliance.whistleblow.show', $wbC) }}" class="text-[0.6875rem] font-semibold no-underline mt-1 inline-block" style="color:var(--brand-default);">View complaint &rarr;</a>
                    @endpermission
                </div>
                @endforeach
            </div>
            @endif

            {{-- Also Marketed By (prospecting matches) --}}
            @if(!$isNew)
            @php
                $prospectMatches = \App\Models\ProspectingListing::where('matched_property_id', $property->id)->whereNull('deleted_at')->orderByDesc('last_seen_at')->get();
            @endphp
            @if($prospectMatches->count() > 0)
            <div class="rounded-md p-3 space-y-2" style="background:var(--surface); border:1px solid var(--border);">
                <p class="text-[0.6875rem] font-bold uppercase tracking-wider" style="color:var(--text-muted);">Also Marketed By ({{ $prospectMatches->count() }})</p>
                @foreach($prospectMatches as $pm)
                <div class="rounded p-2" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-bold" style="color:var(--text-primary);">{{ $pm->agency_name ?: 'Unknown agency' }}</span>
                        <span class="ds-badge ds-badge-muted" style="font-size:0.6rem;">{{ strtoupper($pm->portal_source) }}</span>
                        @if($pm->is_active)
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                        @endif
                    </div>
                    @if($pm->agent_name)
                    <div class="text-[0.6875rem] mt-0.5" style="color:var(--text-secondary);">{{ $pm->agent_name }}</div>
                    @endif
                    <div class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted);">
                        R {{ number_format($pm->price) }} &middot; {{ $pm->last_seen_at?->format('d M Y') }}
                    </div>
                    @if($pm->portal_url)
                    <a href="{{ $pm->portal_url }}" target="_blank" class="text-[0.6875rem] no-underline mt-0.5 inline-block" style="color:var(--brand-default);">View listing &rarr;</a>
                    @endif
                </div>
                @endforeach
            </div>
            @endif
            @endif

        </aside>

        {{-- RIGHT: tabs --}}
        <div class="flex-1 min-w-0" style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:clip;">

        {{-- Mobile-only header strip --}}
        <div class="lg:hidden p-4" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
            <div class="flex items-start gap-3">
                @if($thumb)
                <img src="{{ $thumb }}" alt="" class="w-14 h-14 rounded-md object-cover flex-shrink-0">
                @endif
                <div class="flex-1 min-w-0">
                    <h1 class="text-base font-extrabold leading-tight" style="color:var(--text-primary);">{{ $property->title ?: 'New Property' }}</h1>
                    <div class="text-base font-bold mt-0.5" style="color:var(--brand-default);">{{ $property->formattedPrice() }}</div>
                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                        @php
                            $listingTypeLabel2 = match(strtolower((string) ($property->listing_type ?? 'sale'))) {
                                'rental' => 'For Rent',
                                default  => 'For Sale',
                            };
                            $statusLabel2 = ucwords(str_replace('_', ' ', (string) ($property->status ?: 'Draft')));
                            $brandPillStyle2 = 'background:var(--brand-default); color:#fff; border:none;';
                        @endphp
                        <span class="text-sm px-2.5 py-1 rounded-full font-semibold" style="{{ $brandPillStyle2 }}">{{ $listingTypeLabel2 }}</span>
                        <span class="text-sm px-2.5 py-1 rounded-full font-semibold" style="{{ $brandPillStyle2 }}">{{ $statusLabel2 }}</span>
                        <span class="text-xs" style="color:var(--text-secondary);">{{ $property->beds }}bd · {{ $property->baths }}ba</span>
                    </div>
                </div>
            </div>
        </div>

    {{-- Syndication modal (triggered from sidebar Action stack) --}}
    @if(!$isNew)
            {{-- Centered modal --}}
            @php
                $synPpEnabled      = (bool) \App\Models\PerformanceSetting::get('syndication_pp_enabled', 1);
                $synP24Enabled     = (bool) \App\Models\PerformanceSetting::get('syndication_p24_enabled', 1);
                // $websiteKeys / $websiteState computed in the sidebar @php block above.
            @endphp
            <template x-teleport="body">
            <div x-show="synOpen" x-cloak
                 class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                 x-transition.opacity>
                {{-- Backdrop --}}
                <div class="absolute inset-0" style="background:rgba(0,0,0,0.55); backdrop-filter:blur(2px);"
                     @click="synOpen = false; synStep = 'main'"></div>

                {{-- Modal card --}}
                <div class="relative rounded-md shadow-2xl"
                     style="width:440px; max-width:95vw; max-height:88vh; overflow-y:auto; background:var(--surface); border:1px solid var(--border);"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100">

                    {{-- Header --}}
                    <div class="flex items-center justify-between px-4 py-3" style="border-bottom:1px solid var(--border);">
                        <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--brand-icon);">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z" />
                            </svg>
                            <span class="text-sm font-semibold" style="color:var(--text-primary);">Syndication</span>
                        </div>
                        <button type="button" @click="synOpen = false; synStep = 'main'"
                                class="p-1 rounded transition-colors"
                                style="color:var(--text-muted);"
                                onmouseover="this.style.color='var(--text-primary)'; this.style.background='var(--surface-2)'"
                                onmouseout="this.style.color='var(--text-muted)'; this.style.background='transparent'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                {{-- Step: main --}}
                <div x-show="synStep === 'main'" class="p-4 space-y-4">

                    @if($websiteKeys->isNotEmpty())
                    {{-- Website portals — one panel per agency website (API key). Submit / Refresh / Deactivate. Spec §6.5.2 --}}
                    <div class="space-y-3">
                        <p class="text-[0.6875rem] font-bold uppercase tracking-wider" style="color:var(--text-muted);">Websites</p>
                        @foreach($websiteKeys as $wk)
                        @php $wState = $websiteState[$wk->id] ?? null; @endphp
                        <div @click.stop class="space-y-2 px-3 py-2 rounded-md"
                             x-data="{
                                enabled: {{ optional($wState)->enabled ? 'true' : 'false' }},
                                lastSynced: @js(optional(optional($wState)->last_synced_at)->diffForHumans()),
                                loading: false,
                                errorMsg: '',
                                flash: '',
                                csrf: '{{ csrf_token() }}',
                                urls: {
                                    activate: '{{ route('corex.properties.website-syndication.activate', [$property, $wk]) }}',
                                    deactivate: '{{ route('corex.properties.website-syndication.deactivate', [$property, $wk]) }}',
                                    refresh: '{{ route('corex.properties.website-syndication.refresh', [$property, $wk]) }}',
                                },
                                async post(url) {
                                    if (this.loading) return;
                                    this.loading = true; this.errorMsg = ''; this.flash = '';
                                    const fd = new FormData(); fd.append('_token', this.csrf);
                                    try {
                                        const r = await fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                                        const j = await r.json().catch(() => ({}));
                                        if (r.ok && j.success) {
                                            this.enabled = j.enabled; this.lastSynced = j.last_synced || this.lastSynced; this.flash = j.message || '';
                                        } else {
                                            this.errorMsg = j.message || ('Request failed (HTTP ' + r.status + ')');
                                        }
                                    } catch (e) { this.errorMsg = e.message || 'Network error'; }
                                    this.loading = false;
                                },
                             }"
                             :style="enabled ? 'background:rgba(34,197,94,0.06); border:1px solid rgba(34,197,94,0.25);' : 'background:var(--surface-2); border:1px solid var(--border);'">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" :style="enabled ? 'color:var(--ds-green)' : 'color:var(--text-muted)'">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                    </svg>
                                    <span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $wk->name }}</span>
                                </div>
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.6875rem] font-bold uppercase tracking-wide"
                                      :style="enabled ? 'background:rgba(34,197,94,0.15); color:var(--ds-green);' : 'background:var(--surface-3); color:var(--text-muted);'"
                                      x-text="enabled ? 'Live' : 'Off'"></span>
                            </div>

                            <div x-show="errorMsg" x-cloak class="rounded-md px-2 py-1.5 text-[0.6875rem]" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);" x-text="errorMsg"></div>
                            <div x-show="flash" x-cloak class="text-[0.6875rem]" style="color:var(--ds-green);" x-text="flash"></div>

                            <div class="flex flex-wrap items-center gap-2">
                                {{-- Submit to website (when off) --}}
                                <button type="button" x-show="!enabled" @click.stop="post(urls.activate)" :disabled="loading"
                                        class="px-3 py-1.5 rounded-md text-xs font-semibold transition-opacity hover:opacity-85"
                                        style="background:var(--ds-green); color:#fff;">
                                    <span x-text="loading ? 'Submitting…' : 'Submit to website'"></span>
                                </button>
                                {{-- Refresh + Deactivate (when live) --}}
                                <button type="button" x-show="enabled" @click.stop="post(urls.refresh)" :disabled="loading"
                                        class="px-3 py-1.5 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(34,197,94,0.10); color:var(--ds-green); border:1px solid rgba(34,197,94,0.25);">
                                    <span x-text="loading ? 'Sending…' : 'Refresh'"></span>
                                </button>
                                <button type="button" x-show="enabled" @click.stop="post(urls.deactivate)" :disabled="loading"
                                        class="px-3 py-1.5 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(239,68,68,0.10); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);">
                                    Deactivate
                                </button>
                                <span x-show="enabled && lastSynced" x-cloak class="text-[0.6875rem]" style="color:var(--text-muted);">Synced <span x-text="lastSynced"></span></span>
                            </div>
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
                        <div x-data="ppSyndication({{ Js::from($ppConfig) }})" @click.stop class="space-y-3">

                            {{-- Private Property toggle row --}}
                            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md cursor-pointer"
                                 style="background:var(--surface-2); border:1px solid var(--border);"
                                 @click="toggleEnabled()"
                                 :style="enabled ? 'background:rgba(0,212,170,0.06); border-color:color-mix(in srgb, var(--brand-icon) 25%, transparent);' : 'background:var(--surface-2); border-color:var(--border);'">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" :style="enabled ? 'color:var(--ds-green)' : 'color:var(--text-muted)'">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                    </svg>
                                    <span class="text-xs font-semibold" style="color:var(--text-primary);">Private Property</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    {{-- Toggle switch --}}
                                    <div class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full transition-colors duration-200"
                                         :style="enabled ? 'background:var(--ds-green)' : 'background:var(--surface-3)'"
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
                                 style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25);">
                                <p class="text-xs font-semibold" style="color:var(--ds-amber);">
                                    PP Exclusive listing — do not publish elsewhere until <span x-text="ppDelayUntil"></span>
                                </p>
                                <p class="text-[0.6875rem]" style="color:#d97706;">
                                    <span x-text="ppDelayDaysRemaining()"></span> days remaining
                                </p>
                            </div>

                            {{-- Missing fields warning --}}
                            <div x-show="enabled && missingFields.length > 0" x-cloak
                                 class="rounded-md px-3 py-2.5 space-y-1.5"
                                 style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25);">
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
                                        :style="missingFields.length > 0 ? 'background:#374151; color:#6b7280; cursor:not-allowed;' : 'background:var(--ds-green); color:#fff;'"
                                        :class="missingFields.length === 0 ? 'hover:opacity-85' : ''">
                                    <svg x-show="!loading" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                                    <svg x-show="loading" x-cloak class="w-3.5 h-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="loading ? 'Submitting...' : 'Submit to PP'"></span>
                                </button>
                                {{-- Reactivate (for deactivated, no ref yet edge case) --}}
                                <button type="button" x-show="status === 'deactivated'" @click.stop="reactivateListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(0,212,170,0.10); color:var(--ds-green); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Reactivate
                                </button>
                            </div>

                            {{-- Active listing actions: View · Refresh · Deactivate --}}
                            <div x-show="enabled && ppRef && (status === 'active' || status === 'submitted')" x-cloak class="flex flex-wrap gap-2">
                                <a :href="ppListingUrl()" target="_blank"
                                   class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold no-underline transition-opacity hover:opacity-85"
                                   style="background:var(--ds-green); color:#fff;">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    View on PP
                                </a>
                                <button type="button" @click.stop="refreshListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(0,212,170,0.10); color:var(--ds-green); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    <span x-text="loading ? 'Syncing...' : 'Refresh'"></span>
                                </button>
                                <button type="button" @click.stop="deactivateListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(239,68,68,0.10); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Deactivate
                                </button>
                            </div>

                            {{-- Deactivated listing actions: Reactivate --}}
                            <div x-show="enabled && ppRef && status === 'deactivated'" x-cloak class="flex flex-wrap gap-2">
                                <button type="button" @click.stop="reactivateListing()" :disabled="loading"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(0,212,170,0.10); color:var(--ds-green); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);"
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
                                 style="background:rgba(0,212,170,0.10); color:var(--ds-green); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);"
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
                                        <li class="text-xs break-words" style="color:#f87171; word-break:break-word;"
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
                        <div x-data="p24Syndication({{ Js::from($p24Config) }})" @click.stop class="space-y-3 mt-2">
                            {{-- P24 exclusive lock warning --}}
                            <div x-show="isPpExclusiveLocked()" x-cloak
                                 class="rounded-md px-3 py-2 text-xs font-medium"
                                 style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25); color:var(--ds-amber);">
                                Cannot enable P24 syndication during PP exclusive period (until <span x-text="ppDelayUntil"></span>)
                            </div>
                            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md"
                                 style="background:var(--surface-2); border:1px solid var(--border);"
                                 :class="isPpExclusiveLocked() ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'"
                                 @click="!isPpExclusiveLocked() && toggleEnabled()"
                                 :style="enabled ? 'background:rgba(59,130,246,0.06); border-color:rgba(59,130,246,0.25);' : 'background:var(--surface-2); border-color:var(--border);'">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" :style="enabled ? 'color:#3b82f6' : 'color:var(--text-muted)'">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                    </svg>
                                    <span class="text-xs font-semibold" style="color:var(--text-primary);">Property24</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full transition-colors duration-200"
                                         :style="enabled ? 'background:#3b82f6' : 'background:var(--surface-3)'"
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
                                <template x-if="!p24Ref && status === 'submitted'"><span>Submitted, awaiting activation...</span></template>
                                <template x-if="!p24Ref && status === 'pending'"><span>Ready to submit</span></template>
                                <template x-if="status === 'error'"><span style="color:var(--ds-crimson);" x-text="'Error: ' + lastError"></span></template>
                                <template x-if="status === 'deactivated'"><span style="color:var(--text-muted);">Deactivated</span></template>
                            </div>

                            <div x-show="enabled && !resolvedP24AgencyId" x-cloak class="text-xs px-1" style="color:var(--ds-amber);">
                                No Property24 agency ID configured on branch or agency.
                            </div>

                            {{-- Missing fields warning --}}
                            <div x-show="enabled && !p24Ref && missingFields.length > 0" x-cloak
                                 class="rounded-md px-3 py-2.5 space-y-1.5"
                                 style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25);">
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
                                        :style="missingFields.length > 0 ? 'background:#374151; color:#6b7280; cursor:not-allowed;' : 'background:#3b82f6; color:#fff;'"
                                        :class="missingFields.length === 0 ? 'hover:opacity-85' : ''">
                                    <svg x-show="!loading" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                                    <svg x-show="loading" x-cloak class="w-3.5 h-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="loading ? 'Submitting...' : 'Submit to P24'"></span>
                                </button>
                                {{-- Reactivate (for deactivated, no ref yet edge case) --}}
                                <button type="button" x-show="status === 'deactivated'" @click.stop="reactivateListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(59,130,246,0.10); color:#3b82f6; border:1px solid rgba(59,130,246,0.25);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Reactivate
                                </button>
                            </div>

                            {{-- Active listing actions: View · Refresh · Deactivate --}}
                            <div x-show="enabled && p24Ref && (status === 'active' || status === 'submitted')" x-cloak class="flex flex-wrap gap-2">
                                <a :href="p24ListingUrl()" target="_blank"
                                   class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold no-underline transition-opacity hover:opacity-85"
                                   style="background:#3b82f6; color:#fff;">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    View on P24
                                </a>
                                <button type="button" @click.stop="refreshListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(59,130,246,0.10); color:#3b82f6; border:1px solid rgba(59,130,246,0.25);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    <span x-text="loading ? 'Syncing...' : 'Refresh'"></span>
                                </button>
                                <button type="button" @click.stop="deactivateListing()" :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(239,68,68,0.10); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Deactivate
                                </button>
                            </div>

                            {{-- Deactivated listing actions: Reactivate --}}
                            <div x-show="enabled && p24Ref && status === 'deactivated'" x-cloak class="flex flex-wrap gap-2">
                                <button type="button" @click.stop="reactivateListing()" :disabled="loading"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(59,130,246,0.10); color:#3b82f6; border:1px solid rgba(59,130,246,0.25);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Reactivate
                                </button>
                            </div>

                            {{-- Last submitted timestamp --}}
                            <div x-show="lastSubmitted" x-cloak class="text-[0.6875rem]" style="color:var(--text-muted);">
                                Last submitted: <span x-text="lastSubmitted"></span>
                            </div>

                            {{-- Toast message --}}
                            <div x-show="message && messageType === 'success'" x-cloak x-transition
                                 class="px-3 py-2 rounded-md text-xs font-medium"
                                 style="background:rgba(59,130,246,0.10); color:#3b82f6; border:1px solid rgba(59,130,246,0.25);"
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
                                        <li class="text-xs break-words" style="color:#f87171; word-break:break-word;" x-text="err"></li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                        @endif
                    </div>
                    @endif
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
                       style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);"
                       onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon) 18%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--brand-icon) 8%, transparent)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        Show my info
                    </a>
                    <a href="{{ route('corex.properties.preview', [$property, \Illuminate\Support\Str::slug($property->title)]) }}?agent=listing"
                       target="_blank"
                       class="flex items-center gap-2 px-3 py-2 rounded-md text-xs font-semibold no-underline"
                       style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                       onmouseover="this.style.background='var(--surface-3,#2a3a4a)'" onmouseout="this.style.background='var(--surface-2)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                        Show listing agent info
                    </a>
                </div>
                </div>{{-- /modal card --}}
            </div>{{-- /fixed inset --}}
            </template>
    @endif

    {{-- Compliance readiness modal (triggered from Actions stack) --}}
    @if(!$isNew)
        <template x-teleport="body">
            <div x-show="complianceModalOpen" x-cloak
                 class="fixed inset-0 z-[9999] flex items-start justify-center p-4 overflow-y-auto"
                 x-transition.opacity>
                <div class="absolute inset-0" style="background:rgba(0,0,0,0.5);" @click="complianceModalOpen = false"></div>
                <div class="relative w-full max-w-2xl mt-12"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100">
                    <button type="button" @click="complianceModalOpen = false"
                            class="absolute top-2 right-2 z-10 rounded p-1"
                            style="color:var(--text-muted); background:var(--surface); border:1px solid var(--border);"
                            title="Close">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                    @include('corex.properties.partials.readiness-panel', ['report' => $readinessReport, 'property' => $property])
                </div>
            </div>
        </template>
    @endif

    {{-- Tab bar (shared) --}}
        <div class="flex overflow-x-auto" style="border-bottom:1px solid var(--border);">
            @foreach([
                ['key'=>'overview',  'label'=>'Overview'],
                ['key'=>'info',      'label'=>'Info'],
                ['key'=>'gallery',   'label'=>'Gallery'],
                ['key'=>'contacts',  'label'=>'Contacts'],
                ['key'=>'notes',     'label'=>'Notes'],
                ['key'=>'history',   'label'=>'History'],
                ['key'=>'drive',        'label'=>'Drive'],
                ['key'=>'intelligence', 'label'=>'Intelligence'],
                ['key'=>'core-matches', 'label'=>'Core Matches'],
            ] as $tab)
            @if($tab['key'] === 'core-matches' && (!\App\Models\PerformanceSetting::get('matches_enabled', 1) || !\App\Models\PerformanceSetting::get('matches_show_on_properties', 1) || !auth()->user()->hasPermission('access_core_matches')))
                @continue
            @endif
            <button type="button"
                    data-prop-tab="{{ $tab['key'] }}"
                    @click="activeTab = '{{ $tab['key'] }}'"
                    :class="activeTab === '{{ $tab['key'] }}' ? 'border-b-2 border-sky-500 bg-sky-500/5' : 'border-b-2 border-transparent'"
                    :style="activeTab === '{{ $tab['key'] }}' ? 'color:var(--brand-icon);' : 'color:var(--text-secondary);'"
                    class="px-6 py-4 text-sm font-semibold whitespace-nowrap flex-shrink-0 transition-colors duration-150 outline-none focus:outline-none"
                    style="background:transparent;">
                {{ $tab['label'] }}
                @if(!$isNew && $tab['key'] === 'contacts' && $property->contacts->count())
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon) 20%, transparent);color:var(--brand-icon);">{{ $property->contacts->count() }}</span>
                @endif
                @if(!$isNew && $tab['key'] === 'notes' && $property->notes->count())
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon) 20%, transparent);color:var(--brand-icon);">{{ $property->notes->count() }}</span>
                @endif
                @if(!$isNew && $tab['key'] === 'drive' && $allDriveDocs->count())
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon) 20%, transparent);color:var(--brand-icon);">{{ $allDriveDocs->count() }}</span>
                @endif
                @if(!$isNew && $tab['key'] === 'core-matches' && $coreMatches->count())
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon) 20%, transparent);color:var(--brand-icon);">{{ $coreMatches->count() }}</span>
                @endif
            </button>
            @endforeach
        </div>

        {{-- ── OVERVIEW TAB ──────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'overview'" x-cloak class="p-6 space-y-6">

            @php
                $coverImage   = ($property->gallery_images_json[0] ?? ($property->dawn_images_json[0] ?? null));
                $ownerRoles   = ['seller', 'landlord', 'owner'];
                $owner        = $property->contacts->first(fn($c) => in_array(strtolower($c->pivot->role ?? ''), $ownerRoles))
                                ?? $property->contacts->first();
                $ownerLabel   = $owner ? ucfirst($owner->pivot->role ?: 'Linked Contact') : 'Owner';
                $ownerName    = $owner ? (trim($owner->full_name ?? '') ?: trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')) ?: ($owner->email ?: $owner->phone ?: 'Unnamed contact')) : null;
                $daysOnMarket = $property->listed_date ? (int) $property->listed_date->diffInDays(now()) : null;
                $descPreview  = \Illuminate\Support\Str::limit(strip_tags($property->description ?? ''), 220);
                $statusColor      = $statusColors[$property->status] ?? 'var(--text-muted)';
                $statusBadgeClass = $statusBadgeVariants[$property->status] ?? 'ds-badge-default';
                $statusLabel      = ucwords(str_replace('_', ' ', $property->status ?: 'draft'));
                $photoCount       = count($property->allImages());
            @endphp

            {{-- ── HERO / LIVE PREVIEW ────────────────────────────────────── --}}
            <div class="rounded-md overflow-hidden" style="background:var(--surface-2); border:1px solid var(--border);">
                <div class="grid grid-cols-1 md:grid-cols-5">
                    {{-- Cover image --}}
                    <div class="md:col-span-2 relative" style="min-height:240px; background:var(--surface);">
                        @if($coverImage)
                            <img src="{{ $coverImage }}" alt="" class="w-full h-full object-cover absolute inset-0">
                        @else
                            <div class="w-full h-full absolute inset-0 flex items-center justify-center" style="color:var(--text-muted);">
                                <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.41a2.25 2.25 0 013.182 0l2.909 2.91m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z"/></svg>
                            </div>
                        @endif
                        <div class="absolute top-3 left-3 flex flex-wrap gap-2">
                            <span class="ds-badge {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
                        </div>
                        <button type="button" @click="activeTab='gallery'" class="prop-photo-chip absolute bottom-3 left-3">
                            {{ number_format($photoCount) }} {{ \Illuminate\Support\Str::plural('photo', $photoCount) }}
                        </button>
                    </div>

                    {{-- Details --}}
                    <div class="md:col-span-3 p-5 flex flex-col gap-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-lg font-bold leading-tight" style="color:var(--text-primary);">{{ $property->title ?: 'Untitled property' }}</div>
                                <div class="text-sm mt-0.5" style="color:var(--text-secondary);">
                                    {{ trim(($property->suburb ?? '') . ($property->city ? ', ' . $property->city : '')) ?: 'No address yet' }}
                                </div>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <div class="text-2xl font-extrabold leading-none" style="color:var(--brand-default);">{{ $property->formattedPrice() }}</div>
                                @if($daysOnMarket !== null)
                                    <div class="text-xs mt-1" style="color:var(--text-muted);">{{ number_format($daysOnMarket) }} days on market</div>
                                @endif
                            </div>
                        </div>

                        {{-- At-a-glance stats strip --}}
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm pt-1" style="color:var(--text-primary);">
                            <span><span class="font-semibold">{{ $property->beds ?: '—' }}</span> <span style="color:var(--text-muted);">Beds</span></span>
                            <span style="color:var(--border);">·</span>
                            <span><span class="font-semibold">{{ $property->baths ?: '—' }}</span> <span style="color:var(--text-muted);">Baths</span></span>
                            <span style="color:var(--border);">·</span>
                            <span><span class="font-semibold">{{ $property->garages ?: '—' }}</span> <span style="color:var(--text-muted);">Garages</span></span>
                            @if($property->size_m2)
                                <span style="color:var(--border);">·</span>
                                <span><span class="font-semibold">{{ number_format($property->size_m2) }} m²</span> <span style="color:var(--text-muted);">Floor</span></span>
                            @endif
                            @if($property->erf_size_m2)
                                <span style="color:var(--border);">·</span>
                                <span><span class="font-semibold">{{ number_format($property->erf_size_m2) }} m²</span> <span style="color:var(--text-muted);">Erf</span></span>
                            @endif
                        </div>

                        {{-- Chips --}}
                        @if($property->property_type || $property->mandate_type || $property->category)
                            <div class="flex flex-wrap gap-1.5">
                                @if($property->property_type)
                                    <span class="ds-badge ds-badge-default">{{ ucwords(str_replace('_', ' ', $property->property_type)) }}</span>
                                @endif
                                @if($property->mandate_type)
                                    <span class="ds-badge ds-badge-default">{{ $property->mandate_type }} Mandate</span>
                                @endif
                                @if($property->category)
                                    <span class="ds-badge ds-badge-default">{{ $property->category }}</span>
                                @endif
                            </div>
                        @endif

                        {{-- Description preview --}}
                        @if($descPreview)
                            <p class="text-xs leading-relaxed pt-1" style="color:var(--text-secondary);">{{ $descPreview }}</p>
                        @endif

                        {{-- Action buttons --}}
                        @if(!$isNew)
                            <div class="flex flex-wrap gap-2 pt-2 mt-auto">
                                <button type="button" @click="activeTab='info'" class="corex-btn-primary">Edit Details</button>
                                <button type="button" @click="activeTab='gallery'" class="corex-btn-outline">Add Photos</button>
                                <button type="button" @click="activeTab='contacts'" class="corex-btn-outline">Contacts</button>
                                @if($property->agent && $property->agent->phone)
                                    <a href="tel:{{ $property->agent->phone }}" class="corex-btn-outline ml-auto">Call Agent</a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ── TWO-COLUMN GRID with row-aligned tops (Activity↔Agent, KeyDates↔LinkedContact) ── --}}
            @php
                $keyDates = array_filter([
                    $property->listed_date  ? ['Listed',   $property->listed_date->format('d M Y')] : null,
                    $property->expiry_date  ? ['Expires',  $property->expiry_date->format('d M Y')] : null,
                    $property->created_at   ? ['Loaded',   $property->created_at->format('d M Y')]  : null,
                    $property->updated_at   ? ['Modified', $property->updated_at->diffForHumans()]  : null,
                ]);
                $upcomingShowdays = $isNew ? collect() : $property->showdays()->where('active', true)->where('end_date', '>=', now())->orderBy('start_date')->take(3)->get();
            @endphp
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-x-6 gap-y-6">

                {{-- Row 1: Recent Activity (cols 1-2) | Listing Agent (col 3) --}}
                @if(isset($activityTimeline) && $activityTimeline->count())
                    <div class="lg:col-span-2">
                        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Recent Activity</h3>
                        <div class="rounded-md overflow-hidden" style="background:var(--surface-2); border:1px solid var(--border);">
                            @foreach($activityTimeline as $i => $event)
                                <div class="flex items-start gap-3 px-4 py-2.5" style="{{ $i > 0 ? 'border-top:1px solid var(--border);' : '' }}">
                                    <div class="w-2 h-2 rounded-full flex-shrink-0 mt-1.5" style="background:{{ $event['color'] }};"></div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs font-medium" style="color:var(--text-primary);">{{ $event['label'] }}</div>
                                        @if($event['detail'])
                                            <div class="text-xs truncate" style="color:var(--text-muted);">{{ $event['detail'] }}</div>
                                        @endif
                                    </div>
                                    <div class="text-xs flex-shrink-0" style="color:var(--text-muted);">
                                        {{ $event['date'] ? $event['date']->diffForHumans() : '' }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($property->agent)
                    <div class="lg:col-start-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Listing Agent</h3>
                        <div class="rounded-md p-4 flex items-center gap-3" style="background:var(--surface-2); border:1px solid var(--border);">
                            @if(!empty($property->agent->profile_photo_url))
                                <img src="{{ $property->agent->profile_photo_url }}" class="w-10 h-10 rounded-full object-cover" alt="">
                            @else
                                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm" style="background:var(--brand-icon); color:#fff;">{{ strtoupper(substr($property->agent->name, 0, 1)) }}</div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold truncate" style="color:var(--text-primary);">{{ $property->agent->name }}</div>
                                @if($property->agent->phone)
                                    <div class="text-xs truncate" style="color:var(--text-muted);">{{ $property->agent->phone }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Phase 3g V2 Part C — Property Location embedded map.
                     Centred on property's GPS (resolved by Phase 3f geocoding),
                     shows sold + active comps within 1km. Falls back to a
                     placeholder when GPS is missing. --}}
                <div class="lg:col-span-3">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Property Location</h3>
                        @if($property->latitude && $property->longitude)
                            <a href="{{ route('corex.map.index') }}?focus={{ $property->latitude }},{{ $property->longitude }}&zoom=17"
                               class="text-xs font-medium" style="color: var(--brand-button); text-decoration: none;">
                                Open full map →
                            </a>
                        @endif
                    </div>
                    @include('corex.map.partials._embed-map', [
                        'containerId'  => 'corex-prop-map-' . $property->id,
                        'centerLat'    => $property->latitude,
                        'centerLng'    => $property->longitude,
                        'radiusM'      => 1000,
                        'subjectTitle' => $property->address ?: ('Property #' . $property->id),
                        'mode'         => 'bounds',
                        'enabledLayers' => ['sold_comps', 'active_listings'],
                        'fullMapUrl'   => $property->latitude
                            ? route('corex.map.index') . '?focus=' . $property->latitude . ',' . $property->longitude . '&zoom=17'
                            : null,
                    ])

                    {{-- Phase 3i — HFC Sales History panel --}}
                    @php
                        $hfcSales = \App\Models\Deal::withoutGlobalScopes()
                            ->where('property_id', $property->id)
                            ->whereNotNull('registration_date')
                            ->where(function ($q) {
                                $q->where('accepted_status', 'R')
                                  ->orWhereNotNull('registration_date');
                            })
                            ->orderByDesc('registration_date')
                            ->limit(10)
                            ->get();
                    @endphp
                    @if($hfcSales->isNotEmpty())
                        <div style="margin-top:14px;padding:14px;background:var(--surface);border:1px solid var(--border);border-left:3px solid #00b594;border-radius:6px;">
                            <h3 class="text-xs font-bold uppercase tracking-wider mb-2" style="color:#00b594;">
                                HFC has sold this property before
                            </h3>
                            <p style="font-size:0.75rem;color:var(--text-muted);margin:0 0 10px 0;">
                                Powerful re-mandate signal — we know this property.
                            </p>
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                @foreach($hfcSales as $s)
                                    <div style="display:grid;grid-template-columns:120px 1fr auto;gap:10px;align-items:center;font-size:0.8125rem;padding:8px 10px;background:var(--surface-2);border-radius:4px;">
                                        <div style="color:var(--text-secondary);font-weight:600;">
                                            {{ $s->registration_date?->format('M Y') ?? '—' }}
                                        </div>
                                        <div style="color:var(--text-secondary);font-size:0.75rem;">
                                            @if($s->seller_name)Seller: {{ \Illuminate\Support\Str::limit($s->seller_name, 40) }}@endif
                                        </div>
                                        <div style="font-weight:600;color:var(--text-primary);">
                                            @if($s->sale_price)
                                                R {{ number_format((int) $s->sale_price) }}
                                            @elseif($s->property_value)
                                                R {{ number_format((float) $s->property_value, 0) }}
                                            @else
                                                —
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Phase 3j — SG Documents panel (server-side SG search + save to drive) --}}
                    <div style="margin-top:14px;">
                        @include('corex.properties.partials._sg-documents-panel', ['property' => $property])
                    </div>
                </div>

                {{-- Row 2: Key Dates (cols 1-2) | Linked Contact (col 3) — headings align since rows share top --}}
                @if(count($keyDates))
                    <div class="lg:col-span-2 lg:col-start-1">
                        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Key Dates</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                            @foreach($keyDates as $d)
                                <div>
                                    <div class="text-xs font-medium" style="color:var(--text-muted);">{{ $d[0] }}</div>
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $d[1] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="lg:col-start-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">{{ $ownerLabel }}</h3>
                    @if($owner)
                        <a href="{{ route('corex.contacts.show', $owner) }}" class="block rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="text-sm font-semibold truncate" style="color:var(--text-primary);">{{ $ownerName }}</div>
                            @if($owner->phone)
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);">{{ $owner->phone }}</div>
                            @endif
                            @if($owner->email)
                                <div class="text-xs truncate" style="color:var(--text-muted);">{{ $owner->email }}</div>
                            @endif
                        </a>
                    @else
                        <button type="button" @click="activeTab='contacts'" class="w-full rounded-md p-4 text-left text-xs" style="background:var(--surface-2); border:1px dashed var(--border); color:var(--text-muted);">
                            No owner linked yet — click to add a seller / landlord
                        </button>
                    @endif
                </div>

                {{-- Row 3: Showdays (col 3 only) --}}
                @if($upcomingShowdays->count())
                    <div class="lg:col-start-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Upcoming Showdays</h3>
                        <div class="space-y-2">
                            @foreach($upcomingShowdays as $sd)
                                <div class="flex items-center gap-3 rounded-md px-3 py-2.5" style="background:var(--surface-2); border:1px solid var(--border);">
                                    <div class="w-10 h-10 rounded-md flex flex-col items-center justify-center flex-shrink-0" style="background:var(--brand-icon); color:#fff;">
                                        <span class="text-xs font-bold leading-none">{{ $sd->start_date->format('d') }}</span>
                                        <span class="text-[0.6875rem] uppercase leading-none mt-0.5">{{ $sd->start_date->format('M') }}</span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs font-semibold" style="color:var(--text-primary);">{{ $sd->start_date->format('l, d M Y') }}</div>
                                        <div class="text-xs" style="color:var(--text-muted);">{{ $sd->start_date->format('H:i') }} – {{ $sd->end_date->format('H:i') }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>


        {{-- ── INFO TAB ───────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'info'" {{ $isNew ? '' : 'x-cloak' }} class="px-4 pb-4">
            <form id="prop-update-form" method="POST" enctype="multipart/form-data"
                  action="@if($isNew){{ route('corex.properties.store') }}@else{{ route('corex.properties.update', $property) }}@endif"
                  class="space-y-0"
                  novalidate
                  data-is-new="{{ $isNew ? '1' : '0' }}"
                  data-contact-count="{{ $isNew ? 0 : $property->contacts->count() }}"
                  @submit="if (!window.coreXPropertyContactGuard($event, $el)) { return; } window.coreXPropDirty && window.coreXPropDirty.clear();"
                  x-data="{
                      info: {
                          identity: true,
                          pricing:  true,
                          property: true,
                          mandate:  true,
                          rental:   '{{ strtolower($property->listing_type ?? '') }}' === 'rental',
                      },
                      toggleAll(state) {
                          for (const k of Object.keys(this.info)) this.info[k] = state;
                      },
                  }">
                @csrf
                @if(!$isNew) @method('PUT') @endif

                {{-- Pre-linked contact from "Create Listing" on contact page --}}
                @if($isNew && isset($preLinkedContact) && $preLinkedContact)
                <input type="hidden" name="pending_contact_ids[]" value="{{ $preLinkedContact->id }}">
                <div class="rounded-md px-4 py-3 flex items-center gap-3" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
                    <span class="text-sm font-medium" style="color:var(--brand-icon);">
                        Linking to: <strong>{{ $preLinkedContact->full_name }}</strong>
                    </span>
                </div>
                @endif

                {{-- ── SECTION: IDENTITY ──────────────────────────────────── --}}
                <section id="sec-identity" class="prop-section">
                    <button type="button" class="prop-section-toggle" @click="info.identity = !info.identity">
                        <h3 class="prop-section-heading"><span class="prop-section-heading-text">Identity</span></h3>
                        <svg class="prop-section-chevron" :class="info.identity ? 'is-open' : ''" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </button>
                    <div x-show="info.identity" x-collapse class="prop-section-body space-y-4">
                        <div>
                            <label class="prop-label">Title <span class="prop-required">*</span></label>
                            <input type="text" name="title" value="{{ old('title', $property->title) }}" required class="prop-input">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label class="prop-label">Property Type <span class="prop-required">*</span></label>
                                <select name="property_type" required class="prop-select prop-field-enum">
                                    <option value="">— None —</option>
                                    @foreach($settingItems['types'] as $item)
                                        <option value="{{ $item->name }}" {{ old('property_type', $property->property_type) === $item->name ? 'selected' : '' }}>{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="prop-label">Category</label>
                                <select name="category" class="prop-select prop-field-enum">
                                    <option value="">— None —</option>
                                    @foreach($settingItems['categories'] as $item)
                                        <option value="{{ $item->name }}" {{ old('category', $property->category) === $item->name ? 'selected' : '' }}>{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            {{-- Build 3 — condition level drives CMA Middle band adjustment.
                                 Optional: a property without a recorded condition gets the
                                 baseline valuation (no adjustment). The agent can also
                                 override per-presentation on the review screen. --}}
                            @php
                                $condLevels = $settingItems['conditionLevels'] ?? collect();
                                // Stale-condition graceful fallback: if the property's stored
                                // condition_level_id is no longer in the active list (agency
                                // disabled or deleted it), surface it with strikethrough so
                                // the agent knows to re-pick.
                                $storedCondId = (int) old('condition_level_id', $property->condition_level_id ?? 0);
                                $staleCond = null;
                                if ($storedCondId && !$condLevels->contains('id', $storedCondId)) {
                                    $staleCond = \App\Models\PropertySettingItem::withoutGlobalScopes()
                                        ->where('id', $storedCondId)->first();
                                }
                            @endphp
                            <div>
                                <label class="prop-label">Condition
                                    <span class="text-[10px] font-medium ml-1" style="color:var(--text-muted);">(optional)</span>
                                </label>
                                <select name="condition_level_id" class="prop-select prop-field-enum">
                                    <option value="">— No condition recorded —</option>
                                    @foreach($condLevels as $level)
                                        <option value="{{ $level->id }}" {{ $storedCondId === (int)$level->id ? 'selected' : '' }}>
                                            {{ $level->name }} ({{ $level->adjustment_pct >= 0 ? '+' : '' }}{{ (float) $level->adjustment_pct }}%)
                                        </option>
                                    @endforeach
                                    @if($staleCond)
                                        {{-- Robustness #7 — agency deleted/disabled the condition.
                                             Keep the row selectable but visually flag it; agent
                                             must re-pick from the active list to save. --}}
                                        <option value="{{ $staleCond->id }}" selected style="text-decoration: line-through; color:#d97706;">
                                            {{ $staleCond->name }} (disabled — please re-select)
                                        </option>
                                    @endif
                                </select>
                                @if($staleCond)
                                    <p class="mt-1 text-xs" style="color:var(--ds-amber, #d97706);">
                                        The stored condition is no longer available — please pick a current one.
                                    </p>
                                @endif
                            </div>
                            <div>
                                <label class="prop-label">Listing Type</label>
                                @if($isNew)
                                    <select name="listing_type" class="prop-select prop-field-enum">
                                        <option value="sale"   {{ old('listing_type', $property->listing_type ?? 'sale') === 'sale'   ? 'selected' : '' }}>For Sale</option>
                                        <option value="rental" {{ old('listing_type', $property->listing_type ?? 'sale') === 'rental' ? 'selected' : '' }}>For Rental</option>
                                    </select>
                                    <p class="mt-1 text-xs" style="color:var(--text-muted);">Locked after first save. To change, duplicate the listing.</p>
                                @else
                                    <input type="hidden" name="listing_type" value="{{ $property->listing_type }}">
                                    <input type="text" value="For {{ ucfirst($property->listing_type) }}" disabled class="prop-input prop-field-enum">
                                    <p class="mt-1 text-xs" style="color:var(--text-muted);">Locked. Duplicate to change.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>
                {{-- ── SECTION: PRICING ─────────────────────────────────── --}}
                <section id="sec-pricing" class="prop-section">
                    <button type="button" class="prop-section-toggle" @click="info.pricing = !info.pricing">
                        <h3 class="prop-section-heading"><span class="prop-section-heading-text">Pricing &amp; Costs</span></h3>
                        <svg class="prop-section-chevron" :class="info.pricing ? 'is-open' : ''" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </button>
                    <div x-show="info.pricing" x-collapse class="prop-section-body space-y-4">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4" x-data="{ showPriceModal: false }">
                            <div class="relative">
                                <label class="prop-label">Price (ZAR) <span class="prop-required">*</span></label>
                                <div class="flex prop-field-money">
                                    <input type="number" name="price" value="{{ old('price', $property->price) }}" required min="0"
                                           class="prop-input"
                                           style="border-top-right-radius:0; border-bottom-right-radius:0; border-right:none;">
                                    <button type="button" @click="showPriceModal = true"
                                            class="px-2 rounded-r-md flex items-center justify-center transition-colors hover:opacity-80"
                                            style="background:var(--brand-button); border:1px solid var(--brand-button);"
                                            title="Pricing details">
                                        <svg class="w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                    </button>
                                </div>
                                @if($property->price_on_application)
                                <span class="text-[0.6875rem] mt-0.5 block font-medium" style="color:var(--brand-icon);">Price on Application</span>
                                @endif
                            </div>
                            <div>
                                <label class="prop-label">Rates &amp; Taxes</label>
                                <input type="number" name="rates_taxes" value="{{ old('rates_taxes', $property->rates_taxes) }}" min="0" placeholder="—" class="prop-input prop-field-money">
                            </div>
                            <div>
                                <label class="prop-label">Levy</label>
                                <input type="number" name="levy" value="{{ old('levy', $property->levy) }}" min="0" placeholder="—" class="prop-input prop-field-money">
                            </div>
                            <div>
                                <label class="prop-label">Special Levy</label>
                                <input type="number" name="special_levy" value="{{ old('special_levy', $property->special_levy) }}" min="0" placeholder="—" class="prop-input prop-field-money">
                            </div>

                            {{-- Price Details Modal --}}
                            <template x-teleport="body">
                                <div x-show="showPriceModal" x-cloak
                                     class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                                     @keydown.escape.window="showPriceModal = false">
                                    <div class="absolute inset-0" style="background:rgba(0,0,0,0.6);" @click="showPriceModal = false"></div>
                                    <div class="relative w-full max-w-lg rounded-lg shadow-xl overflow-hidden"
                                         style="background:var(--surface); border:1px solid var(--border);"
                                         @click.stop>
                                        {{-- Header --}}
                                        <div class="px-5 py-3 flex items-center justify-between" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                                            <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Pricing Details</h3>
                                            <button type="button" @click="showPriceModal = false" class="p-1 rounded-md hover:opacity-70" style="color:var(--text-muted);">
                                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>

                                        {{-- Body --}}
                                        <div class="px-5 py-4 space-y-4 max-h-[70vh] overflow-y-auto">

                                            {{-- Price per Month --}}
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold whitespace-nowrap" style="color:var(--text-secondary);">Price per Month</label>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs" style="color:var(--text-muted);">ZAR</span>
                                                    <span class="text-sm font-bold tabular-nums" style="color:var(--text-primary);">{{ number_format($property->price ?? 0, 0, '.', ',') }}</span>
                                                </div>
                                            </div>

                                            {{-- Price On Application --}}
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold" style="color:var(--text-secondary);">Price On Application</label>
                                                <label class="relative inline-flex items-center cursor-pointer">
                                                    <input type="checkbox" name="price_on_application" value="1"
                                                           {{ old('price_on_application', $property->price_on_application) ? 'checked' : '' }}
                                                           class="sr-only peer">
                                                    <div class="w-9 h-5 rounded-full peer transition-colors"
                                                         style="background:var(--surface-2); border:1px solid var(--border);"
                                                         :class="{ '!bg-[var(--brand-button)]': $el.previousElementSibling.checked }"></div>
                                                    <div class="absolute left-[2px] top-[2px] bg-white w-4 h-4 rounded-full transition-transform peer-checked:translate-x-full shadow-sm"></div>
                                                </label>
                                            </div>

                                            {{-- Has Deposit --}}
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold" style="color:var(--text-secondary);">Has Deposit</label>
                                                <label class="relative inline-flex items-center cursor-pointer">
                                                    <input type="checkbox" name="has_deposit" value="1"
                                                           {{ old('has_deposit', $property->has_deposit) ? 'checked' : '' }}
                                                           class="sr-only peer">
                                                    <div class="w-9 h-5 rounded-full peer transition-colors"
                                                         style="background:var(--surface-2); border:1px solid var(--border);"></div>
                                                    <div class="absolute left-[2px] top-[2px] bg-white w-4 h-4 rounded-full transition-transform peer-checked:translate-x-full shadow-sm"></div>
                                                </label>
                                            </div>

                                            <div style="border-top:1px solid var(--border);"></div>

                                            {{-- Lease Period --}}
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold" style="color:var(--text-secondary);">Lease Period</label>
                                                <input type="text" name="lease_period" value="{{ old('lease_period', $property->lease_period) }}"
                                                       placeholder="e.g. 12 Months"
                                                       class="w-40 rounded-md px-3 py-1.5 text-xs text-right"
                                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>

                                            {{-- Price per m2 (auto-calculated) --}}
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold" style="color:var(--text-secondary);">Price per m&sup2;</label>
                                                <span class="text-xs font-medium tabular-nums" style="color:var(--text-muted);">
                                                    @if($property->price && $property->size_m2 && $property->size_m2 > 0)
                                                        R {{ number_format($property->price / $property->size_m2, 2, '.', ',') }}
                                                        <span class="text-[0.6875rem] opacity-60">(auto)</span>
                                                    @else
                                                        —
                                                    @endif
                                                </span>
                                            </div>

                                            <div style="border-top:1px solid var(--border);"></div>

                                            {{-- Optional pricing rows --}}
                                            @foreach([
                                                ['price_per_day',  'Price per Day',  'optional'],
                                                ['price_per_week', 'Price per Week', 'optional'],
                                                ['price_per_year', 'Price per Year', 'optional'],
                                            ] as [$field, $label, $hint])
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold" style="color:var(--text-secondary);">{{ $label }}</label>
                                                <div class="flex items-center gap-1">
                                                    <input type="number" name="{{ $field }}" value="{{ old($field, $property->$field) }}"
                                                           placeholder="{{ $hint }}" min="0" step="0.01"
                                                           class="w-32 rounded-md px-3 py-1.5 text-xs text-right"
                                                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                                </div>
                                            </div>
                                            @endforeach

                                            <div style="border-top:1px solid var(--border);"></div>

                                            {{-- Lease Type --}}
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold" style="color:var(--text-secondary);">Lease Type</label>
                                                <select name="lease_type" class="w-40 rounded-md px-3 py-1.5 text-xs"
                                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                                    <option value="">— Select —</option>
                                                    @foreach(['N Triple Net', 'Gross', 'Modified Gross', 'Percentage'] as $lt)
                                                    <option value="{{ $lt }}" {{ old('lease_type', $property->lease_type) === $lt ? 'selected' : '' }}>{{ $lt }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            {{-- Gross / Net / Yard --}}
                                            @foreach([
                                                ['gross_price', 'Gross Price', 'optional'],
                                                ['net_price',   'Net Price',   'optional'],
                                                ['yard_price',  'Yard Price',  'optional'],
                                            ] as [$field, $label, $hint])
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold" style="color:var(--text-secondary);">{{ $label }}</label>
                                                <input type="number" name="{{ $field }}" value="{{ old($field, $property->$field) }}"
                                                       placeholder="{{ $hint }}" min="0" step="0.01"
                                                       class="w-32 rounded-md px-3 py-1.5 text-xs text-right"
                                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                            @endforeach

                                            <div style="border-top:1px solid var(--border);"></div>

                                            {{-- Show / Primary Display --}}
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold" style="color:var(--text-secondary);">Show</label>
                                                <select name="primary_price_display" class="w-48 rounded-md px-3 py-1.5 text-xs"
                                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                                    @foreach(['monthly' => 'Monthly Price as Primary', 'daily' => 'Daily Price as Primary', 'weekly' => 'Weekly Price as Primary', 'yearly' => 'Yearly Price as Primary'] as $val => $lbl)
                                                    <option value="{{ $val }}" {{ old('primary_price_display', $property->primary_price_display ?? 'monthly') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>

                                        {{-- Footer --}}
                                        <div class="px-5 py-3 flex justify-end" style="background:var(--surface-2); border-top:1px solid var(--border);">
                                            <button type="button" @click="showPriceModal = false"
                                                    class="px-4 py-2 rounded-md text-xs font-semibold text-white transition-opacity hover:opacity-80"
                                                    style="background:var(--brand-button);">
                                                Done
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </section>

                {{-- ── SECTION: PROPERTY ────────────────────────────────── --}}
                <section id="sec-property" class="prop-section">
                    <button type="button" class="prop-section-toggle" @click="info.property = !info.property">
                        <h3 class="prop-section-heading"><span class="prop-section-heading-text">Property Details</span></h3>
                        <svg class="prop-section-chevron" :class="info.property ? 'is-open' : ''" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </button>
                    <div x-show="info.property" x-collapse class="prop-section-body space-y-5">
                        <div>
                            <p class="prop-subsection-heading">Sizes</p>
                            <div class="flex flex-wrap gap-4">
                                @foreach([['garages','Garages','prop-field-count'],['size_m2','Floor m²','prop-field-m2'],['erf_size_m2','Erf m²','prop-field-m2']] as [$n,$lbl,$cls])
                                    <div>
                                        <label class="prop-label">{{ $lbl }}</label>
                                        <input type="number" name="{{ $n }}" value="{{ old($n, $property->$n ?? '') }}" min="0"
                                               {!! $n === 'garages' ? 'required max=20' : 'placeholder="—"' !!}
                                               class="prop-input {{ $cls }}">
                                    </div>
                                @endforeach
                            </div>
                        </div>

                {{-- Spaces & Features --}}
                @php
                    $spacesData     = $property->spaces_json ?? null;
                    $initSpaces     = $spacesData['spaces']   ?? [];
                    $initFeatures   = $spacesData['features'] ?? new \stdClass();
                @endphp
                <div x-data="spacesAndFeaturesManager(
                    {{ json_encode($initSpaces) }},
                    {{ json_encode($initFeatures) }},
                    {{ (int)($property->beds  ?? 0) }},
                    {{ (int)($property->baths ?? 0) }}
                )">
                    {{-- Hidden form inputs (beds/baths derived from spaces; spaces_json = full data) --}}
                    <input type="hidden" name="beds"        :value="bedsCount">
                    <input type="hidden" name="baths"       :value="bathsCount">
                    <input type="hidden" name="spaces_json" :value="spacesJsonStr">

                    {{-- ── SPACES ────────────────────────────────────────────── --}}
                    <div x-data="{ spacesInfoOpen: false }">
                        <div class="flex items-center mb-1.5">
                            <span class="text-xs font-semibold" style="color:var(--text-secondary);">Spaces:</span>
                            <div class="ml-auto relative">
                                <button type="button"
                                        @click="spacesInfoOpen = !spacesInfoOpen"
                                        title="How Spaces work"
                                        class="w-6 h-6 rounded flex items-center justify-center transition-colors"
                                        style="color:var(--text-muted);"
                                        onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <div x-show="spacesInfoOpen" x-cloak
                                     @click.outside="spacesInfoOpen = false"
                                     class="absolute right-0 top-7 z-20 w-72 rounded-md p-3 shadow-lg"
                                     style="background:var(--surface); border:1px solid var(--border);"
                                     x-transition.opacity>
                                    <p class="text-xs font-bold mb-1.5" style="color:var(--text-primary);">How Spaces work</p>
                                    <p class="text-xs leading-relaxed" style="color:var(--text-secondary);">
                                        Spaces are the rooms and areas of the property — bedrooms, bathrooms, garages, etc. Click a tile to set the count and add per-room features (e.g. en-suite on bedroom 1). Use the <span style="color:var(--text-primary); font-weight:600;">Add</span> tile at the end of the row to add a new space type. Counts on the tiles drive the at-a-glance stats on the Overview tab.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-md" style="border:1px solid var(--border); overflow:hidden;">
                            <div class="flex overflow-x-auto" style="scrollbar-width:thin; scroll-behavior:smooth;">
                                <template x-for="(space, idx) in spaces" :key="space.type">
                                    <button type="button"
                                            @click="openSpace(idx)"
                                            class="flex flex-col items-center justify-center gap-2 px-4 py-4 transition-all cursor-pointer"
                                            style="flex:1 0 110px; border-right:1px solid var(--border);"
                                            :style="(idx === modalSpaceIdx && modalOpen)
                                                ? 'background:color-mix(in srgb, var(--brand-icon) 6%, transparent); border-bottom:2px solid var(--brand-icon);'
                                                : 'background:var(--surface); border-bottom:2px solid transparent;'">
                                        <div class="flex items-center gap-2">
                                            <span class="w-7 h-7 flex items-center justify-center flex-shrink-0"
                                                  :style="(idx === modalSpaceIdx && modalOpen) ? 'color:var(--brand-icon);' : 'color:var(--text-secondary);'"
                                                  x-html="getSpaceIconSvg(space.type)"></span>
                                            <span class="text-xl font-bold tabular-nums leading-none"
                                                  :style="(idx === modalSpaceIdx && modalOpen) ? 'color:var(--brand-icon);' : 'color:var(--text-primary);'"
                                                  x-text="formatCount(space.count)"></span>
                                        </div>
                                        <span class="text-xs font-medium text-center leading-tight"
                                              style="color:var(--text-muted); max-width:100px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                              x-text="space.type"></span>
                                    </button>
                                </template>

                                {{-- + Add tile --}}
                                <button type="button"
                                        @click="addSpaceOpen = true"
                                        class="flex flex-col items-center justify-center gap-2 px-4 py-4 transition-all cursor-pointer"
                                        style="flex:1 0 80px; border-left:1px solid var(--border); background:var(--surface);"
                                        onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon) 4%, transparent)'"
                                        onmouseout="this.style.background='var(--surface)'">
                                    <div class="flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0"
                                              style="border:2px dashed var(--border-hover); color:var(--text-muted);">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                                        </span>
                                    </div>
                                    <span class="text-xs font-medium" style="color:var(--text-muted);">Add</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- ── FEATURES SECTION ──────────────────────────────────── --}}
                    <div class="mt-4" x-data="{ featuresInfoOpen: false }">
                        <div class="flex items-center mb-1.5">
                            <span class="text-xs font-semibold" style="color:var(--text-secondary);">Features:</span>
                            <div class="ml-auto relative">
                                <button type="button"
                                        @click="featuresInfoOpen = !featuresInfoOpen"
                                        title="How Features work"
                                        class="w-6 h-6 rounded flex items-center justify-center transition-colors"
                                        style="color:var(--text-muted);"
                                        onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <div x-show="featuresInfoOpen" x-cloak
                                     @click.outside="featuresInfoOpen = false"
                                     class="absolute right-0 top-7 z-20 w-72 rounded-md p-3 shadow-lg"
                                     style="background:var(--surface); border:1px solid var(--border);"
                                     x-transition.opacity>
                                    <p class="text-xs font-bold mb-1.5" style="color:var(--text-primary);">How Features work</p>
                                    <p class="text-xs leading-relaxed" style="color:var(--text-secondary);">
                                        Features are property-wide amenities — pool, security, fibre, sea view, etc. Pick a category tab (Outdoor, Security, Connectivity…) and click any chip to toggle it on. Selected features appear in the <span style="color:var(--text-primary); font-weight:600;">Feature Summary</span> below and feed into portal listings (P24, Private Property) and the public website. Click a chip in the summary to remove it.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                            <div class="flex" style="background:var(--surface); border-bottom:1px solid var(--border);">
                                <template x-for="[catKey, catDef] in Object.entries(featureCategories)" :key="catKey">
                                    <button type="button"
                                            @click="featureCategoryTab = catKey"
                                            class="relative flex flex-col items-center gap-1 px-4 py-3 transition-all cursor-pointer"
                                            style="flex:1; border-right:1px solid var(--border);"
                                            :style="featureCategoryTab === catKey
                                                ? 'background:color-mix(in srgb, var(--brand-icon) 5%, transparent); border-bottom:2px solid var(--brand-icon);'
                                                : 'background:var(--surface); border-bottom:2px solid transparent;'">
                                        <span class="w-7 h-7 flex items-center justify-center" x-html="getFeatureCatIconSvg(catKey)"></span>
                                        <span class="text-xs font-medium"
                                              :style="featureCategoryTab === catKey ? 'color:var(--brand-icon);' : 'color:var(--text-secondary);'"
                                              x-text="catDef.label"></span>
                                        <span x-show="features[catKey] && features[catKey].length > 0"
                                              class="absolute top-1 right-1.5 text-[0.6875rem] px-1 rounded-full font-bold leading-tight"
                                              style="background:color-mix(in srgb, var(--brand-icon) 18%, transparent); color:var(--brand-icon); min-width:14px; text-align:center;"
                                              x-text="features[catKey].length"></span>
                                    </button>
                                </template>
                                <button type="button" disabled
                                        class="flex flex-col items-center gap-1 px-4 py-3 opacity-40 cursor-not-allowed"
                                        style="flex:1; background:var(--surface);">
                                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="color:var(--text-muted);"><circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8v8M8 12h8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span class="text-xs font-medium" style="color:var(--text-muted);">Add</span>
                                </button>
                            </div>

                            <div class="flex flex-wrap gap-1.5 p-3" style="background:var(--surface-2); min-height:50px;">
                                <template x-for="feat in featureCategories[featureCategoryTab].features" :key="feat">
                                    <button type="button"
                                            @click="toggleGlobalFeature(featureCategoryTab, feat)"
                                            class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-full transition-colors"
                                            :style="features[featureCategoryTab] && features[featureCategoryTab].includes(feat)
                                                ? 'background:color-mix(in srgb, var(--brand-icon) 15%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 35%, transparent);'
                                                : 'background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);'">
                                        <span x-text="feat"></span>
                                        <svg x-show="features[featureCategoryTab] && features[featureCategoryTab].includes(feat)"
                                             xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- ── FEATURE SUMMARY ──────────────────────────────────── --}}
                    <div class="mt-4">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold" style="color:var(--text-secondary);">Feature Summary:</span>
                            <button type="button" title="Auto-generated from all selected spaces and feature categories" class="w-6 h-6 rounded flex items-center justify-center transition-colors" style="color:var(--text-muted);" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8v4l2 2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-1.5 rounded-md p-3 min-h-[44px]" style="border:1px solid var(--border); background:var(--surface-2);">
                            <span x-show="allFeaturesFlat.length === 0" class="text-xs italic" style="color:var(--text-muted);">No features selected yet</span>
                            <template x-for="feat in allFeaturesFlat" :key="feat">
                                <button type="button"
                                        @click="removeFeatureByName(feat)"
                                        class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-full font-medium transition-opacity hover:opacity-75 cursor-pointer"
                                        style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                                    <span x-text="feat"></span>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- ── SPACE DETAIL MODAL ────────────────────────────────── --}}
                    <div x-show="modalOpen" x-cloak
                         class="fixed inset-0 z-[9999] flex items-end sm:items-center justify-center"
                         style="background:rgba(0,0,0,0.6);">
                        <div class="absolute inset-0" @click="featurePickerOpen ? featurePickerOpen=false : closeModal()"></div>
                        <div class="relative w-full sm:w-[500px] max-h-[90vh] flex flex-col rounded-t-2xl sm:rounded-md shadow-2xl"
                             style="background:var(--surface); border:1px solid var(--border);">

                            {{-- Modal header --}}
                            <div class="flex items-center justify-between px-5 py-4" style="border-bottom:1px solid var(--border);">
                                <h3 class="text-base font-bold" style="color:var(--text-primary);"
                                    x-text="currentSpace ? currentSpace.type : ''"></h3>
                                <button type="button" @click="closeModal()"
                                        class="w-7 h-7 rounded-md flex items-center justify-center"
                                        style="color:var(--text-muted);"
                                        onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            {{-- Modal body (scrollable) --}}
                            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-5">
                                <template x-if="currentSpace">
                                    <div class="space-y-5">

                                        {{-- Count stepper --}}
                                        <div>
                                            <label class="block text-xs font-semibold mb-2" style="color:var(--text-secondary);"
                                                   x-text="'Number of ' + currentSpace.type + 's'"></label>
                                            <div class="flex items-center gap-3 flex-wrap">
                                                <button type="button" @click="decrementCount(modalSpaceIdx)"
                                                        class="w-9 h-9 rounded-md flex items-center justify-center text-lg font-bold"
                                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">−</button>
                                                <span class="text-2xl font-bold w-14 text-center" style="color:var(--text-primary);"
                                                      x-text="formatCount(currentSpace.count)"></span>
                                                <button type="button" @click="incrementCount(modalSpaceIdx)"
                                                        class="w-9 h-9 rounded-md flex items-center justify-center text-lg font-bold"
                                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">+</button>
                                                <template x-if="supportsHalf(currentSpace.type)">
                                                    <button type="button" @click="toggleHalf(modalSpaceIdx)"
                                                            class="text-xs px-2.5 py-1.5 rounded-md font-semibold"
                                                            style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);">
                                                        ½ Toggle
                                                    </button>
                                                </template>
                                            </div>
                                            <template x-if="supportsHalf(currentSpace.type)">
                                                <p class="text-xs mt-1.5" style="color:var(--text-muted);">
                                                    Supports half units (e.g. ½ bathroom = toilet only). Click ½ Toggle to add/remove.
                                                </p>
                                            </template>
                                        </div>

                                        {{-- Feature picker panel (replaces body when open) --}}
                                        <template x-if="featurePickerOpen">
                                            <div class="space-y-4">
                                                <div class="flex items-center gap-2">
                                                    <button type="button" @click="featurePickerOpen = false"
                                                            class="flex items-center gap-1 text-xs font-semibold" style="color:var(--brand-icon);">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                                                        Back
                                                    </button>
                                                    <span class="text-xs" style="color:var(--text-muted);"
                                                          x-text="featurePickerTarget === 'all'
                                                              ? 'Features for all ' + currentSpace.type + 's'
                                                              : 'Features for ' + (currentSpace.units[featurePickerTarget] ? currentSpace.units[featurePickerTarget].label : '')"></span>
                                                </div>
                                                <template x-for="[group, items] in Object.entries(getSpaceFeatures(currentSpace.type))" :key="group">
                                                    <div>
                                                        <h4 class="text-[0.6875rem] font-bold uppercase tracking-wider mb-1.5" style="color:var(--text-muted);" x-text="group"></h4>
                                                        <div class="flex flex-wrap gap-1.5">
                                                            <template x-for="item in items" :key="item">
                                                                <button type="button"
                                                                        @click="togglePickerFeature(item)"
                                                                        class="text-xs px-2.5 py-1 rounded-full transition-colors"
                                                                        :style="isPickerFeatureSelected(item)
                                                                            ? 'background:color-mix(in srgb, var(--brand-icon) 15%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 40%, transparent);'
                                                                            : 'background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);'"
                                                                        x-text="item">
                                                                </button>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        {{-- Normal modal body (hidden when picker open) --}}
                                        <template x-if="!featurePickerOpen">
                                            <div class="space-y-5">

                                                {{-- Features of all [space]s --}}
                                                <div>
                                                    <div class="flex items-center justify-between mb-2">
                                                        <label class="text-xs font-semibold" style="color:var(--text-secondary);"
                                                               x-text="'Features of all ' + currentSpace.type + 's'"></label>
                                                        <button type="button" @click="openFeaturePicker('all')"
                                                                class="text-xs px-2.5 py-1 rounded-md font-semibold"
                                                                style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);">
                                                            + Add Feature
                                                        </button>
                                                    </div>
                                                    <div class="flex flex-wrap gap-1.5 rounded-md p-2.5 min-h-[36px]"
                                                         style="background:var(--surface-2); border:1px solid var(--border);">
                                                        <template x-for="(feat, fi) in currentSpace.featuresAll" :key="feat + fi">
                                                            <span class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-full"
                                                                  style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                                                                <span x-text="feat"></span>
                                                                <button type="button" @click="removeSpaceFeature(modalSpaceIdx,'all',fi)"
                                                                        class="font-bold hover:opacity-70 leading-none">×</button>
                                                            </span>
                                                        </template>
                                                        <span x-show="currentSpace.featuresAll.length === 0"
                                                              class="text-xs italic" style="color:var(--text-muted);">None selected</span>
                                                    </div>
                                                </div>

                                                {{-- Description of all [space]s --}}
                                                <div>
                                                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);"
                                                           x-text="'Description of all ' + currentSpace.type + 's'"></label>
                                                    <textarea x-model="currentSpace.descriptionAll" rows="2"
                                                              class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                                              style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                                              :placeholder="'Optional description for all ' + currentSpace.type + 's'"></textarea>
                                                </div>

                                                {{-- Individual units --}}
                                                <div x-show="currentSpace.units && currentSpace.units.length > 0" class="space-y-2">
                                                    <label class="block text-xs font-semibold" style="color:var(--text-secondary);"
                                                           x-text="'Individual ' + currentSpace.type + 's'"></label>
                                                    <template x-for="(unit, ui) in currentSpace.units" :key="ui">
                                                        <div class="rounded-md p-3 space-y-2"
                                                             style="background:var(--surface-2); border:1px solid var(--border);">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <input type="text" x-model="unit.label"
                                                                       class="text-xs font-semibold rounded px-2 py-0.5 flex-1 max-w-[140px]"
                                                                       style="background:transparent; border:1px solid var(--border); color:var(--text-primary);">
                                                                <button type="button" @click="openFeaturePicker(ui)"
                                                                        class="text-xs px-2 py-0.5 rounded-md flex-shrink-0"
                                                                        style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                                                                    + Feature
                                                                </button>
                                                                <template x-if="ui < currentSpace.units.length - 1">
                                                                    <button type="button" @click="copyFeaturesDown(modalSpaceIdx, ui)"
                                                                            title="Copy these features to all units below"
                                                                            class="text-xs px-2 py-0.5 rounded-md flex-shrink-0"
                                                                            style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                                                                        Copy ↓
                                                                    </button>
                                                                </template>
                                                            </div>
                                                            <div class="flex flex-wrap gap-1">
                                                                <template x-for="(feat, fi) in unit.features" :key="feat + fi">
                                                                    <span class="inline-flex items-center gap-0.5 text-xs px-2 py-0.5 rounded-full"
                                                                          style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                                                                        <span x-text="feat"></span>
                                                                        <button type="button" @click="removeSpaceFeature(modalSpaceIdx,ui,fi)"
                                                                                class="font-bold hover:opacity-70 leading-none text-xs">×</button>
                                                                    </span>
                                                                </template>
                                                                <span x-show="unit.features.length === 0"
                                                                      class="text-xs italic" style="color:var(--text-muted);">No features</span>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>

                                            </div>
                                        </template>

                                    </div>
                                </template>
                            </div>

                            {{-- Modal footer --}}
                            <div class="flex items-center justify-between px-5 py-4" style="border-top:1px solid var(--border);">
                                <button type="button" @click="deleteSpace(modalSpaceIdx)"
                                        class="flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-md transition-colors"
                                        style="color:var(--ds-crimson);"
                                        onmouseover="this.style.background='color-mix(in srgb, var(--ds-crimson) 8%, transparent)'" onmouseout="this.style.background='transparent'">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.021-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                    Remove Space
                                </button>
                                <button type="button" @click="featurePickerOpen ? featurePickerOpen=false : closeModal()"
                                        class="flex items-center gap-1.5 text-sm font-semibold text-white px-4 py-2 rounded-md"
                                        style="background:var(--ds-green);">
                                    <template x-if="featurePickerOpen">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                                    </template>
                                    <template x-if="!featurePickerOpen">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                    </template>
                                    <span x-text="featurePickerOpen ? 'Back' : 'Done'"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- ── ADD SPACE MODAL ───────────────────────────────────── --}}
                    <div x-show="addSpaceOpen" x-cloak
                         class="fixed inset-0 z-[9999] flex items-end sm:items-center justify-center"
                         style="background:rgba(0,0,0,0.6);">
                        <div class="absolute inset-0" @click="addSpaceOpen = false"></div>
                        <div class="relative w-full sm:w-[560px] max-h-[90vh] flex flex-col rounded-t-2xl sm:rounded-md shadow-2xl"
                             style="background:var(--surface); border:1px solid var(--border);">
                            <div class="flex items-center justify-between px-5 py-4" style="border-bottom:1px solid var(--border);">
                                <h3 class="text-base font-bold" style="color:var(--text-primary);">Add a Space</h3>
                                <button type="button" @click="addSpaceOpen = false"
                                        class="w-7 h-7 rounded-md flex items-center justify-center"
                                        style="color:var(--text-muted);"
                                        onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="flex-1 overflow-y-auto p-4">
                                <div class="grid grid-cols-4 sm:grid-cols-5 gap-2">
                                    <template x-for="type in availableSpaceTypes" :key="type">
                                        <button type="button"
                                                @click="addSpace(type)"
                                                class="flex flex-col items-center gap-1 p-2.5 rounded-md transition-colors"
                                                :style="hasSpace(type)
                                                    ? 'background:color-mix(in srgb, var(--brand-icon) 10%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent); color:var(--brand-icon);'
                                                    : 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);'">
                                            <span class="w-5 h-5 flex items-center justify-center flex-shrink-0" x-html="getSpaceIconSvg(type)"></span>
                                            <span class="text-[0.6875rem] text-center leading-tight" x-text="type"></span>
                                            <span x-show="hasSpace(type)" class="text-[0.6875rem] font-bold" style="color:var(--brand-icon);">Added ✓</span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>{{-- /spacesAndFeaturesManager --}}

                {{-- Description / marketing copy (still inside Property section) --}}
                <div>
                    <p class="prop-subsection-heading">Description</p>
                    <textarea name="description" rows="6" class="prop-textarea" placeholder="Full property description...">{{ old('description', $property->description) }}</textarea>
                </div>

                {{-- Property Address --}}
                <div x-data="propertyAddress({{ Js::from([
                    'streetNumber' => old('street_number', $property->street_number ?? ''),
                    'streetName' => old('street_name', $property->street_name ?? ''),
                    'complexName' => old('complex_name', $property->complex_name ?? ''),
                    'unitNumber' => old('unit_number', $property->unit_number ?? ''),
                    'suburb' => old('suburb', $property->suburb ?? ''),
                    'city' => old('city', $property->city ?? ''),
                    'province' => old('province', $property->province ?? ''),
                    'hideStreetName' => (bool) old('pp_hide_street_name', $property->pp_hide_street_name ?? false),
                    'hideStreetNumber' => (bool) old('pp_hide_street_number', $property->pp_hide_street_number ?? false),
                    'hideComplexName' => (bool) old('pp_hide_complex_name', $property->pp_hide_complex_name ?? false),
                    'hideUnitNumber' => (bool) old('pp_hide_unit_number', $property->pp_hide_unit_number ?? false),
                ]) }})">
                    <p class="prop-subsection-heading">Address</p>

                    {{-- Summary rows — Internal & Public --}}
                    <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                        {{-- Internal row --}}
                        <div class="flex items-center cursor-pointer transition-colors"
                             style="border-bottom:1px solid var(--border);"
                             @click="openModal = 'internal'"
                             @mouseenter="$el.style.background='var(--surface-2)'" @mouseleave="$el.style.background=''">
                            <div class="px-3 py-2.5 flex items-center gap-1.5 flex-shrink-0" style="width:100px;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" style="color:var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                                <span class="text-xs font-semibold" style="color:var(--ds-amber);">Internal</span>
                            </div>
                            <div class="flex-1 px-3 py-2.5 text-right text-xs truncate" style="color:var(--text-primary);" x-text="internalAddress || 'Click to set address'"></div>
                        </div>
                        {{-- Public row --}}
                        <div class="flex items-center cursor-pointer transition-colors"
                             @click="openModal = 'public'"
                             @mouseenter="$el.style.background='var(--surface-2)'" @mouseleave="$el.style.background=''">
                            <div class="px-3 py-2.5 flex items-center gap-1.5 flex-shrink-0" style="width:100px;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                                <span class="text-xs font-semibold" style="color:var(--brand-icon);">Public</span>
                            </div>
                            <div class="flex-1 px-3 py-2.5 text-right text-xs truncate" style="color:var(--text-primary);" x-text="publicAddress || 'Click to configure'"></div>
                        </div>
                    </div>

                    {{-- Map (collapsible) — pin for the Internal suburb.
                         Auto-geocodes on page load and whenever the P24 picker
                         reports a new suburb. Panel auto-collapses when an
                         address modal opens (Leaflet tiles otherwise float
                         over the modal due to their own stacking context). --}}
                    <div class="mt-2 rounded-md overflow-hidden"
                         style="border:1px solid var(--border); background:var(--surface); position:relative; z-index:0; isolation:isolate;"
                         x-data="propertyMapWidget({
                             propertyId:           {{ (int) $property->id }},
                             initialLat:           {{ (float) old('latitude',  $property->latitude  ?? 0) }},
                             initialLng:           {{ (float) old('longitude', $property->longitude ?? 0) }},
                             initialStreetNumber:  @js(old('street_number', $property->street_number ?? '')),
                             initialStreetName:    @js(old('street_name',   $property->street_name   ?? '')),
                             initialSuburb:        @js(old('suburb',        $property->suburb        ?? '')),
                             initialCity:          @js(old('city',          $property->city          ?? '')),
                             initialProvince:      @js(old('province',      $property->province      ?? '')),
                             initialGeoSource:     @js($property->geo_source ?? ''),
                         })"
                         x-init="init()"
                         x-effect="if (openModal && open) open = false">
                        <input type="hidden" name="latitude"  :value="lat">
                        <input type="hidden" name="longitude" :value="lng">

                        <button type="button" @click="open = !open"
                                class="w-full flex items-center justify-between px-3 py-2 transition-all duration-300"
                                style="background:var(--surface-2); border-bottom:1px solid var(--border);"
                                onmouseover="this.style.background='var(--surface-3, var(--surface-2))'"
                                onmouseout="this.style.background='var(--surface-2)'">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" style="color:var(--brand-icon);"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                </svg>
                                <span class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-primary);">Map</span>
                                <span class="text-[10px]" style="color:var(--text-muted);"
                                      x-text="lat && lng ? ('pin: ' + (+lat).toFixed(5) + ', ' + (+lng).toFixed(5)) : 'no pin yet — click to drop'"></span>
                                {{-- Approximate-pin warning: pin came back as suburb_centroid
                                     (no street_number / street_name available, or geocoder fell
                                     through to suburb level). Agent should add street parts and
                                     re-resolve for building-level accuracy. --}}
                                <span x-show="isApproximate" x-cloak
                                      class="text-[10px] font-semibold px-1.5 py-0.5 rounded"
                                      style="background:rgba(245,158,11,0.15); color:#b45309; border:1px solid rgba(245,158,11,0.35);"
                                      title="Pin is a suburb-level approximation. Add street_number + street_name and re-resolve for a building-level pin.">approx</span>
                            </div>
                            <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 transition-all duration-300" style="color:var(--text-muted);"
                                 xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="open" x-cloak>
                            <div class="px-3 py-2 text-[11px]" style="background:var(--surface); color:var(--text-muted);">
                                <span x-show="geocoding" x-cloak>Locating pin for the selected suburb…</span>
                                <span x-show="!geocoding && (!lat || !lng)" x-cloak>
                                    Pick a Property24 suburb in the Internal address — we'll drop a pin automatically. Drag the pin to fine-tune.
                                </span>
                                <span x-show="!geocoding && lat && lng" x-cloak>
                                    Pin is at the suburb centroid. Drag it to the exact property location.
                                </span>
                            </div>
                            <div id="property-map" style="width:100%; height:280px; background:var(--surface-2);"></div>
                        </div>
                    </div>

                    {{-- Hidden inputs that always submit with the form --}}
                    <input type="hidden" name="address" value="{{ old('address', $property->address) }}">

                    {{-- ===== INTERNAL MODAL ===== --}}
                    <div x-show="openModal === 'internal'" x-cloak
                         class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                         @keydown.escape.window="openModal = null">
                        <div class="absolute inset-0 bg-black/60" @click="openModal = null"></div>
                        <div class="relative w-full max-w-[46rem] max-h-[85vh] overflow-y-auto rounded-lg shadow-2xl"
                             style="background:var(--surface); border:1px solid var(--border);" @click.stop>

                            <div class="sticky top-0 z-10 flex items-center justify-between px-5 py-3 rounded-t-lg"
                                 style="background:var(--brand-default); color:#fff;">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" style="color:var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                                    <span class="text-sm font-bold">Internal Address</span>
                                </div>
                                <button type="button" @click="openModal = null" class="p-1 rounded hover:bg-white/10">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <div class="p-5 space-y-5">
                                {{-- Complex or Estate --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default); color:#fff;">Complex or Estate</div>
                                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Unit Number</label>
                                                <input type="text" name="unit_number" x-model="unitNumber" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Floor Number</label>
                                                <input type="text" name="floor_number" value="{{ old('floor_number', $property->floor_number) }}" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Unit, Section or Block</label>
                                            <input type="text" name="unit_section_block" value="{{ old('unit_section_block', $property->unit_section_block) }}" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Complex or Estate</label>
                                            <input type="text" name="complex_name" x-model="complexName" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                    </div>
                                </div>

                                {{-- Street --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default); color:#fff;">Street</div>
                                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Number</label>
                                            <input type="text" name="street_number" x-model="streetNumber" placeholder="e.g. 1046-2" autocomplete="off" class="w-40 rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Name</label>
                                            <input type="text" name="street_name" x-model="streetName" placeholder="e.g. Clarendon Road" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                    </div>
                                </div>

                                {{-- Province / City / Suburb — Property24-backed typeahead.
                                     User must pick a suburb P24 recognises; can't save free-text. --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default); color:#fff;">Province / City / Suburb</div>
                                    <div class="p-4 rounded-b-md" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        @include('corex._partials.p24-location-picker', [
                                            'fieldPrefix'         => 'p24',
                                            'initialProvinceId'   => old('p24_province_id', $property->p24_province_id ?? 0),
                                            'initialCityId'       => old('p24_city_id',     $property->p24_city_id ?? 0),
                                            'initialSuburbId'     => old('p24_suburb_id',   $property->p24_suburb_id ?? 0),
                                            'initialProvinceName' => old('province', $property->province ?? ''),
                                            'initialCityName'     => old('city',     $property->city ?? ''),
                                            'initialSuburbName'   => old('suburb',   $property->suburb ?? ''),
                                            'denormaliseNames'    => true,
                                        ])
                                    </div>
                                </div>

                                {{-- More Info --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default); color:#fff;">More Info</div>
                                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Property / Erf Number</label>
                                                <input type="text" name="property_number" value="{{ old('property_number', $property->property_number) }}" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Stand Number</label>
                                                <input type="text" name="stand_number" value="{{ old('stand_number', $property->stand_number) }}" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Zone Type</label>
                                                <select name="zone_type" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                                    <option value="">-- None --</option>
                                                    @foreach(['Residential','Commercial','Industrial','Agricultural','Mixed Use'] as $zt)
                                                    <option value="{{ $zt }}" {{ old('zone_type', $property->zone_type) === $zt ? 'selected' : '' }}>{{ $zt }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">District / Municipality</label>
                                                <input type="text" name="district" value="{{ old('district', $property->district) }}" placeholder="e.g. Ray Nkonyeni" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Region</label>
                                            <input type="text" name="region" value="{{ old('region', $property->region) }}" placeholder="KZN South Coast" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Internal Note</label>
                                            <textarea name="address_internal_note" rows="2" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('address_internal_note', $property->address_internal_note) }}</textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="sticky bottom-0 px-5 py-3 rounded-b-lg flex justify-end" style="background:var(--surface); border-top:1px solid var(--border);">
                                <button type="button" @click="openModal = null" class="px-4 py-2 rounded-md text-xs font-semibold text-white" style="background:var(--ds-green);">Done</button>
                            </div>
                        </div>
                    </div>

                    {{-- ===== PUBLIC MODAL ===== --}}
                    <div x-show="openModal === 'public'" x-cloak
                         class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                         @keydown.escape.window="openModal = null">
                        <div class="absolute inset-0 bg-black/60" @click="openModal = null"></div>
                        <div class="relative w-full max-w-lg max-h-[85vh] overflow-y-auto rounded-lg shadow-2xl"
                             style="background:var(--surface); border:1px solid var(--border);" @click.stop>

                            <div class="sticky top-0 z-10 flex items-center justify-between px-5 py-3 rounded-t-lg"
                                 style="background:var(--brand-icon); color:#fff;">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3"/></svg>
                                    <span class="text-sm font-bold">Public Address &mdash; Portal Feeds</span>
                                </div>
                                <button type="button" @click="openModal = null" class="p-1 rounded hover:bg-white/10">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <div class="p-5 space-y-5">
                                <p class="text-xs" style="color:var(--text-muted);">
                                    This controls what is shown on portal feeds (Private Property, Property24, website). Unchecked fields are <strong>hidden</strong> from the public.
                                </p>

                                {{-- Public preview --}}
                                <div class="rounded-md px-4 py-3" style="background:var(--surface-2); border:1px solid var(--border);">
                                    <p class="text-[0.6875rem] font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Feed Preview</p>
                                    <p class="text-sm font-semibold" style="color:var(--text-primary);" x-text="publicAddress"></p>
                                </div>

                                {{-- Visibility toggles --}}
                                <div class="space-y-0" style="border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                                    <div class="flex items-center justify-between px-4 py-3" style="border-bottom:1px solid var(--border);">
                                        <div>
                                            <p class="text-xs font-semibold" style="color:var(--text-primary);">Street Number</p>
                                            <p class="text-xs" style="color:var(--text-muted);" x-text="streetNumber || '(not set)'"></p>
                                        </div>
                                        <label class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200"
                                               :style="!hideStreetNumber ? 'background:var(--ds-green)' : 'background:var(--surface-3)'">
                                            <input type="checkbox" name="pp_hide_street_number" value="1" :checked="hideStreetNumber" @change="hideStreetNumber = $el.checked" class="sr-only">
                                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                                  style="background:#fff; margin-top:2px;"
                                                  :style="!hideStreetNumber ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between px-4 py-3" style="border-bottom:1px solid var(--border);">
                                        <div>
                                            <p class="text-xs font-semibold" style="color:var(--text-primary);">Street Name</p>
                                            <p class="text-xs" style="color:var(--text-muted);" x-text="streetName || '(not set)'"></p>
                                        </div>
                                        <label class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200"
                                               :style="!hideStreetName ? 'background:var(--ds-green)' : 'background:var(--surface-3)'">
                                            <input type="checkbox" name="pp_hide_street_name" value="1" :checked="hideStreetName" @change="hideStreetName = $el.checked" class="sr-only">
                                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                                  style="background:#fff; margin-top:2px;"
                                                  :style="!hideStreetName ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between px-4 py-3" style="border-bottom:1px solid var(--border);">
                                        <div>
                                            <p class="text-xs font-semibold" style="color:var(--text-primary);">Complex Name</p>
                                            <p class="text-xs" style="color:var(--text-muted);" x-text="complexName || '(not set)'"></p>
                                        </div>
                                        <label class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200"
                                               :style="!hideComplexName ? 'background:var(--ds-green)' : 'background:var(--surface-3)'">
                                            <input type="checkbox" name="pp_hide_complex_name" value="1" :checked="hideComplexName" @change="hideComplexName = $el.checked" class="sr-only">
                                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                                  style="background:#fff; margin-top:2px;"
                                                  :style="!hideComplexName ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between px-4 py-3">
                                        <div>
                                            <p class="text-xs font-semibold" style="color:var(--text-primary);">Unit Number</p>
                                            <p class="text-xs" style="color:var(--text-muted);" x-text="unitNumber || '(not set)'"></p>
                                        </div>
                                        <label class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200"
                                               :style="!hideUnitNumber ? 'background:var(--ds-green)' : 'background:var(--surface-3)'">
                                            <input type="checkbox" name="pp_hide_unit_number" value="1" :checked="hideUnitNumber" @change="hideUnitNumber = $el.checked" class="sr-only">
                                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                                  style="background:#fff; margin-top:2px;"
                                                  :style="!hideUnitNumber ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                                        </label>
                                    </div>
                                </div>

                                <p class="text-[0.6875rem]" style="color:var(--text-muted);">
                                    Toggle ON (green) = visible on feeds. Toggle OFF = hidden. Changes apply when you save the property.
                                </p>
                            </div>

                            <div class="sticky bottom-0 px-5 py-3 rounded-b-lg flex justify-end" style="background:var(--surface); border-top:1px solid var(--border);">
                                <button type="button" @click="openModal = null" class="px-4 py-2 rounded-md text-xs font-semibold text-white" style="background:var(--ds-green);">Done</button>
                            </div>
                        </div>
                    </div>
                </div>

                    </div>{{-- /info.property body --}}
                </section>{{-- /Property section --}}

                {{-- ── SECTION: MANDATE & ASSIGNMENT ────────────────────── --}}
                <section id="sec-mandate" class="prop-section">
                    <button type="button" class="prop-section-toggle" @click="info.mandate = !info.mandate">
                        <h3 class="prop-section-heading"><span class="prop-section-heading-text">Mandate &amp; Assignment</span></h3>
                        <svg class="prop-section-chevron" :class="info.mandate ? 'is-open' : ''" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </button>
                    <div x-show="info.mandate" x-collapse class="prop-section-body space-y-5">

                        {{-- Lifecycle: Status, Mandate, Listed/Expiry --}}
                        <div>
                            <p class="prop-subsection-heading">Lifecycle</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                                <div>
                                    <label class="prop-label">Status <span class="prop-required">*</span></label>
                                    <select name="status" required class="prop-select prop-field-lifecycle">
                                        <option value="">— None —</option>
                                        @foreach($settingItems['statuses'] as $item)
                                            @php $val = strtolower(str_replace(' ','_',$item->name)); @endphp
                                            <option value="{{ $val }}" {{ old('status', $property->status) === $val ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="prop-label">Mandate Type</label>
                                    <select name="mandate_type" class="prop-select prop-field-lifecycle">
                                        <option value="">— None —</option>
                                        @foreach($settingItems['mandateTypes'] as $item)
                                            <option value="{{ $item->name }}" {{ old('mandate_type', $property->mandate_type) === $item->name ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @php
                                    $listedDateValue = $property->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');
                                @endphp
                                <div x-data="{
                                        listedDate: '{{ $listedDateValue }}',
                                        expiryDate: '{{ old('expiry_date', $property->expiry_date?->format('Y-m-d')) }}',
                                        addMonths(n) {
                                            const base = this.listedDate ? new Date(this.listedDate) : new Date();
                                            base.setMonth(base.getMonth() + n);
                                            this.expiryDate = base.toISOString().slice(0, 10);
                                        }
                                     }" class="contents">
                                    <div>
                                        <label class="prop-label">Listed Date</label>
                                        <input type="date" name="listed_date" :value="listedDate" readonly
                                               class="prop-input prop-field-lifecycle"
                                               style="color-scheme: light dark; opacity:.75; cursor:not-allowed;"
                                               title="Listed Date is always the date the property was loaded">
                                    </div>
                                    <div x-data="{ qaOpen: false }" @click.outside="qaOpen = false" class="relative">
                                        <label class="prop-label">Expiry Date</label>
                                        <input type="date" name="expiry_date" x-model="expiryDate" :min="listedDate"
                                               class="prop-input prop-field-lifecycle" style="color-scheme: light dark;"
                                               @focus="qaOpen = true" @click="qaOpen = true"
                                               @change="if (expiryDate && expiryDate < listedDate) { expiryDate = listedDate; }">
                                        <div x-show="qaOpen" x-cloak x-transition.opacity
                                             class="absolute left-0 right-0 z-50 rounded-md border shadow-lg p-2 flex flex-wrap items-center gap-1.5"
                                             style="bottom:100%; margin-bottom:4px; background:var(--surface-1); border-color:var(--border);">
                                            <span class="text-[0.6875rem]" style="color:var(--text-muted);">Quick set:</span>
                                            <button type="button" @click="addMonths(3); qaOpen = false" class="text-[0.6875rem] px-2 py-0.5 rounded-md border" style="border-color:var(--border); color:var(--text-secondary);">+3 months</button>
                                            <button type="button" @click="addMonths(6); qaOpen = false" class="text-[0.6875rem] px-2 py-0.5 rounded-md border" style="border-color:var(--border); color:var(--text-secondary);">+6 months</button>
                                            <button type="button" @click="addMonths(12); qaOpen = false" class="text-[0.6875rem] px-2 py-0.5 rounded-md border" style="border-color:var(--border); color:var(--text-secondary);">+12 months</button>
                                        </div>
                                    </div>
                                </div>
                                @if(!$isNew)
                                    <div>
                                        <label class="prop-label">Loaded</label>
                                        <input type="text" value="{{ $property->created_at->format('d M Y H:i') }}" disabled class="prop-input prop-field-lifecycle"
                                               title="{{ $property->created_at->toDayDateTimeString() }}">
                                    </div>
                                    <div>
                                        <label class="prop-label">Modified</label>
                                        <input type="text"
                                               value="{{ $property->updated_at->format('d M Y H:i') }} ({{ $property->updated_at->diffForHumans() }})"
                                               disabled class="prop-input prop-field-lifecycle"
                                               title="{{ $property->updated_at->toDayDateTimeString() }}">
                                    </div>
                                @endif
                            </div>
                        </div>

                    {{-- Showday Events --}}
                    @if(!$isNew)
                    @php $existingShowdays = $property->activeShowdays()->get(); @endphp
                    <div x-data="{
                        showForm: false,
                        sdStart: '', sdEnd: '', sdDesc: '', sdLoading: false, sdMsg: '',
                        showdays: {{ Js::from($existingShowdays->map(fn($s) => [
                            'id' => $s->id,
                            'start_date' => $s->start_date->format('d M Y H:i'),
                            'end_date' => $s->end_date->format('d M Y H:i'),
                            'description' => $s->description,
                        ])) }},
                        async createShowday() {
                            if (!this.sdStart || !this.sdEnd) return;
                            this.sdLoading = true; this.sdMsg = '';
                            try {
                                const res = await fetch('/corex/properties/{{ $property->id }}/syndication/showday', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                                    body: JSON.stringify({ start_date: this.sdStart, end_date: this.sdEnd, description: this.sdDesc || 'Open Showday' }),
                                });
                                const d = await res.json();
                                if (d.success) {
                                    this.showdays = d.showdays;
                                    this.sdStart = ''; this.sdEnd = ''; this.sdDesc = '';
                                    this.showForm = false;
                                    this.sdMsg = 'Showday created';
                                    setTimeout(() => this.sdMsg = '', 3000);
                                } else { this.sdMsg = d.message || 'Failed'; }
                            } catch { this.sdMsg = 'Network error'; }
                            finally { this.sdLoading = false; }
                        },
                        async removeShowday(id) {
                            if (!confirm('Remove this showday?')) return;
                            try {
                                const res = await fetch('/corex/properties/{{ $property->id }}/syndication/showday/' + id, {
                                    method: 'DELETE',
                                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                                });
                                const d = await res.json();
                                if (d.success) this.showdays = d.showdays;
                            } catch {}
                        }
                    }">
                        <div class="flex items-center justify-between mb-3">
                            <p class="prop-subsection-heading" style="margin-bottom:0;">Showday Events</p>
                            <button type="button" @click="showForm = !showForm"
                                    class="flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-md transition-colors"
                                    style="background:color-mix(in srgb, var(--ds-green) 8%, transparent); color:var(--ds-green); border:1px solid color-mix(in srgb, var(--ds-green) 25%, transparent);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                <span x-text="showForm ? 'Cancel' : 'Add Showday'"></span>
                            </button>
                        </div>

                        {{-- Existing showdays --}}
                        <template x-if="showdays.length > 0">
                            <div class="space-y-2 mb-3">
                                <template x-for="sd in showdays" :key="sd.id">
                                    <div class="flex items-center justify-between px-3 py-2 rounded-md text-xs"
                                         style="background:var(--surface-2); border:1px solid var(--border);">
                                        <div class="flex items-center gap-3">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                                            <div>
                                                <span style="color:var(--text-primary);" x-text="sd.start_date + ' — ' + sd.end_date"></span>
                                                <span class="ml-2" style="color:var(--text-muted);" x-text="sd.description"></span>
                                            </div>
                                        </div>
                                        <button type="button" @click="removeShowday(sd.id)"
                                                class="p-1 rounded transition-colors flex-shrink-0"
                                                style="color:var(--ds-crimson);" title="Remove showday">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="showdays.length === 0 && !showForm">
                            <p class="text-xs mb-3" style="color:var(--text-muted);">No showdays scheduled</p>
                        </template>

                        {{-- Create form --}}
                        <div x-show="showForm" x-cloak x-transition class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Start</label>
                                <input type="datetime-local" x-model="sdStart"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); color-scheme: light dark;">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">End</label>
                                <input type="datetime-local" x-model="sdEnd"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); color-scheme: light dark;">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Description</label>
                                <input type="text" x-model="sdDesc" placeholder="Open Showday"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="sm:col-span-3 flex items-center gap-3">
                                <button type="button" @click="createShowday()"
                                        :disabled="sdLoading || !sdStart || !sdEnd"
                                        class="px-4 py-2 rounded-md text-xs font-semibold text-white"
                                        style="background:var(--ds-green);">
                                    <span x-text="sdLoading ? 'Creating...' : 'Create Showday'"></span>
                                </button>
                                <span x-show="sdMsg" x-text="sdMsg" class="text-xs" style="color:var(--ds-green);"></span>
                            </div>
                        </div>
                    </div>
                    @endif

                {{-- Assignment subsection (still inside Mandate body) --}}
                <div>
                    <p class="prop-subsection-heading">Assignment</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Primary Agent Card --}}
                        <div class="rounded-md p-3" style="background:var(--surface-2); border:1px solid var(--border);">
                            <label class="prop-label" style="margin-bottom:0.5rem;">Primary Agent <span class="prop-required">*</span></label>
                            <div class="flex items-start gap-3" x-data="{ agentId: {{ (int) old('agent_id', $property->agent_id) }} }">
                                {{-- Agent photo preview — uses the eager-loaded full User relation
                                     so accessors (profilePhotoUrl / profile_photo_url) have the data they need. --}}
                                @php
                                    $primaryAgent = $property->agent;
                                    $primaryImgSrc = $property->pp_agent_image_path
                                        ? asset('storage/' . $property->pp_agent_image_path)
                                        : ($primaryAgent
                                            ? ($primaryAgent->profile_photo_url ?? (method_exists($primaryAgent, 'profilePhotoUrl') ? $primaryAgent->profilePhotoUrl() : null))
                                            : null);
                                @endphp
                                <div class="flex-shrink-0">
                                    @if($primaryImgSrc)
                                        <img src="{{ $primaryImgSrc }}" alt="" class="w-14 h-14 rounded-md object-cover" style="border:1px solid var(--border);">
                                    @elseif($primaryAgent)
                                        @php
                                            $pn = trim($primaryAgent->name ?? '');
                                            $pInitials = strtoupper(collect(preg_split('/\s+/', $pn))->filter()->map(fn($w) => substr($w, 0, 1))->take(2)->implode(''));
                                        @endphp
                                        <div class="w-14 h-14 rounded-md flex items-center justify-center font-bold text-lg" style="background:var(--brand-icon); color:#fff; border:1px solid var(--border);">
                                            {{ $pInitials ?: '—' }}
                                        </div>
                                    @else
                                        <div class="w-14 h-14 rounded-md flex items-center justify-center" style="background:var(--surface-3); border:1px solid var(--border);">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 space-y-2">
                                    <select name="agent_id" x-model="agentId" required class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface-3); border:1px solid var(--border); color:var(--text-primary);">
                                        @foreach($agents as $agent)
                                        <option value="{{ $agent->id }}" {{ (int) old('agent_id', $property->agent_id) === $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        {{-- Second Agent Card --}}
                        <div class="rounded-md p-3" style="background:var(--surface-2); border:1px solid var(--border);">
                            <label class="prop-label" style="margin-bottom:0.5rem;">Second Agent</label>
                            <div class="flex items-start gap-3">
                                {{-- Agent photo preview — fetch the full User to get profile photo accessors --}}
                                @php
                                    $secondAgent = $property->pp_second_agent_id ? \App\Models\User::find($property->pp_second_agent_id) : null;
                                    $secondImgSrc = $property->pp_second_agent_image_path
                                        ? asset('storage/' . $property->pp_second_agent_image_path)
                                        : ($secondAgent
                                            ? ($secondAgent->profile_photo_url ?? (method_exists($secondAgent, 'profilePhotoUrl') ? $secondAgent->profilePhotoUrl() : null))
                                            : null);
                                @endphp
                                <div class="flex-shrink-0">
                                    @if($secondImgSrc)
                                        <img src="{{ $secondImgSrc }}" alt="" class="w-14 h-14 rounded-md object-cover" style="border:1px solid var(--border);">
                                    @elseif($secondAgent)
                                        @php
                                            $sn = trim($secondAgent->name ?? '');
                                            $sInitials = strtoupper(collect(preg_split('/\s+/', $sn))->filter()->map(fn($w) => substr($w, 0, 1))->take(2)->implode(''));
                                        @endphp
                                        <div class="w-14 h-14 rounded-md flex items-center justify-center font-bold text-lg" style="background:var(--brand-icon); color:#fff; border:1px solid var(--border);">
                                            {{ $sInitials ?: '—' }}
                                        </div>
                                    @else
                                        <div class="w-14 h-14 rounded-md flex items-center justify-center" style="background:var(--surface-3); border:1px solid var(--border);">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 space-y-2">
                                    <select name="pp_second_agent_id" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface-3); border:1px solid var(--border); color:var(--text-primary);">
                                        <option value="">— None —</option>
                                        @foreach($agents as $agent)
                                        <option value="{{ $agent->id }}" {{ (int) old('pp_second_agent_id', $property->pp_second_agent_id ?? '') === $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Video & Virtual Tour Links --}}
                    <div class="mt-4">
                        <p class="prop-subsection-heading">Video &amp; Virtual Tour</p>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="prop-label">YouTube Video <span class="opacity-60">(URL or ID)</span></label>
                                <input type="text" name="youtube_video_id" value="{{ old('youtube_video_id', $property->youtube_video_id) }}"
                                       placeholder="https://www.youtube.com/watch?v=... or video ID"
                                       class="prop-input" style="font-family:var(--font-mono, monospace);">
                            </div>
                            <div>
                                <label class="prop-label">Matterport ID</label>
                                <input type="text" name="matterport_id" value="{{ old('matterport_id', $property->matterport_id) }}"
                                       placeholder="Matterport scan ID"
                                       class="prop-input" style="font-family:var(--font-mono, monospace);">
                            </div>
                            <div>
                                <label class="prop-label">Other Virtual Tour / Video URL</label>
                                <input type="url" name="virtual_tour_url" value="{{ old('virtual_tour_url', $property->virtual_tour_url) }}"
                                       placeholder="https://findaholiday.co.za/wordpress/ipanorama/virtualtour/121"
                                       class="prop-input" style="font-family:var(--font-mono, monospace);">
                            </div>
                        </div>
                        <p class="text-xs mt-2" style="color:var(--text-muted);">iPanorama, Kuula, or any embeddable 360 host can go in the Other Virtual Tour field. Shown on Live Preview + custom websites only — not pushed to portal feeds.</p>
                    </div>

                </div>{{-- /Assignment subsection --}}

                    </div>{{-- /info.mandate body --}}
                </section>{{-- /Mandate section --}}

                {{-- ── SECTION: RENTAL DETAILS (only when listing type = rental) ── --}}
                <section id="sec-rental" class="prop-section"
                     x-data="{ isRental: document.querySelector('[name=listing_type]')?.value === 'rental' }"
                     x-init="document.querySelector('[name=listing_type]')?.addEventListener('change', e => isRental = e.target.value === 'rental')"
                     x-show="isRental || '{{ strtolower($property->listing_type ?? '') }}' === 'rental'" x-cloak>
                    <button type="button" class="prop-section-toggle" @click="info.rental = !info.rental">
                        <h3 class="prop-section-heading"><span class="prop-section-heading-text">Rental Details</span></h3>
                        <svg class="prop-section-chevron" :class="info.rental ? 'is-open' : ''" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </button>
                    <div x-show="info.rental" x-collapse class="prop-section-body grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="prop-label">Monthly Rental (R)</label>
                            <input type="number" name="rental_amount" value="{{ old('rental_amount', $property->rental_amount) }}" placeholder="0.00" min="0" step="0.01" class="prop-input prop-field-money">
                        </div>
                        <div>
                            <label class="prop-label">Deposit (R)</label>
                            <input type="number" name="deposit_amount" value="{{ old('deposit_amount', $property->deposit_amount) }}" placeholder="0.00" min="0" step="0.01" class="prop-input prop-field-money">
                        </div>
                        <div>
                            <label class="prop-label">Rental Price Type</label>
                            <select name="rental_price_type" class="prop-select prop-field-enum">
                                <option value="">— Not Set —</option>
                                @foreach(['per month' => 'Per Month', 'per sqm' => 'Per Sqm', 'per day' => 'Per Day', 'per week' => 'Per Week', 'per year' => 'Per Year'] as $val => $lbl)
                                    <option value="{{ $val }}" {{ old('rental_price_type', $property->rental_price_type) === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="prop-label">Lease Start Date</label>
                            <input type="date" name="lease_start_date" value="{{ old('lease_start_date', $property->lease_start_date?->format('Y-m-d')) }}" class="prop-input prop-field-date" style="color-scheme: light dark;">
                        </div>
                        <div>
                            <label class="prop-label">Lease End Date</label>
                            <input type="date" name="lease_end_date" value="{{ old('lease_end_date', $property->lease_end_date?->format('Y-m-d')) }}" class="prop-input prop-field-date" style="color-scheme: light dark;">
                        </div>
                    </div>
                </section>

            </form>{{-- /prop-update-form --}}

            {{-- Save / Delete — outside the update form to prevent nesting --}}
            <div class="flex items-center justify-between pt-4">
                <button type="submit" form="prop-update-form"
                        class="px-5 py-2 rounded-md text-sm font-semibold text-white"
                        style="background:var(--brand-default); border:1px solid var(--border);"
                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    {{ $isNew ? 'Create Property' : 'Save Changes' }}
                </button>
                @if(!$isNew)
                <form method="POST" action="{{ route('corex.properties.destroy', $property) }}"
                      onsubmit="return confirm('Delete this property?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm font-semibold px-4 py-2 rounded-md transition-colors hover:opacity-80" style="color:var(--ds-crimson);">
                        Delete Property
                    </button>
                </form>
                @endif
            </div>
        </div>

        {{-- ── GALLERY TAB ────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'gallery'" x-cloak class="p-6 space-y-6"
             x-data="galleryManager()">
        @if($isNew)
            <div>
                <p class="text-xs mb-4" style="color:var(--text-muted);">Images will be uploaded when you click <strong style="color:var(--text-secondary);">Create Property</strong>.</p>
                <label class="flex items-center gap-3 px-4 py-3 rounded-md border border-dashed cursor-pointer text-sm transition-colors"
                       style="border-color:var(--border-hover); color:var(--text-secondary);"
                       onmouseover="this.style.borderColor='var(--brand-icon)'" onmouseout="this.style.borderColor='var(--border-hover)'">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                    <span id="gallery_images-create-label">Select images (multiple allowed)</span>
                    <input type="file" name="gallery_images[]" multiple accept="image/*" form="prop-update-form" class="hidden"
                           onchange="document.getElementById('gallery_images-create-label').textContent = this.files.length + ' file' + (this.files.length !== 1 ? 's' : '') + ' selected';">
                </label>
            </div>
        @else

            {{-- Upload new images --}}
            <div x-data="galleryUploader('{{ route('corex.properties.upload-images', $property) }}', '{{ csrf_token() }}')">
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Upload Images</h3>

                <label class="flex items-center gap-3 px-4 py-3 rounded-md border border-dashed cursor-pointer transition-colors text-sm"
                       :style="uploading ? 'opacity:0.6; pointer-events:none;' : ''"
                       style="border-color:var(--border-hover); color:var(--text-secondary);"
                       onmouseover="this.style.borderColor='var(--brand-icon)'" onmouseout="this.style.borderColor='var(--border-hover)'">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                    <span x-text="labelText">Select images to upload (multiple allowed)</span>
                    <input type="file" name="gallery_images[]" multiple accept="image/*" class="hidden"
                           x-ref="fileInput"
                           @change="onPick($event)">
                </label>

                <button x-show="files.length > 0 && !uploading" type="button"
                        @click="upload()"
                        class="mt-2 px-4 py-2 rounded-md text-sm font-semibold text-white"
                        style="background:var(--brand-button,#0ea5e9);">
                    Upload Images
                </button>

                {{-- Progress bar --}}
                <div x-show="uploading || percent === 100" x-cloak class="mt-3">
                    <div class="flex items-center justify-between mb-1 text-xs" style="color:var(--text-secondary);">
                        <span x-text="statusText"></span>
                        <span x-text="percent + '%'"></span>
                    </div>
                    <div class="w-full h-2 rounded-full overflow-hidden" style="background:var(--surface-3);">
                        <div class="h-full transition-all duration-200"
                             :style="'width: ' + percent + '%; background: var(--brand-button, #0ea5e9);'"></div>
                    </div>
                </div>

                <div x-show="errorMsg" x-cloak class="mt-2 text-xs" style="color:#ef4444;" x-text="errorMsg"></div>
            </div>

            <script>
            function galleryUploader(endpoint, csrf) {
                return {
                    files: [],
                    uploading: false,
                    percent: 0,
                    errorMsg: '',
                    statusText: 'Uploading...',
                    get labelText() {
                        if (this.uploading) return 'Uploading ' + this.files.length + ' image(s)...';
                        if (this.files.length === 0) return 'Select images to upload (multiple allowed)';
                        return this.files.length + ' file' + (this.files.length !== 1 ? 's' : '') + ' selected';
                    },
                    onPick(e) {
                        this.files = Array.from(e.target.files || []);
                        this.errorMsg = '';
                        this.percent = 0;
                    },
                    upload() {
                        if (!this.files.length) return;
                        const fd = new FormData();
                        this.files.forEach(f => fd.append('gallery_images[]', f));
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', endpoint);
                        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                        xhr.setRequestHeader('Accept', 'text/html');
                        xhr.upload.onprogress = (ev) => {
                            if (ev.lengthComputable) {
                                this.percent = Math.round((ev.loaded / ev.total) * 100);
                                if (this.percent >= 100) this.statusText = 'Saving...';
                            }
                        };
                        xhr.onload = () => {
                            this.uploading = false;
                            if (xhr.status >= 200 && xhr.status < 400) {
                                this.percent = 100;
                                this.statusText = 'Done';
                                window.location.reload();
                            } else {
                                this.errorMsg = 'Upload failed (HTTP ' + xhr.status + '). Please try again.';
                            }
                        };
                        xhr.onerror = () => {
                            this.uploading = false;
                            this.errorMsg = 'Network error during upload.';
                        };
                        this.uploading = true;
                        this.percent = 0;
                        this.statusText = 'Uploading...';
                        this.errorMsg = '';
                        xhr.send(fd);
                    }
                }
            }
            </script>

            {{-- Tag-based Gallery --}}
            @php
                $galleryImages = $property->gallery_images_json ?? [];
                $galleryCats = $property->gallery_categories_json ?? null;
                // Build tag map: image URL → category name
                $tagMap = [];
                if ($galleryCats && isset($galleryCats['categories'])) {
                    foreach ($galleryCats['categories'] as $cat) {
                        foreach ($cat['images'] ?? [] as $img) {
                            $tagMap[$img] = $cat['name'];
                        }
                    }
                }
                // Build available tags from spaces or beds/baths columns
                $spacesData = $property->spaces_json ?? [];
                $spacesList = $spacesData['spaces'] ?? [];
                if (empty($spacesList) && !empty($spacesData) && isset($spacesData[0]['type'])) {
                    $spacesList = $spacesData;
                }
                // Single source of truth — see Property::getAvailableGalleryTags()
                $availableTags = $property->getAvailableGalleryTags();
            @endphp

            <div x-data="Object.assign(smartGallery({{ Js::from($galleryImages) }}, {{ Js::from($tagMap) }}, {{ $property->id }}, '{{ csrf_token() }}', {{ Js::from($availableTags) }}), { tagsInfoOpen: false, manageTagsOpen: false, selectMode: false })" class="space-y-4">

                {{-- Header --}}
                <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">
                    Gallery (<span x-text="images.length"></span> images)
                </h3>
                <div class="flex items-center justify-between gap-4">
                    <button type="button" @click="toggleSelectMode()"
                            class="text-[0.6875rem] font-semibold px-2.5 py-1 rounded transition-colors"
                            :style="selectMode ? 'background:var(--brand-icon); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);'">
                        <span x-text="selectMode ? 'Done Selecting' : 'Select'"></span>
                    </button>
                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <button type="button" @click="tagsInfoOpen = !tagsInfoOpen"
                                    title="How tagging works"
                                    class="w-7 h-7 rounded flex items-center justify-center transition-colors"
                                    style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);"
                                    onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div x-show="tagsInfoOpen" x-cloak
                                 @click.outside="tagsInfoOpen = false"
                                 class="absolute right-0 top-9 z-30 w-80 rounded-md p-3 shadow-lg"
                                 style="background:var(--surface); border:1px solid var(--border);"
                                 x-transition.opacity>
                                <p class="text-xs font-bold mb-1.5" style="color:var(--text-primary);">Tag Images</p>
                                <p class="text-xs leading-relaxed mb-2" style="color:var(--text-secondary);">
                                    Click <span style="color:var(--text-primary); font-weight:600;">Tag Images</span> to open the sticky tag bar above the gallery. <span style="color:var(--text-primary); font-weight:600;">1.</span> Click a tag chip (e.g. Exterior, Lounge, Kitchen) to pick it. <span style="color:var(--text-primary); font-weight:600;">2.</span> Click any image in the grid — it gets tagged with that tag instantly. <span style="color:var(--text-primary); font-weight:600;">3.</span> Switch tags or click <span style="color:var(--ds-crimson); font-weight:600;">Clear tag</span> to keep going. Click <span style="color:var(--text-primary); font-weight:600;">Done Tagging</span> when finished.
                                </p>
                                <p class="text-xs font-bold mb-1.5" style="color:var(--text-primary);">Sort order &amp; Custom tags</p>
                                <p class="text-xs leading-relaxed mb-2" style="color:var(--text-secondary);">
                                    Opens a popup where you drag the <span style="color:var(--text-primary); font-weight:600;">⋮⋮</span> grip to reorder tags, add a custom tag, or remove one. When you click <span style="color:var(--text-primary); font-weight:600;">Done</span>, the gallery auto-sorts to match this order:
                                </p>
                                <ul class="text-xs leading-relaxed mb-2 pl-4 space-y-0.5" style="color:var(--text-secondary); list-style:disc;">
                                    <li>Tagged images first, grouped together by tag.</li>
                                    <li>Tag groups follow the order you set (e.g. Exterior → Lounge → Kitchen → Bedrooms…).</li>
                                    <li>Untagged images stay in their existing order at the very end.</li>
                                </ul>
                                <p class="text-xs leading-relaxed mb-2" style="color:var(--text-secondary);">
                                    Tip: tag your hero shot under the first category in your tag order so it always becomes the cover image on the website and portals.
                                </p>
                                <p class="text-xs font-bold mb-1.5" style="color:var(--text-primary);">Untagged images</p>
                                <p class="text-xs leading-relaxed mb-2" style="color:var(--text-secondary);">
                                    Images without a tag still display in the gallery — they're just not grouped. Portal feeds (P24 / Private Property) only use the tag as a photo caption when one is set.
                                </p>
                                <p class="text-xs font-bold mb-1.5" style="color:var(--text-primary);">Removing a tag</p>
                                <p class="text-xs leading-relaxed" style="color:var(--text-secondary);">
                                    In tag mode, click <span style="color:var(--ds-crimson); font-weight:600;">Clear tag</span> in the sticky bar, then click the image(s) you want to untag. To delete a tag entirely, open <span style="color:var(--text-primary); font-weight:600;">Sort order &amp; Custom tags</span> and click the × on the chip. Don't forget to <span style="color:var(--text-primary); font-weight:600;">Save</span> when you're done.
                                </p>
                            </div>
                        </div>
                        <button type="button" @click="if (selectMode) toggleSelectMode(); if (manageTagsOpen) manageTagsOpen=false; if (!tagMode) activeTag=null; toggleTagMode()"
                                class="text-[0.6875rem] font-semibold px-2.5 py-1 rounded transition-colors"
                                :style="tagMode ? 'background:var(--brand-icon); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);'">
                            <span x-text="tagMode ? 'Done Tagging' : 'Tag Images'"></span>
                        </button>
                        <button type="button" @click="if (tagMode) toggleTagMode(); manageTagsOpen = !manageTagsOpen"
                                class="text-[0.6875rem] font-semibold px-2.5 py-1 rounded transition-colors"
                                :style="manageTagsOpen ? 'background:var(--brand-icon); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);'">
                            Sort order &amp; Custom tags
                        </button>
                        <button type="button" @click="save()" :disabled="saving"
                                class="text-[0.6875rem] font-semibold px-2.5 py-1 rounded transition-colors"
                                style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);">
                            <span x-text="saving ? 'Saving...' : (dirty ? 'Save' : 'Saved')"></span>
                        </button>
                    </div>
                </div>

                {{-- Sort order & Custom tags popup (teleported, centered) --}}
                <template x-teleport="body">
                    <div x-show="manageTagsOpen" x-cloak x-transition.opacity
                         class="fixed inset-0 z-[9999] flex items-end sm:items-center justify-center p-4">
                        <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="manageTagsOpen = false"></div>
                        <div class="relative w-full sm:w-[560px] max-h-[90vh] flex flex-col rounded-t-2xl sm:rounded-md shadow-2xl"
                             style="background:var(--surface); border:1px solid var(--border);"
                             @click.stop>
                            {{-- Header --}}
                            <div class="flex items-center justify-between px-5 py-3" style="border-bottom:1px solid var(--border);">
                                <div>
                                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Sort order &amp; Custom tags</h3>
                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Reorder tags by dragging the grip. Add or remove your own tags.</p>
                                </div>
                                <button type="button" @click="manageTagsOpen = false"
                                        class="w-7 h-7 rounded-md flex items-center justify-center"
                                        style="color:var(--text-muted);"
                                        onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            {{-- Body (scrollable) --}}
                            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">

                                {{-- Drag instructions --}}
                                <div class="rounded-md px-3 py-2 flex items-start gap-2" style="background:color-mix(in srgb, var(--brand-icon) 6%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--brand-icon);" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>
                                    <p class="text-xs leading-relaxed" style="color:var(--text-secondary);">
                                        Drag the <span style="color:var(--text-primary); font-weight:600;">⋮⋮ grip</span> on each tag chip to reorder. When you click <span style="color:var(--text-primary); font-weight:600;">Done</span>, the gallery auto-sorts to this order — which is also the order photos appear in portal feeds.
                                    </p>
                                </div>

                                {{-- Available tags (draggable chips) --}}
                                <div>
                                    <p class="text-xs font-semibold mb-2" style="color:var(--text-secondary);">Available Tags</p>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="(tag, tIdx) in availableTags" :key="tag">
                                            <div class="inline-flex items-center gap-0 rounded-full transition-colors"
                                                 draggable="true"
                                                 @dragstart="tagDragStart(tIdx, $event)"
                                                 @dragover.prevent="tagDragOver(tIdx, $event)"
                                                 @drop.prevent="tagDragDrop()"
                                                 :style="activeTag === tag ? 'background:var(--brand-icon); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);'">
                                                <span class="pl-2 pr-1 cursor-grab select-none" :style="activeTag === tag ? 'color:#fff; opacity:.85;' : 'color:var(--text-muted);'" title="Drag to reorder">⋮⋮</span>
                                                <button type="button" @click="tagSelected(tag)"
                                                        class="text-xs font-semibold pr-1 py-1"
                                                        x-text="tag"></button>
                                                <button type="button" @click.stop="removeCustomTag(tag)"
                                                        class="px-1.5 py-1 rounded-r-full transition-opacity hover:opacity-100"
                                                        :style="activeTag === tag ? 'opacity:.85; color:#fff;' : 'opacity:.6; color:var(--text-muted);'"
                                                        title="Remove this tag">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                {{-- Custom tag input --}}
                                <div>
                                    <p class="text-xs font-semibold mb-2" style="color:var(--text-secondary);">Add a custom tag</p>
                                    <div class="flex items-center gap-2">
                                        <input type="text" x-model="customTagInput"
                                               @keydown.enter.prevent="addCustomTag()"
                                               placeholder="e.g. Garden, Pool, View"
                                               maxlength="40"
                                               class="prop-input" style="flex:1;">
                                        <button type="button" @click="addCustomTag()"
                                                :disabled="!customTagInput.trim()"
                                                class="text-xs font-semibold px-3 py-2 rounded-md transition-opacity"
                                                :style="customTagInput.trim() ? 'background:var(--brand-icon); color:#fff;' : 'background:var(--surface-2); color:var(--text-muted); cursor:not-allowed;'">
                                            Add tag
                                        </button>
                                    </div>
                                    <p class="text-xs mt-1" style="color:var(--text-muted);">New tags appear in the chip row above and stay available across saves.</p>
                                </div>
                            </div>

                            {{-- Footer --}}
                            <div class="flex items-center justify-between px-5 py-3" style="border-top:1px solid var(--border); background:var(--surface-2);">
                                <span class="text-xs" style="color:var(--text-muted);">
                                    <span x-text="availableTags.length"></span> tag<span x-show="availableTags.length !== 1">s</span> in library
                                </span>
                                <button type="button" @click="sortByCategory(); manageTagsOpen = false"
                                        class="text-xs font-semibold px-4 py-2 rounded-md"
                                        style="background:var(--ds-green); color:#fff;">
                                    Done
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Sticky tag-pick bar — shows above the gallery while in Tag mode --}}
                <div x-show="tagMode" x-cloak x-transition
                     class="rounded-md px-3 py-2.5 flex flex-wrap items-center gap-2"
                     style="position:sticky; top:8px; z-index:25; background:var(--surface); border:1px solid color-mix(in srgb, var(--brand-icon) 35%, transparent); box-shadow:0 4px 12px rgba(0,0,0,0.18);">
                    <span class="text-xs font-semibold flex-shrink-0" style="color:var(--text-primary);">
                        <span x-show="!activeTag">Pick a tag, then click images to tag them.</span>
                        <span x-show="activeTag && activeTag !== '__CLEAR__'">
                            Tagging with <span class="px-2 py-0.5 rounded-full ml-1" style="background:var(--brand-icon); color:#fff;" x-text="activeTag"></span> — click images.
                        </span>
                        <span x-show="activeTag === '__CLEAR__'" style="color:var(--ds-crimson);">
                            Clearing tags — click images to untag.
                        </span>
                    </span>

                    <div class="flex flex-wrap gap-1.5 ml-auto items-center">
                        <template x-for="tag in availableTags" :key="tag">
                            <button type="button" @click="activeTag = (activeTag === tag ? null : tag)"
                                    class="text-xs font-semibold px-2.5 py-1 rounded-full transition-colors"
                                    :style="activeTag === tag
                                        ? 'background:var(--brand-icon); color:#fff;'
                                        : 'background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);'"
                                    x-text="tag"></button>
                        </template>
                        <button type="button" @click="activeTag = (activeTag === '__CLEAR__' ? null : '__CLEAR__')"
                                class="text-xs font-semibold px-2.5 py-1 rounded-full transition-colors"
                                :style="activeTag === '__CLEAR__'
                                    ? 'background:var(--ds-crimson); color:#fff;'
                                    : 'background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);'">
                            Clear tag
                        </button>
                    </div>
                </div>

                {{-- Sticky select-mode bar — shows above the gallery while in Select mode --}}
                <div x-show="selectMode" x-cloak x-transition
                     class="rounded-md px-3 py-2.5 flex flex-wrap items-center gap-2"
                     style="position:sticky; top:8px; z-index:25; background:var(--surface); border:1px solid color-mix(in srgb, var(--brand-icon) 35%, transparent); box-shadow:0 4px 12px rgba(0,0,0,0.18);">
                    <span class="text-xs font-semibold flex-shrink-0" style="color:var(--text-primary);">
                        <span x-show="selected.length === 0">Click images to select them.</span>
                        <span x-show="selected.length > 0">
                            <span x-text="selected.length"></span> image<span x-show="selected.length > 1">s</span> selected
                        </span>
                    </span>
                    <div class="flex items-center gap-2 ml-auto">
                        <button type="button" @click="selectAll()"
                                class="text-xs font-semibold px-2.5 py-1 rounded"
                                style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);">
                            Select all
                        </button>
                        <button type="button" @click="selectNone()" x-show="selected.length > 0"
                                class="text-xs font-semibold px-2.5 py-1 rounded"
                                style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">
                            Clear
                        </button>
                        <button type="button" @click="deleteSelected()"
                                :disabled="selected.length === 0"
                                class="text-xs font-semibold px-2.5 py-1 rounded inline-flex items-center gap-1 transition-opacity"
                                :style="selected.length === 0
                                    ? 'background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border); opacity:.55; cursor:not-allowed;'
                                    : 'background:var(--ds-crimson); color:#fff; border:1px solid var(--ds-crimson);'">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                            <span x-text="selected.length > 0 ? 'Delete (' + selected.length + ')' : 'Delete'"></span>
                        </button>
                    </div>
                </div>

                {{-- Image grid --}}
                <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2" x-show="images.length > 0">
                    <template x-for="(img, idx) in images" :key="img + idx">
                        <div class="gallery-item relative group rounded-md overflow-hidden"
                             :class="(tagMode || selectMode) ? 'cursor-pointer' : 'cursor-grab'"
                             style="aspect-ratio:1/1;"
                             @click="handleClick(idx)"
                             :draggable="!tagMode && !selectMode"
                             @dragstart="(!tagMode && !selectMode) && dragStart(idx, $event)"
                             @dragover.prevent="(!tagMode && !selectMode) && dragOver(idx, $event)"
                             @drop.prevent="(!tagMode && !selectMode) && dragDrop(idx)">
                            <img :src="img" alt="" class="w-full h-full object-cover transition-transform duration-200 group-hover:scale-105">

                            {{-- Cover badge --}}
                            <div x-show="idx === 0" class="absolute top-1 left-1 px-1.5 py-0.5 rounded text-[8px] font-bold text-white" style="background:rgba(0,0,0,0.7);">COVER</div>

                            {{-- Tag badge --}}
                            <div x-show="tags[img]" class="absolute bottom-1 left-1 right-1">
                                <span class="inline-block px-1.5 py-0.5 rounded text-[0.6875rem] font-bold text-white truncate max-w-full"
                                      style="background:rgba(14,165,233,0.85);"
                                      x-text="tags[img] || ''"></span>
                            </div>

                            {{-- Selection checkmark (tag or select mode) --}}
                            <div x-show="selectMode && selected.includes(idx)" class="absolute inset-0 rounded-md" style="border:3px solid var(--brand-icon); background:rgba(14,165,233,0.18);">
                                <div class="absolute top-1 right-1 w-5 h-5 rounded-full flex items-center justify-center" style="background:var(--brand-icon);">
                                    <svg class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                </div>
                            </div>

                            {{-- Hover actions (normal mode only) --}}
                            <div x-show="!tagMode && !selectMode" class="absolute top-1 right-1 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button type="button" @click.stop="viewImage(idx)"
                                        class="w-6 h-6 rounded-full flex items-center justify-center text-white"
                                        style="background:rgba(0,0,0,0.5);">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </button>
                                <button type="button" @click.stop="deleteImage(idx)"
                                        class="w-6 h-6 rounded-full flex items-center justify-center text-white"
                                        style="background:rgba(239,68,68,0.6);">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Empty state --}}
                <div x-show="images.length === 0" class="rounded-md p-8 text-center" style="background:var(--surface-2); border:1px dashed var(--border-hover);">
                    <div class="text-sm" style="color:var(--text-secondary);">No gallery images yet. Upload some above.</div>
                </div>

                {{-- Tip --}}
                <div x-show="images.length > 0 && !tagMode" class="text-[0.6875rem]" style="color:var(--text-muted);">
                    Drag to reorder · First image = cover photo · Click "Tag Images" to categorise
                </div>

                {{-- Save status --}}
                <div x-show="saveMsg" x-cloak x-transition class="text-xs font-medium" :style="saveError ? 'color:var(--ds-crimson)' : 'color:var(--ds-green)'" x-text="saveMsg"></div>
            </div>

            {{-- Lightbox with prev/next navigation --}}
            @php $galleryJsonForJs = json_encode(array_values($galleryImages)); @endphp
            <div id="lightbox" class="hidden fixed inset-0 z-[9999] flex items-center justify-center"
                 style="background:rgba(0,0,0,0.93);">

                {{-- Close area (click outside image) --}}
                <div class="absolute inset-0" onclick="closeLightbox()"></div>

                {{-- Prev arrow --}}
                <button type="button" onclick="lightboxNav(-1)"
                        class="absolute left-4 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full flex items-center justify-center text-white transition-colors"
                        style="background:rgba(255,255,255,0.12);"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'"
                        id="lightbox-prev">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                </button>

                {{-- Image --}}
                <img id="lightbox-img" src="" alt=""
                     class="relative z-10 rounded-md shadow-2xl select-none"
                     style="max-width:90vw; max-height:88vh; object-fit:contain;">

                {{-- Next arrow --}}
                <button type="button" onclick="lightboxNav(1)"
                        class="absolute right-4 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full flex items-center justify-center text-white transition-colors"
                        style="background:rgba(255,255,255,0.12);"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'"
                        id="lightbox-next">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </button>

                {{-- Close button --}}
                <button type="button" onclick="closeLightbox()"
                        class="absolute top-5 right-5 z-10 w-10 h-10 rounded-full flex items-center justify-center text-white text-lg font-bold"
                        style="background:rgba(255,255,255,0.12);"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                    ×
                </button>

                {{-- Counter --}}
                <div id="lightbox-counter"
                     class="absolute bottom-5 left-1/2 -translate-x-1/2 z-10 px-3 py-1 rounded-full text-xs font-semibold text-white"
                     style="background:rgba(0,0,0,0.5);">
                    1 / 1
                </div>
            </div>
        @endif {{-- /!$isNew gallery --}}

            {{-- Portal Agents section removed — agent info shown in live preview and sidebar --}}

        </div>

        {{-- ── CONTACTS TAB ─────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'contacts'" x-cloak class="p-6 space-y-6"
             @if($isNew)
             x-data="pendingContactsManager('{{ route('corex.properties.contacts.search-global') }}')"
             @else
             x-data="propertyContactsManager('{{ route('corex.properties.contacts.search', $property) }}')"
             @endif>
        @if($isNew)

            {{-- Pending linked contacts list --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Contacts to Link</h3>
                <template x-if="pending.length === 0 && pendingNew.length === 0">
                    <div class="text-sm" style="color:var(--text-muted);">None selected yet. Search below to add contacts.</div>
                </template>
                <template x-for="(c, idx) in pending" :key="'e'+idx">
                    <div class="flex items-center gap-3 px-4 py-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="c.name"></div>
                            <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="[c.phone, c.email].filter(Boolean).join(' · ')"></div>
                        </div>
                        <input type="hidden" :name="'pending_contact_ids['+idx+']'" :value="c.id" form="prop-update-form">
                        <button type="button" @click="remove(idx)"
                                class="text-xs font-semibold px-3 py-1.5 rounded-md transition-colors hover:opacity-80" style="color:var(--ds-crimson);">Remove</button>
                    </div>
                </template>
                <template x-for="(nc, idx) in pendingNew" :key="'n'+idx">
                    <div class="flex items-center gap-3 px-4 py-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="nc.first_name + ' ' + nc.last_name"></div>
                            <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="[nc.phone, nc.email].filter(Boolean).join(' · ')"></div>
                            <div class="text-xs font-medium mt-0.5" style="color:var(--brand-icon);">New contact (will be created)</div>
                        </div>
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][first_name]'" :value="nc.first_name" form="prop-update-form">
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][last_name]'"  :value="nc.last_name"  form="prop-update-form">
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][phone]'"      :value="nc.phone"      form="prop-update-form">
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][email]'"      :value="nc.email"      form="prop-update-form">
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][contact_type_id]'" :value="nc.contact_type_id" form="prop-update-form">
                        <button type="button" @click="removeNew(idx)"
                                class="text-xs font-semibold px-3 py-1.5 rounded-md transition-colors hover:opacity-80" style="color:var(--ds-crimson);">Remove</button>
                    </div>
                </template>
            </div>

            {{-- Search & link existing contact --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:20px;">
                <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Link Existing Contact</h3>
                <div class="relative mb-3">
                    <input type="text" x-model="query" @input.debounce.300ms="search()"
                           placeholder="Search by name, phone or email…"
                           class="w-full rounded-md px-3 py-2 text-sm pr-10"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <div x-show="loading" class="absolute right-3 top-2.5">
                        <svg class="animate-spin w-4 h-4" style="color:var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </div>
                </div>
                <div x-show="results.length > 0" class="rounded-md overflow-hidden mb-3" style="border:1px solid var(--border);">
                    <template x-for="r in results" :key="r.id">
                        <button type="button" @click="add(r)"
                                class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-sky-500/10 transition-colors"
                                style="border-bottom:1px solid var(--border); background:var(--surface);">
                            <div>
                                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="r.first_name + ' ' + r.last_name"></div>
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="[r.phone, r.email].filter(Boolean).join(' · ')"></div>
                            </div>
                            <span class="ml-auto text-xs font-semibold flex-shrink-0" style="color:var(--brand-icon);">+ Add</span>
                        </button>
                    </template>
                </div>
                <div x-show="searched && results.length === 0" class="text-sm" style="color:var(--text-muted);">No matching contacts found.</div>
            </div>

            {{-- Create new contact & add to pending --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:20px;">
                <button type="button" @click="showNewForm = !showNewForm"
                        class="flex items-center gap-2 text-sm font-semibold"
                        style="color:var(--brand-icon); background:none; border:none; cursor:pointer; padding:0;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4"
                         :class="showNewForm ? 'rotate-45' : ''" style="transition:transform .2s;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span x-text="showNewForm ? 'Cancel' : 'Create new contact &amp; link'"></span>
                </button>
                <div x-show="showNewForm" x-cloak class="mt-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="prop-required">*</span></label>
                            <input type="text" x-model="newForm.first_name"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="prop-required">*</span></label>
                            <input type="text" x-model="newForm.last_name"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone <span class="prop-required">*</span></label>
                            <input type="text" x-model="newForm.phone"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email</label>
                            <input type="email" x-model="newForm.email"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contact Type</label>
                            <select x-model="newForm.contact_type_id"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— None —</option>
                                @foreach(\App\Models\ContactType::where('is_active',true)->orderBy('sort_order')->get() as $ct)
                                <option value="{{ $ct->id }}">{{ $ct->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <button type="button" @click="addNew()"
                            class="px-5 py-2 rounded-md text-sm font-semibold text-white"
                            style="background:var(--brand-button,#0ea5e9);">
                        Add to Pending
                    </button>
                </div>
            </div>

        @else

            {{-- Linked contacts --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">
                    Linked Contacts (<span data-linked-count>{{ $property->contacts->count() }}</span>)
                </h3>
                <div id="linked-contacts-list">
                @forelse($property->contacts as $c)
                <div class="flex items-center gap-3 px-4 py-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);" data-contact-row="{{ $c->id }}">
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0 text-sm font-bold text-white"
                         style="background:{{ $c->type?->color ?? '#334155' }};">
                        {{ $c->initials }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('corex.contacts.show', $c) }}"
                           class="text-sm font-semibold no-underline hover:underline"
                           style="color:var(--text-primary);">{{ $c->full_name }}</a>
                        <div class="text-xs mt-0.5 flex gap-3" style="color:var(--text-muted);">
                            @if($c->phone)<span>{{ $c->phone }}</span>@endif
                            @if($c->email)<span>{{ $c->email }}</span>@endif
                            @if($c->pivot->role)<span class="font-semibold" style="color:var(--brand-icon);">{{ ucfirst($c->pivot->role) }}</span>@endif
                        </div>
                    </div>
                    <button type="button"
                            @click="unlinkContact({{ $c->id }}, @js($c->full_name))"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md transition-colors hover:opacity-80" style="color:var(--ds-crimson);">Unlink</button>
                </div>
                @empty
                @endforelse
                </div>
                <div id="linked-contacts-empty" class="rounded-md p-6 text-center" style="background:var(--surface-2); border:1px dashed var(--border-hover); {{ $property->contacts->count() > 0 ? 'display:none;' : '' }}">
                    <div class="text-sm" style="color:var(--text-secondary);">No contacts linked yet.</div>
                </div>
            </div>

            {{-- Link existing contact --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:20px;">
                <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Link Existing Contact</h3>

                <div class="relative mb-3">
                    <input type="text" x-model="query" @input.debounce.300ms="search()"
                           placeholder="Search by name, phone or email…"
                           class="w-full rounded-md px-3 py-2 text-sm pr-10"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <div x-show="loading" class="absolute right-3 top-2.5">
                        <svg class="animate-spin w-4 h-4" style="color:var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </div>
                </div>

                <div x-show="results.length > 0" class="rounded-md overflow-hidden mb-3"
                     style="border:1px solid var(--border);">
                    <template x-for="r in results" :key="r.id">
                        <button type="button" @click="linkExisting(r)"
                                class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-sky-500/10 transition-colors"
                                style="border-bottom:1px solid var(--border); background:var(--surface); width:100%;">
                            <div>
                                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="r.first_name + ' ' + r.last_name"></div>
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="[r.phone, r.email].filter(Boolean).join(' · ')"></div>
                            </div>
                            <span class="ml-auto text-xs font-semibold flex-shrink-0" style="color:var(--brand-icon);">+ Link</span>
                        </button>
                    </template>
                </div>

                <div x-show="searched && results.length === 0" class="text-sm mb-3" style="color:var(--text-muted);">
                    No matching contacts found.
                </div>
            </div>

            {{-- Create new contact and link --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:20px;"
                 x-data="{ open: false }">
                <button type="button" @click="open = !open"
                        class="flex items-center gap-2 text-sm font-semibold"
                        style="color:var(--brand-icon); background:none; border:none; cursor:pointer; padding:0;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4"
                         :class="open ? 'rotate-45' : ''" style="transition:transform .2s;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span x-text="open ? 'Cancel' : 'Create new contact &amp; link'"></span>
                </button>

                <div x-show="open" x-cloak class="mt-5 space-y-4">
                    <form @submit.prevent="createAndLink($el, () => { open = false; })" class="space-y-4">
                        @csrf
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="prop-required">*</span></label>
                                <input type="text" name="first_name" required
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="prop-required">*</span></label>
                                <input type="text" name="last_name" required
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone <span class="prop-required">*</span></label>
                                <input type="text" name="phone" required
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email</label>
                                <input type="email" name="email"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contact Type</label>
                                <select name="contact_type_id"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="">— None —</option>
                                    @foreach(\App\Models\ContactType::where('is_active',true)->orderBy('sort_order')->get() as $ct)
                                    <option value="{{ $ct->id }}">{{ $ct->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Role (optional)</label>
                                <input type="text" name="role" placeholder="e.g. owner, buyer, tenant"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            {{-- A.2.5 — optional SA ID number with client-side hint. --}}
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">ID number (optional)</label>
                                <input type="text" name="id_number" inputmode="numeric" maxlength="13"
                                       pattern="\d{13}" placeholder="e.g. 7610025020081"
                                       title="13 digits — empty is fine"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <p class="mt-1 text-[11px]" style="color:var(--text-muted);">SA ID — 13 digits. Leave blank if not known.</p>
                            </div>
                        </div>
                        <button type="submit"
                                :disabled="submitting"
                                class="px-5 py-2 rounded-md text-sm font-semibold text-white"
                                style="background:var(--brand-button,#0ea5e9);">
                            <span x-text="submitting ? 'Saving…' : 'Create Contact & Link'"></span>
                        </button>
                    </form>
                </div>
            </div>

        @endif {{-- /!$isNew contacts --}}
        </div>

        {{-- ── NOTES TAB ───────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'notes'" x-cloak class="p-6 space-y-5">
        @if($isNew)
            <div class="space-y-2">
                <label class="block text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">
                    Initial Note <span class="font-normal normal-case text-xs">(optional — saved with the property)</span>
                </label>
                <textarea name="initial_note" rows="5" form="prop-update-form"
                          class="w-full rounded-md px-4 py-3 text-sm resize-none"
                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                          placeholder="Add an optional note...">{{ old('initial_note') }}</textarea>
            </div>
        @else
            {{-- Add note --}}
            <form method="POST" action="{{ route('corex.properties.notes.store', $property) }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Add Note</label>
                <textarea name="content" rows="3" required
                          class="w-full rounded-md px-4 py-3 text-sm resize-none"
                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                          placeholder="Type a note..."></textarea>
                <button type="submit"
                        class="px-4 py-2 rounded-md text-sm font-semibold text-white"
                        style="background:var(--brand-button,#0ea5e9);">
                    Add Note
                </button>
            </form>

            {{-- Notes list --}}
            <div class="space-y-3">
                @forelse($property->notes as $note)
                <div class="rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1">
                            <div class="text-sm whitespace-pre-line" style="color:var(--text-primary);">{{ $note->content }}</div>
                            <div class="mt-2 text-xs" style="color:var(--text-muted);">
                                {{ $note->user?->name ?? 'Unknown' }} · {{ $note->created_at->format('d M Y H:i') }}
                            </div>
                        </div>
                        @if(auth()->id() === $note->user_id || in_array(auth()->user()->effectiveRole(), ['super_admin', 'admin']))
                        <form method="POST" action="{{ route('corex.properties.notes.destroy', [$property, $note]) }}"
                              onsubmit="return confirm('Delete this note?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs font-semibold flex-shrink-0 transition-opacity hover:opacity-80" style="color:var(--ds-crimson);">Delete</button>
                        </form>
                        @endif
                    </div>
                </div>
                @empty
                <div class="rounded-md p-6 text-center" style="background:var(--surface-2); border:1px dashed var(--border-hover);">
                    <div class="text-sm" style="color:var(--text-secondary);">No notes yet.</div>
                </div>
                @endforelse
            </div>
        @endif {{-- /!$isNew notes --}}
        </div>

        {{-- ── HISTORY TAB ──────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'history'" x-cloak class="p-6 space-y-4">
            @if(!$isNew && isset($fullAuditLog))
                @php
                    $catColors = [
                        'property' => '#94a3b8', 'compliance' => '#10b981', 'syndication' => '#3b82f6',
                        'document' => '#8b5cf6', 'marketing' => '#ec4899', 'media' => '#f59e0b',
                        'contact_link' => '#06b6d4', 'system' => '#64748b',
                    ];
                @endphp
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Property Audit Trail</h3>
                    <a href="{{ route('corex.properties.show', $property->id) }}?tab=history&export=csv"
                       class="text-[10px] font-medium px-2 py-1 rounded no-underline"
                       style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">Export CSV</a>
                </div>
                @forelse($fullAuditLog as $entry)
                    <div class="flex items-start gap-3 px-4 py-2.5 rounded" style="background:var(--surface-2); border:1px solid var(--border);" x-data="{ showDetail: false }">
                        <div class="w-2 h-2 rounded-full flex-shrink-0 mt-1.5" style="background:{{ $catColors[$entry->event_category] ?? '#94a3b8' }};"></div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $entry->human_summary ?? ucfirst(str_replace('_', ' ', $entry->event_type)) }}</span>
                                    <span class="text-[10px] ml-1 px-1.5 py-0.5 rounded" style="background:{{ $catColors[$entry->event_category] ?? '#94a3b8' }}20; color:{{ $catColors[$entry->event_category] ?? '#94a3b8' }};">{{ ucfirst($entry->event_category) }}</span>
                                </div>
                                <div class="text-[10px] flex-shrink-0" style="color:var(--text-muted);">{{ $entry->created_at->format('j M Y, H:i') }}</div>
                            </div>
                            <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">
                                @if($entry->user) {{ $entry->user->name }} @else System @endif
                            </div>
                            @if($entry->old_values || $entry->new_values || $entry->metadata)
                                <button type="button" @click="showDetail = !showDetail" class="text-[10px] mt-1 underline" style="color:var(--text-muted);" x-text="showDetail ? 'Hide details' : 'Show details'"></button>
                                <div x-show="showDetail" x-cloak class="mt-1 text-[10px] rounded p-2" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                                    @if($entry->old_values)<div><span class="font-medium">Before:</span> {{ json_encode($entry->old_values) }}</div>@endif
                                    @if($entry->new_values)<div><span class="font-medium">After:</span> {{ json_encode($entry->new_values) }}</div>@endif
                                    @if($entry->metadata)<div><span class="font-medium">Details:</span> {{ json_encode($entry->metadata) }}</div>@endif
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center">
                        <p class="text-sm" style="color:var(--text-muted);">No audit history recorded yet.</p>
                    </div>
                @endforelse
            @endif
        </div>

        {{-- ── DRIVE TAB ────────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'drive'" x-cloak class="p-6 space-y-5">
        @if($isNew)
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Upload Files</h3>
                <p class="text-xs mb-4" style="color:var(--text-muted);">Files will be uploaded when you click <strong style="color:var(--text-secondary);">Create Property</strong>.</p>
                <label class="flex items-center gap-3 px-4 py-3 rounded-md border border-dashed cursor-pointer text-sm transition-colors"
                       style="border-color:var(--border-hover); color:var(--text-secondary);"
                       onmouseover="this.style.borderColor='var(--brand-icon)'" onmouseout="this.style.borderColor='var(--border-hover)'">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" /></svg>
                    <span id="drive-create-label">Select files (multiple allowed, max 50 MB each)</span>
                    <input type="file" name="drive_files[]" multiple form="prop-update-form" class="hidden"
                           onchange="updateDriveCreateList(this);">
                </label>
                <ul id="drive-create-list" class="mt-3 space-y-1"></ul>
            </div>
        @else

            {{-- Upload --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Upload File</h3>
                <form method="POST" action="{{ route('corex.properties.files.store', $property) }}"
                      enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <div class="flex items-center gap-3 flex-wrap">
                        <label class="flex-1 flex items-center gap-3 px-4 py-3 rounded-md border border-dashed cursor-pointer transition-colors text-sm"
                               style="border-color:var(--border-hover); color:var(--text-secondary); min-width:200px;"
                               onmouseover="this.style.borderColor='var(--brand-icon)'" onmouseout="this.style.borderColor='var(--border-hover)'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" /></svg>
                            <span id="drive-label">Select a file (max 50 MB)</span>
                            <input type="file" name="file" class="hidden"
                                   onchange="document.getElementById('drive-label').textContent = this.files[0].name; document.getElementById('drive-submit').classList.remove('hidden');">
                        </label>
                        <button id="drive-submit" type="submit"
                                class="hidden px-4 py-2 rounded-md text-sm font-semibold text-white"
                                style="background:var(--brand-button,#0ea5e9);">
                            Upload
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <select name="document_type_id" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface-1); color:var(--text-primary);">
                            <option value="">Document Type (optional)</option>
                            @foreach($documentTypes as $dt)
                            <option value="{{ $dt->id }}">{{ $dt->label }}</option>
                            @endforeach
                        </select>
                        <select name="contact_id" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface-1); color:var(--text-primary);">
                            <option value="">Link to Contact (optional)</option>
                            @foreach($property->contacts as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}{{ $c->type ? ' ('.$c->type->name.')' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>
            </div>

            {{-- Document folders by type --}}
            <div class="space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">
                    Document Folders ({{ $allDriveDocs->count() }} file{{ $allDriveDocs->count() === 1 ? '' : 's' }})
                </h3>

                @php
                    // Group uploaded docs by document_type_id
                    $docsByType = $allDriveDocs->groupBy('document_type_id');
                    // Docs with no type go to "Unfiled"
                    $unfiledDocs = $docsByType->pull('') ?? collect();
                    if ($docsByType->has(null)) {
                        $unfiledDocs = $unfiledDocs->merge($docsByType->pull(null));
                    }
                @endphp

                {{-- Folder for each applicable document type --}}
                @foreach(($driveFolders ?? $documentTypes) as $folder)
                @php $folderDocs = $docsByType->get($folder->id, collect()); @endphp
                <div x-data="{ open: {{ $folderDocs->isNotEmpty() ? 'true' : 'false' }} }"
                     class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between px-4 py-2.5 transition-colors"
                            style="background:var(--surface-2);"
                            onmouseover="this.style.background='rgba(14,165,233,0.04)'" onmouseout="this.style.background='var(--surface-2)'">
                        <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" style="color:var(--brand-icon);"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                            <span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $folder->label }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full font-medium"
                                  style="background:{{ $folderDocs->isNotEmpty() ? 'rgba(14,165,233,0.12)' : 'var(--surface)' }}; color:{{ $folderDocs->isNotEmpty() ? 'var(--brand-icon)' : 'var(--text-muted)' }};">{{ $folderDocs->count() }}</span>
                        </div>
                        <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div x-show="open" x-cloak style="border-top:1px solid var(--border);">
                        @if($folderDocs->isNotEmpty())
                            @foreach($folderDocs as $doc)
                            @include('corex.properties._drive-row', ['doc' => $doc, 'property' => $property, 'documentTypes' => $documentTypes])
                            @endforeach
                        @else
                            <div class="px-4 py-4 flex items-center justify-between">
                                <span class="text-xs italic" style="color:var(--text-muted);">No files uploaded</span>
                            </div>
                        @endif
                    </div>
                </div>
                @endforeach

                {{-- Unfiled documents --}}
                @if($unfiledDocs->isNotEmpty())
                <div x-data="{ open: true }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between px-4 py-2.5 transition-colors"
                            style="background:var(--surface-2);">
                        <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                            <span class="text-xs font-semibold" style="color:var(--text-muted);">Unfiled</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full font-medium" style="background:var(--surface); color:var(--text-muted);">{{ $unfiledDocs->count() }}</span>
                        </div>
                        <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div x-show="open" x-cloak style="border-top:1px solid var(--border);">
                        @foreach($unfiledDocs as $doc)
                        @include('corex.properties._drive-row', ['doc' => $doc, 'property' => $property, 'documentTypes' => $documentTypes])
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Remaining docs that belong to types NOT in driveFolders --}}
                @php
                    $folderIds = ($driveFolders ?? $documentTypes)->pluck('id')->toArray();
                    $otherDocs = $docsByType->filter(fn($docs, $typeId) => !in_array($typeId, $folderIds))->flatten();
                @endphp
                @if($otherDocs->isNotEmpty())
                <div x-data="{ open: true }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between px-4 py-2.5 transition-colors"
                            style="background:var(--surface-2);">
                        <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                            <span class="text-xs font-semibold" style="color:var(--text-muted);">Other Documents</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full font-medium" style="background:var(--surface); color:var(--text-muted);">{{ $otherDocs->count() }}</span>
                        </div>
                        <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div x-show="open" x-cloak style="border-top:1px solid var(--border);">
                        @foreach($otherDocs as $doc)
                        @include('corex.properties._drive-row', ['doc' => $doc, 'property' => $property, 'documentTypes' => $documentTypes])
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        @endif {{-- /!$isNew drive --}}
        </div>

        {{-- ── INTELLIGENCE TAB ──────────────────────────────────────────── --}}
        <div x-show="activeTab === 'intelligence'" x-cloak class="p-6 space-y-6"
             x-data="{ sellerPreview: false }">
            @if($isNew)
                <p class="text-sm" style="color:var(--text-muted);">Save the property first to see Intelligence data.</p>
            @else
                @php
                    $intel = app(\App\Services\PropertyIntelligenceService::class);
                    $feedbackRollup = $intel->getFeedbackRollup($property->id);
                    $portalPerf = $intel->getPortalPerformance($property->id);
                    $compliance = $intel->getComplianceStatus($property->id);
                    $recommendations = $intel->getAgentRecommendations($property->id);
                    $comparables = $intel->getComparableListings($property->id);
                    $buyerSignals = $intel->getBuyerInterestSignals($property->id);
                @endphp

                {{-- Controls row: Preview toggle + Log Marketing Action + Mark as Sold --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2" x-show="!sellerPreview">
                        @if(in_array($property->status, ['active', 'for_sale', 'under_offer', 'to_let']))
                        <details class="inline">
                            <summary class="text-xs font-medium cursor-pointer px-2 py-1 rounded" style="color: #ef4444; background: color-mix(in srgb, #ef4444 8%, transparent);">Mark as Sold</summary>
                            <form method="POST" action="{{ route('corex.properties.mark-sold') }}" class="mt-2 p-3 rounded space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                                @csrf
                                <input type="hidden" name="property_id" value="{{ $property->id }}">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Sold Price (R)*</label>
                                        <input type="number" name="sold_price" required placeholder="e.g. 2500000" class="w-full rounded px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Sold Date*</label>
                                        <input type="date" name="sold_date" value="{{ now()->toDateString() }}" required class="w-full rounded px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Listing Price at Sale</label>
                                    <input type="number" name="listing_price_at_sale" value="{{ $property->price }}" class="w-full rounded px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <input type="text" name="notes" placeholder="Notes (optional)" class="w-full rounded px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <button type="submit" class="text-[10px] font-medium px-3 py-1 rounded text-white" style="background: #ef4444;">Confirm Sold</button>
                            </form>
                        </details>
                        @endif
                    <details class="inline">
                        <summary class="text-xs font-medium cursor-pointer px-2 py-1 rounded" style="color: #00d4aa; background: color-mix(in srgb, #00d4aa 8%, transparent);">+ Log Marketing Action</summary>
                        <form method="POST" action="{{ route('corex.properties.marketing-activity.store') }}" class="mt-2 p-3 rounded space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                            @csrf
                            <input type="hidden" name="property_id" value="{{ $property->id }}">
                            <div class="grid grid-cols-2 gap-2">
                                <select name="activity_type" required class="rounded px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    <option value="">Activity type…</option>
                                    @foreach(['portal_listed'=>'Portal Listed','portal_renewed'=>'Portal Renewed','photos_refreshed'=>'Photos Refreshed','price_adjusted'=>'Price Adjusted','show_day_held'=>'Show Day Held','social_share'=>'Social Media Share','featured_upgrade'=>'Featured Upgrade','marketing_email'=>'Marketing Email','other'=>'Other'] as $v => $l)
                                        <option value="{{ $v }}">{{ $l }}</option>
                                    @endforeach
                                </select>
                                <input type="date" name="occurred_at" value="{{ now()->toDateString() }}" class="rounded px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                            <input type="text" name="notes" placeholder="Notes (optional)" class="w-full rounded px-2 py-1 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            <div class="flex items-center justify-between">
                                <label class="flex items-center gap-1 text-[10px]" style="color: var(--text-muted);">
                                    <input type="checkbox" name="internal_only" value="1" class="rounded w-3 h-3"> Internal only
                                </label>
                                <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded text-white" style="background: var(--brand-button);">Log</button>
                            </div>
                        </form>
                    </details>
                    </div>{{-- end buttons group --}}
                    <label class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--text-muted);">
                        <input type="checkbox" x-model="sellerPreview" class="rounded w-3 h-3">
                        Preview as Seller
                    </label>
                </div>

                {{-- ── MARKET INTELLIGENCE SNAPSHOT (CMAInfo OCR + matched portal listings) ── --}}
                @php
                    $cmaSnapshot = $intel->getCmaSnapshot($property->id);
                    $matchedProspects = $intel->getMatchedProspectingListings($property->id);
                    $crossSourceTimeline = $intel->getCrossSourceTimeline($property->id);
                @endphp
                <div x-show="!sellerPreview">
                    @include('corex.properties.intelligence._market-snapshot', [
                        'property' => $property,
                        'cmaSnapshot' => $cmaSnapshot,
                        'matchedProspects' => $matchedProspects,
                    ])
                </div>

                <div x-show="!sellerPreview">
                    @include('corex.properties.intelligence._cross-source-timeline', [
                        'timeline' => $crossSourceTimeline,
                    ])
                </div>

                {{-- Portal Leads (P24 + PP) — Spec: .ai/specs/portal-leads.md --}}
                <div x-show="!sellerPreview">
                    @include('corex.properties.intelligence._portal-leads', [
                        'property' => $property,
                    ])
                </div>

                {{-- Section A: Performance Dashboard --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="rounded-md p-4 text-center" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $feedbackRollup['total_viewings'] }}</div>
                        <div class="text-[10px] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Total Viewings</div>
                    </div>
                    <div class="rounded-md p-4 text-center" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $portalPerf['views'] }}</div>
                        <div class="text-[10px] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Portal Views (30d)</div>
                    </div>
                    <div class="rounded-md p-4 text-center" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $buyerSignals->count() }}</div>
                        <div class="text-[10px] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Buyer Matches</div>
                    </div>
                    <div class="rounded-md p-4 text-center" style="background: var(--surface-2); border: 1px solid var(--border);">
                        @php $dom = $compliance['days_on_market']; @endphp
                        <div class="text-2xl font-bold" style="color: {{ $dom === null ? 'var(--text-muted)' : ($dom > 60 ? '#ef4444' : ($dom > 30 ? '#f59e0b' : '#10b981')) }};">
                            {{ $dom ?? '—' }}
                        </div>
                        <div class="text-[10px] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Days on Market</div>
                    </div>
                </div>

                {{-- Section B: Agent Recommendations --}}
                @if($recommendations->isNotEmpty())
                {{-- Agent view --}}
                <div x-show="!sellerPreview" class="space-y-2">
                    <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Agent Recommendations</h3>
                    @foreach($recommendations as $rec)
                        <div class="rounded-md p-3" style="background: color-mix(in srgb, #00d4aa 5%, var(--surface)); border: 1px solid color-mix(in srgb, #00d4aa 20%, var(--border));">
                            <div class="flex items-start gap-3">
                                <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color: #00d4aa;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                                <div class="flex-1">
                                    <div class="text-xs font-semibold" style="color: var(--text-primary);">{{ $rec->title }}</div>
                                    <div class="text-[11px] mt-0.5" style="color: var(--text-secondary);">{{ $rec->reasoning }}</div>
                                    @if($rec->suggested_action)
                                        <div class="text-[10px] mt-1 font-medium" style="color: #00d4aa;">→ {{ $rec->suggested_action }}</div>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center justify-between mt-2 pt-2" style="border-top: 1px solid var(--border);">
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('corex.properties.recommendations.action', $rec->id) }}">
                                        @csrf <input type="hidden" name="action" value="actioned">
                                        <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded hover:opacity-80" style="background: #00d4aa; color: #fff;">Mark Actioned</button>
                                    </form>
                                    <form method="POST" action="{{ route('corex.properties.recommendations.action', $rec->id) }}">
                                        @csrf <input type="hidden" name="action" value="dismissed">
                                        <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded hover:opacity-80" style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">Dismiss</button>
                                    </form>
                                </div>
                                <form method="POST" action="{{ route('corex.properties.recommendations.action', $rec->id) }}">
                                    @csrf <input type="hidden" name="action" value="toggle_seller_visible">
                                    <label class="flex items-center gap-1 text-[10px] cursor-pointer" style="color: var(--text-muted);">
                                        <input type="checkbox" {{ $rec->seller_visible ? 'checked' : '' }} onchange="this.form.submit()" class="w-3 h-3 rounded">
                                        Seller visible
                                    </label>
                                </form>
                            </div>
                        </div>
                    @endforeach

                    {{-- Past recommendations history --}}
                    @php $pastRecs = DB::table('property_recommendations')->where('property_id', $property->id)->where(fn($q) => $q->whereNotNull('dismissed_at')->orWhereNotNull('actioned_at'))->orderByDesc('generated_at')->get(); @endphp
                    @if($pastRecs->isNotEmpty())
                        <details class="text-xs mt-2">
                            <summary class="cursor-pointer font-medium" style="color: var(--text-muted);">View {{ $pastRecs->count() }} past recommendations</summary>
                            <div class="mt-2 space-y-1">
                                @foreach($pastRecs as $past)
                                    <div class="flex items-center justify-between px-2 py-1 rounded" style="background: var(--surface-2);">
                                        <span style="color: var(--text-secondary);">{{ $past->title }}</span>
                                        <span class="text-[10px]" style="color: var(--text-muted);">{{ $past->actioned_at ? 'Actioned' : 'Dismissed' }} {{ \Carbon\Carbon::parse($past->actioned_at ?? $past->dismissed_at)->format('d M') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>

                {{-- Seller view — rephrased recommendations --}}
                <div x-show="sellerPreview" x-cloak class="space-y-2">
                    <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Agent Insights</h3>
                    @foreach($recommendations->where('seller_visible', true) as $rec)
                        @if($rec->seller_facing_title)
                        <div class="rounded-md p-3 flex items-start gap-3" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                            <div>
                                <div class="text-xs font-semibold" style="color: var(--text-primary);">{{ $rec->seller_facing_title }}</div>
                                @if($rec->seller_facing_reasoning)
                                    <div class="text-[11px] mt-0.5" style="color: var(--text-secondary);">{{ $rec->seller_facing_reasoning }}</div>
                                @endif
                            </div>
                        </div>
                        @endif
                    @endforeach
                </div>
                @endif

                {{-- Section C: Feedback Rollup --}}
                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Feedback Summary</h3>
                    <div class="grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <span style="color: var(--text-muted);">Total feedback captures:</span>
                            <span class="font-semibold ml-1" style="color: var(--text-primary);">{{ $feedbackRollup['total_feedback_rows'] }}</span>
                        </div>
                        <div>
                            <span style="color: var(--text-muted);">Unique viewings with feedback:</span>
                            <span class="font-semibold ml-1" style="color: var(--text-primary);">{{ $feedbackRollup['total_viewings'] }}</span>
                        </div>
                    </div>
                </div>

                {{-- Section: Recent Viewings & Feedback Detail --}}
                @php
                    $recentViewings = $intel->getRecentViewings($property->id);
                @endphp
                @if($recentViewings->isNotEmpty())
                    <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Recent Viewings & Feedback</h3>
                        <div class="space-y-3">
                            @foreach($recentViewings as $rv)
                                <div class="rounded px-3 py-2.5" style="background: var(--surface); border: 1px solid var(--border);">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="text-xs font-medium" style="color: var(--text-primary);">{{ $rv['title'] }}</div>
                                            <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                                                Agent: {{ $rv['agent_name'] }}
                                                @if($rv['buyers']->isNotEmpty())
                                                    · Buyer{{ $rv['buyers']->count() > 1 ? 's' : '' }}:
                                                    @foreach($rv['buyers'] as $b)
                                                        @if(auth()->user()->hasPermission('access_contacts'))
                                                            <a href="{{ route('corex.contacts.show', $b['id']) }}" class="no-underline hover:underline" style="color:var(--brand-icon);">{{ $b['name'] }}</a>{{ !$loop->last ? ', ' : '' }}
                                                        @else
                                                            {{ $b['name'] }}{{ !$loop->last ? ', ' : '' }}
                                                        @endif
                                                    @endforeach
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-[10px] flex-shrink-0" style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($rv['event_date'])->format('j M Y') }}</div>
                                    </div>
                                    @if($rv['feedback']->isNotEmpty())
                                        @foreach($rv['feedback'] as $fb)
                                            <div class="mt-2 rounded px-2 py-1.5" style="background: var(--surface-2);">
                                                @if($fb['outcome_label'] ?? null)
                                                    <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded" style="background:rgba(16,185,129,.15); color:#059669;">{{ $fb['outcome_label'] }}</span>
                                                @endif
                                                @if($fb['seller_notes'] ?? null)
                                                    <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $fb['seller_notes'] }}</p>
                                                @endif
                                                @if($fb['internal_notes'] ?? null)
                                                    <p class="text-[11px] mt-1" style="color: var(--text-muted);"><span class="font-medium">Internal:</span> {{ $fb['internal_notes'] }}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    @else
                                        <span class="text-[10px] mt-1 inline-block px-1.5 py-0.5 rounded" style="background:rgba(107,114,128,.15); color:#6b7280;">No feedback captured</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Section: Seller Live Links (auto-created, no generate button) --}}
                <div x-show="!sellerPreview">
                    @php
                        $sellers = $property->contacts()->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])->get();
                        // Auto-create links for any sellers that don't have one yet
                        foreach ($sellers as $seller) {
                            \App\Models\PropertySellerLink::ensureExists($property->id, $seller->id);
                        }
                        $sellerLinks = \App\Models\PropertySellerLink::where('property_id', $property->id)
                            ->whereNull('revoked_at')
                            ->get();
                    @endphp
                    <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Seller Live Links</h3>
                    @if($sellerLinks->isNotEmpty())
                        <div class="space-y-1">
                            @foreach($sellerLinks as $sl)
                                @php $slContact = App\Models\Contact::withoutGlobalScopes()->find($sl->contact_id); @endphp
                                <div class="flex items-center justify-between px-3 py-2 rounded text-xs" style="background: var(--surface-2);">
                                    <div class="min-w-0 flex-1">
                                        <span class="font-medium" style="color: var(--text-primary);">{{ $slContact?->full_name ?? 'Contact' }}</span>
                                        <span class="text-[10px] ml-1" style="color: var(--text-muted);">Seller</span>
                                        <div class="text-[10px] mt-0.5 truncate" style="color: var(--text-muted);">{{ url('/property/live/' . $sl->token) }}</div>
                                        <div class="text-[10px]" style="color: var(--text-muted);">
                                            Viewed {{ $sl->access_count }}x
                                            @if($sl->last_accessed_at) · Last: {{ $sl->last_accessed_at->diffForHumans() }} @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <button type="button" onclick="navigator.clipboard.writeText('{{ url('/property/live/' . $sl->token) }}'); this.textContent='Copied!';"
                                                class="text-[10px] font-medium px-2 py-0.5 rounded" style="color: #00d4aa; background: color-mix(in srgb, #00d4aa 10%, transparent);">Copy</button>
                                        @php $sellerEmail = $slContact?->email; @endphp
                                        @if($sellerEmail)
                                            <a href="mailto:{{ $sellerEmail }}?subject={{ urlencode('Your live marketing dashboard for ' . ($property->title ?? 'your property')) }}&body={{ urlencode("Hi " . ($slContact->first_name ?? 'there') . ",\n\nYour live marketing dashboard is ready. Bookmark this link to see real-time updates on viewings, feedback, and marketing activity:\n\n" . url('/property/live/' . $sl->token) . "\n\nThis page updates automatically. Any questions, just reply to this email or call me.\n\nBest regards,\n" . (auth()->user()->name ?? 'Your Agent')) }}"
                                               class="text-[10px] font-medium px-2 py-0.5 rounded no-underline" style="color: var(--brand-icon);">Email</a>
                                        @endif
                                        <form method="POST" action="{{ route('corex.properties.seller-links.revoke', $sl->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-[10px] font-medium px-2 py-0.5 rounded" style="color: var(--text-muted);">Revoke</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs py-2" style="color: var(--text-muted);">No sellers linked to this property.</p>
                    @endif
                </div>

                {{-- Section D2: Presentations & Market Positioning --}}
                @php
                    $presentations = $intel->getPresentations($property->id);
                    $marketPosition = $intel->getLatestMarketPosition($property->id);
                @endphp
                <div>
                    <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Presentations & Market Positioning</h3>

                    {{-- Market Position card (if snapshot exists) --}}
                    @if($marketPosition)
                        <div class="rounded-md p-3 mb-3 grid grid-cols-3 gap-3 text-center" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div>
                                <div class="text-sm font-bold" style="color: var(--text-primary);">R {{ number_format($marketPosition['recommended_price'] ?? 0) }}</div>
                                <div class="text-[10px]" style="color: var(--text-muted);">Recommended Price</div>
                            </div>
                            <div>
                                <div class="text-sm font-bold" style="color: var(--text-primary);">R {{ number_format($marketPosition['area_avg_price'] ?? 0) }}</div>
                                <div class="text-[10px]" style="color: var(--text-muted);">Area Average</div>
                            </div>
                            <div>
                                <div class="text-sm font-bold" style="color: var(--text-primary);">{{ $marketPosition['comparable_sales_count'] ?? 0 }}</div>
                                <div class="text-[10px]" style="color: var(--text-muted);">Recent Comps</div>
                            </div>
                        </div>
                    @endif

                    {{-- Presentation list --}}
                    @if($presentations->isNotEmpty())
                        <div class="space-y-1">
                            @foreach($presentations as $pres)
                                <div class="flex items-center justify-between px-3 py-2 rounded" style="background: var(--surface-2);">
                                    <div>
                                        <span class="text-xs font-medium" style="color: var(--text-primary);">{{ $pres->title }}</span>
                                        <span class="text-[10px] ml-2" style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($pres->created_at)->format('d M Y') }}</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded ml-1" style="background: {{ $pres->status === 'finalized' ? 'rgba(16,185,129,0.1)' : 'rgba(245,158,11,0.1)' }}; color: {{ $pres->status === 'finalized' ? '#10b981' : '#f59e0b' }};">{{ ucfirst($pres->status) }}</span>
                                    </div>
                                    <div class="flex items-center gap-2" x-show="!sellerPreview">
                                        @if(\Illuminate\Support\Facades\Route::has('presentations.show'))
                                            <a href="{{ route('presentations.show', $pres->id) }}" target="_blank" class="text-[10px] font-medium no-underline" style="color: var(--brand-icon);">View</a>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs" style="color: var(--text-muted);">No presentations created for this property yet.</p>
                    @endif

                    {{-- Generate Updated Presentation (Phase 2 placeholder) --}}
                    <div x-show="!sellerPreview" class="mt-2">
                        <button type="button" disabled
                                class="text-[10px] font-medium px-3 py-1.5 rounded opacity-50 cursor-not-allowed"
                                style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);"
                                title="Coming soon: weekly auto-refreshed market positioning. Market data is being captured now to power this.">
                            Generate Updated Presentation (Coming Soon)
                        </button>
                    </div>
                </div>

                {{-- Section E: Buyer Interest Signals --}}
                @if($buyerSignals->isNotEmpty())
                <div>
                    <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">
                        <span x-show="!sellerPreview">Buyer Interest Signals</span>
                        <span x-show="sellerPreview" x-cloak>Buyer Interest</span>
                    </h3>
                    <div x-show="sellerPreview" x-cloak class="text-xs" style="color: var(--text-secondary);">
                        {{ $buyerSignals->count() }} potential buyers match this property's profile.
                    </div>
                    <div x-show="!sellerPreview" class="space-y-1">
                        @foreach($buyerSignals as $buyer)
                            <div class="flex items-center justify-between px-3 py-2 rounded" style="background: var(--surface-2);">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium" style="color: var(--text-primary);">{{ $buyer['name'] }}</span>
                                    @php $statePill = match($buyer['state']) { 'warm' => '#10b981', 'cold' => '#f59e0b', 'lost' => '#ef4444', default => '#3b82f6' }; @endphp
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold" style="background: {{ $statePill }}20; color: {{ $statePill }};">{{ $buyer['state'] ?? 'new' }}</span>
                                </div>
                                <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $buyer['id'], 'prefill_class' => 'viewing']) }}"
                                   class="text-[10px] font-medium no-underline" style="color: #00d4aa;">Schedule Viewing</a>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Section F: Comparable Listings --}}
                @if($comparables->isNotEmpty())
                <div>
                    <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Comparable Listings</h3>
                    <div class="space-y-1">
                        @foreach($comparables as $comp)
                            <a href="{{ route('corex.properties.show', $comp['id']) }}" target="_blank"
                               class="flex items-center justify-between px-3 py-2 rounded no-underline hover:opacity-80" style="background: var(--surface-2);">
                                <div>
                                    <span class="text-xs font-medium" style="color: var(--text-primary);">{{ $comp['title'] }}</span>
                                    <span class="text-[10px] ml-2" style="color: var(--text-muted);">{{ $comp['suburb'] }}</span>
                                </div>
                                <div class="text-xs" style="color: var(--text-secondary);">
                                    R {{ number_format($comp['price'] ?? 0) }}
                                    @if($comp['days_on_market']) · {{ $comp['days_on_market'] }}d @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Section G: Compliance Badges --}}
                <div class="flex flex-wrap gap-3">
                    <span class="text-[10px] px-2 py-1 rounded font-medium"
                          style="background: {{ $compliance['mandate_expired'] ?? true ? 'color-mix(in srgb, var(--ds-crimson) 10%, transparent)' : 'rgba(16,185,129,0.1)' }}; color: {{ $compliance['mandate_expired'] ?? true ? '#ef4444' : '#10b981' }}; border: 1px solid {{ $compliance['mandate_expired'] ?? true ? 'rgba(239,68,68,0.2)' : 'rgba(16,185,129,0.2)' }};">
                        Mandate: {{ $compliance['mandate_type'] ?? 'None' }} {{ $compliance['mandate_expired'] ?? true ? '(EXPIRED)' : ($compliance['mandate_expiry'] ? 'until ' . $compliance['mandate_expiry'] : '') }}
                    </span>
                    <span class="text-[10px] px-2 py-1 rounded font-medium"
                          style="background: {{ ($compliance['seller_fica_complete'] ?? false) ? 'rgba(16,185,129,0.1)' : 'color-mix(in srgb, var(--ds-crimson) 10%, transparent)' }}; color: {{ ($compliance['seller_fica_complete'] ?? false) ? '#10b981' : '#ef4444' }};">
                        Seller FICA: {{ ($compliance['seller_fica_complete'] ?? false) ? 'Complete' : 'Outstanding' }}
                    </span>
                    <span class="text-[10px] px-2 py-1 rounded font-medium"
                          style="background: {{ ($compliance['published'] ?? false) ? 'rgba(16,185,129,0.1)' : 'rgba(107,114,128,0.1)' }}; color: {{ ($compliance['published'] ?? false) ? '#10b981' : '#6b7280' }};">
                        Listing: {{ ($compliance['published'] ?? false) ? 'Active' : 'Unpublished' }}
                    </span>
                </div>
            @endif
        </div>

        {{-- ── CORE MATCHES TAB ──────────────────────────────────────────── --}}
        <div x-show="activeTab === 'core-matches'" x-cloak class="p-6 space-y-4">
        @if($isNew)
            <p class="text-sm" style="color:var(--text-muted);">Save the property first to see Core Matches.</p>
        @elseif($coreMatches->isEmpty())
            <div class="rounded-md py-14 text-center" style="background:var(--surface-2); border:1px solid var(--border);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10 mx-auto mb-3 opacity-20" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
                <p class="font-bold text-sm" style="color:var(--text-muted);">This property doesn't match any active Core Match criteria.</p>
                <p class="text-xs mt-1" style="color:var(--text-muted); opacity:.7;">When a client's search criteria matches this property it will appear here.</p>
            </div>
        @else
            <p class="text-xs font-semibold" style="color:var(--text-muted);">
                This property matches the criteria of <strong style="color:var(--text-secondary);">{{ $coreMatches->count() }} {{ Str::plural('client search', $coreMatches->count()) }}</strong>.
            </p>
            <div class="space-y-3">
            @foreach($coreMatches as $cm)
            @php $views = $cm->propertyViewCount($property->id); @endphp
            <div x-data="{ open: false }"
                 class="rounded-md overflow-hidden"
                 style="background:var(--surface); border:1px solid var(--border);">

                {{-- Row --}}
                <button type="button"
                        @click="open = !open"
                        class="w-full flex items-center gap-4 px-5 py-4 text-left"
                        style="background:transparent;">

                    {{-- Client avatar --}}
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0 text-xs font-bold text-white"
                         style="background:linear-gradient(135deg,#0b2a4a,#0ea5e9);">
                        {{ strtoupper(substr($cm->contact->first_name ?? '?', 0, 1) . substr($cm->contact->last_name ?? '', 0, 1)) }}
                    </div>

                    {{-- Client info --}}
                    <div class="flex-1 min-w-0 text-left">
                        <div class="text-sm font-bold truncate" style="color:var(--text-primary);">
                            {{ $cm->contact->full_name ?? '—' }}
                        </div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">
                            {{ $cm->listingTypeLabel() }} · {{ $cm->priceRangeLabel() }}
                            @if($cm->suburb) · 📍 {{ $cm->suburb }} @endif
                        </div>
                    </div>

                    {{-- View count --}}
                    <div class="flex-shrink-0 text-center px-3">
                        <div class="text-base font-extrabold" style="color:{{ $views > 0 ? 'var(--brand-icon)' : 'var(--text-muted)' }};">
                            {{ $views }}
                        </div>
                        <div class="text-[0.6875rem] font-semibold" style="color:var(--text-muted);">
                            {{ $views === 1 ? 'view' : 'views' }}
                        </div>
                    </div>

                    {{-- Chevron --}}
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0 transition-transform duration-200"
                         :class="open ? 'rotate-90' : ''"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                         style="color:var(--text-muted);">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                </button>

                {{-- Expanded: agent details --}}
                <div x-show="open" x-collapse style="border-top:1px solid var(--border);">
                    <div class="px-5 py-4 grid grid-cols-1 sm:grid-cols-2 gap-4">

                        {{-- Agent card --}}
                        <div class="rounded-md p-4 space-y-2" style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="text-[0.6875rem] font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Agent</div>
                            @if($cm->createdBy)
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0 text-xs font-bold text-white"
                                     style="background:linear-gradient(135deg,#1e3a5f,#0ea5e9);">
                                    {{ strtoupper(substr($cm->createdBy->name, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-bold truncate" style="color:var(--text-primary);">{{ $cm->createdBy->name }}</div>
                                    @if($cm->createdBy->branch)
                                    <div class="text-[0.6875rem]" style="color:var(--text-muted);">{{ $cm->createdBy->branch->name }}</div>
                                    @endif
                                </div>
                            </div>
                            <div class="space-y-1.5 pt-1">
                                @if($cm->createdBy->email)
                                <a href="mailto:{{ $cm->createdBy->email }}"
                                   class="flex items-center gap-2 text-xs no-underline hover:underline"
                                   style="color:var(--text-secondary);">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                    {{ $cm->createdBy->email }}
                                </a>
                                @endif
                                @if($cm->createdBy->cell ?? $cm->createdBy->phone ?? null)
                                <a href="tel:{{ $cm->createdBy->cell ?? $cm->createdBy->phone }}"
                                   class="flex items-center gap-2 text-xs no-underline hover:underline"
                                   style="color:var(--text-secondary);">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                    {{ $cm->createdBy->cell ?? $cm->createdBy->phone }}
                                </a>
                                @endif
                            </div>
                            @else
                            <p class="text-xs" style="color:var(--text-muted);">Agent not found.</p>
                            @endif
                        </div>

                        {{-- Client + match details --}}
                        <div class="rounded-md p-4 space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="text-[0.6875rem] font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Client</div>
                            <div class="text-sm font-bold" style="color:var(--text-primary);">{{ $cm->contact->full_name ?? '—' }}</div>
                            <div class="space-y-1">
                                @if($cm->contact->phone ?? null)
                                <a href="tel:{{ $cm->contact->phone }}" class="flex items-center gap-2 text-xs no-underline hover:underline" style="color:var(--text-secondary);">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                    {{ $cm->contact->phone }}
                                </a>
                                @endif
                                @if($cm->contact->email ?? null)
                                <a href="mailto:{{ $cm->contact->email }}" class="flex items-center gap-2 text-xs no-underline hover:underline" style="color:var(--text-secondary);">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                    {{ $cm->contact->email }}
                                </a>
                                @endif
                            </div>
                            <div class="pt-1 flex flex-wrap gap-x-4 gap-y-1.5 text-[0.6875rem]" style="color:var(--text-muted);">
                                @foreach([[$cm->beds_min,'Min Beds'],[$cm->baths_min,'Min Baths'],[$cm->garages_min,'Min Gar']] as [$v,$l])
                                @if($v !== null)<span><strong style="color:var(--text-secondary);">{{ $v }}+</strong> {{ $l }}</span>@endif
                                @endforeach
                                @if($cm->floor_size_min || $cm->floor_size_max)
                                <span><strong style="color:var(--text-secondary);">{{ $cm->floor_size_min ? number_format($cm->floor_size_min) : '—' }}–{{ $cm->floor_size_max ? number_format($cm->floor_size_max) : '—' }}</strong> m² floor</span>
                                @endif
                            </div>
                            @if($cm->contact)
                            <a href="{{ route('corex.contacts.matches.results', [$cm->contact, $cm]) }}"
                               class="inline-flex items-center gap-1.5 mt-2 text-xs font-semibold no-underline"
                               style="color:var(--brand-icon);">
                                View match results →
                            </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
            </div>
        @endif
        </div>

        </div>{{-- /tab container (right column) --}}

    </div>{{-- /two-column layout --}}

    {{-- Unsaved changes modal (in-app navigation only — browser still shows native dialog on F5/close) --}}
    {{-- Contact required modal --}}
    <div x-show="contactRequiredModalOpen" x-cloak
         class="fixed inset-0 z-[9999] flex items-center justify-center"
         style="background: rgba(0,0,0,0.6);"
         @keydown.escape.window="contactRequiredModalOpen = false"
         @click.self="contactRequiredModalOpen = false">
        <div class="rounded-md shadow-2xl w-full max-w-md mx-4 p-6"
             style="background: var(--surface); border: 1px solid var(--border);"
             @click.stop>
            <div class="flex items-start gap-3 mb-3">
                <div class="flex-shrink-0 rounded-full p-2"
                     style="background: color-mix(in srgb, var(--brand-icon) 15%, transparent);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" style="color: var(--brand-icon);"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Contact required</h3>
                    <p class="text-sm mt-1" style="color: var(--text-secondary);">
                        A contact must be linked to this property before it can be saved.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 justify-end mt-4">
                <button type="button"
                        class="px-4 py-2 text-sm rounded-md"
                        style="background: transparent; color: var(--text-secondary); border: 1px solid var(--border);"
                        @click="contactRequiredModalOpen = false">
                    Cancel
                </button>
                <button type="button"
                        class="px-4 py-2 text-sm rounded-md font-medium text-white"
                        style="background: var(--brand-button, #0ea5e9);"
                        @click="activeTab = 'contacts'; contactRequiredModalOpen = false;">
                    Add Contact
                </button>
            </div>
        </div>
    </div>

    {{-- Unsaved-changes modal (vanilla, lives outside Alpine scope chaos) --}}
    <div id="prop-unsaved-modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); width:100%; max-width:28rem; margin:0 1rem; padding:1.5rem;">
            <h3 style="font-size:1.125rem; font-weight:600; margin-bottom:0.5rem; color:var(--text-primary);">Unsaved changes</h3>
            <p style="font-size:0.875rem; margin-bottom:1.25rem; color:var(--text-secondary);">
                You have unsaved changes on this property. What would you like to do?
            </p>
            <div style="display:flex; flex-wrap:wrap; gap:0.5rem; justify-content:flex-end;">
                <button type="button" data-prop-unsaved="cancel"
                        style="padding:0.5rem 1rem; font-size:0.875rem; border-radius:6px; background:transparent; color:var(--text-secondary); border:1px solid var(--border); cursor:pointer;">
                    Cancel
                </button>
                <button type="button" data-prop-unsaved="discard"
                        style="padding:0.5rem 1rem; font-size:0.875rem; border-radius:6px; font-weight:500; background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border); cursor:pointer;">
                    Discard changes
                </button>
                <button type="button" data-prop-unsaved="save"
                        style="padding:0.5rem 1rem; font-size:0.875rem; border-radius:6px; font-weight:500; color:white; background:var(--brand-button, #0ea5e9); border:none; cursor:pointer;">
                    Save changes
                </button>
            </div>
        </div>
    </div>

</div>{{-- /w-full --}}

@push('scripts')
<script>
// ─────────────────────────────────────────────────────────────────────
// Property page: unsaved-changes guard (self-contained, no Alpine deps)
// ─────────────────────────────────────────────────────────────────────
(function () {
    var dirty = false;
    var pending = null; // { type: 'tab'|'url', value: string }
    var initialSnapshot = null;

    function form() { return document.getElementById('prop-update-form'); }
    function modal() { return document.getElementById('prop-unsaved-modal'); }
    function saveBtn() { return document.querySelector('[data-prop-save]'); }
    function saveLabel() { return document.querySelector('[data-prop-save] .prop-save-label'); }

    function snapshot() {
        var f = form();
        if (!f) return '';
        try { return new URLSearchParams(new FormData(f)).toString(); } catch (e) { return ''; }
    }

    function paintSaveBtn() {
        var btn = saveBtn(); var lbl = saveLabel();
        if (lbl) lbl.textContent = dirty ? 'Save Changes *' : 'Save Changes';
        if (btn) {
            if (dirty) {
                btn.style.background = 'var(--brand-button, #0ea5e9)';
                btn.style.color = '#fff';
                btn.style.borderColor = 'var(--brand-button, #0ea5e9)';
                btn.classList.add('is-dirty');
            } else {
                btn.style.background = '';
                btn.style.color = '';
                btn.style.borderColor = '';
                btn.classList.remove('is-dirty');
            }
        }
    }

    function recompute() {
        var current = snapshot();
        dirty = (current !== initialSnapshot);
        paintSaveBtn();
    }

    function setClean() {
        initialSnapshot = snapshot();
        dirty = false;
        paintSaveBtn();
    }

    function showModal() { var m = modal(); if (m) m.style.display = 'flex'; }
    function hideModal() { var m = modal(); if (m) m.style.display = 'none'; pending = null; }

    function isInternalNavigableAnchor(a, e) {
        if (!a) return false;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) return false;
        if (a.target === '_blank' || a.hasAttribute('download')) return false;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
        try {
            var url = new URL(a.href, window.location.href);
            if (url.origin !== window.location.origin) return false;
            if (url.pathname === window.location.pathname && url.search === window.location.search) return false;
        } catch (err) { return false; }
        return true;
    }

    // Capture-phase listener: catch tab buttons and internal anchor nav
    document.addEventListener('click', function (e) {
        if (!dirty) return;
        if (modal() && modal().style.display === 'flex') return;

        var tabBtn = e.target.closest && e.target.closest('[data-prop-tab]');
        if (tabBtn) {
            // ignore click on already-active tab
            var key = tabBtn.getAttribute('data-prop-tab');
            var rootEl = document.querySelector('[x-data*="activeTab"]');
            var activeKey = null;
            try { activeKey = rootEl && window.Alpine && Alpine.$data(rootEl).activeTab; } catch (err) {}
            if (key && key !== activeKey) {
                e.preventDefault(); e.stopPropagation();
                pending = { type: 'tab', value: key };
                showModal();
            }
            return;
        }

        var a = e.target.closest && e.target.closest('a[href]');
        if (a && isInternalNavigableAnchor(a, e)) {
            e.preventDefault(); e.stopPropagation();
            pending = { type: 'url', value: a.href };
            showModal();
        }
    }, true);

    // Modal buttons
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-prop-unsaved]');
        if (!btn) return;
        var action = btn.getAttribute('data-prop-unsaved');

        if (action === 'cancel') { hideModal(); return; }

        if (action === 'discard') {
            var p = pending; hideModal(); setClean();
            if (p && p.type === 'tab') {
                window.dispatchEvent(new CustomEvent('corex:switch-tab', { detail: p.value }));
            } else if (p && p.type === 'url') {
                window.location.href = p.value;
            }
            return;
        }

        if (action === 'save') {
            var f = form();
            if (f && window.coreXPropertyContactGuard && !window.coreXPropertyContactGuard(null, f)) {
                hideModal(); return;
            }
            hideModal(); setClean();
            if (f) f.submit();
        }
    });

    // ESC closes modal
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal() && modal().style.display === 'flex') hideModal();
    });

    // beforeunload: warn on hard navigation away
    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        if (modal() && modal().style.display === 'flex') return;
        e.preventDefault(); e.returnValue = '';
    });

    // Recompute dirty on every input/change in the form
    document.addEventListener('input', function (e) {
        if (!e.target.closest || !e.target.closest('#prop-update-form')) return;
        recompute();
    }, true);
    document.addEventListener('change', function (e) {
        if (!e.target.closest || !e.target.closest('#prop-update-form')) return;
        recompute();
    }, true);

    // Any other form submitting (link/unlink contact, delete note, etc.) is intentional —
    // clear dirty so beforeunload doesn't prompt.
    document.addEventListener('submit', function (e) {
        if (!e.target || e.target.id === 'prop-update-form') return;
        setClean();
    }, true);

    function init() {
        if (!form()) return;
        initialSnapshot = snapshot();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for save form @submit handler
    window.coreXPropDirty = {
        isDirty: function () { return dirty; },
        clear: setClean,
        recompute: recompute,
    };
})();

window.coreXPropertyContactGuard = function (event, form) {
    if (!form) return true;
    var isNew = form.getAttribute('data-is-new') === '1';
    var hasContact;
    if (isNew) {
        hasContact = document.querySelectorAll(
            "input[name^='pending_contact_ids'], input[name^='pending_new_contacts']"
        ).length > 0;
    } else {
        hasContact = parseInt(form.getAttribute('data-contact-count') || '0', 10) > 0;
    }
    if (hasContact) return true;
    if (event) event.preventDefault();
    window.dispatchEvent(new CustomEvent('corex:contact-required'));
    return false;
};

// Pending contacts manager (create form — no property ID yet)
function pendingContactsManager(searchUrl) {
    return {
        query: '',
        results: [],
        loading: false,
        searched: false,
        pending: [],    // existing contacts to link: { id, name, phone, email }
        pendingNew: [], // new contacts to create+link: { first_name, last_name, phone, email, contact_type_id }
        newForm: { first_name: '', last_name: '', phone: '', email: '', contact_type_id: '' },
        showNewForm: false,

        async search() {
            if (this.query.length < 1) { this.results = []; this.searched = false; return; }
            this.loading = true;
            try {
                const excludeIds = this.pending.map(p => p.id).filter(Boolean);
                let url = searchUrl + '?q=' + encodeURIComponent(this.query);
                excludeIds.forEach(id => { url += '&exclude[]=' + id; });
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                this.results = await res.json();
                this.searched = true;
            } finally {
                this.loading = false;
            }
        },

        add(contact) {
            if (!this.pending.find(p => p.id === contact.id)) {
                this.pending.push({
                    id:    contact.id,
                    name:  contact.first_name + ' ' + contact.last_name,
                    phone: contact.phone || '',
                    email: contact.email || '',
                });
            }
            this.results = [];
            this.query   = '';
        },

        remove(idx)    { this.pending.splice(idx, 1); },
        removeNew(idx) { this.pendingNew.splice(idx, 1); },

        addNew() {
            if (!this.newForm.first_name.trim() || !this.newForm.last_name.trim() || !this.newForm.phone.trim()) {
                alert('First name, last name and phone are required.');
                return;
            }
            this.pendingNew.push({ ...this.newForm });
            this.newForm      = { first_name: '', last_name: '', phone: '', email: '', contact_type_id: '' };
            this.showNewForm  = false;
        },
    };
}

// Drive files selected during create: update label + file list
function updateDriveCreateList(input) {
    const label = document.getElementById('drive-create-label');
    const list  = document.getElementById('drive-create-list');
    const files = Array.from(input.files);
    label.textContent = files.length + ' file' + (files.length !== 1 ? 's' : '') + ' selected';
    list.innerHTML = files.map(f =>
        '<li class="text-xs" style="color:var(--text-muted);">• ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(1) + ' MB)</li>'
    ).join('');
}

// Property contacts search manager
function propertyContactsManager(searchUrl) {
    return {
        query: '',
        results: [],
        loading: false,
        searched: false,
        submitting: false,
        async search() {
            if (this.query.length < 1) { this.results = []; this.searched = false; return; }
            this.loading = true;
            try {
                const res = await fetch(searchUrl + '?q=' + encodeURIComponent(this.query), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                this.results = await res.json();
                this.searched = true;
            } finally {
                this.loading = false;
            }
        },
        _csrf() {
            const m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.getAttribute('content') : '';
        },
        _afterAdd(contact) {
            window.coreXAppendLinkedContact(contact);
            // Drop this contact from current search results
            this.results = this.results.filter(r => r.id !== contact.id);
            // Dismiss contact-required modal and return to info tab so user can save
            window.dispatchEvent(new CustomEvent('corex:contact-added'));
        },
        async linkExisting(r) {
            if (this.submitting) return;
            this.submitting = true;
            try {
                const res = await fetch(@js(route('corex.properties.contacts.link', $property)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify({ contact_id: r.id })
                });
                if (!res.ok) { alert('Failed to link contact.'); return; }
                const data = await res.json();
                if (data.ok && data.contact) this._afterAdd(data.contact);
            } catch (e) {
                alert('Network error while linking contact.');
            } finally {
                this.submitting = false;
            }
        },
        async createAndLink(formEl, onDone) {
            if (this.submitting) return;
            this.submitting = true;
            try {
                const fd = new FormData(formEl);
                const payload = {};
                for (const [k, v] of fd.entries()) { if (k !== '_token') payload[k] = v; }
                let res = await fetch(@js(route('corex.properties.contacts.createAndLink', $property)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    body: JSON.stringify(payload)
                });
                if (res.status === 409) {
                    const data = await res.json();
                    const dup = data.duplicate_detected || {};
                    const names = (dup.duplicates || []).map(d => '• ' + d.name + (d.phone ? ' (' + d.phone + ')' : '')).join('\n');
                    if (dup.mode === 'hard_block_request') {
                        alert('A possible duplicate contact exists. Please ask an admin to review:\n\n' + names);
                        return;
                    }
                    if (dup.can_override || dup.mode === 'soft_warn') {
                        if (!confirm('Possible duplicate contact(s) found:\n\n' + names + '\n\nCreate anyway?')) return;
                        payload.bypass_duplicate_check = 1;
                        res = await fetch(@js(route('corex.properties.contacts.createAndLink', $property)), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': this._csrf(),
                            },
                            body: JSON.stringify(payload)
                        });
                    } else {
                        alert('Duplicate contact exists:\n\n' + names);
                        return;
                    }
                }
                if (!res.ok) {
                    if (res.status === 422) {
                        const data = await res.json().catch(() => ({}));
                        const msgs = Object.values(data.errors || {}).flat().join('\n');
                        alert('Please fix the following:\n' + (msgs || 'Validation failed.'));
                    } else {
                        alert('Failed to create contact.');
                    }
                    return;
                }
                const data = await res.json();
                if (data.ok && data.contact) {
                    this._afterAdd(data.contact);
                    formEl.reset();
                    if (typeof onDone === 'function') onDone();
                }
            } catch (e) {
                alert('Network error while creating contact.');
            } finally {
                this.submitting = false;
            }
        },
        async unlinkContact(id, name) {
            if (!confirm('Unlink ' + name + ' from this property?')) return;
            try {
                const urlTpl = @js(route('corex.properties.contacts.unlink', [$property->id, 0]));
                const res = await fetch(urlTpl.replace(/\/0$/, '/' + id), {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                });
                if (!res.ok) { alert('Failed to unlink contact.'); return; }
                const data = await res.json();
                window.coreXRemoveLinkedContact(id, data.count);
            } catch (e) {
                alert('Network error while unlinking contact.');
            }
        },
    };
}

// DOM helpers — keep the linked-contacts list in sync with AJAX results,
// update the data-contact-count on the main form, and toggle empty state.
window.coreXAppendLinkedContact = function (c) {
    const list = document.getElementById('linked-contacts-list');
    const empty = document.getElementById('linked-contacts-empty');
    if (!list) return;
    if (list.querySelector('[data-contact-row="' + c.id + '"]')) return; // already present
    const meta = [];
    if (c.phone) meta.push('<span>' + escapeHtml(c.phone) + '</span>');
    if (c.email) meta.push('<span>' + escapeHtml(c.email) + '</span>');
    if (c.role)  meta.push('<span class="font-semibold" style="color:var(--brand-icon);">' + escapeHtml(c.role.charAt(0).toUpperCase() + c.role.slice(1)) + '</span>');
    const row = document.createElement('div');
    row.className = 'flex items-center gap-3 px-4 py-3 rounded-md mb-2';
    row.setAttribute('style', 'background:var(--surface-2); border:1px solid var(--border);');
    row.setAttribute('data-contact-row', c.id);
    row.innerHTML =
        '<div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0 text-sm font-bold text-white" style="background:' + escapeHtml(c.type_color || '#334155') + ';">' + escapeHtml(c.initials || '?') + '</div>' +
        '<div class="flex-1 min-w-0">' +
            '<a href="' + escapeHtml(c.show_url) + '" class="text-sm font-semibold no-underline hover:underline" style="color:var(--text-primary);">' + escapeHtml(c.full_name) + '</a>' +
            '<div class="text-xs mt-0.5 flex gap-3" style="color:var(--text-muted);">' + meta.join('') + '</div>' +
        '</div>' +
        '<button type="button" class="text-xs font-semibold px-3 py-1.5 rounded-md transition-colors hover:opacity-80" style="color:var(--ds-crimson);">Unlink</button>';
    row.querySelector('button').addEventListener('click', function () {
        // Defer to Alpine manager so we go through the same unlink flow.
        const wrap = document.querySelector('[x-data^="propertyContactsManager"]');
        if (wrap && wrap._x_dataStack && wrap._x_dataStack[0]) {
            wrap._x_dataStack[0].unlinkContact(c.id, c.full_name);
        }
    });
    list.appendChild(row);
    if (empty) empty.style.display = 'none';
    // Update count badge + main-form data-contact-count
    document.querySelectorAll('[data-linked-count]').forEach(el => el.textContent = list.children.length);
    const form = document.getElementById('prop-update-form');
    if (form) form.setAttribute('data-contact-count', String(list.children.length));
};

window.coreXRemoveLinkedContact = function (id, serverCount) {
    const list = document.getElementById('linked-contacts-list');
    const empty = document.getElementById('linked-contacts-empty');
    if (!list) return;
    const row = list.querySelector('[data-contact-row="' + id + '"]');
    if (row) row.remove();
    const count = typeof serverCount === 'number' ? serverCount : list.children.length;
    document.querySelectorAll('[data-linked-count]').forEach(el => el.textContent = count);
    const form = document.getElementById('prop-update-form');
    if (form) form.setAttribute('data-contact-count', String(count));
    if (empty) empty.style.display = count > 0 ? 'none' : '';
};

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// ── Spaces & Features Manager ─────────────────────────────────────────────
const _SPACE_FEATURES = {
    'Bedroom': {
        'General': ['Air Conditioned','Balcony','Built-in Cupboards','Fan','Fireplace','Fridge','TV Port','Walk in Closet'],
        'Bed':     ['Double Bed','King Bed','Queen Bed','Single Bed','Twin Bed'],
        'Door':    ['Sliding Doors'],
        'Floor':   ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Underfloor Heating','Vinyl Floors','Wooden Floors'],
        'Layout':  ['Open Plan'],
        'Wall':    ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
        'Window':  ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
    },
    'Bathroom': {
        'General': ['Basin','Bath','Bidet','Built-in Cupboards','Communal','Double Basin','En-suite','Full Bathroom','Guest Toilet','Half Bathroom','Jacuzzi Bath','Main en-suite','Separate Toilet','Shower','Toilet','Urinal','Commercial','Executive','In Unit','Unisex'],
        'Door':    ['Sliding Doors'],
        'Floor':   ['Tiled Floors','Underfloor Heating','Vinyl Floors','Wooden Floors'],
        'Wall':    ['Brick Wall','Concrete Wall','Glass Wall','Plaster Wall','Wood Wall'],
        'Window':  ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
    },
    'Garage': {
        'General': ['Built-in Cupboards','Dishwasher','Dishwasher Connection','Double Garage','Garbage Disposal','Single Garage','Tandem Garage','Tumble Dryer','Washing Machine','Washing Machine Connection','Zinc'],
        'Door':    ['Automated Garage Doors','Rollup Door','Tipup Door'],
        'Floor':   ['Tiled Floors'],
    },
    'Parking': {
        'General': ['Carport','Secure Parking','Shade Net Covered Parking','Street Parking','Underground Parking','Visitors Parking'],
        'Layout':  ['Double Parking','Single Parking','Tandem Parking','Triple Parking'],
    },
    'Pool': {
        'General': ['Auto Cleaning Equipment','Chlorinator','Fenced','Heated','Safety Net','Water Feature'],
        'Type':    ['Communal Pool','Fibreglass in Ground','Indoor Pool','Portapool','Rock Pool','Splash Pool'],
    },
    'Garden': {
        'General': ['Communal','Garden Services','Garden Terrace','Irrigation','Landscaped','Lighting','Sprinklers','Water Feature','Zen Garden'],
        'Wall':    ['Brick Wall','Concrete Wall','Stone Wall'],
    },
    'Kitchen': {
        'General': ['Air Conditioned','Basin','Breakfast Nook','Built-in Cupboards','Coffee Machine','Dishwasher','Dishwasher Connection','Double Basin','Extractor Fan','Eye Level Oven','Fan','Fireplace','Fridge','Garbage Disposal','Gas Hob','Gas Oven','Granite Tops','Grill','Hob','Icemaker','Oven and Hob','Pantry','Sink','Tumble Dryer','Under Counter Oven','Washing Machine','Washing Machine Connection','Water Cooler','Zinc'],
        'Door':    ['Sliding Doors'],
        'Floor':   ['Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
        'Layout':  ['Open Plan'],
        'Wall':    ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
        'Window':  ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
    },
    'Dining Room': {
        'General': ['Air Conditioned','Built-In Braai','Built-in Cupboards','Fan','Fireplace'],
        'Door':    ['Sliding Doors'],
        'Floor':   ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Underfloor Heating','Vinyl Floors','Wooden Floors'],
        'Layout':  ['Open Plan'],
        'Wall':    ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
        'Window':  ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
    },
    'Lounge': {
        'General': ['Air Conditioned','Balcony','Built-In Braai','Built-in Cupboards','Fan','Fireplace','TV Port'],
        'Door':    ['Sliding Doors'],
        'Floor':   ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Underfloor Heating','Vinyl Floors','Wooden Floors'],
        'Layout':  ['Open Plan'],
        'Wall':    ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
        'Window':  ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
    },
    'Study': {
        'General': ['Air Conditioned','Built-in Cupboards','Fan','Fridge','TV Port'],
        'Door':    ['Sliding Doors'],
        'Floor':   ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
        'Wall':    ['Brick Wall','Concrete Wall','Glass Wall','Plaster Wall','Wood Wall'],
        'Window':  ['Aluminium Windows','Bay Windows','Blinds','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
    },
    'Laundry Room': {
        'General': ['Basin','Built-in Cupboards','Double Basin','Tumble Dryer','Washing Machine','Washing Machine Connection','Zinc'],
        'Floor':   ['Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
        'Window':  ['Cottage Windows'],
    },
    'Patio': {
        'General': ['Built-In Braai','Covered','Pizza Oven'],
        'Floor':   ['Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
        'Window':  ['Aluminium Windows','Bay Windows','Cottage Windows','Double Glazed Windows','Lead Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
    },
};
const _DEFAULT_SPACE_FEATURES = {
    'Door':   ['Sliding Doors'],
    'Floor':  ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
    'Wall':   ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
    'Window': ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
};
const _ALL_SPACE_TYPES = ['Bedroom','Bathroom','Garage','Parking','Kitchen','Garden','Pool','Flatlet','Study','Domestic Room','Lounge','Dining Room','Outside Toilet','Domestic Bathroom','Entrance Hall','Bar','Boardroom','Boat Launch','Boathouse','Braai Room','Cellar','Changing Room','Clubhouse','Courtyard','Gazebo','Greenhouse','Gym','Jacuzzi','Jetty','Lapa','Laundry Room','Linen Room','Loft','Office','Patio','Pool Shed','Reception Room','Sauna','Scullery','Shed','Squash Court','Stable','Storeroom','Studio','Tennis Court','TV Room','Veranda','Wendy House','Workshop','Yard'];
const _FEATURE_CATEGORIES = {
    theProperty:    { label: 'The Property', features: ['Air Conditioned','Balcony','Cleaning Service','Freehold','Furnished','Green Building','Ground Floor Unit','Investment','Leasehold','Multi Tenanted','Natural Light','Pet Friendly','Pets Not Allowed','Renovation Fixer-Upper','Second Floor and Above','Sectional Title','Serviced','Single Storey','Standalone','Top Floor','Unfurnished','Wheelchair Friendly'] },
    security:       { label: 'Security',     features: ['24 Hour Access','24 Hour Guard','Alarm System','Armed Response','Boomed Area','Burglar Bars','CCTV','Electric Fence','Electric Gate','Gated Community','Guard House','In Security','Indoor Beams','Intercom','Outdoor Beams','Partially Fenced','Perimeter Wall','Safe','Security Gate','Totally Fenced','Totally Walled','Security Complex','Automated Garage Doors','Security Estate'] },
    connectivity:   { label: 'Connectivity', features: ['ADSL','Cable TV','Fast Internet','Fibre','Internet Port','Satellite Dish','Satellite Internet','Telephone Port','TV Port','Wi-Fi'] },
    sustainability: { label: 'Sustainability',features: ['Backup Battery','Backup Water','Borehole','Gas Geyser','Gas Hob','Gas Oven','Generator','Inverter','Septic Tank','Solar Geyser','Solar Heating','Solar Panel','Water Tank'] },
};
const _HALF_UNIT_SPACES = ['Bathroom','Parking'];
const _SVG_ATTRS = ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
const _SPACE_SVG = {
    'Bedroom':     `<svg${_SVG_ATTRS}><path d="M2 9.5V19h20V9.5M2 14h20"/><path d="M2 9.5C2 8 3.5 7 5 7h4a2 2 0 012 2v1.5"/><path d="M13 10.5V9a2 2 0 012-2h4c1.5 0 3 1 3 2.5"/></svg>`,
    'Bathroom':    `<svg${_SVG_ATTRS}><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M4 11h16M10 5V3M14 5V3"/></svg>`,
    'Garage':      `<svg${_SVG_ATTRS}><path d="M2 10.5L12 3l10 7.5V21H2V10.5z"/><path d="M8 21v-6h8v6M7 13.5h10M7 16.5h10"/></svg>`,
    'Parking':     `<svg${_SVG_ATTRS}><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M10 7h4a3 3 0 010 6h-4V7zm0 6v5"/></svg>`,
    'Kitchen':     `<svg${_SVG_ATTRS}><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M8 4v16M2 9.5h6"/><circle cx="14" cy="9" r="1.5"/><circle cx="19" cy="9" r="1.5"/><circle cx="14" cy="14" r="1.5"/><circle cx="19" cy="14" r="1.5"/></svg>`,
    'Garden':      `<svg${_SVG_ATTRS}><path d="M12 22v-9"/><path d="M7 8a5 5 0 0110 0c0 3.5-2.5 5-5 5S7 11.5 7 8z"/><path d="M9 21a3 3 0 006 0"/></svg>`,
    'Pool':        `<svg${_SVG_ATTRS}><path d="M2 12c2.5 0 2.5-3 5-3s2.5 3 5 3 2.5-3 5-3M2 17c2.5 0 2.5-3 5-3s2.5 3 5 3 2.5-3 5-3"/><circle cx="12" cy="5" r="2"/></svg>`,
    'Flatlet':     `<svg${_SVG_ATTRS}><path d="M3 9l9-7 9 7v11a1 1 0 01-1 1H4a1 1 0 01-1-1V9z"/><path d="M9 22V12h6v10"/></svg>`,
    'Study':       `<svg${_SVG_ATTRS}><rect x="2" y="5" width="20" height="13" rx="2"/><path d="M2 10h20M8 5V3M16 5V3"/></svg>`,
    'Domestic Room':`<svg${_SVG_ATTRS}><path d="M3 9l9-7 9 7v11a1 1 0 01-1 1H4a1 1 0 01-1-1V9z"/><path d="M9 22V14h6v8"/></svg>`,
    'Lounge':      `<svg${_SVG_ATTRS}><path d="M3 16h18M6 16V11a3 3 0 016 0m0 0a3 3 0 016 0v5"/><path d="M3 16v2M21 16v2M5 11H2v5M19 11h3v5"/></svg>`,
    'Dining Room': `<svg${_SVG_ATTRS}><path d="M18 2v20M14 2c0 4-2 7-2 7s2 3 2 7M10 2v5a3 3 0 01-6 0V2M7 7v13"/></svg>`,
    'Laundry Room':`<svg${_SVG_ATTRS}><rect x="2" y="3" width="20" height="18" rx="2"/><circle cx="12" cy="13" r="4"/><path d="M2 8h20M6 6h.01"/></svg>`,
    'Patio':       `<svg${_SVG_ATTRS}><path d="M3 11h18M12 3l-9 8h18l-9-8z"/><path d="M5 11v9M19 11v9M3 20h18"/></svg>`,
    'Gym':         `<svg${_SVG_ATTRS}><path d="M4 12h16M6 8v8M18 8v8M2 10v4M22 10v4"/></svg>`,
    'Office':      `<svg${_SVG_ATTRS}><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 20h8M12 17v3"/></svg>`,
    'TV Room':     `<svg${_SVG_ATTRS}><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 20h8M12 17v3M7 9l3 3-3 3"/></svg>`,
    'Bar':         `<svg${_SVG_ATTRS}><path d="M8 2l2 8H6M16 2l-2 8h4M7 10l1 12h8l1-12M3 10h18"/></svg>`,
    'Braai Room':  `<svg${_SVG_ATTRS}><path d="M12 2v4M8 3.5l2 3.5M16 3.5l-2 3.5M4 14h16M6 14v4a2 2 0 002 2h8a2 2 0 002-2v-4M12 10v4"/></svg>`,
    'Sauna':       `<svg${_SVG_ATTRS}><path d="M3 9l9-7 9 7v11H3V9z"/><path d="M8 15c1-2 3-3 4-3s3 1 4 3M8 12c1-1 2-2 4-2"/></svg>`,
    'Lapa':        `<svg${_SVG_ATTRS}><path d="M12 2L2 9h20L12 2z"/><path d="M5 9v13M19 9v13M2 22h20M8 9v13M16 9v13"/></svg>`,
    'Jacuzzi':     `<svg${_SVG_ATTRS}><path d="M2 12c2.5 0 2.5-3 5-3s2.5 3 5 3 2.5-3 5-3M4 19h16a2 2 0 002-2v-5H2v5a2 2 0 002 2z"/></svg>`,
    'Workshop':    `<svg${_SVG_ATTRS}><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>`,
    'Storeroom':   `<svg${_SVG_ATTRS}><path d="M5 3h14l1 3H4L5 3z"/><path d="M3 6v14a2 2 0 002 2h14a2 2 0 002-2V6M10 12h4"/></svg>`,
    'Shed':        `<svg${_SVG_ATTRS}><path d="M3 10.5L12 3l9 7.5V21H3V10.5z"/><path d="M9 21v-7h6v7"/></svg>`,
    'Cellar':      `<svg${_SVG_ATTRS}><path d="M8 21V9M16 21V9M3 6l3-3h12l3 3v2H3V6z"/><path d="M3 8h18M10 13h4M10 17h4"/></svg>`,
    'Veranda':     `<svg${_SVG_ATTRS}><path d="M3 10h18M3 10V6l9-3 9 3v4M3 10v11h18V10M7 10v11M17 10v11M12 10v11"/></svg>`,
    '_default':    `<svg${_SVG_ATTRS}><path d="M3 9l9-7 9 7v11a1 1 0 01-1 1H4a1 1 0 01-1-1V9z"/></svg>`,
};
const _FEAT_CAT_SVG = {
    theProperty:    `<svg viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a1 1 0 01-1 1H4a1 1 0 01-1-1V9z"/><path d="M9 22V12h6v10"/></svg>`,
    security:       `<svg viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 3v5c0 5-3.5 9.74-8 11-4.5-1.26-8-6-8-11V5l8-3z"/><path d="M9 12l2 2 4-4"/></svg>`,
    connectivity:   `<svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 8.5a15 15 0 0121 0M5 12.5a10 10 0 0114 0M8.5 16.5a5 5 0 017 0"/><circle cx="12" cy="20" r="1" fill="#22c55e" stroke="#22c55e"/></svg>`,
    sustainability: `<svg viewBox="0 0 24 24" fill="none" stroke="#14b8a6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a1 1 0 01-1 1H4a1 1 0 01-1-1V9z"/><path d="M9 14a3 3 0 006 0V11"/><circle cx="12" cy="8.5" r="1.5" fill="#14b8a6" stroke="none"/></svg>`,
};

function spacesAndFeaturesManager(initSpaces, initFeatures, initBeds, initBaths) {
    return {
        spaces: initSpaces || [],
        features: {
            theProperty:    (initFeatures && Array.isArray(initFeatures.theProperty))    ? initFeatures.theProperty    : [],
            security:       (initFeatures && Array.isArray(initFeatures.security))       ? initFeatures.security       : [],
            connectivity:   (initFeatures && Array.isArray(initFeatures.connectivity))   ? initFeatures.connectivity   : [],
            sustainability: (initFeatures && Array.isArray(initFeatures.sustainability)) ? initFeatures.sustainability : [],
        },
        modalOpen:          false,
        modalSpaceIdx:      null,
        featurePickerOpen:  false,
        featurePickerTarget:'all',
        addSpaceOpen:       false,
        featureCategoryTab: 'theProperty',
        featureCategories:  _FEATURE_CATEGORIES,
        availableSpaceTypes:_ALL_SPACE_TYPES,

        init() {
            if (this.spaces.length === 0) {
                const defaults = ['Bedroom','Bathroom','Garage','Parking','Pool','Kitchen','Garden'];
                for (const type of defaults) {
                    let count = 0;
                    if (type === 'Bedroom'  && initBeds  > 0) count = initBeds;
                    if (type === 'Bathroom' && initBaths > 0) count = initBaths;
                    this.spaces.push(this._makeSpace(type, count));
                }
            }
        },

        _makeSpace(type, count) {
            const units = [];
            const ceil = Math.ceil(count);
            for (let i = 0; i < ceil; i++) units.push({ label: type + ' ' + (i + 1), features: [] });
            return { type, count, featuresAll: [], descriptionAll: '', units };
        },

        get currentSpace() {
            return (this.modalSpaceIdx !== null && this.spaces[this.modalSpaceIdx]) ? this.spaces[this.modalSpaceIdx] : null;
        },
        get bedsCount()  { const s = this.spaces.find(s => s.type === 'Bedroom');  return s ? Math.floor(s.count) : 0; },
        get bathsCount() { const s = this.spaces.find(s => s.type === 'Bathroom'); return s ? s.count : 0; },
        get spacesJsonStr() { return JSON.stringify({ spaces: this.spaces, features: this.features }); },
        get allFeaturesFlat() {
            const set = new Set();
            for (const sp of this.spaces) {
                for (const f of (sp.featuresAll || [])) set.add(f);
                for (const u of (sp.units || [])) { for (const f of (u.features || [])) set.add(f); }
            }
            for (const cat of Object.values(this.features)) { for (const f of cat) set.add(f); }
            return Array.from(set).sort();
        },

        openSpace(idx)   { this.modalSpaceIdx = idx; this.modalOpen = true; this.featurePickerOpen = false; },
        closeModal()     { this.modalOpen = false; this.modalSpaceIdx = null; this.featurePickerOpen = false; },
        hasSpace(type)   { return this.spaces.some(s => s.type === type); },
        deleteSpace(idx) { if (idx !== null) this.spaces.splice(idx, 1); this.closeModal(); },

        addSpace(type) {
            const idx = this.spaces.findIndex(s => s.type === type);
            if (idx >= 0) { this.openSpace(idx); } else { this.spaces.push(this._makeSpace(type, 1)); this.openSpace(this.spaces.length - 1); }
            this.addSpaceOpen = false;
        },

        incrementCount(idx) {
            // +1 always — half-units are added separately via the ½ Toggle button.
            const sp = this.spaces[idx];
            sp.count = parseFloat((sp.count + 1).toFixed(1)); this._rebuildUnits(idx);
        },
        decrementCount(idx) {
            // −1 always — preserves any existing half so e.g. 2.5 → 1.5.
            const sp = this.spaces[idx];
            const n = parseFloat((sp.count - 1).toFixed(1)); if (n < 0) return;
            sp.count = n; this._rebuildUnits(idx);
        },
        toggleHalf(idx) {
            const sp = this.spaces[idx]; const floor = Math.floor(sp.count);
            const hasHalf = (sp.count * 2) % 2 !== 0;
            sp.count = hasHalf ? floor : parseFloat((floor + 0.5).toFixed(1)); this._rebuildUnits(idx);
        },
        _rebuildUnits(idx) {
            const sp = this.spaces[idx]; const ceil = Math.ceil(sp.count); const ex = sp.units || [];
            const newUnits = [];
            for (let i = 0; i < ceil; i++) newUnits.push(ex[i] || { label: sp.type + ' ' + (i + 1), features: [] });
            sp.units = newUnits;
        },
        supportsHalf(type) { return _HALF_UNIT_SPACES.includes(type); },

        openFeaturePicker(target) { this.featurePickerTarget = target; this.featurePickerOpen = true; },
        isPickerFeatureSelected(feat) {
            if (!this.currentSpace) return false;
            const arr = this.featurePickerTarget === 'all'
                ? this.currentSpace.featuresAll
                : (this.currentSpace.units[this.featurePickerTarget] ? this.currentSpace.units[this.featurePickerTarget].features : []);
            return arr.includes(feat);
        },
        togglePickerFeature(feat) {
            if (!this.currentSpace) return;
            const arr = this.featurePickerTarget === 'all'
                ? this.currentSpace.featuresAll
                : this.currentSpace.units[this.featurePickerTarget].features;
            const i = arr.indexOf(feat); if (i >= 0) arr.splice(i, 1); else arr.push(feat);
        },
        removeSpaceFeature(spaceIdx, target, featIdx) {
            const sp = this.spaces[spaceIdx];
            if (target === 'all') sp.featuresAll.splice(featIdx, 1);
            else sp.units[target].features.splice(featIdx, 1);
        },
        copyFeaturesDown(spaceIdx, unitIdx) {
            const sp = this.spaces[spaceIdx];
            if (!sp || !sp.units || unitIdx >= sp.units.length - 1) return;
            const src = [...sp.units[unitIdx].features];
            for (let i = unitIdx + 1; i < sp.units.length; i++) {
                sp.units[i].features = [...src];
            }
        },
        toggleGlobalFeature(catKey, feat) {
            const arr = this.features[catKey]; const i = arr.indexOf(feat);
            if (i >= 0) arr.splice(i, 1); else arr.push(feat);
        },
        removeFeatureByName(feat) {
            // Remove from global feature categories
            for (const [catKey, arr] of Object.entries(this.features)) {
                const i = arr.indexOf(feat);
                if (i >= 0) { arr.splice(i, 1); return; }
            }
            // Remove from space features
            for (const sp of this.spaces) {
                let i = (sp.featuresAll || []).indexOf(feat);
                if (i >= 0) { sp.featuresAll.splice(i, 1); return; }
                for (const u of (sp.units || [])) {
                    i = (u.features || []).indexOf(feat);
                    if (i >= 0) { u.features.splice(i, 1); return; }
                }
            }
        },
        getSpaceFeatures(type)    { return _SPACE_FEATURES[type] || _DEFAULT_SPACE_FEATURES; },
        getSpaceIconSvg(type)     { return _SPACE_SVG[type] || _SPACE_SVG['_default']; },
        getFeatureCatIconSvg(key) { return _FEAT_CAT_SVG[key] || _FEAT_CAT_SVG['theProperty']; },
        formatCount(count) {
            const floor = Math.floor(count); const hasHalf = (count * 2) % 2 !== 0;
            if (hasHalf && floor === 0) return '½';
            if (hasHalf) return floor + '½';
            return String(floor);
        },
    };
}

// Gallery lightbox with prev/next navigation
var _lbImages = {!! $galleryJsonForJs ?? '[]' !!};
var _lbIndex  = 0;

function openLightbox(idx) {
    _lbIndex = idx;
    _lbRender();
    document.getElementById('lightbox').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').classList.add('hidden');
    document.body.style.overflow = '';
}
function lightboxNav(dir) {
    _lbIndex = (_lbIndex + dir + _lbImages.length) % _lbImages.length;
    _lbRender();
}
function _lbRender() {
    var img     = document.getElementById('lightbox-img');
    var counter = document.getElementById('lightbox-counter');
    var prev    = document.getElementById('lightbox-prev');
    var next    = document.getElementById('lightbox-next');
    img.src     = _lbImages[_lbIndex] || '';
    counter.textContent = (_lbIndex + 1) + ' / ' + _lbImages.length;
    // Hide arrows when only 1 image
    var show = _lbImages.length > 1;
    prev.style.display = show ? '' : 'none';
    next.style.display = show ? '' : 'none';
}
document.addEventListener('keydown', function(e) {
    var lb = document.getElementById('lightbox');
    if (lb.classList.contains('hidden')) return;
    if (e.key === 'Escape')       closeLightbox();
    if (e.key === 'ArrowLeft')    lightboxNav(-1);
    if (e.key === 'ArrowRight')   lightboxNav(1);
});

// Smart Gallery Manager
// Tag-based gallery manager
function smartGallery(initImages, initTags, propertyId, csrfToken, availableTags) {
    return {
        images: initImages || [],
        tags: initTags || {},
        availableTags: availableTags || [],
        propertyId, csrfToken,
        dirty: false, saving: false, saveMsg: '', saveError: false,
        tagMode: false,
        selected: [],              // array of indices selected in tag mode
        activeTag: null,
        _dragIdx: null,

        toggleTagMode() {
            this.tagMode = !this.tagMode;
            this.selected = [];
            this.activeTag = null;
        },

        handleClick(idx) {
            if (this.selectMode) {
                // Select mode: toggle membership in the selected[] list — bulk-delete via the bin icon.
                const i = this.selected.indexOf(idx);
                if (i >= 0) this.selected.splice(i, 1);
                else this.selected.push(idx);
                return;
            }
            if (this.tagMode) {
                // Tag flow: pick a tag first, then click images to apply.
                // activeTag === '__CLEAR__' means clear-tag mode (untag the image).
                if (!this.activeTag) return; // no tag picked yet — ignore the click
                const img = this.images[idx];
                if (this.activeTag === '__CLEAR__') {
                    delete this.tags[img];
                } else {
                    this.tags[img] = this.activeTag;
                }
                this.dirty = true;
                this.save();
            }
            // Normal mode: no action on click (use hover buttons for view/delete)
        },

        toggleSelectMode() {
            this.selectMode = !this.selectMode;
            this.selected = [];
            // Mutually exclusive with tag mode and manage-tags popup
            if (this.selectMode) {
                if (this.tagMode) this.toggleTagMode();
                if (this.manageTagsOpen) this.manageTagsOpen = false;
            }
        },

        deleteSelected() {
            if (this.selected.length === 0) return;
            if (!confirm(`Delete ${this.selected.length} image${this.selected.length > 1 ? 's' : ''}? This cannot be undone.`)) return;
            // Sort indices DESC so each splice doesn't shift later targets.
            const idxs = [...this.selected].sort((a, b) => b - a);
            const removedUrls = [];
            for (const idx of idxs) {
                const img = this.images.splice(idx, 1)[0];
                if (img !== undefined) {
                    delete this.tags[img];
                    removedUrls.push(img);
                }
            }
            this.selected = [];
            this.dirty = true;
            this.save();
            // Best-effort server-side cleanup so storage files don't orphan.
            for (const url of removedUrls) {
                fetch(`/corex/properties/${this.propertyId}/delete-image`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ url }),
                });
            }
        },

        selectAll() { this.selected = this.images.map((_, i) => i); },
        selectNone() { this.selected = []; },

        // Tag all selected images
        tagSelected(tag) {
            this.activeTag = tag;
            for (const idx of this.selected) {
                const img = this.images[idx];
                if (tag) { this.tags[img] = tag; } else { delete this.tags[img]; }
            }
            this.dirty = true;
            this.selected = [];
            // Auto-save after tagging
            this.save();
        },

        // Drag reorder tags
        _dragTagIdx: null,
        tagDragStart(idx, e) { this._dragTagIdx = idx; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); },
        tagDragOver(idx, e) {
            if (this._dragTagIdx === null || this._dragTagIdx === idx) return;
            const item = this.availableTags.splice(this._dragTagIdx, 1)[0];
            this.availableTags.splice(idx, 0, item);
            this._dragTagIdx = idx;
        },
        tagDragDrop() { this._dragTagIdx = null; },

        // Add a custom tag — case-insensitive de-dupe, trims whitespace, capitalises first letter.
        customTagInput: '',
        addCustomTag() {
            const raw = (this.customTagInput || '').trim();
            if (!raw) return;
            const name = raw.charAt(0).toUpperCase() + raw.slice(1);
            const exists = this.availableTags.some(t => t.toLowerCase() === name.toLowerCase());
            if (exists) { this.customTagInput = ''; return; }
            this.availableTags.push(name);
            this.customTagInput = '';
            this.dirty = true;
        },
        removeCustomTag(tag) {
            // Strip the tag from any tagged images, then remove from availableTags.
            for (const img of Object.keys(this.tags)) {
                if (this.tags[img] === tag) delete this.tags[img];
            }
            this.availableTags = this.availableTags.filter(t => t !== tag);
            this.dirty = true;
        },

        // Sort images by category order (uses current tag button order)
        sortByCategory() {
            const order = {};
            this.availableTags.forEach((t, i) => order[t] = i);
            const tagged = this.images.filter(img => this.tags[img]);
            const untagged = this.images.filter(img => !this.tags[img]);
            tagged.sort((a, b) => (order[this.tags[a]] ?? 999) - (order[this.tags[b]] ?? 999));
            this.images = [...tagged, ...untagged];
            this.dirty = true;
            this.save();
        },

        // Drag to reorder
        dragStart(idx, e) { this._dragIdx = idx; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); },
        dragOver(idx, e) {
            if (this._dragIdx === null || this._dragIdx === idx) return;
            const item = this.images.splice(this._dragIdx, 1)[0];
            this.images.splice(idx, 0, item);
            this._dragIdx = idx;
            this.dirty = true;
        },
        dragDrop(idx) { this._dragIdx = null; },

        viewImage(idx) { _lbImages = [...this.images]; openLightbox(idx); },

        deleteImage(idx) {
            if (!confirm('Delete this image?')) return;
            const img = this.images.splice(idx, 1)[0];
            delete this.tags[img];
            if (this.taggingIdx === idx) this.taggingIdx = null;
            this.dirty = true;
            // Delete from server
            fetch(`/corex/properties/${this.propertyId}/delete-image`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ url: img }),
            });
            this.save();
        },

        // Build categories structure from tags for saving
        buildCategories() {
            const cats = {};
            const unsorted = [];
            for (const img of this.images) {
                const tag = this.tags[img];
                if (tag) {
                    if (!cats[tag]) cats[tag] = [];
                    cats[tag].push(img);
                } else {
                    unsorted.push(img);
                }
            }
            return {
                categories: Object.entries(cats).map(([name, images]) => ({ name, images })),
                unsorted,
            };
        },

        async save() {
            this.saving = true; this.saveMsg = '';
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/reorder-images`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        gallery_categories_json: this.buildCategories(),
                        gallery_images_json: this.images,
                    }),
                });
                this.dirty = !res.ok;
                this.saveMsg = res.ok ? 'Saved' : 'Failed to save';
                this.saveError = !res.ok;
            } catch (e) { this.saveMsg = 'Network error'; this.saveError = true; }
            finally { this.saving = false; setTimeout(() => this.saveMsg = '', 3000); }
        },
    };
}
function galleryManager() { return {}; }

// Property Address modal component
function propertyAddress(config) {
    return {
        openModal: null,
        streetNumber: config.streetNumber || '',
        streetName: config.streetName || '',
        complexName: config.complexName || '',
        unitNumber: config.unitNumber || '',
        suburb: config.suburb || '',
        city: config.city || '',
        province: config.province || '',
        hideStreetName: config.hideStreetName || false,
        hideStreetNumber: config.hideStreetNumber || false,
        hideComplexName: config.hideComplexName || false,
        hideUnitNumber: config.hideUnitNumber || false,

        get internalAddress() {
            let street = [this.streetNumber, this.streetName].filter(Boolean).join(' ');
            let location = [this.suburb, this.city, this.province].filter(Boolean).join(', ');
            return [street, location].filter(Boolean).join(', ');
        },

        get publicAddress() {
            let parts = [];
            if (!this.hideStreetNumber && this.streetNumber) parts.push(this.streetNumber);
            if (!this.hideStreetName && this.streetName) {
                if (parts.length > 0) {
                    parts[parts.length - 1] += ' ' + this.streetName;
                } else {
                    parts.push(this.streetName);
                }
            }
            if (!this.hideComplexName && this.complexName) parts.push(this.complexName);
            if (!this.hideUnitNumber && this.unitNumber) parts.push('Unit ' + this.unitNumber);
            parts.push(...[this.suburb, this.city, this.province].filter(Boolean));
            return parts.join(', ') || 'No public address configured';
        },
    };
}

// Private Property Syndication Alpine component
function ppSyndication(config) {
    return {
        propertyId: config.propertyId,
        enabled: config.enabled,
        status: config.status || '',
        ppRef: config.ppRef || '',
        lastSubmitted: config.lastSubmitted || '',
        lastError: config.lastError || '',
        exclusiveDays: config.exclusiveDays || 0,
        mandateType: config.mandateType || '',
        activatedAt: config.activatedAt || '',
        csrfToken: config.csrfToken,
        missingFields: config.missingFields || [],
        loading: false,
        message: '',
        messageType: 'success',
        debugErrors: [],
        showDebug: false,
        // Address visibility
        hideStreetName: config.hideStreetName || false,
        hideStreetNumber: config.hideStreetNumber || false,
        hideComplexName: config.hideComplexName || false,
        hideUnitNumber: config.hideUnitNumber || false,
        // Video / Matterport
        youtubeVideoId: config.youtubeVideoId || '',
        matterportId: config.matterportId || '',
        videoLoading: false, videoMsg: '', videoOk: null,
        // Listing ownership
        ppListingId: '',
        listingIdLoading: false, listingIdMsg: '', listingIdOk: null,
        // Exclusive delay
        ppDelayUntil: config.ppDelayUntil || '',
        ppDelayUntilRaw: config.ppDelayUntilRaw || '',
        // Showday
        showShowdayForm: false,
        showdayStart: '',
        showdayEnd: '',
        showdayDescription: '',

        statusLabel() {
            const labels = {
                '': 'Disabled',
                'pending': 'Pending',
                'submitted': 'Submitted',
                'active': 'Active',
                'error': 'Error',
                'deactivated': 'Deactivated',
            };
            if (!this.enabled && !this.status) return 'Disabled';
            return labels[this.status] || 'Disabled';
        },

        statusBadgeStyle() {
            const styles = {
                '': 'background:var(--surface-2); color:var(--text-muted);',
                'pending': 'background:rgba(245,158,11,0.12); color:var(--ds-amber);',
                'submitted': 'background:rgba(245,158,11,0.12); color:var(--ds-amber);',
                'active': 'background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--ds-green);',
                'error': 'background:rgba(239,68,68,0.12); color:var(--ds-crimson);',
                'deactivated': 'background:var(--surface-2); color:var(--text-muted);',
            };
            if (!this.enabled && !this.status) return styles[''];
            return styles[this.status] || styles[''];
        },

        ppListingUrl() {
            // A.2.1 — sourced from Property::publicListingUrls()['pp'] server-side.
            // Falls through to the legacy search-by-ref pattern if the server
            // hasn't populated publicUrl (e.g. status not 'active' yet).
            return this.publicUrl || (this.ppRef ? `https://www.privateproperty.co.za/search?q=${this.ppRef}` : '#');
        },

        showMessage(msg, type = 'success') {
            this.message = msg;
            this.messageType = type;
            setTimeout(() => { this.message = ''; }, 5000);
        },

        async toggleEnabled() {
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/toggle`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (data.success) {
                    this.enabled = data.pp_syndication_enabled;
                    this.status = data.pp_syndication_status || '';
                    this.showMessage(this.enabled ? 'PP syndication enabled' : 'PP syndication disabled');
                    // Refresh readiness when enabling so warnings show immediately
                    if (this.enabled) {
                        await this.refreshReadiness();
                    }
                } else {
                    this.showMessage(data.message || 'Toggle failed', 'error');
                }
            } catch (e) {
                this.showMessage('Network error', 'error');
            } finally {
                this.loading = false;
            }
        },

        async refreshReadiness() {
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/readiness`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                this.missingFields = data.missing_fields || [];
            } catch (e) { /* silent */ }
        },

        async submitListing() {
            // Double-check readiness before submitting
            await this.refreshReadiness();
            if (this.missingFields.length > 0) {
                this.showMessage('Cannot submit — fill in the required fields first', 'error');
                return;
            }

            this.loading = true;
            this.debugErrors = [];
            this.showDebug = false;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/submit`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({}),
                });
                const data = await res.json();
                if (data.success) {
                    this.status = data.pp_syndication_status || 'submitted';
                    this.ppRef = data.pp_ref || this.ppRef;
                    this.lastSubmitted = new Date().toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    this.lastError = '';
                    this.debugErrors = [];
                    this.showDebug = false;
                    this.showMessage(data.message || 'Submitted to PP');
                } else {
                    if (data.missing_fields && data.missing_fields.length > 0) {
                        this.missingFields = data.missing_fields;
                    }
                    this.status = data.pp_syndication_status || 'error';
                    this.lastError = data.message || 'Submission failed';

                    // Build debug info from all available error data
                    this.debugErrors = [];
                    if (data.errors && data.errors.length > 0) {
                        data.errors.forEach(e => this.debugErrors.push(typeof e === 'string' ? e : e.label || JSON.stringify(e)));
                    }
                    if (data.message) {
                        this.debugErrors.push(data.message);
                    }
                    this.showDebug = true;
                }
            } catch (e) {
                this.debugErrors = ['Network error: ' + e.message];
                this.showDebug = true;
            } finally {
                this.loading = false;
            }
        },

        async refreshListing() {
            this.loading = true;
            this.debugErrors = [];
            this.showDebug = false;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/submit`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({}),
                });
                const data = await res.json();
                if (data.success) {
                    this.status = data.pp_syndication_status || 'active';
                    this.ppRef = data.pp_ref || this.ppRef;
                    this.lastSubmitted = new Date().toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    this.lastError = '';
                    this.showMessage('Listing synced to PP');
                } else {
                    this.lastError = data.message || 'Sync failed';
                    this.debugErrors = data.errors || [data.message];
                    this.showDebug = true;
                }
            } catch (e) {
                this.debugErrors = ['Network error: ' + e.message];
                this.showDebug = true;
            } finally {
                this.loading = false;
            }
        },

        async deactivateListing() {
            if (!confirm('Deactivate this listing on Private Property?')) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/deactivate`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (data.success) {
                    this.status = data.pp_syndication_status || 'deactivated';
                    this.showMessage('Listing deactivated on PP');
                } else {
                    this.showMessage(data.message || 'Deactivation failed', 'error');
                }
            } catch (e) {
                this.showMessage('Network error', 'error');
            } finally {
                this.loading = false;
            }
        },

        async reactivateListing() {
            if (!confirm('Reactivate this listing on Private Property?')) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/reactivate`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (data.success) {
                    this.status = data.pp_syndication_status || 'submitted';
                    this.showMessage('Listing reactivated on PP');
                } else {
                    this.debugErrors = [data.message || 'Reactivation failed'];
                    this.showDebug = true;
                }
            } catch (e) {
                this.showMessage('Network error', 'error');
            } finally {
                this.loading = false;
            }
        },

        async submitShowday() {
            if (!this.showdayStart || !this.showdayEnd) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/showday`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        start_date: this.showdayStart,
                        end_date: this.showdayEnd,
                        description: this.showdayDescription || 'Open Showday',
                    }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showMessage('Showday event submitted to PP');
                    this.showShowdayForm = false;
                    this.showdayStart = '';
                    this.showdayEnd = '';
                    this.showdayDescription = '';
                } else {
                    this.debugErrors = [data.message || 'Showday submission failed'];
                    this.showDebug = true;
                }
            } catch (e) {
                this.showMessage('Network error', 'error');
            } finally {
                this.loading = false;
            }
        },

        async pushVideo() {
            if (!this.youtubeVideoId && !this.matterportId) { this.videoOk = false; this.videoMsg = 'Enter a YouTube ID or Matterport ID'; return; }
            if (this.youtubeVideoId && this.youtubeVideoId.length !== 11) { this.videoOk = false; this.videoMsg = 'YouTube ID must be exactly 11 characters'; return; }
            this.videoLoading = true; this.videoMsg = '';
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/video`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ youtube_video_id: this.youtubeVideoId || null, matterport_id: this.matterportId || null }),
                });
                const data = await res.json();
                this.videoOk = data.success;
                this.videoMsg = data.message;
            } catch (e) { this.videoOk = false; this.videoMsg = 'Network error'; }
            this.videoLoading = false;
        },

        async claimListingOwnership() {
            if (!this.ppListingId.trim()) { this.listingIdOk = false; this.listingIdMsg = 'Enter PP Encrypted Listing ID'; return; }
            if (!confirm('This will permanently claim PP ownership of this listing. Continue?')) return;
            this.listingIdLoading = true; this.listingIdMsg = '';
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/update-id`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ pp_listing_id: this.ppListingId }),
                });
                const data = await res.json();
                this.listingIdOk = data.success;
                this.listingIdMsg = data.message;
                if (data.success) this.ppListingId = '';
            } catch (e) { this.listingIdOk = false; this.listingIdMsg = 'Network error'; }
            this.listingIdLoading = false;
        },

        ppDelayDaysRemaining() {
            if (!this.ppDelayUntilRaw) return 0;
            const diff = new Date(this.ppDelayUntilRaw) - new Date();
            return Math.max(0, Math.ceil(diff / 86400000));
        },

        isPpExclusiveActive() {
            return this.ppDelayDaysRemaining() > 0;
        },

        async saveVisibility() {
            try {
                await fetch(`/corex/properties/${this.propertyId}/syndication/visibility`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        hide_street_name: this.hideStreetName,
                        hide_street_number: this.hideStreetNumber,
                        hide_complex_name: this.hideComplexName,
                        hide_unit_number: this.hideUnitNumber,
                    }),
                });
            } catch (e) { /* silent save */ }
        },
    };
}

function p24Syndication(config) {
    return {
        propertyId: config.propertyId, enabled: config.enabled, status: config.status || '',
        p24Ref: config.p24Ref || '', lastSubmitted: config.lastSubmitted || '',
        lastError: config.lastError || '', csrfToken: config.csrfToken, isSandbox: config.isSandbox ?? true,
        suburb: config.suburb || '', city: config.city || '', province: config.province || '', suburbId: config.suburbId || '', listingType: config.listingType || 'sale',
        missingFields: config.missingFields || [],
        ppDelayUntilRaw: config.ppDelayUntilRaw || '', ppDelayUntil: config.ppDelayUntil || '',
        resolvedP24AgencyId: config.resolvedP24AgencyId || '', resolvedP24AgencyLabel: config.resolvedP24AgencyLabel || '',
        loading: false, message: '', messageType: 'success', debugErrors: [], showDebug: false,
        isPpExclusiveLocked() {
            if (!this.ppDelayUntilRaw) return false;
            return new Date(this.ppDelayUntilRaw) > new Date();
        },
        statusLabel() {
            const labels = {'':'Disabled','pending':'Pending','submitted':'Submitted','active':'Active','error':'Error','rejected':'Rejected','deactivated':'Deactivated'};
            if (!this.enabled && !this.status) return 'Disabled';
            return labels[this.status] || 'Disabled';
        },
        statusBadgeStyle() {
            const styles = {'':'background:var(--surface-2);color:var(--text-muted);','pending':'background:rgba(245,158,11,0.12);color:var(--ds-amber);','submitted':'background:rgba(245,158,11,0.12);color:var(--ds-amber);','active':'background:rgba(59,130,246,0.12);color:#3b82f6;','error':'background:rgba(239,68,68,0.12);color:var(--ds-crimson);','rejected':'background:rgba(239,68,68,0.12);color:var(--ds-crimson);','deactivated':'background:var(--surface-2);color:var(--text-muted);'};
            if (!this.enabled && !this.status) return styles[''];
            return styles[this.status] || styles[''];
        },
        p24ListingUrl() {
            // A.2.1 — single source of truth lives on Property::publicListingUrls()['p24'].
            // Server-rendered URL when status is 'active'. Sandbox testing still
            // computes locally because the sandbox domain isn't in the accessor.
            if (this.publicUrl) return this.publicUrl;
            const domain = this.isSandbox ? 'www.exdev.property24-test.com' : 'www.property24.com';
            const slug = (s) => (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'property';
            const section = this.listingType === 'rental' ? 'to-rent' : 'for-sale';
            return `https://${domain}/${section}/${slug(this.suburb)}/${slug(this.city)}/${slug(this.province)}/${this.suburbId || '0'}/${this.p24Ref}`;
        },
        showMessage(msg, type = 'success') { this.message = msg; this.messageType = type; setTimeout(() => { this.message = ''; }, 5000); },
        async toggleEnabled() {
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/toggle`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (data.success) { this.enabled = data.p24_syndication_enabled; this.status = data.p24_syndication_status || ''; this.showMessage(this.enabled ? 'P24 syndication enabled' : 'P24 syndication disabled'); }
                else { this.showMessage(data.message || 'Toggle failed', 'error'); }
            } catch (e) { this.showMessage('Network error', 'error'); } finally { this.loading = false; }
        },
        async submitListing() {
            this.loading = true; this.debugErrors = []; this.showDebug = false;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/submit`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({}) });
                const data = await res.json();
                if (data.success) { this.status = data.p24_syndication_status || 'submitted'; this.p24Ref = data.p24_ref || this.p24Ref; this.lastSubmitted = new Date().toLocaleDateString('en-ZA', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }); this.lastError = ''; this.debugErrors = []; this.showDebug = false; this.showMessage(data.message || 'Submitted to P24'); }
                else { this.status = data.p24_syndication_status || 'error'; this.lastError = data.message || 'Submission failed'; this.debugErrors = []; if (data.errors && data.errors.length > 0) { data.errors.forEach(e => this.debugErrors.push(typeof e === 'string' ? e : e.label || JSON.stringify(e))); } if (data.message) { this.debugErrors.push(data.message); } this.showDebug = true; }
            } catch (e) { this.debugErrors = ['Network error: ' + e.message]; this.showDebug = true; } finally { this.loading = false; }
        },
        async refreshListing() {
            this.loading = true; this.debugErrors = []; this.showDebug = false;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/submit`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({}) });
                const data = await res.json();
                if (data.success) { this.status = data.p24_syndication_status || 'active'; this.p24Ref = data.p24_ref || this.p24Ref; this.lastSubmitted = new Date().toLocaleDateString('en-ZA', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }); this.lastError = ''; this.showMessage('Listing synced to P24'); }
                else { this.lastError = data.message || 'Sync failed'; this.debugErrors = data.errors || [data.message]; this.showDebug = true; }
            } catch (e) { this.debugErrors = ['Network error: ' + e.message]; this.showDebug = true; } finally { this.loading = false; }
        },
        async deactivateListing() {
            if (!confirm('Deactivate this listing on Property24?')) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/deactivate`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (data.success) { this.status = data.p24_syndication_status || 'deactivated'; this.showMessage('Listing deactivated on P24'); }
                else { this.showMessage(data.message || 'Deactivation failed', 'error'); }
            } catch (e) { this.showMessage('Network error', 'error'); } finally { this.loading = false; }
        },
        async reactivateListing() {
            if (!confirm('Reactivate this listing on Property24?')) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/reactivate`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (data.success) { this.status = data.p24_syndication_status || 'submitted'; this.showMessage('Listing reactivated on P24'); }
                else { this.debugErrors = [data.message || 'Reactivation failed']; this.showDebug = true; }
            } catch (e) { this.showMessage('Network error', 'error'); } finally { this.loading = false; }
        },
    };
}

// ── Property form: required-fields modal ────────────────────────────────
(function() {
    var form     = document.getElementById('prop-update-form');
    var modal    = document.getElementById('prop-required-modal');
    if (!form || !modal) return;

    var listEl   = document.getElementById('prop-required-list');
    var closeBtn = document.getElementById('prop-required-close');
    var gotoBtn  = document.getElementById('prop-required-goto');
    var firstMissingEl = null;

    function labelFor(field) {
        // Look up the closest wrapping div, find its label
        var wrap = field.closest('div');
        while (wrap) {
            var lbl = wrap.querySelector('label');
            if (lbl && lbl.textContent.trim()) {
                return lbl.textContent.replace(/\*/g, '').trim();
            }
            wrap = wrap.parentElement && wrap.parentElement.closest('div');
            if (!wrap) break;
        }
        return field.name || 'Required field';
    }

    function activateTabFor(el) {
        // Walk up looking for x-show="activeTab === '...'" and switch the root Alpine tab
        var node = el.parentElement;
        while (node && node !== document.body) {
            var attr = node.getAttribute('x-show');
            if (attr) {
                var m = attr.match(/activeTab\s*===\s*['"]([^'"]+)['"]/);
                if (m) {
                    try {
                        var root = node.closest('[x-data]');
                        while (root) {
                            var data = window.Alpine && Alpine.$data ? Alpine.$data(root) : null;
                            if (data && 'activeTab' in data) { data.activeTab = m[1]; break; }
                            root = root.parentElement && root.parentElement.closest('[x-data]');
                        }
                    } catch (e) {}
                    break;
                }
            }
            node = node.parentElement;
        }
    }

    function showModal(missing) {
        listEl.innerHTML = '';
        missing.forEach(function(item) {
            var li = document.createElement('li');
            li.textContent = item.label;
            listEl.appendChild(li);
        });
        firstMissingEl = missing.length ? missing[0].el : null;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function hideModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    form.addEventListener('submit', function(e) {
        var missing = [];
        var seen = {};
        form.querySelectorAll('[required]').forEach(function(f) {
            // Skip duplicate hidden inputs sharing a name
            if (f.type === 'hidden') return;
            if (seen[f.name]) return;
            var val = (f.value || '').trim();
            if (!val) {
                seen[f.name] = true;
                missing.push({ el: f, label: labelFor(f) });
            }
        });
        if (missing.length) {
            e.preventDefault();
            showModal(missing);
        }
    });

    closeBtn.addEventListener('click', hideModal);
    gotoBtn.addEventListener('click', function() {
        hideModal();
        if (firstMissingEl) {
            activateTabFor(firstMissingEl);
            setTimeout(function() {
                firstMissingEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                try { firstMissingEl.focus({ preventScroll: true }); } catch (e) { firstMissingEl.focus(); }
            }, 80);
        }
    });
    modal.addEventListener('click', function(e) { if (e.target === modal) hideModal(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) hideModal();
    });
})();
</script>

{{-- Leaflet (OpenStreetMap) — pin map for the Internal address. --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
/**
 * Map widget under the Public/Internal address card. Calls the backend
 * AddressResolverService (POST /api/v1/properties/{id}/geocode) with the
 * FULL address (street_number + street_name + suburb + town) so the pin
 * lands at building level when street parts are present. Falls back to
 * suburb_centroid only when no street parts are available, and surfaces
 * that case in the UI with an "approx" badge so the agent can fix it.
 *
 * Drag-to-adjust still wins (manual marker move overrides the geocoded
 * value); form submit persists lat/lng via the hidden inputs.
 */
function propertyMapWidget(cfg) {
    return {
        open: false,
        propertyId: cfg.propertyId || 0,
        lat: cfg.initialLat || 0,
        lng: cfg.initialLng || 0,
        isApproximate: ['suburb_centroid','unresolved','nominatim_suburb',''].includes(cfg.initialGeoSource || '') && !!(cfg.initialLat && cfg.initialLng),
        geocoding: false,
        _map: null, _marker: null,
        _initialStreetNumber: cfg.initialStreetNumber || '',
        _initialStreetName:   cfg.initialStreetName   || '',
        _initialSuburb:       cfg.initialSuburb       || '',
        _initialCity:         cfg.initialCity         || '',
        _initialProvince:     cfg.initialProvince     || '',

        init() {
            // Panel starts CLOSED — user opens it to see the map.
            this.open = false;

            // Auto-geocode every time the P24 picker reports a new suburb.
            window.addEventListener('p24-location-changed', (e) => {
                if (e.detail && e.detail.suburbName) {
                    this.geocodeFromBackend({
                        suburb: e.detail.suburbName,
                        town:   e.detail.cityName || this._initialCity,
                    });
                }
            });

            // Render the map only after the user expands the panel.
            this.$watch('open', (val) => { if (val) this.$nextTick(() => this._renderMap()); });

            // On initial load: if no saved coords, kick the backend to
            // resolve from whatever address parts we have. The backend will
            // pick the building level when street parts are present; if not,
            // it will return suburb_centroid + we'll show the approx badge.
            if (!this.lat || !this.lng) {
                this.geocodeFromBackend({
                    street_number: this._initialStreetNumber,
                    street_name:   this._initialStreetName,
                    suburb:        this._initialSuburb,
                    town:          this._initialCity,
                });
            }
        },

        /**
         * Resolve via the backend AddressResolverService. Payload is the
         * in-flight form values (not the saved record); backend returns
         * coords + source label but does NOT persist (form submit handles
         * persistence via the hidden lat/lng inputs).
         */
        async geocodeFromBackend(detail) {
            if (!this.propertyId) return;
            // Send whatever parts we have; backend tolerates nulls.
            const body = {
                street_number: detail.street_number ?? null,
                street_name:   detail.street_name   ?? null,
                suburb:        detail.suburb        ?? null,
                town:          detail.town          ?? null,
            };
            this.geocoding = true;
            try {
                const data = await window.CoreX.api.fetch(
                    '/api/v1/properties/' + this.propertyId + '/geocode',
                    { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
                );
                if (data && data.ok && data.latitude !== null && data.longitude !== null) {
                    this.lat = (+data.latitude).toFixed(7);
                    this.lng = (+data.longitude).toFixed(7);
                    this.isApproximate = !!data.is_approximate;
                    if (this.open) this.$nextTick(() => this._placeMarker(+this.lat, +this.lng));
                }
            } catch (e) { /* silent — pin stays where it was */ }
            finally { this.geocoding = false; }
        },

        _renderMap() {
            const el = document.getElementById('property-map');
            if (!el || !window.L) return;
            // Avoid re-initialising on second open.
            if (this._map) { setTimeout(() => this._map.invalidateSize(), 50); return; }

            const center = (this.lat && this.lng) ? [+this.lat, +this.lng] : [-30.5, 30.5];
            this._map = L.map(el).setView(center, (this.lat && this.lng) ? 16 : 6);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(this._map);
            if (this.lat && this.lng) this._placeMarker(+this.lat, +this.lng);
        },

        _placeMarker(lat, lng) {
            if (!this._map) return;
            if (this._marker) {
                this._marker.setLatLng([lat, lng]);
            } else {
                this._marker = L.marker([lat, lng], { draggable: true }).addTo(this._map);
                this._marker.on('dragend', (e) => {
                    const p = e.target.getLatLng();
                    this.lat = p.lat.toFixed(7);
                    this.lng = p.lng.toFixed(7);
                    // Manual drag = trusted pin. Clear the approx badge.
                    this.isApproximate = false;
                });
            }
            this._map.setView([lat, lng], 16);
        },

    };
}
</script>
@endpush

{{-- ── Required Fields Modal ───────────────────────────────────────────── --}}
<div id="prop-required-modal"
     class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/60 px-4"
     role="dialog" aria-modal="true" aria-labelledby="prop-required-title">
    <div class="rounded-lg shadow-xl max-w-md w-full overflow-hidden"
         style="background:var(--surface,#fff); border:1px solid var(--border);">
        <div class="px-6 py-4 flex items-start gap-3" style="border-bottom:1px solid var(--border);">
            <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center" style="background:rgba(220,38,38,0.12);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" style="color:#dc2626;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
            </div>
            <div class="flex-1">
                <h3 id="prop-required-title" class="text-base font-bold" style="color:var(--text-primary);">Missing Required Fields</h3>
                <p class="text-xs mt-0.5" style="color:var(--text-muted);">Please complete the following before saving:</p>
            </div>
        </div>
        <div class="px-6 py-4 max-h-64 overflow-y-auto">
            <ul id="prop-required-list" class="list-disc list-inside space-y-1 text-sm" style="color:var(--text-primary);"></ul>
        </div>
        <div class="px-6 py-4 flex items-center justify-end gap-2" style="background:var(--surface-2); border-top:1px solid var(--border);">
            <button type="button" id="prop-required-close"
                    class="px-4 py-2 rounded-md text-sm font-medium transition-colors"
                    style="color:var(--text-secondary); border:1px solid var(--border);"
                    onmouseover="this.style.background='var(--surface-3)'" onmouseout="this.style.background='transparent'">
                Close
            </button>
            <button type="button" id="prop-required-goto"
                    class="px-4 py-2 rounded-md text-sm font-semibold text-white transition-colors"
                    style="background:var(--brand-default);"
                    onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                Take Me There
            </button>
        </div>
    </div>
</div>
{{-- ═══════════ Whistleblower Report Modal ═══════════ --}}
@permission('compliance.whistleblow.create')
@if(!$isNew)
<template x-teleport="body">
<div x-show="wbReportOpen" x-cloak class="fixed inset-0 z-[9999] flex items-center justify-center p-4" x-transition.opacity>
    <div class="absolute inset-0" style="background:rgba(0,0,0,0.55); backdrop-filter:blur(2px);" @click="wbReportOpen = false"></div>
    <div class="relative rounded-md shadow-2xl" style="width:520px; max-width:95vw; max-height:88vh; overflow-y:auto; background:var(--surface); border:1px solid var(--border);"
         x-data="{
            tier: 'tier_1', submitting: false, errorMsg: '', successMsg: '',
            async submitReport() {
                this.submitting = true; this.errorMsg = '';
                const fd = new FormData(document.getElementById('wb-report-form'));
                try {
                    const resp = await fetch('{{ route("compliance.whistleblow.store") }}', {
                        method: 'POST', body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    });
                    const data = await resp.json();
                    if (data.ok) {
                        this.successMsg = 'Report submitted. Reference: ' + data.reference;
                        setTimeout(() => { wbReportOpen = false; this.successMsg = ''; }, 2500);
                    } else {
                        this.errorMsg = data.message || 'Submission failed.';
                    }
                } catch (e) { this.errorMsg = 'Network error. Please try again.'; }
                this.submitting = false;
            }
         }">
        <form id="wb-report-form" @submit.prevent="submitReport()">
            @csrf
            <input type="hidden" name="property_id" value="{{ $property->id }}">
            <input type="hidden" name="property_address" value="{{ $property->address ?? $property->title }}">

            <div class="p-5 border-b" style="border-color:var(--border);">
                <h3 class="text-base font-bold" style="color:var(--text-primary);">Report Non-Compliant Listing</h3>
                <p class="text-xs mt-1" style="color:var(--text-muted);">{{ $property->address ?? $property->title }}</p>
            </div>

            <div class="p-5 space-y-4">
                <template x-if="successMsg">
                    <div class="rounded-md p-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-green) 10%, transparent); color:var(--ds-green);" x-text="successMsg"></div>
                </template>
                <template x-if="errorMsg">
                    <div class="rounded-md p-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-red) 10%, transparent); color:var(--ds-red);" x-text="errorMsg"></div>
                </template>

                {{-- Tier --}}
                <fieldset>
                    <legend class="text-xs font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Complaint Type</legend>
                    <div class="space-y-2">
                        <label class="flex items-start gap-2 cursor-pointer"><input type="radio" name="tier" value="tier_1" x-model="tier" class="mt-0.5"><span><span class="text-sm font-semibold" style="color:var(--text-primary);">Paperwork breach</span><br><span class="text-xs" style="color:var(--text-muted);">Seller confirmed no mandate / FICA</span></span></label>
                        <label class="flex items-start gap-2 cursor-pointer"><input type="radio" name="tier" value="tier_2" x-model="tier" class="mt-0.5"><span><span class="text-sm font-semibold" style="color:var(--text-primary);">No FFC displayed</span></span></label>
                        <label class="flex items-start gap-2 cursor-pointer"><input type="radio" name="tier" value="tier_3" x-model="tier" class="mt-0.5"><span><span class="text-sm font-semibold" style="color:var(--text-primary);">Unregistered practitioner</span></span></label>
                    </div>
                </fieldset>

                <div>
                    <label class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Subject Agency *</label>
                    <input type="text" name="subjects[0][agency_name]" required class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="Agency or practitioner name">
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Practitioner name</label>
                    <input type="text" name="subjects[0][practitioner_name]" class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="If known">
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Portal URL *</label>
                    <input type="url" name="subjects[0][portal_url]" required class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="https://...">
                </div>
                <input type="hidden" name="subjects[0][portal_source]" value="other">

                <div x-show="tier === 'tier_1'" x-cloak>
                    <label class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Seller statement / Notes *</label>
                    <textarea name="seller_statement" rows="3" class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="What did the seller say..."></textarea>
                </div>
                <div x-show="tier !== 'tier_1'">
                    <label class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Notes</label>
                    <textarea name="agent_notes" rows="3" class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="What did you observe..."></textarea>
                </div>

                <div>
                    <label class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Attach Screenshot</label>
                    <input type="file" name="screenshot" accept="image/*" class="mt-1 w-full text-sm" style="color:var(--text-primary);">
                </div>
            </div>

            <div class="p-5 border-t flex justify-end gap-3" style="border-color:var(--border);">
                <button type="button" @click="wbReportOpen = false" class="px-4 py-2 rounded-md text-sm font-medium" style="color:var(--text-secondary);">Cancel</button>
                <button type="submit" :disabled="submitting" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-default);">
                    <span x-text="submitting ? 'Submitting...' : 'Submit Report'"></span>
                </button>
            </div>
        </form>
    </div>
</div>
</template>
@endif
@endpermission
@endsection
