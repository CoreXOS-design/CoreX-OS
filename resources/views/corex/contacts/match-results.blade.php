{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $defaultWaMsg = \App\Models\PerformanceSetting::get('matches_wa_message',
        "Hi {name}! \xf0\x9f\x91\x8b\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!"
    );
    $waPhone = preg_replace('/\D/', '', $contact->phone ?? '');
    if ($waPhone && str_starts_with($waPhone, '0')) {
        $waPhone = '27' . substr($waPhone, 1);
    }
    $renderedWaMsg = str_replace(['{name}', '{link}'], [$contact->first_name, $match->sharedUrl()], $defaultWaMsg);
    $totalViews = array_sum($match->property_view_counts ?? []);
    $hiddenCount = count($match->hidden_property_ids ?? []);
@endphp
<div class="w-full space-y-6"
     x-data="{
         showWaModal: false,
         noteOpen: null,
         waMessage: {{ Js::from($renderedWaMsg) }},
         waPhone: '{{ $waPhone }}',
         outreachAllowed: {{ ($outreachWindow['allowed'] ?? true) ? 'true' : 'false' }},
         outreachWindowMessage: {{ Js::from($outreachWindow['message'] ?? '') }},
         sendWhatsApp() {
             if (!this.waPhone) return;
             // AT-117 §4a — send-window lock.
             if (!this.outreachAllowed) {
                 alert(this.outreachWindowMessage || 'Outreach sending is closed right now.');
                 return;
             }
             window.open('https://wa.me/' + this.waPhone + '?text=' + encodeURIComponent(this.waMessage), '_blank');
             this.showWaModal = false;
         },
         // AT-117 — add this composed message to the outreach queue (ready now).
         queueUrl: @js(route('corex.outreach-queue.enqueue')),
         queueContactId: {{ (int) $contact->id }},
         queueCsrf: @js(csrf_token()),
         queuing: false,
         async addToQueue() {
             if (this.queuing) return;
             this.queuing = true;
             try {
                 const res = await fetch(this.queueUrl, {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': this.queueCsrf, 'Accept': 'application/json' },
                     body: new URLSearchParams({ contact_id: this.queueContactId, channel: 'whatsapp', source: 'mic', body: this.waMessage }),
                 });
                 const data = await res.json();
                 if (!res.ok || !data.ok) { alert(data.message || 'Could not queue.'); return; }
                 alert(data.message || 'Added to your outreach queue.');
                 this.showWaModal = false;
             } catch (e) { alert('Network error — try again.'); } finally { this.queuing = false; }
         },
         hideModal: { open: false, reason: '', title: '', form: null },
         openHideModal(form, title) {
             this.hideModal.form = form;
             this.hideModal.title = title;
             this.hideModal.reason = '';
             this.hideModal.open = true;
             this.$nextTick(() => this.$refs.hideReasonInput && this.$refs.hideReasonInput.focus());
         },
         confirmHide() {
             const r = this.hideModal.reason.trim();
             if (r.length < 3) return;
             this.hideModal.form.querySelector('input[name=reason]').value = r;
             this.hideModal.open = false;
             this.hideModal.form.submit();
         }
     }">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">

        {{-- Top bar: back nav --}}
        <div class="flex items-center gap-2 mb-4">
            <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
               class="inline-flex items-center gap-1.5 text-xs font-semibold no-underline"
               style="color: rgba(255,255,255,0.6);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Back to {{ $contact->full_name }}
            </a>
            <span class="text-xs" style="color: rgba(255,255,255,0.35);">/</span>
            <span class="text-xs font-semibold" style="color: rgba(255,255,255,0.6);">Core Matches</span>
        </div>

        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">

            {{-- Left: contact + criteria --}}
            <div class="flex items-start gap-4 min-w-0">
                {{-- Avatar --}}
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                     style="background: {{ $contact->type?->color ?? 'var(--brand-icon)' }};">
                    {{ $contact->initials }}
                </div>

                <div class="min-w-0">
                    {{-- Title row --}}
                    <div class="flex items-center gap-2 flex-wrap mb-1">
                        <h1 class="text-xl font-bold leading-tight text-white">{{ $contact->full_name }}</h1>
                        @if($contact->type)
                        <span class="ds-badge ds-badge-default" style="background: {{ $contact->type->color }}33; color: #fff; border-color: {{ $contact->type->color }}55;">
                            {{ $contact->type->name }}
                        </span>
                        @endif
                        <span class="ds-badge {{ $match->listing_type === 'rental' ? 'ds-badge-info' : 'ds-badge-success' }}">
                            {{ $match->listingTypeLabel() }}
                        </span>
                        @if(auth()->user()->hasPermission('access_core_matches'))
                        {{-- AT-240 — edit this wishlist/criteria; opens the existing edit flow. --}}
                        <a href="{{ route('corex.contacts.matches.edit', [$contact, $match]) }}"
                           class="ds-badge no-underline inline-flex items-center gap-1"
                           style="background: rgba(255,255,255,0.12); color:#fff; border:1px solid rgba(255,255,255,0.28);"
                           title="Edit this wishlist / match criteria">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                            Edit criteria
                        </a>
                        @endif
                    </div>

                    {{-- Phone / email --}}
                    <div class="flex items-center gap-3 mb-3 flex-wrap text-sm" style="color: rgba(255,255,255,0.6);">
                        @if($contact->phone)<span>{{ $contact->phone }}</span>@endif
                        @if($contact->email)<span>{{ $contact->email }}</span>@endif
                    </div>

                    {{-- Criteria chips --}}
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @if($match->price_min || $match->price_max)
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-md"
                              style="background: color-mix(in srgb, var(--brand-icon) 18%, transparent); color: #fff; border: 1px solid color-mix(in srgb, var(--brand-icon) 35%, transparent);">
                            {{ $match->priceRangeLabel() }}
                        </span>
                        @endif
                        @foreach($match->suburbList() as $sub)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); border: 1px solid rgba(255,255,255,0.15);">
                            {{ $sub }}
                        </span>
                        @endforeach
                        @if($match->category)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); border: 1px solid rgba(255,255,255,0.15);">
                            {{ $match->category }}
                        </span>
                        @endif
                        @if($match->property_type)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); border: 1px solid rgba(255,255,255,0.15);">
                            {{ $match->property_type }}
                        </span>
                        @endif
                        @foreach([[$match->beds_min,'Beds'],[$match->baths_min,'Baths'],[$match->garages_min,'Gar']] as [$val,$lbl])
                        @if($val !== null)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); border: 1px solid rgba(255,255,255,0.15);">
                            {{ $val }}+ {{ $lbl }}
                        </span>
                        @endif
                        @endforeach
                        @if($match->floor_size_min || $match->floor_size_max)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); border: 1px solid rgba(255,255,255,0.15);">
                            {{ $match->floor_size_min ? number_format($match->floor_size_min) : '—' }}–{{ $match->floor_size_max ? number_format($match->floor_size_max) : '—' }} m²
                        </span>
                        @endif
                        @if(!$match->category && !$match->property_type && !$match->suburb && !$match->price_min && !$match->price_max && !$match->beds_min && !$match->baths_min)
                        <span class="text-xs italic" style="color: rgba(255,255,255,0.5);">Any property</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Right: stats + actions --}}
            <div class="flex flex-col md:items-end gap-3 flex-shrink-0">
                {{-- Stats row --}}
                <div class="flex items-center gap-4">
                    <div class="md:text-right">
                        <div class="text-[1.625rem] font-semibold leading-tight text-white">
                            {{ number_format($properties->count()) }}
                        </div>
                        <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: rgba(255,255,255,0.6);">
                            {{ Str::plural('match', $properties->count()) }}
                        </div>
                    </div>
                    @if($totalViews > 0)
                    <div style="width:1px; height:32px; background: rgba(255,255,255,0.15);"></div>
                    <div class="md:text-right">
                        <div class="text-[1.625rem] font-semibold leading-tight text-white">{{ number_format($totalViews) }}</div>
                        <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: rgba(255,255,255,0.6);">
                            client {{ Str::plural('view', $totalViews) }}
                        </div>
                    </div>
                    @endif
                    @if($hiddenCount > 0)
                    <div style="width:1px; height:32px; background: rgba(255,255,255,0.15);"></div>
                    <div class="md:text-right">
                        <div class="text-[1.625rem] font-semibold leading-tight" style="color: rgba(255,255,255,0.6);">{{ number_format($hiddenCount) }}</div>
                        <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: rgba(255,255,255,0.5);">hidden</div>
                    </div>
                    @endif
                </div>

                {{-- Action buttons --}}
                <div class="flex items-center gap-2">
                    @if($waPhone)
                    <button type="button" @click="showWaModal = true" class="corex-btn-primary" style="background: #25d366; box-shadow: none;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                            <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                        </svg>
                        WhatsApp
                    </button>
                    @endif
                    <a href="{{ $match->sharedUrl() }}" target="_blank" class="corex-btn-outline" style="background: rgba(255,255,255,0.08); color: #fff; border-color: rgba(255,255,255,0.2);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        Client Page
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Property list --}}
    @if($properties->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No active properties match these criteria</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">Try broadening the price range, suburb, or room requirements.</p>
        <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches" class="corex-btn-outline">
            ← Back to Core Matches
        </a>
    </div>
    @else
    <div class="space-y-3">
        @php
            // Belt-and-braces: hard-filter results to the match's listing_type.
            // The controller already uses ClientMatchResolver which filters strictly,
            // but if anything ever leaks through (legacy code path, cache, etc.)
            // a sale match must never display rentals, and vice versa.
            // Spec: .ai/specs/client-auth.md
            $matchListingType = $match->listing_type;
            $rentalStatuses   = ['to_rent','torent','for_rent','forrent','rented'];
            $saleStatuses     = ['for_sale','forsale','sold'];

            $filteredProperties = collect($properties)->filter(function ($p) use ($matchListingType, $rentalStatuses, $saleStatuses) {
                if (!$matchListingType) return true;
                $pLt = strtolower((string) ($p->listing_type ?? ''));
                $pSt = strtolower((string) ($p->status ?? ''));
                if ($matchListingType === 'sale') {
                    if ($pLt === 'rental') return false;
                    if (in_array($pSt, $rentalStatuses, true)) return false;
                }
                if ($matchListingType === 'rental') {
                    if ($pLt === 'sale') return false;
                    if (in_array($pSt, $saleStatuses, true)) return false;
                }
                return true;
            });

            // Visible properties first, hidden ones grouped at the bottom.
            $visibleProperties = $filteredProperties->reject(fn ($p) => $match->isPropertyHidden($p->id))->values();
            $hiddenProperties  = $filteredProperties->filter(fn ($p) => $match->isPropertyHidden($p->id))->values();
            $orderedProperties = $visibleProperties->concat($hiddenProperties);
            $firstHiddenId     = $hiddenProperties->first()?->id;
        @endphp
        @foreach($orderedProperties as $property)
        @php
            $isHidden = $match->isPropertyHidden($property->id);
        @endphp
        @if($isHidden && $property->id === $firstHiddenId)
        <div class="flex items-center gap-3 pt-6 pb-1">
            <div class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                Hidden from this match ({{ $hiddenProperties->count() }})
            </div>
            <div class="flex-1" style="height:1px; background: var(--border);"></div>
        </div>
        @endif
        @php
            $views = $match->propertyViewCount($property->id);
            $thumb = $property->thumbFor(
                $property->gallery_images_json[0]
                ?? $property->dawn_images_json[0]
                ?? $property->noon_images_json[0]
                ?? $property->dusk_images_json[0]
                ?? null
            );
            $statusVariant = match($property->status) {
                'active'    => 'ds-badge-success',
                'sold'      => 'ds-badge-info',
                'withdrawn' => 'ds-badge-warning',
                default     => 'ds-badge-default',
            };
            $score = (int) ($property->match_score ?? 0);
            $tier  = $property->match_tier ?? \App\Services\Matching\MatchingService::tierFor($score);
            $scoreVariant = match($tier) {
                'strong' => 'ds-badge-success',
                'good'   => 'ds-badge-info',
                default  => 'ds-badge-warning',
            };
            $scoreLabel = match($tier) {
                'strong' => 'Strong',
                'good'   => 'Good',
                default  => 'Fair',
            };

            $fb = $feedback[$property->id] ?? null;
            $reactionMeta = [
                'interested'     => ['label' => 'Interested', 'variant' => 'ds-badge-success'],
                'not_interested' => ['label' => 'Not for me', 'variant' => 'ds-badge-warning'],
            ];
            $fbMeta = $fb && isset($reactionMeta[$fb->reaction]) ? $reactionMeta[$fb->reaction] : null;
        @endphp

        <div class="rounded-md overflow-hidden flex items-stretch flex-wrap md:flex-nowrap transition-opacity"
             style="background: var(--surface); border: 1px solid var(--border); {{ $isHidden ? 'opacity:.45; filter:grayscale(.85);' : '' }}"
             @if($isHidden) title="Hidden from this match — click Unhide to restore" @endif>

            {{-- Thumbnail --}}
            <div class="relative flex-shrink-0 overflow-hidden" style="width: 140px; min-height: 100px; background: var(--surface-2);">
                @if($thumb)
                <img src="{{ $thumb }}" alt="{{ $property->title }}" class="absolute inset-0 w-full h-full object-cover">
                @else
                <div class="absolute inset-0 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-8 h-8 opacity-30" style="color: var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
                </div>
                @endif
                @if($isHidden)
                <div class="absolute inset-0 flex items-center justify-center" style="background: rgba(0,0,0,0.5);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                </div>
                @endif
            </div>

            {{-- Main content --}}
            <div class="flex-1 min-w-0 px-5 py-4 flex flex-col gap-2 justify-between">

                {{-- Top: status badges + title --}}
                <div>
                    <div class="flex items-center gap-2 flex-wrap mb-1.5">
                        @if($score > 0)
                        <span class="ds-badge {{ $scoreVariant }}" title="{{ $scoreLabel }} match">
                            {{ $score }}% · {{ $scoreLabel }}
                        </span>
                        @endif
                        <span class="ds-badge {{ $statusVariant }}">{{ ucfirst(str_replace('_', ' ', (string) $property->status)) }}</span>
                        @if($isHidden)
                        @php $hiddenReason = $match->hiddenReasonFor($property->id); @endphp
                        <span class="ds-badge ds-badge-warning" @if($hiddenReason) title="Reason: {{ $hiddenReason }}" @endif>Hidden</span>
                        @endif
                        @if($fbMeta)
                        <span class="ds-badge {{ $fbMeta['variant'] }}" title="Client reaction">
                            {{ $fbMeta['label'] }}
                        </span>
                        @if(!empty($fb->note))
                        <button type="button"
                                @click="noteOpen = (noteOpen === {{ $property->id }} ? null : {{ $property->id }})"
                                class="inline-flex items-center gap-1 text-xs font-semibold rounded-md px-2 py-0.5"
                                style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);"
                                title="Read client's note">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" /></svg>
                            Note
                        </button>
                        @endif
                        @endif
                    </div>
                    @if($isHidden && !empty($match->hiddenReasonFor($property->id)))
                    <div class="rounded-md p-2 mb-2 text-xs"
                         style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                        <span class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Hidden reason</span>
                        <div class="whitespace-pre-wrap leading-relaxed mt-0.5" style="color: var(--text-primary);">{{ $match->hiddenReasonFor($property->id) }}</div>
                    </div>
                    @endif
                    @if($fbMeta && !empty($fb->note))
                    <div x-show="noteOpen === {{ $property->id }}" x-cloak x-transition
                         class="rounded-md p-3 mb-2 text-xs"
                         style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <div class="flex items-start justify-between gap-2 mb-1">
                            <span class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                                Client note · {{ $fb->updated_at?->diffForHumans() }}
                            </span>
                            <button type="button" @click="noteOpen = null" class="text-xs font-bold" style="color: var(--text-muted);">✕</button>
                        </div>
                        <div class="whitespace-pre-wrap leading-relaxed">{{ $fb->note }}</div>
                    </div>
                    @endif
                    <div class="text-sm font-semibold leading-snug mb-1" style="color: var(--text-primary);">
                        {{ $property->title ?: 'Untitled Property' }}
                    </div>
                    <div class="flex items-center gap-3 text-xs flex-wrap" style="color: var(--text-muted);">
                        <span class="font-semibold text-sm" style="color: var(--brand-icon);">{{ $property->formattedPrice() }}</span>
                        @if($property->suburb)<span>{{ $property->suburb }}</span>@endif
                        @foreach([[$property->beds,'Beds'],[$property->baths,'Baths'],[$property->garages,'Gar']] as [$v,$l])
                        @if($v)<span>{{ $v }} {{ $l }}</span>@endif
                        @endforeach
                        @if($property->size_m2)
                        <span>{{ number_format($property->size_m2) }} m²</span>
                        @endif
                    </div>
                    @if($property->agent)
                    <div class="text-xs mt-1" style="color: var(--text-muted);">Agent: {{ $property->agent->name }}</div>
                    @endif
                </div>

                {{-- Bottom: client view counter --}}
                <div class="flex items-center gap-2 pt-2" style="border-top: 1px solid var(--border);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"
                         style="color: {{ $views > 0 ? 'var(--brand-icon)' : 'var(--text-muted)' }};"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    <span class="text-xs" style="color: var(--text-muted);">
                        @if($views > 0)
                            Viewed by client
                            <strong style="color: var(--brand-icon);">{{ number_format($views) }} {{ $views === 1 ? 'time' : 'times' }}</strong>
                        @else
                            Not yet viewed by client
                        @endif
                    </span>
                </div>
            </div>

            {{-- Action buttons --}}
            <div class="flex flex-col gap-2 justify-center px-4 py-4 flex-shrink-0 w-full md:w-auto" style="border-left: 1px solid var(--border);">
                <a href="{{ route('corex.properties.show', $property) }}" class="corex-btn-outline">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                    View Property
                </a>

                @permission('core_matches.convert_to_deal')
                <form method="POST" action="{{ route('corex.contacts.matches.convertToDeal', [$contact, $match, $property]) }}"
                      onsubmit="return confirm('Create a draft Deal from this match?');">
                    @csrf
                    <input type="hidden" name="mark_fulfilled" value="0">
                    <button type="submit" class="corex-btn-primary w-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Convert to Deal
                    </button>
                </form>
                @endpermission

                <form method="POST" action="{{ route('corex.contacts.matches.toggleHide', [$contact, $match, $property]) }}">
                    @csrf
                    @unless($isHidden)
                    <input type="hidden" name="reason" value="">
                    @endunless
                    <button type="{{ $isHidden ? 'submit' : 'button' }}" class="corex-btn-outline w-full"
                            @unless($isHidden) @click="openHideModal($el.closest('form'), {{ Js::from($property->title ?: 'this property') }})" @endunless>
                        @if($isHidden)
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.58-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        Unhide
                        @else
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                        Hide
                        @endif
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>

    @endif

    {{-- WhatsApp Modal --}}
    <div x-show="showWaModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);"
         @keydown.escape.window="showWaModal = false">
        <div class="w-full max-w-lg rounded-md overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             @click.stop>

            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0"
                         style="background: color-mix(in srgb, #25d366 12%, transparent); border: 1px solid color-mix(in srgb, #25d366 30%, transparent);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#25d366" style="width:18px;height:18px;">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                            <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="text-lg font-semibold" style="color: var(--text-primary);">Send via WhatsApp</div>
                        <div class="text-xs" style="color: var(--text-muted);">{{ $contact->full_name }}@if($contact->phone) · {{ $contact->phone }}@endif</div>
                    </div>
                </div>
                <button type="button" @click="showWaModal = false"
                        class="w-8 h-8 flex items-center justify-center rounded-md text-sm font-bold"
                        style="color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border);">✕</button>
            </div>

            {{-- Message editor --}}
            <div class="px-6 py-5 space-y-3">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Edit message before sending</label>
                <textarea x-model="waMessage" rows="10"
                          class="w-full rounded-md px-3 py-2 text-sm"
                          style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); resize: vertical; line-height: 1.6;"></textarea>
                <p class="text-xs" style="color: var(--text-muted);">The client's personalised link is already included in the message.</p>
            </div>

            {{-- AT-117 — add to the outreach queue (ready now). Available any time;
                 sending from the queue is gated by the send-window. --}}
            <div class="px-6 pb-4 mt-2 pt-3" style="border-top: 1px solid var(--border);">
                <button type="button" @click="addToQueue()" :disabled="queuing"
                        class="corex-btn-outline disabled:opacity-40 disabled:cursor-not-allowed">
                    <span x-show="!queuing">Add to queue</span>
                    <span x-show="queuing" x-cloak>Adding…</span>
                </button>
            </div>

            {{-- Footer --}}
            <div class="px-6 pb-5 flex items-center justify-end gap-3">
                <button type="button" @click="showWaModal = false" class="corex-btn-outline">Cancel</button>
                <template x-if="!outreachAllowed">
                    <span class="text-xs" style="color:var(--ds-crimson,#c41e3a);" x-text="outreachWindowMessage"></span>
                </template>
                <button type="button" @click="sendWhatsApp()" class="corex-btn-primary" :disabled="!outreachAllowed" :class="{ 'opacity-60 cursor-not-allowed': !outreachAllowed }" style="background: #25d366; box-shadow: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                    </svg>
                    Open in WhatsApp
                </button>
            </div>
        </div>
    </div>

    {{-- Hide property — reason modal (custom CoreX) --}}
    <div x-show="hideModal.open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);"
         @keydown.escape.window="hideModal.open = false">
        <div class="w-full max-w-md rounded-md overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             @click.stop>

            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0"
                         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="var(--ds-amber, #f59e0b)" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                    </div>
                    <div>
                        <div class="text-lg font-semibold" style="color: var(--text-primary);">Hide property</div>
                        <div class="text-xs" style="color: var(--text-muted);" x-text="hideModal.title"></div>
                    </div>
                </div>
                <button type="button" @click="hideModal.open = false"
                        class="w-8 h-8 flex items-center justify-center rounded-md text-sm font-bold"
                        style="color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border);">✕</button>
            </div>

            {{-- Reason input --}}
            <div class="px-6 py-5 space-y-3">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Why are you hiding this property from this match?</label>
                <textarea x-ref="hideReasonInput" x-model="hideModal.reason" rows="4"
                          @keydown.enter.meta="confirmHide()" @keydown.enter.ctrl="confirmHide()"
                          placeholder="e.g. Already sold, client not interested in this area, out of budget…"
                          class="w-full rounded-md px-3 py-2 text-sm"
                          style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); resize: vertical; line-height: 1.6;"></textarea>
                <p class="text-xs" style="color: var(--text-muted);">This reason is saved against the match and visible to your team.</p>
            </div>

            {{-- Footer --}}
            <div class="px-6 pb-5 flex items-center justify-end gap-3">
                <button type="button" @click="hideModal.open = false" class="corex-btn-outline">Cancel</button>
                <button type="button" @click="confirmHide()" class="corex-btn-primary"
                        :disabled="hideModal.reason.trim().length < 3"
                        :style="hideModal.reason.trim().length < 3 ? 'opacity:.5; cursor:not-allowed;' : ''">
                    Hide property
                </button>
            </div>
        </div>
    </div>

</div>
@endsection
