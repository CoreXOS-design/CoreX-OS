@extends('layouts.corex')

@section('corex-content')
@php $isNew = !$property->exists; @endphp
<div class="w-full space-y-4"
     x-data="{ activeTab: '{{ $isNew ? 'info' : session('tab', $activeTab) }}' }">

    {{-- Top bar: back + flash --}}
    <div class="flex items-center gap-4 flex-wrap">
        <a href="{{ route('corex.properties.index') }}"
           class="inline-flex items-center gap-1.5 text-sm no-underline flex-shrink-0"
           style="color:var(--text-secondary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Back
        </a>
        @if(session('success'))
        <div class="flex-1 rounded-md border px-4 py-2 text-sm font-medium" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
            {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div class="flex-1 rounded-md border px-4 py-2 text-sm font-medium" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ session('error') }}
        </div>
        @endif
        @if($errors->any())
        <div class="flex-1 rounded-md border px-4 py-2 text-sm" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ $errors->first() }}
        </div>
        @endif
    </div>

    {{-- Two-column layout on large screens --}}
    <div class="flex gap-5 items-start" style="min-height:0;">

        {{-- LEFT: sticky property summary panel --}}
        @php
        $thumb = $property->gallery_images_json[0] ?? ($property->dawn_images_json[0] ?? null);
        $statusColors = ['active'=>'#22c55e','draft'=>'#94a3b8','sold'=>'#3b82f6','withdrawn'=>'#f59e0b'];
        $sc = $statusColors[$property->status] ?? '#94a3b8';
        @endphp
        <aside class="hidden lg:flex flex-col gap-4 flex-shrink-0" style="width:280px; position:sticky; top:0;">

            {{-- Hero image --}}
            <div class="rounded-md overflow-hidden" style="aspect-ratio:4/3; background:var(--surface-2);">
                @if($thumb)
                <img src="{{ $thumb }}" alt="" class="w-full h-full object-cover">
                @else
                <div class="w-full h-full flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-16 h-16" style="color:var(--text-muted);opacity:.4;"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                </div>
                @endif
            </div>

            {{-- Property info card --}}
            <div class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
                <div>
                    <div class="flex items-start gap-2 flex-wrap">
                        <span class="text-xs px-2 py-0.5 rounded-full font-semibold flex-shrink-0"
                              style="background:{{ $sc }}22; color:{{ $sc }}; border:1px solid {{ $sc }}44;">
                            {{ ucfirst($property->status) }}
                        </span>
                        @if($property->isPublished())
                        <span class="text-xs px-2 py-0.5 rounded-full font-semibold flex-shrink-0" style="background:rgba(34,197,94,0.12); color:#22c55e; border:1px solid rgba(34,197,94,0.3);">LIVE</span>
                        @endif
                    </div>
                    <h1 class="text-base font-extrabold leading-tight mt-2" style="color:var(--text-primary);">{{ $property->title ?: 'New Property' }}</h1>
                    @if(!$isNew)<div class="text-lg font-bold mt-1" style="color:var(--brand-default,#0b2a4a);">{{ $property->formattedPrice() }}</div>@endif
                    @if($property->suburb)
                    <div class="text-xs mt-1" style="color:var(--text-muted);">
                        {{ $property->suburb }}{{ $property->city ? ', '.$property->city : '' }}
                    </div>
                    @endif
                </div>

                {{-- Room stats --}}
                <div class="grid grid-cols-3 gap-2">
                    @foreach([[$property->beds,'Beds'],[$property->baths,'Baths'],[$property->garages,'Gar']] as [$v,$l])
                    <div class="rounded-md py-2 text-center" style="background:var(--surface-2);">
                        <div class="text-sm font-bold" style="color:var(--text-primary);">{{ $v }}</div>
                        <div class="text-[10px] font-medium" style="color:var(--text-muted);">{{ $l }}</div>
                    </div>
                    @endforeach
                </div>

                @if($property->size_m2 || $property->erf_size_m2)
                <div class="grid grid-cols-2 gap-2">
                    @if($property->size_m2)
                    <div class="rounded-md py-2 px-3" style="background:var(--surface-2);">
                        <div class="text-xs font-bold" style="color:var(--text-primary);">{{ number_format($property->size_m2) }} m²</div>
                        <div class="text-[10px]" style="color:var(--text-muted);">Floor</div>
                    </div>
                    @endif
                    @if($property->erf_size_m2)
                    <div class="rounded-md py-2 px-3" style="background:var(--surface-2);">
                        <div class="text-xs font-bold" style="color:var(--text-primary);">{{ number_format($property->erf_size_m2) }} m²</div>
                        <div class="text-[10px]" style="color:var(--text-muted);">Erf</div>
                    </div>
                    @endif
                </div>
                @endif

                {{-- Meta --}}
                <div class="space-y-1.5 pt-1" style="border-top:1px solid var(--border);">
                    @if($property->property_type)
                    <div class="flex items-center justify-between">
                        <span class="text-xs" style="color:var(--text-muted);">Type</span>
                        <span class="text-xs font-medium" style="color:var(--text-primary);">{{ ucwords(str_replace('_',' ',$property->property_type)) }}</span>
                    </div>
                    @endif
                    @if($property->category)
                    <div class="flex items-center justify-between">
                        <span class="text-xs" style="color:var(--text-muted);">Category</span>
                        <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $property->category }}</span>
                    </div>
                    @endif
                    @if($property->mandate_type)
                    <div class="flex items-center justify-between">
                        <span class="text-xs" style="color:var(--text-muted);">Mandate</span>
                        <span class="text-xs font-medium" style="color:var(--text-primary);">{{ ucfirst($property->mandate_type) }}</span>
                    </div>
                    @endif
                    @if($property->agent)
                    <div class="flex items-center justify-between">
                        <span class="text-xs" style="color:var(--text-muted);">Agent</span>
                        <span class="text-xs font-medium truncate max-w-[120px]" style="color:var(--text-primary);">{{ $property->agent->name }}</span>
                    </div>
                    @endif
                    @if($property->listed_date)
                    <div class="flex items-center justify-between">
                        <span class="text-xs" style="color:var(--text-muted);">Listed</span>
                        <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $property->listed_date->format('d M Y') }}</span>
                    </div>
                    @endif
                    @if($property->expiry_date)
                    <div class="flex items-center justify-between">
                        <span class="text-xs" style="color:var(--text-muted);">Expires</span>
                        <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $property->expiry_date->format('d M Y') }}</span>
                    </div>
                    @endif
                </div>

                {{-- Actions --}}
                @if(!$isNew)
                <div class="grid grid-cols-1 gap-2 pt-1">
                    <a href="{{ route('corex.properties.ad', $property) }}"
                       class="flex items-center justify-center gap-2 px-3 py-2 rounded-md text-xs font-semibold no-underline transition-colors"
                       style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 30%, transparent);"
                       onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 22%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Ad Builder
                    </a>
                    @if(\Illuminate\Support\Facades\Route::has('corex.properties.marketing.index') && \App\Models\PerformanceSetting::get('marketing_enabled', 1))
                    <a href="{{ route('corex.properties.marketing.index', $property) }}"
                       class="flex items-center justify-center gap-2 px-3 py-2 rounded-md text-xs font-semibold no-underline transition-colors"
                       style="background:rgba(24,119,242,0.12); color:#1877f2; border:1px solid rgba(24,119,242,0.3);"
                       onmouseover="this.style.background='rgba(24,119,242,0.22)'" onmouseout="this.style.background='rgba(24,119,242,0.12)'">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535"/></svg>
                        Market Property
                    </a>
                    @endif
                </div>
                @endif
            </div>
        </aside>

        {{-- RIGHT: tabs --}}
        <div class="flex-1 min-w-0" style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">

        {{-- Mobile-only header strip --}}
        <div class="lg:hidden p-4" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
            <div class="flex items-start gap-3">
                @if($thumb)
                <img src="{{ $thumb }}" alt="" class="w-14 h-14 rounded-md object-cover flex-shrink-0">
                @endif
                <div class="flex-1 min-w-0">
                    <h1 class="text-base font-extrabold leading-tight" style="color:var(--text-primary);">{{ $property->title ?: 'New Property' }}</h1>
                    <div class="text-base font-bold mt-0.5" style="color:var(--brand-default,#0b2a4a);">{{ $property->formattedPrice() }}</div>
                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                        <span class="text-xs px-2 py-0.5 rounded-full font-semibold"
                              style="background:{{ $sc }}22; color:{{ $sc }}; border:1px solid {{ $sc }}44;">{{ ucfirst($property->status) }}</span>
                        <span class="text-xs" style="color:var(--text-secondary);">{{ $property->beds }}bd · {{ $property->baths }}ba</span>
                    </div>
                </div>
            </div>
        </div>

    {{-- Syndication bar --}}
    @if(!$isNew)
    <div class="flex items-center justify-end gap-2 px-4 py-2"
         style="border-bottom:1px solid var(--border);"
         x-data="{ synOpen: false, synStep: 'main' }">
        <div class="relative">
            {{-- Syndication button --}}
            <button type="button"
                    @click="synOpen = !synOpen; synStep = 'main'"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                    style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 30%, transparent);"
                    onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent)'">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z" />
                </svg>
                Syndication
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 transition-transform duration-150" :class="synOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {{-- Dropdown panel --}}
            <div x-show="synOpen"
                 x-cloak
                 @click.away="synOpen = false; synStep = 'main'"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-1"
                 class="absolute right-0 top-full mt-2 z-50 rounded-md shadow-2xl"
                 style="width:380px; max-height:80vh; overflow-y:auto; background:var(--surface); border:1px solid var(--border);">

                {{-- Step: main --}}
                <div x-show="synStep === 'main'" class="p-4 space-y-4">
                    {{-- Website publish section --}}
                    <p class="text-[10px] font-bold uppercase tracking-wider" style="color:var(--text-muted);">Publish to Website</p>

                    @if(!$property->isPublished())
                    <form method="POST" action="{{ route('corex.properties.update', $property) }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="title"   value="{{ $property->title }}">
                        <input type="hidden" name="suburb"  value="{{ $property->suburb }}">
                        <input type="hidden" name="price"   value="{{ $property->price }}">
                        <input type="hidden" name="beds"    value="{{ $property->beds }}">
                        <input type="hidden" name="baths"   value="{{ $property->baths }}">
                        <input type="hidden" name="garages" value="{{ $property->garages }}">
                        <input type="hidden" name="status"  value="{{ $property->status }}">
                        <input type="hidden" name="publish" value="1">
                        <button type="submit"
                                class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-md text-xs font-semibold"
                                style="background:#22c55e; color:#fff;"
                                onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            Publish Now
                        </button>
                    </form>
                    @else
                    <div class="flex items-center gap-2 px-3 py-2 rounded-md text-xs font-semibold"
                         style="background:rgba(34,197,94,0.10); color:#22c55e; border:1px solid rgba(34,197,94,0.25);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        Published {{ $property->published_at->diffForHumans() }}
                    </div>
                    @endif

                    <button type="button"
                            @click="synStep = 'preview'"
                            class="w-full flex items-center gap-2 px-3 py-2 rounded-md text-xs font-semibold"
                            style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 8%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);"
                            onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 18%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 8%, transparent)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        Live Preview
                    </button>

                    {{-- Portal Syndication section --}}
                    <div style="border-top:1px solid var(--border); margin-top:4px; padding-top:12px;">
                        <p class="text-[10px] font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Portal Syndication</p>

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
                            ];
                        @endphp
                        <div x-data="ppSyndication({{ Js::from($ppConfig) }})" @click.stop class="space-y-3">

                            {{-- Private Property toggle row --}}
                            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md cursor-pointer"
                                 style="background:var(--surface-2); border:1px solid var(--border);"
                                 @click="toggleEnabled()"
                                 :style="enabled ? 'background:rgba(0,212,170,0.06); border-color:rgba(0,212,170,0.25);' : 'background:var(--surface-2); border-color:var(--border);'">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" :style="enabled ? 'color:#00d4aa' : 'color:var(--text-muted)'">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                                    </svg>
                                    <span class="text-xs font-semibold" style="color:var(--text-primary);">Private Property</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    {{-- Toggle switch --}}
                                    <div class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full transition-colors duration-200"
                                         :style="enabled ? 'background:#00d4aa' : 'background:var(--surface-3,#374151)'"
                                         role="switch"
                                         :aria-checked="enabled">
                                        <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                              style="background:#fff; margin-top:2px;"
                                              :style="enabled ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                                    </div>
                                    {{-- Status badge --}}
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide"
                                          :style="statusBadgeStyle()" x-text="statusLabel()"></span>
                                </div>
                            </div>

                            {{-- Status line --}}
                            <div x-show="status && status !== ''" x-cloak class="text-[11px] px-1" style="color:var(--text-secondary);">
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
                                    <span style="color:#ef4444;" x-text="'Error: ' + lastError"></span>
                                </template>
                                <template x-if="status === 'deactivated'">
                                    <span style="color:var(--text-muted);">Deactivated</span>
                                </template>
                            </div>

                            {{-- Missing fields warning --}}
                            <div x-show="enabled && missingFields.length > 0" x-cloak
                                 class="rounded-md px-3 py-2.5 space-y-1.5"
                                 style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25);">
                                <p class="text-[11px] font-semibold" style="color:#f59e0b;">Cannot submit — missing required fields:</p>
                                <ul class="space-y-0.5 m-0 pl-3" style="list-style:disc;">
                                    <template x-for="(f, idx) in missingFields" :key="idx">
                                        <li class="text-[11px]" style="color:#f59e0b;">
                                            <span x-text="f.label"></span>
                                            <span class="opacity-60" x-text="'(' + f.tab + ' tab)'"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>

                            {{-- Exclusive days (sole mandate sale only) --}}
                            @if(in_array(strtolower($property->mandate_type ?? ''), ['sole', 'sole mandate']) && !in_array(strtolower($property->mandate_type ?? ''), ['rental']))
                            <div x-show="enabled" x-cloak class="flex items-center gap-2">
                                <label class="text-[11px]" style="color:var(--text-secondary);">Exclusive Days</label>
                                <input type="number"
                                       x-model.number="exclusiveDays"
                                       @click.stop
                                       min="0" max="92" step="1"
                                       class="w-16 px-2 py-1 rounded-md text-xs text-center"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <span class="text-[10px]" style="color:var(--text-muted);">0-92</span>
                            </div>
                            @endif

                            {{-- Action buttons --}}
                            <div x-show="enabled" x-cloak class="flex flex-wrap gap-2">
                                <button type="button"
                                        @click.stop="submitListing()"
                                        :disabled="loading || missingFields.length > 0"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        :style="missingFields.length > 0 ? 'background:#374151; color:#6b7280; cursor:not-allowed;' : 'background:#00d4aa; color:#fff;'"
                                        :class="missingFields.length === 0 ? 'hover:opacity-85' : ''">
                                    <svg x-show="!loading" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                                    <svg x-show="loading" x-cloak class="w-3.5 h-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="loading ? 'Submitting...' : 'Submit to PP'"></span>
                                </button>
                                {{-- Reactivate button (for deactivated listings) --}}
                                <button type="button"
                                        x-show="status === 'deactivated'"
                                        @click.stop="reactivateListing()"
                                        :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(0,212,170,0.10); color:#00d4aa; border:1px solid rgba(0,212,170,0.25);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Reactivate
                                </button>
                                {{-- Deactivate button --}}
                                <button type="button"
                                        x-show="status === 'submitted' || status === 'active'"
                                        @click.stop="deactivateListing()"
                                        :disabled="loading"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-opacity"
                                        style="background:rgba(239,68,68,0.10); color:#ef4444; border:1px solid rgba(239,68,68,0.25);"
                                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                    Deactivate
                                </button>
                            </div>

                            {{-- Last submitted timestamp --}}
                            <div x-show="lastSubmitted" x-cloak class="text-[10px]" style="color:var(--text-muted);">
                                Last submitted: <span x-text="lastSubmitted"></span>
                            </div>

                            {{-- Toast message (success only) --}}
                            <div x-show="message && messageType === 'success'" x-cloak
                                 x-transition
                                 class="px-3 py-2 rounded-md text-[11px] font-medium"
                                 style="background:rgba(0,212,170,0.10); color:#00d4aa; border:1px solid rgba(0,212,170,0.25);"
                                 x-text="message"></div>

                            {{-- Debug error panel --}}
                            <div x-show="showDebug && debugErrors.length > 0" x-cloak
                                 x-transition
                                 class="rounded-md space-y-2"
                                 style="background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.25); padding:10px 12px;">
                                <div class="flex items-center justify-between">
                                    <p class="text-[11px] font-bold" style="color:#ef4444;">Submission Failed</p>
                                    <button type="button" @click.stop="showDebug = false; debugErrors = []"
                                            class="text-[10px] px-1.5 py-0.5 rounded"
                                            style="color:var(--text-muted); background:var(--surface-2);">
                                        Dismiss
                                    </button>
                                </div>
                                <ul class="space-y-1 m-0 pl-3" style="list-style:disc;">
                                    <template x-for="(err, i) in debugErrors" :key="i">
                                        <li class="text-[11px] break-words" style="color:#f87171; word-break:break-word;"
                                            x-text="err"></li>
                                    </template>
                                </ul>
                            </div>
                        </div>
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
                    <a href="{{ route('corex.properties.preview', $property) }}?agent=me"
                       target="_blank"
                       class="flex items-center gap-2 px-3 py-2 rounded-md text-xs font-semibold no-underline"
                       style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 8%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);"
                       onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 18%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 8%, transparent)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        Show my info
                    </a>
                    <a href="{{ route('corex.properties.preview', $property) }}?agent=listing"
                       target="_blank"
                       class="flex items-center gap-2 px-3 py-2 rounded-md text-xs font-semibold no-underline"
                       style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                       onmouseover="this.style.background='var(--surface-3,#2a3a4a)'" onmouseout="this.style.background='var(--surface-2)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                        Show listing agent info
                    </a>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Tab bar (shared) --}}
        <div class="flex overflow-x-auto" style="border-bottom:1px solid var(--border);">
            @foreach([
                ['key'=>'overview',  'label'=>'Overview'],
                ['key'=>'info',      'label'=>'Info'],
                ['key'=>'gallery',   'label'=>'Gallery'],
                ['key'=>'contacts',  'label'=>'Contacts'],
                ['key'=>'notes',     'label'=>'Notes'],
                ['key'=>'drive',        'label'=>'Drive'],
                ['key'=>'core-matches', 'label'=>'Core Matches'],
            ] as $tab)
            @if($tab['key'] === 'core-matches' && (!\App\Models\PerformanceSetting::get('matches_enabled', 1) || !\App\Models\PerformanceSetting::get('matches_show_on_properties', 1) || !auth()->user()->hasPermission('access_core_matches')))
                @continue
            @endif
            <button type="button"
                    @click="activeTab = '{{ $tab['key'] }}'"
                    :class="activeTab === '{{ $tab['key'] }}' ? 'border-b-2 border-sky-500 bg-sky-500/5' : 'border-b-2 border-transparent'"
                    :style="activeTab === '{{ $tab['key'] }}' ? 'color:var(--brand-icon,#0ea5e9);' : 'color:var(--text-secondary);'"
                    class="px-6 py-4 text-sm font-semibold whitespace-nowrap flex-shrink-0 transition-colors duration-150 outline-none focus:outline-none"
                    style="background:transparent;">
                {{ $tab['label'] }}
                @if(!$isNew && $tab['key'] === 'contacts' && $property->contacts->count())
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);color:var(--brand-icon,#0ea5e9);">{{ $property->contacts->count() }}</span>
                @endif
                @if(!$isNew && $tab['key'] === 'notes' && $property->notes->count())
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);color:var(--brand-icon,#0ea5e9);">{{ $property->notes->count() }}</span>
                @endif
                @if(!$isNew && $tab['key'] === 'drive' && $property->files->count())
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);color:var(--brand-icon,#0ea5e9);">{{ $property->files->count() }}</span>
                @endif
                @if(!$isNew && $tab['key'] === 'core-matches' && $coreMatches->count())
                <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);color:var(--brand-icon,#0ea5e9);">{{ $coreMatches->count() }}</span>
                @endif
            </button>
            @endforeach
        </div>

        {{-- ── OVERVIEW TAB ──────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'overview'" x-cloak class="p-6 space-y-6">

            <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-4 gap-4">
                @foreach([
                    ['label'=>'Price',       'value'=>$property->formattedPrice(),                        'color'=>'var(--brand-default,#0b2a4a)'],
                    ['label'=>'Status',      'value'=>ucfirst($property->status),                         'color'=>$statusColors[$property->status] ?? '#94a3b8'],
                    ['label'=>'Type',        'value'=>$property->property_type ? ucwords(str_replace('_',' ',$property->property_type)) : '—', 'color'=>null],
                    ['label'=>'Category',    'value'=>$property->category ?: '—',                        'color'=>null],
                ] as $kpi)
                <div class="rounded-md p-4 text-center" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-lg font-bold leading-tight" style="color:{{ $kpi['color'] ?? 'var(--text-primary)' }};">{{ $kpi['value'] }}</div>
                    <div class="text-xs mt-1 font-medium" style="color:var(--text-muted);">{{ $kpi['label'] }}</div>
                </div>
                @endforeach
            </div>

            <div class="grid grid-cols-3 sm:grid-cols-5 gap-3">
                @foreach([
                    ['label'=>'Beds',     'value'=>$property->beds],
                    ['label'=>'Baths',    'value'=>$property->baths],
                    ['label'=>'Garages',  'value'=>$property->garages],
                    ['label'=>'Floor m²', 'value'=>$property->size_m2    ? number_format($property->size_m2) : '—'],
                    ['label'=>'Erf m²',   'value'=>$property->erf_size_m2 ? number_format($property->erf_size_m2) : '—'],
                ] as $stat)
                <div class="rounded-md p-3 text-center" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-xl font-bold" style="color:var(--text-primary);">{{ $stat['value'] }}</div>
                    <div class="text-xs mt-0.5 font-medium" style="color:var(--text-muted);">{{ $stat['label'] }}</div>
                </div>
                @endforeach
            </div>

            @if($property->rates_taxes || $property->levy || $property->special_levy)
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Monthly Costs</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @if($property->rates_taxes)
                    <div class="rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-medium mb-1" style="color:var(--text-muted);">Rates & Taxes</div>
                        <div class="text-base font-bold" style="color:var(--text-primary);">R {{ number_format($property->rates_taxes) }}</div>
                    </div>
                    @endif
                    @if($property->levy)
                    <div class="rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-medium mb-1" style="color:var(--text-muted);">Levy</div>
                        <div class="text-base font-bold" style="color:var(--text-primary);">R {{ number_format($property->levy) }}</div>
                    </div>
                    @endif
                    @if($property->special_levy)
                    <div class="rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-medium mb-1" style="color:var(--text-muted);">Special Levy</div>
                        <div class="text-base font-bold" style="color:var(--text-primary);">R {{ number_format($property->special_levy) }}</div>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            @if($property->features_json && count($property->features_json))
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Features</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach($property->features_json as $feat)
                    <span class="text-xs px-3 py-1.5 rounded-full font-medium"
                          style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);">
                        {{ $feat }}
                    </span>
                    @endforeach
                </div>
            </div>
            @endif

            @if($property->excerpt || $property->description)
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Description</h3>
                @if($property->excerpt)
                <p class="text-sm font-medium mb-2" style="color:var(--text-primary);">{{ $property->excerpt }}</p>
                @endif
                @if($property->description)
                <div class="text-sm whitespace-pre-line" style="color:var(--text-secondary);">{{ $property->description }}</div>
                @endif
            </div>
            @endif

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @if($property->created_at)
                <div>
                    <div class="text-xs font-medium mb-0.5" style="color:var(--text-muted);">Loaded</div>
                    <div class="text-sm" style="color:var(--text-primary);">{{ $property->created_at->format('d M Y') }}</div>
                </div>
                @endif
                @if($property->updated_at)
                <div>
                    <div class="text-xs font-medium mb-0.5" style="color:var(--text-muted);">Modified</div>
                    <div class="text-sm" style="color:var(--text-primary);">{{ $property->updated_at->format('d M Y H:i') }}</div>
                </div>
                @endif
                @if($property->listed_date)
                <div>
                    <div class="text-xs font-medium mb-0.5" style="color:var(--text-muted);">Listed Date</div>
                    <div class="text-sm" style="color:var(--text-primary);">{{ $property->listed_date->format('d M Y') }}</div>
                </div>
                @endif
                @if($property->expiry_date)
                <div>
                    <div class="text-xs font-medium mb-0.5" style="color:var(--text-muted);">Expiry Date</div>
                    <div class="text-sm" style="color:var(--text-primary);">{{ $property->expiry_date->format('d M Y') }}</div>
                </div>
                @endif
            </div>

        </div>

        {{-- ── INFO TAB ───────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'info'" {{ $isNew ? '' : 'x-cloak' }} class="p-6">
            <form id="prop-update-form" method="POST" enctype="multipart/form-data"
                  action="{{ $isNew ? route('corex.properties.store') : route('corex.properties.update', $property) }}"
                  class="space-y-6">
                @csrf
                @if(!$isNew) @method('PUT') @endif

                {{-- Classification --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Classification</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Category</label>
                            <select name="category" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— None —</option>
                                @foreach($settingItems['categories'] as $item)
                                <option value="{{ $item->name }}" {{ old('category', $property->category) === $item->name ? 'selected' : '' }}>
                                    {{ $item->name }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Property Type</label>
                            <select name="property_type" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— None —</option>
                                @foreach($settingItems['types'] as $item)
                                <option value="{{ $item->name }}" {{ old('property_type', $property->property_type) === $item->name ? 'selected' : '' }}>
                                    {{ $item->name }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Property Status <span class="text-red-400">*</span></label>
                            <select name="status" required class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— None —</option>
                                @foreach($settingItems['statuses'] as $item)
                                @php $val = strtolower(str_replace(' ','_',$item->name)); @endphp
                                <option value="{{ $val }}" {{ old('status', $property->status) === $val ? 'selected' : '' }}>
                                    {{ $item->name }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Mandate Type</label>
                            <select name="mandate_type" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— None —</option>
                                @foreach($settingItems['mandateTypes'] as $item)
                                <option value="{{ $item->name }}" {{ old('mandate_type', $property->mandate_type) === $item->name ? 'selected' : '' }}>
                                    {{ $item->name }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Title --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Listing Details</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Title <span class="text-red-400">*</span></label>
                            <input type="text" name="title" value="{{ old('title', $property->title) }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Price (ZAR) <span class="text-red-400">*</span></label>
                                <input type="number" name="price" value="{{ old('price', $property->price) }}" required min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Rates & Taxes</label>
                                <input type="number" name="rates_taxes" value="{{ old('rates_taxes', $property->rates_taxes) }}" min="0"
                                       placeholder="—"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Levy</label>
                                <input type="number" name="levy" value="{{ old('levy', $property->levy) }}" min="0"
                                       placeholder="—"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Special Levy</label>
                                <input type="number" name="special_levy" value="{{ old('special_levy', $property->special_levy) }}" min="0"
                                       placeholder="—"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            @foreach([['garages','Garages'],['size_m2','Floor m²'],['erf_size_m2','Erf m²']] as [$n,$lbl])
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">{{ $lbl }}</label>
                                <input type="number" name="{{ $n }}" value="{{ old($n, $property->$n ?? '') }}" min="0"
                                       {{ $n === 'garages' ? 'required max=20' : 'placeholder=—' }}
                                       class="w-full rounded-md px-3 py-2 text-sm text-center"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            @endforeach
                        </div>
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
                    <div>
                        <div class="flex items-center mb-1.5">
                            <span class="text-xs font-semibold" style="color:var(--text-secondary);">Spaces:</span>
                            <div class="ml-auto flex items-center gap-0.5">
                                <button type="button" title="Search spaces" class="w-6 h-6 rounded flex items-center justify-center transition-colors" style="color:var(--text-muted);" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" title="Click any tile to edit its details" class="w-6 h-6 rounded flex items-center justify-center transition-colors" style="color:var(--text-muted);" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <button type="button" @click="addSpaceOpen = true" title="Add space" class="w-6 h-6 rounded flex items-center justify-center transition-colors" style="color:var(--text-muted);" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8v8M8 12h8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
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
                                                ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 6%, transparent); border-bottom:2px solid var(--brand-icon,#0ea5e9);'
                                                : 'background:var(--surface); border-bottom:2px solid transparent;'">
                                        <div class="flex items-center gap-2">
                                            <span class="w-7 h-7 flex items-center justify-center flex-shrink-0"
                                                  :style="(idx === modalSpaceIdx && modalOpen) ? 'color:var(--brand-icon,#0ea5e9);' : 'color:var(--text-secondary);'"
                                                  x-html="getSpaceIconSvg(space.type)"></span>
                                            <span class="text-xl font-bold tabular-nums leading-none"
                                                  :style="(idx === modalSpaceIdx && modalOpen) ? 'color:var(--brand-icon,#0ea5e9);' : 'color:var(--text-primary);'"
                                                  x-text="formatCount(space.count)"></span>
                                        </div>
                                        <span class="text-[11px] font-medium text-center leading-tight"
                                              style="color:var(--text-muted); max-width:100px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                              x-text="space.type"></span>
                                    </button>
                                </template>

                                {{-- + Add tile --}}
                                <button type="button"
                                        @click="addSpaceOpen = true"
                                        class="flex flex-col items-center justify-center gap-2 px-4 py-4 transition-all cursor-pointer"
                                        style="flex:1 0 80px; border-left:1px solid var(--border); background:var(--surface);"
                                        onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 4%, transparent)'"
                                        onmouseout="this.style.background='var(--surface)'">
                                    <div class="flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0"
                                              style="border:2px dashed var(--border-hover); color:var(--text-muted);">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                                        </span>
                                    </div>
                                    <span class="text-[11px] font-medium" style="color:var(--text-muted);">Add</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- ── FEATURES SECTION ──────────────────────────────────── --}}
                    <div class="mt-4">
                        <div class="flex items-center mb-1.5">
                            <span class="text-xs font-semibold" style="color:var(--text-secondary);">Features:</span>
                            <div class="ml-auto flex items-center gap-0.5">
                                <button type="button" title="Search features" class="w-6 h-6 rounded flex items-center justify-center transition-colors" style="color:var(--text-muted);" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" title="Help" class="w-6 h-6 rounded flex items-center justify-center transition-colors" style="color:var(--text-muted);" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <button type="button" title="Add feature category" class="w-6 h-6 rounded flex items-center justify-center transition-colors" style="color:var(--text-muted);" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8v8M8 12h8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
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
                                                ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 5%, transparent); border-bottom:2px solid var(--brand-icon,#0ea5e9);'
                                                : 'background:var(--surface); border-bottom:2px solid transparent;'">
                                        <span class="w-7 h-7 flex items-center justify-center" x-html="getFeatureCatIconSvg(catKey)"></span>
                                        <span class="text-[11px] font-medium"
                                              :style="featureCategoryTab === catKey ? 'color:var(--brand-icon,#0ea5e9);' : 'color:var(--text-secondary);'"
                                              x-text="catDef.label"></span>
                                        <span x-show="features[catKey] && features[catKey].length > 0"
                                              class="absolute top-1 right-1.5 text-[9px] px-1 rounded-full font-bold leading-tight"
                                              style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 18%, transparent); color:var(--brand-icon,#0ea5e9); min-width:14px; text-align:center;"
                                              x-text="features[catKey].length"></span>
                                    </button>
                                </template>
                                <button type="button" disabled
                                        class="flex flex-col items-center gap-1 px-4 py-3 opacity-40 cursor-not-allowed"
                                        style="flex:1; background:var(--surface);">
                                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="color:var(--text-muted);"><circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8v8M8 12h8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span class="text-[11px] font-medium" style="color:var(--text-muted);">Add</span>
                                </button>
                            </div>

                            <div class="flex flex-wrap gap-1.5 p-3" style="background:var(--surface-2); min-height:50px;">
                                <template x-for="feat in featureCategories[featureCategoryTab].features" :key="feat">
                                    <button type="button"
                                            @click="toggleGlobalFeature(featureCategoryTab, feat)"
                                            class="text-xs px-2.5 py-1 rounded-full transition-colors"
                                            :style="features[featureCategoryTab] && features[featureCategoryTab].includes(feat)
                                                ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 35%, transparent);'
                                                : 'background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);'"
                                            x-text="feat">
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
                                <span class="text-xs px-2.5 py-1 rounded-full font-medium"
                                      style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);"
                                      x-text="feat"></span>
                            </template>
                        </div>
                    </div>

                    {{-- ── SPACE DETAIL MODAL ────────────────────────────────── --}}
                    <div x-show="modalOpen" x-cloak
                         class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
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
                                                            style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);">
                                                        ½ Toggle
                                                    </button>
                                                </template>
                                            </div>
                                            <template x-if="supportsHalf(currentSpace.type)">
                                                <p class="text-[11px] mt-1.5" style="color:var(--text-muted);">
                                                    Supports half units (e.g. ½ bathroom = toilet only). Click ½ Toggle to add/remove.
                                                </p>
                                            </template>
                                        </div>

                                        {{-- Feature picker panel (replaces body when open) --}}
                                        <template x-if="featurePickerOpen">
                                            <div class="space-y-4">
                                                <div class="flex items-center gap-2">
                                                    <button type="button" @click="featurePickerOpen = false"
                                                            class="flex items-center gap-1 text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">
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
                                                        <h4 class="text-[10px] font-bold uppercase tracking-wider mb-1.5" style="color:var(--text-muted);" x-text="group"></h4>
                                                        <div class="flex flex-wrap gap-1.5">
                                                            <template x-for="item in items" :key="item">
                                                                <button type="button"
                                                                        @click="togglePickerFeature(item)"
                                                                        class="text-xs px-2.5 py-1 rounded-full transition-colors"
                                                                        :style="isPickerFeatureSelected(item)
                                                                            ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 40%, transparent);'
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
                                                                style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);">
                                                            + Add Feature
                                                        </button>
                                                    </div>
                                                    <div class="flex flex-wrap gap-1.5 rounded-md p-2.5 min-h-[36px]"
                                                         style="background:var(--surface-2); border:1px solid var(--border);">
                                                        <template x-for="(feat, fi) in currentSpace.featuresAll" :key="feat + fi">
                                                            <span class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-full"
                                                                  style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
                                                                <span x-text="feat"></span>
                                                                <button type="button" @click="removeSpaceFeature(modalSpaceIdx,'all',fi)"
                                                                        class="font-bold hover:text-red-400 leading-none">×</button>
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
                                                                        style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
                                                                    + Feature
                                                                </button>
                                                                <template x-if="ui < currentSpace.units.length - 1">
                                                                    <button type="button" @click="copyFeaturesDown(modalSpaceIdx, ui)"
                                                                            title="Copy these features to all units below"
                                                                            class="text-xs px-2 py-0.5 rounded-md flex-shrink-0"
                                                                            style="background:rgba(99,102,241,0.1); color:#818cf8; border:1px solid rgba(99,102,241,0.2);">
                                                                        Copy ↓
                                                                    </button>
                                                                </template>
                                                            </div>
                                                            <div class="flex flex-wrap gap-1">
                                                                <template x-for="(feat, fi) in unit.features" :key="feat + fi">
                                                                    <span class="inline-flex items-center gap-0.5 text-xs px-2 py-0.5 rounded-full"
                                                                          style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
                                                                        <span x-text="feat"></span>
                                                                        <button type="button" @click="removeSpaceFeature(modalSpaceIdx,ui,fi)"
                                                                                class="font-bold hover:text-red-400 leading-none text-xs">×</button>
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
                                        style="color:#ef4444;"
                                        onmouseover="this.style.background='rgba(239,68,68,0.08)'" onmouseout="this.style.background='transparent'">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.021-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                    Remove Space
                                </button>
                                <button type="button" @click="featurePickerOpen ? featurePickerOpen=false : closeModal()"
                                        class="flex items-center gap-1.5 text-sm font-semibold text-white px-4 py-2 rounded-md"
                                        style="background:#22c55e;">
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
                         class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
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
                                                    ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 30%, transparent); color:var(--brand-icon,#0ea5e9);'
                                                    : 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);'">
                                            <span class="w-5 h-5 flex items-center justify-center flex-shrink-0" x-html="getSpaceIconSvg(type)"></span>
                                            <span class="text-[10px] text-center leading-tight" x-text="type"></span>
                                            <span x-show="hasSpace(type)" class="text-[9px] font-bold" style="color:var(--brand-icon,#0ea5e9);">Added ✓</span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>{{-- /spacesAndFeaturesManager --}}

                {{-- Description --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Description</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Excerpt <span class="text-xs font-normal" style="color:var(--text-muted);">(max 500 chars)</span></label>
                            <textarea name="excerpt" rows="2"
                                      class="w-full rounded-md px-3 py-2 text-sm"
                                      style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                      placeholder="Short summary shown in search results...">{{ old('excerpt', $property->excerpt) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Full Description</label>
                            <textarea name="description" rows="6"
                                      class="w-full rounded-md px-3 py-2 text-sm"
                                      style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                      placeholder="Full property description...">{{ old('description', $property->description) }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- Address --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Address</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-semibold" style="color:var(--text-secondary);">Full Address</label>
                                <label class="flex items-center gap-1 text-[10px] cursor-pointer" style="color:var(--text-muted);">
                                    <input type="checkbox" name="pp_hide_street_name" value="1" {{ old('pp_hide_street_name', $property->pp_hide_street_name ?? false) ? 'checked' : '' }} class="w-3 h-3 rounded" style="accent-color:#00d4aa;">
                                    Hide street on PP
                                </label>
                            </div>
                            <input type="text" name="address" value="{{ old('address', $property->address) }}"
                                   placeholder="e.g. 21 Dee Road"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-semibold" style="color:var(--text-secondary);">Complex Name</label>
                                <label class="flex items-center gap-1 text-[10px] cursor-pointer" style="color:var(--text-muted);">
                                    <input type="checkbox" name="pp_hide_complex_name" value="1" {{ old('pp_hide_complex_name', $property->pp_hide_complex_name ?? false) ? 'checked' : '' }} class="w-3 h-3 rounded" style="accent-color:#00d4aa;">
                                    Hide on PP
                                </label>
                            </div>
                            <input type="text" name="complex_name" value="{{ old('complex_name', $property->complex_name) }}"
                                   placeholder="e.g. Ocean View"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-semibold" style="color:var(--text-secondary);">Unit Number</label>
                                <label class="flex items-center gap-1 text-[10px] cursor-pointer" style="color:var(--text-muted);">
                                    <input type="checkbox" name="pp_hide_unit_number" value="1" {{ old('pp_hide_unit_number', $property->pp_hide_unit_number ?? false) ? 'checked' : '' }} class="w-3 h-3 rounded" style="accent-color:#00d4aa;">
                                    Hide on PP
                                </label>
                            </div>
                            <input type="text" name="unit_number" value="{{ old('unit_number', $property->unit_number) }}"
                                   placeholder="e.g. 14"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Property / Erf Number</label>
                            <input type="text" name="property_number" value="{{ old('property_number', $property->property_number) }}"
                                   placeholder="e.g. Erf 789"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Suburb <span class="text-red-400">*</span></label>
                            <input type="text" name="suburb" value="{{ old('suburb', $property->suburb) }}" required
                                   placeholder="e.g. Uvongo"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">City / Town</label>
                            <input type="text" name="city" value="{{ old('city', $property->city) }}"
                                   placeholder="e.g. Margate"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">District / Municipality</label>
                            <input type="text" name="district" value="{{ old('district', $property->district) }}"
                                   placeholder="e.g. Ray Nkonyeni"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Region</label>
                            <input type="text" name="region" value="{{ old('region', $property->region) }}"
                                   placeholder="KZN South Coast"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Dates & Meta --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Dates & Meta</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Listed Date</label>
                            <input type="date" name="listed_date" value="{{ old('listed_date', $property->listed_date?->format('Y-m-d')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); color-scheme: light dark;">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Expiry Date</label>
                            <input type="date" name="expiry_date" value="{{ old('expiry_date', $property->expiry_date?->format('Y-m-d')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); color-scheme: light dark;">
                        </div>
                        @if(!$isNew)
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Loaded</label>
                            <input type="text" value="{{ $property->created_at->format('d M Y H:i') }}" disabled
                                   class="w-full rounded-md px-3 py-2 text-sm cursor-not-allowed"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Modified</label>
                            <input type="text" value="{{ $property->updated_at->format('d M Y H:i') }}" disabled
                                   class="w-full rounded-md px-3 py-2 text-sm cursor-not-allowed"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Agent / Branch --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Assignment</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Primary Agent <span class="text-red-400">*</span></label>
                            <select name="agent_id" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" {{ (int) old('agent_id', $property->agent_id) === $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Second Agent</label>
                            <select name="pp_second_agent_id" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— None —</option>
                                @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" {{ (int) old('pp_second_agent_id', $property->pp_second_agent_id ?? '') === $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Branch</label>
                            <select name="branch_id" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— None —</option>
                                @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('branch_id', $property->branch_id) === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Publish to Website</label>
                            <div class="flex items-center gap-2 mt-1">
                                <input type="hidden" name="publish" value="0">
                                <input type="checkbox" name="publish" value="1" id="publish_toggle"
                                       {{ $property->isPublished() ? 'checked disabled' : '' }}
                                       class="w-4 h-4 rounded" style="accent-color:var(--brand-icon,#0ea5e9);">
                                <label for="publish_toggle" class="text-xs" style="color:var(--text-secondary);">
                                    {{ $property->isPublished() ? 'Published '.$property->published_at->diffForHumans() : 'Publish now' }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Rental & Lease Details — visible when mandate_type is Rental --}}
                @php $isRental = in_array(strtolower(old('mandate_type', $property->mandate_type) ?? ''), ['rental']); @endphp
                <div x-data="{ isRental: {{ $isRental ? 'true' : 'false' }} }"
                     x-init="$watch('$root.querySelector(\'[name=mandate_type]\')?.value', v => { isRental = v && v.toLowerCase() === 'rental'; })"
                     @change.window="if ($event.target?.name === 'mandate_type') { isRental = $event.target.value.toLowerCase() === 'rental'; }">
                    <div x-show="isRental" x-cloak>
                        <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Rental & Lease Details</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Monthly Rental (R)</label>
                                <input type="number" name="rental_amount" value="{{ old('rental_amount', $property->rental_amount) }}"
                                       placeholder="0.00" min="0" step="0.01"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Deposit (R)</label>
                                <input type="number" name="deposit_amount" value="{{ old('deposit_amount', $property->deposit_amount) }}"
                                       placeholder="0.00" min="0" step="0.01"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Price Type</label>
                                <select name="rental_price_type" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="per month" {{ old('rental_price_type', $property->rental_price_type) === 'per month' ? 'selected' : '' }}>Per Month</option>
                                    <option value="per sqm" {{ old('rental_price_type', $property->rental_price_type) === 'per sqm' ? 'selected' : '' }}>Per Sqm</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Commission (%)</label>
                                <input type="number" name="commission_percent" value="{{ old('commission_percent', $property->commission_percent) }}"
                                       placeholder="0.00" min="0" max="100" step="0.01"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Admin Fee (R)</label>
                                <input type="number" name="admin_fee" value="{{ old('admin_fee', $property->admin_fee) }}"
                                       placeholder="0.00" min="0" step="0.01"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Marketing Fee (R)</label>
                                <input type="number" name="marketing_fee" value="{{ old('marketing_fee', $property->marketing_fee) }}"
                                       placeholder="0.00" min="0" step="0.01"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Lease Start Date</label>
                                <input type="date" name="lease_start_date" value="{{ old('lease_start_date', $property->lease_start_date?->format('Y-m-d')) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); color-scheme: light dark;">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Lease End Date</label>
                                <input type="date" name="lease_end_date" value="{{ old('lease_end_date', $property->lease_end_date?->format('Y-m-d')) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); color-scheme: light dark;">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Showday Events (PP syndication) --}}
                @if(!$isNew && $property->pp_syndication_enabled && in_array($property->pp_syndication_status, ['submitted', 'active']))
                <div x-data="{ showForm: false, sdStart: '', sdEnd: '', sdDesc: '', sdLoading: false, sdMsg: '' }">
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-4" style="color:var(--text-muted);">Showday Events (Private Property)</h3>
                    <div class="space-y-3">
                        <button type="button" @click="showForm = !showForm"
                                class="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-md transition-colors"
                                style="background:rgba(0,212,170,0.08); color:#00d4aa; border:1px solid rgba(0,212,170,0.2);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            <span x-text="showForm ? 'Cancel' : 'Add Showday'"></span>
                        </button>
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
                                <button type="button" @click="
                                    if (!sdStart || !sdEnd) return;
                                    sdLoading = true; sdMsg = '';
                                    fetch('/corex/properties/{{ $property->id }}/syndication/showday', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                                        body: JSON.stringify({ start_date: sdStart, end_date: sdEnd, description: sdDesc || 'Open Showday' }),
                                    }).then(r => r.json()).then(d => {
                                        sdMsg = d.success ? 'Showday submitted to PP' : (d.message || 'Failed');
                                        if (d.success) { sdStart = ''; sdEnd = ''; sdDesc = ''; showForm = false; }
                                    }).catch(() => { sdMsg = 'Network error'; }).finally(() => { sdLoading = false; });
                                " :disabled="sdLoading || !sdStart || !sdEnd"
                                   class="px-4 py-2 rounded-md text-xs font-semibold text-white"
                                   style="background:#00d4aa;">
                                    <span x-text="sdLoading ? 'Submitting...' : 'Submit Showday to PP'"></span>
                                </button>
                                <span x-show="sdMsg" x-text="sdMsg" class="text-xs" style="color:#00d4aa;"></span>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

            </form>{{-- /prop-update-form --}}

            {{-- Save / Delete — outside the update form to prevent nesting --}}
            <div class="flex items-center justify-between pt-4">
                <button type="submit" form="prop-update-form"
                        class="px-5 py-2 rounded-md text-sm font-semibold text-white"
                        style="background:var(--brand-default,#0b2a4a); border:1px solid var(--border);"
                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    {{ $isNew ? 'Create Property' : 'Save Changes' }}
                </button>
                @if(!$isNew)
                <form method="POST" action="{{ route('corex.properties.destroy', $property) }}"
                      onsubmit="return confirm('Delete this property?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm font-semibold text-red-500 hover:text-red-600 px-4 py-2 rounded-md hover:bg-red-500/10 transition-colors">
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
                       onmouseover="this.style.borderColor='var(--brand-icon,#0ea5e9)'" onmouseout="this.style.borderColor='var(--border-hover)'">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                    <span id="gallery_images-create-label">Select images (multiple allowed)</span>
                    <input type="file" name="gallery_images[]" multiple accept="image/*" form="prop-update-form" class="hidden"
                           onchange="document.getElementById('gallery_images-create-label').textContent = this.files.length + ' file' + (this.files.length !== 1 ? 's' : '') + ' selected';">
                </label>
            </div>
        @else

            {{-- Upload new images --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Upload Images</h3>
                <form method="POST" action="{{ route('corex.properties.update', $property) }}"
                      enctype="multipart/form-data">
                    @csrf @method('PUT')
                    {{-- Pass required fields silently --}}
                    <input type="hidden" name="title" value="{{ $property->title }}">
                    <input type="hidden" name="suburb" value="{{ $property->suburb }}">
                    <input type="hidden" name="price" value="{{ $property->price }}">
                    <input type="hidden" name="beds" value="{{ $property->beds }}">
                    <input type="hidden" name="baths" value="{{ $property->baths }}">
                    <input type="hidden" name="garages" value="{{ $property->garages }}">
                    <input type="hidden" name="status" value="{{ $property->status }}">

                    <label class="flex items-center gap-3 px-4 py-3 rounded-md border border-dashed cursor-pointer transition-colors text-sm"
                           style="border-color:var(--border-hover); color:var(--text-secondary);"
                           onmouseover="this.style.borderColor='var(--brand-icon,#0ea5e9)'" onmouseout="this.style.borderColor='var(--border-hover)'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                        <span id="gal-label">Select images to upload (multiple allowed)</span>
                        <input type="file" name="gallery_images[]" multiple accept="image/*" class="hidden"
                               onchange="document.getElementById('gal-label').textContent = this.files.length + ' file' + (this.files.length > 1 ? 's' : '') + ' selected'; document.getElementById('gal-submit').classList.remove('hidden');">
                    </label>
                    <button id="gal-submit" type="submit"
                            class="hidden mt-2 px-4 py-2 rounded-md text-sm font-semibold text-white"
                            style="background:var(--brand-button,#0ea5e9);">
                        Upload Images
                    </button>
                </form>
            </div>

            {{-- Gallery grid with drag to reorder --}}
            @php $galleryImages = $property->gallery_images_json ?? []; @endphp

            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">
                        Gallery Images ({{ count($galleryImages) }})
                    </h3>
                    <span class="text-xs" style="color:var(--text-muted);">Drag to reorder · Click to view/delete</span>
                </div>

                @if(count($galleryImages))
                <div id="gallery-grid" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2">
                    @foreach($galleryImages as $idx => $imgUrl)
                    <div class="gallery-item relative group rounded-md overflow-hidden cursor-grab"
                         data-index="{{ $idx }}" style="aspect-ratio:1/1;">
                        <img src="{{ $imgUrl }}" alt=""
                             class="w-full h-full object-cover transition-transform duration-200 group-hover:scale-105">

                        {{-- Overlay actions --}}
                        <div class="absolute inset-0 flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity duration-150"
                             style="background:rgba(0,0,0,0.55);">
                            {{-- View --}}
                            <button type="button"
                                    onclick="openLightbox({{ $idx }})"
                                    class="w-7 h-7 rounded-full flex items-center justify-center text-white"
                                    style="background:rgba(255,255,255,0.18);"
                                    title="View">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                            </button>
                            {{-- Delete --}}
                            <form method="POST" action="{{ route('corex.properties.deleteImage', $property) }}">
                                @csrf
                                <input type="hidden" name="group" value="gallery_images_json">
                                <input type="hidden" name="index" value="{{ $idx }}">
                                <button type="submit"
                                        onclick="return confirm('Delete this image?')"
                                        class="w-7 h-7 rounded-full flex items-center justify-center text-white"
                                        style="background:rgba(239,68,68,0.35);"
                                        title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                </button>
                            </form>
                        </div>

                        {{-- Drag handle --}}
                        <div class="absolute top-1 left-1 opacity-0 group-hover:opacity-60 transition-opacity pointer-events-none">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="white" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" /></svg>
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="mt-2 text-xs" style="color:var(--text-muted);">
                    Drag to reorder · Changes saved automatically
                </div>
                @else
                <div class="rounded-md p-8 text-center" style="background:var(--surface-2); border:1px dashed var(--border-hover);">
                    <div class="text-sm" style="color:var(--text-secondary);">No gallery images yet. Upload some above.</div>
                </div>
                @endif
            </div>

            {{-- Lightbox with prev/next navigation --}}
            @php $galleryJsonForJs = json_encode(array_values($galleryImages)); @endphp
            <div id="lightbox" class="hidden fixed inset-0 z-50 flex items-center justify-center"
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
                                class="text-xs font-semibold text-red-500 hover:text-red-600 px-3 py-1.5 rounded-md hover:bg-red-500/10 transition-colors">Remove</button>
                    </div>
                </template>
                <template x-for="(nc, idx) in pendingNew" :key="'n'+idx">
                    <div class="flex items-center gap-3 px-4 py-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="nc.first_name + ' ' + nc.last_name"></div>
                            <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="[nc.phone, nc.email].filter(Boolean).join(' · ')"></div>
                            <div class="text-xs font-medium mt-0.5" style="color:var(--brand-icon,#0ea5e9);">New contact (will be created)</div>
                        </div>
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][first_name]'" :value="nc.first_name" form="prop-update-form">
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][last_name]'"  :value="nc.last_name"  form="prop-update-form">
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][phone]'"      :value="nc.phone"      form="prop-update-form">
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][email]'"      :value="nc.email"      form="prop-update-form">
                        <input type="hidden" :name="'pending_new_contacts['+idx+'][contact_type_id]'" :value="nc.contact_type_id" form="prop-update-form">
                        <button type="button" @click="removeNew(idx)"
                                class="text-xs font-semibold text-red-500 hover:text-red-600 px-3 py-1.5 rounded-md hover:bg-red-500/10 transition-colors">Remove</button>
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
                            <span class="ml-auto text-xs font-semibold flex-shrink-0" style="color:var(--brand-icon,#0ea5e9);">+ Add</span>
                        </button>
                    </template>
                </div>
                <div x-show="searched && results.length === 0" class="text-sm" style="color:var(--text-muted);">No matching contacts found.</div>
            </div>

            {{-- Create new contact & add to pending --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:20px;">
                <button type="button" @click="showNewForm = !showNewForm"
                        class="flex items-center gap-2 text-sm font-semibold"
                        style="color:var(--brand-icon,#0ea5e9); background:none; border:none; cursor:pointer; padding:0;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4"
                         :class="showNewForm ? 'rotate-45' : ''" style="transition:transform .2s;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span x-text="showNewForm ? 'Cancel' : 'Create new contact &amp; link'"></span>
                </button>
                <div x-show="showNewForm" x-cloak class="mt-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="text-red-500">*</span></label>
                            <input type="text" x-model="newForm.first_name"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="text-red-500">*</span></label>
                            <input type="text" x-model="newForm.last_name"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone <span class="text-red-500">*</span></label>
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
                    Linked Contacts ({{ $property->contacts->count() }})
                </h3>
                @forelse($property->contacts as $c)
                <div class="flex items-center gap-3 px-4 py-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);">
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
                            @if($c->pivot->role)<span class="font-semibold" style="color:var(--brand-icon,#0ea5e9);">{{ ucfirst($c->pivot->role) }}</span>@endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('corex.properties.contacts.unlink', [$property, $c]) }}"
                          onsubmit="return confirm('Unlink {{ addslashes($c->full_name) }} from this property?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs font-semibold text-red-500 hover:text-red-600 px-3 py-1.5 rounded-md hover:bg-red-500/10 transition-colors">Unlink</button>
                    </form>
                </div>
                @empty
                <div class="rounded-md p-6 text-center" style="background:var(--surface-2); border:1px dashed var(--border-hover);">
                    <div class="text-sm" style="color:var(--text-secondary);">No contacts linked yet.</div>
                </div>
                @endforelse
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
                        <form method="POST" action="{{ route('corex.properties.contacts.link', $property) }}">
                            @csrf
                            <input type="hidden" name="contact_id" :value="r.id">
                            <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-sky-500/10 transition-colors"
                                    style="border-bottom:1px solid var(--border); background:var(--surface);">
                                <div>
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="r.first_name + ' ' + r.last_name"></div>
                                    <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="[r.phone, r.email].filter(Boolean).join(' · ')"></div>
                                </div>
                                <span class="ml-auto text-xs font-semibold flex-shrink-0" style="color:var(--brand-icon,#0ea5e9);">+ Link</span>
                            </button>
                        </form>
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
                        style="color:var(--brand-icon,#0ea5e9); background:none; border:none; cursor:pointer; padding:0;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4"
                         :class="open ? 'rotate-45' : ''" style="transition:transform .2s;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span x-text="open ? 'Cancel' : 'Create new contact &amp; link'"></span>
                </button>

                <div x-show="open" x-cloak class="mt-5 space-y-4">
                    <form method="POST" action="{{ route('corex.properties.contacts.createAndLink', $property) }}" class="space-y-4">
                        @csrf
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="text-red-500">*</span></label>
                                <input type="text" name="first_name" required
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="text-red-500">*</span></label>
                                <input type="text" name="last_name" required
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone <span class="text-red-500">*</span></label>
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
                        </div>
                        <button type="submit"
                                class="px-5 py-2 rounded-md text-sm font-semibold text-white"
                                style="background:var(--brand-button,#0ea5e9);">
                            Create Contact &amp; Link
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
                            <button type="submit" class="text-xs text-red-500 hover:text-red-600 font-semibold flex-shrink-0">Delete</button>
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

        {{-- ── DRIVE TAB ────────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'drive'" x-cloak class="p-6 space-y-5">
        @if($isNew)
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Upload Files</h3>
                <p class="text-xs mb-4" style="color:var(--text-muted);">Files will be uploaded when you click <strong style="color:var(--text-secondary);">Create Property</strong>.</p>
                <label class="flex items-center gap-3 px-4 py-3 rounded-md border border-dashed cursor-pointer text-sm transition-colors"
                       style="border-color:var(--border-hover); color:var(--text-secondary);"
                       onmouseover="this.style.borderColor='var(--brand-icon,#0ea5e9)'" onmouseout="this.style.borderColor='var(--border-hover)'">
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
                      enctype="multipart/form-data" class="flex items-center gap-3 flex-wrap">
                    @csrf
                    <label class="flex-1 flex items-center gap-3 px-4 py-3 rounded-md border border-dashed cursor-pointer transition-colors text-sm"
                           style="border-color:var(--border-hover); color:var(--text-secondary); min-width:200px;"
                           onmouseover="this.style.borderColor='var(--brand-icon,#0ea5e9)'" onmouseout="this.style.borderColor='var(--border-hover)'">
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
                </form>
            </div>

            {{-- Files list --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">
                    Files ({{ $property->files->count() }})
                </h3>
                @forelse($property->files as $file)
                <div class="flex items-center gap-4 p-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);">
                    {{-- File icon --}}
                    @php
                    $mime = $file->mime_type ?? '';
                    $icon = str_contains($mime,'pdf') ? '#ef4444' : (str_contains($mime,'image') ? '#0ea5e9' : '#94a3b8');
                    @endphp
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0"
                         style="background:{{ $icon }}22;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="{{ $icon }}" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium truncate" style="color:var(--text-primary);">{{ $file->name }}</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">
                            {{ $file->formattedSize() }} · {{ $file->user?->name ?? 'Unknown' }} · {{ $file->created_at->format('d M Y') }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <a href="{{ $file->url() }}" target="_blank"
                           class="text-xs font-semibold no-underline px-3 py-1.5 rounded-md transition-colors"
                           style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);"
                           onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent)'">
                            Download
                        </a>
                        @if(auth()->id() === $file->user_id || in_array(auth()->user()->effectiveRole(), ['super_admin', 'admin']))
                        <form method="POST" action="{{ route('corex.properties.files.destroy', [$property, $file]) }}"
                              onsubmit="return confirm('Delete this file?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs font-semibold text-red-500 hover:text-red-600 px-3 py-1.5 rounded-md hover:bg-red-500/10 transition-colors">Delete</button>
                        </form>
                        @endif
                    </div>
                </div>
                @empty
                <div class="rounded-md p-6 text-center" style="background:var(--surface-2); border:1px dashed var(--border-hover);">
                    <div class="text-sm" style="color:var(--text-secondary);">No files uploaded yet.</div>
                </div>
                @endforelse
            </div>
        @endif {{-- /!$isNew drive --}}
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
                        <div class="text-base font-extrabold" style="color:{{ $views > 0 ? 'var(--brand-icon,#0ea5e9)' : 'var(--text-muted)' }};">
                            {{ $views }}
                        </div>
                        <div class="text-[10px] font-semibold" style="color:var(--text-muted);">
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
                            <div class="text-[10px] font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Agent</div>
                            @if($cm->createdBy)
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0 text-xs font-bold text-white"
                                     style="background:linear-gradient(135deg,#1e3a5f,#0ea5e9);">
                                    {{ strtoupper(substr($cm->createdBy->name, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-bold truncate" style="color:var(--text-primary);">{{ $cm->createdBy->name }}</div>
                                    @if($cm->createdBy->branch)
                                    <div class="text-[10px]" style="color:var(--text-muted);">{{ $cm->createdBy->branch->name }}</div>
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
                            <div class="text-[10px] font-bold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Client</div>
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
                            <div class="pt-1 flex flex-wrap gap-x-4 gap-y-1.5 text-[10px]" style="color:var(--text-muted);">
                                @foreach([[$cm->beds_min,'Min Beds'],[$cm->baths_min,'Min Baths'],[$cm->garages_min,'Min Gar']] as [$v,$l])
                                @if($v !== null)<span><strong style="color:var(--text-secondary);">{{ $v }}+</strong> {{ $l }}</span>@endif
                                @endforeach
                                @if($cm->floor_size_min || $cm->floor_size_max)
                                <span><strong style="color:var(--text-secondary);">{{ $cm->floor_size_min ? number_format($cm->floor_size_min) : '—' }}–{{ $cm->floor_size_max ? number_format($cm->floor_size_max) : '—' }}</strong> m² floor</span>
                                @endif
                            </div>
                            <a href="{{ route('corex.contacts.matches.results', [$cm->contact, $cm]) }}"
                               class="inline-flex items-center gap-1.5 mt-2 text-xs font-semibold no-underline"
                               style="color:var(--brand-icon,#0ea5e9);">
                                View match results →
                            </a>
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

</div>{{-- /w-full --}}

@push('scripts')
<script>
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
        }
    };
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
            const sp = this.spaces[idx]; const step = this.supportsHalf(sp.type) ? 0.5 : 1;
            sp.count = parseFloat((sp.count + step).toFixed(1)); this._rebuildUnits(idx);
        },
        decrementCount(idx) {
            const sp = this.spaces[idx]; const step = this.supportsHalf(sp.type) ? 0.5 : 1;
            const n = parseFloat((sp.count - step).toFixed(1)); if (n < 0) return;
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

// Gallery drag to reorder (SortableJS-style without external library)
(function() {
    const grid = document.getElementById('gallery-grid');
    if (!grid) return;

    let dragging = null;

    grid.addEventListener('dragstart', function(e) {
        const item = e.target.closest('.gallery-item');
        if (!item) return;
        dragging = item;
        item.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
    });

    grid.addEventListener('dragend', function(e) {
        const item = e.target.closest('.gallery-item');
        if (item) item.style.opacity = '1';
        dragging = null;
        saveOrder();
    });

    grid.addEventListener('dragover', function(e) {
        e.preventDefault();
        const target = e.target.closest('.gallery-item');
        if (!target || target === dragging) return;
        const rect = target.getBoundingClientRect();
        const mid  = rect.left + rect.width / 2;
        if (e.clientX < mid) {
            grid.insertBefore(dragging, target);
        } else {
            grid.insertBefore(dragging, target.nextSibling);
        }
    });

    function saveOrder() {
        const items = Array.from(grid.querySelectorAll('.gallery-item'));
        const order = items.map(i => parseInt(i.dataset.index));
        fetch('{{ $isNew ? '' : route('corex.properties.reorderImages', $property) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ group: 'gallery_images_json', order: order }),
        });
    }
})();

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
                'pending': 'background:rgba(245,158,11,0.12); color:#f59e0b;',
                'submitted': 'background:rgba(245,158,11,0.12); color:#f59e0b;',
                'active': 'background:rgba(0,212,170,0.12); color:#00d4aa;',
                'error': 'background:rgba(239,68,68,0.12); color:#ef4444;',
                'deactivated': 'background:var(--surface-2); color:var(--text-muted);',
            };
            if (!this.enabled && !this.status) return styles[''];
            return styles[this.status] || styles[''];
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
                    body: JSON.stringify({ pp_exclusive_days: this.exclusiveDays || null }),
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
</script>
@endpush
@endsection
