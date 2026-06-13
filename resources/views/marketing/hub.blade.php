@extends('layouts.corex')

@section('corex-content')
@php
    $fbAccount  = $socialAccounts->get('facebook');
    $igAccount  = $socialAccounts->get('instagram');
    $allImages  = $property->allImages();
@endphp

<div class="w-full space-y-5"
     x-data="{
         activeTab: 'facebook',
         fbCopy: '',
         fbHeadline: '',
         igCopy: '',
         igHashtags: '',
         igHeadline: '',
         selectedImages: {{ request('marketing_img') ? '[\'' . request('marketing_img') . '\']' : '[]' }},
         generating: false,
         publishing: false,
         publishResults: {},
         fbMode: null,
         igMode: null,
         toggleImage(url) {
             if (this.selectedImages.includes(url)) {
                 this.selectedImages = this.selectedImages.filter(u => u !== url);
             } else if (this.selectedImages.length < 10) {
                 this.selectedImages.push(url);
             }
         },
         async regenerate(platform) {
             this.generating = true;
             try {
                 const resp = await fetch('{{ route('corex.properties.marketing.generateCopy', $property) }}', {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                     body: JSON.stringify({ platform }),
                 });
                 const data = await resp.json();
                 if (data.ok && data.copy) {
                     if (platform === 'facebook') {
                         this.fbCopy = data.copy.primary;
                         this.fbHeadline = data.copy.headline;
                     } else {
                         this.igCopy = data.copy.primary;
                         this.igHeadline = data.copy.headline;
                         this.igHashtags = (data.copy.hashtags || []).join(' ');
                     }
                 } else {
                     // Show the server message directly (config/budget notices come through here).
                     alert(data.error || 'Could not generate copy. Please try again.');
                 }
             } catch (e) {
                 alert('Request failed: ' + e.message);
             } finally {
                 this.generating = false;
             }
         },
         async publishNow(platforms) {
             if (!platforms.length) { alert('Select at least one platform to publish to.'); return; }
             if (!this.selectedImages.length) { alert('Select at least one photo to include.'); return; }
             const copy = this.activeTab === 'facebook' ? this.fbCopy : (this.igCopy + '\n\n' + this.igHashtags);
             if (!copy.trim()) { alert('Ad copy cannot be empty.'); return; }
             this.publishing = true;
             this.publishResults = {};
             try {
                 const resp = await fetch('{{ route('corex.properties.marketing.publish', $property) }}', {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                     body: JSON.stringify({ platforms, copy, image_urls: this.selectedImages }),
                 });
                 const data = await resp.json();
                 if (data.results) {
                     this.publishResults = data.results;
                     // Reload after 2s to show new post in history
                     setTimeout(() => window.location.reload(), 2000);
                 } else {
                     alert('Publish failed.');
                 }
             } catch (e) {
                 alert('Request failed: ' + e.message);
             } finally {
                 this.publishing = false;
             }
         },
         async syncPost(postId, btn) {
             btn.disabled = true;
             btn.textContent = 'Syncing...';
             try {
                 const resp = await fetch('/corex/marketing/posts/' + postId + '/sync-insights', {
                     method: 'POST',
                     headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                 });
                 const data = await resp.json();
                 if (data.ok) { window.location.reload(); }
                 else { alert('Sync failed: ' + (data.error || 'Unknown')); btn.disabled = false; btn.textContent = 'Sync'; }
             } catch(e) {
                 alert('Sync failed: ' + e.message);
                 btn.disabled = false; btn.textContent = 'Sync';
             }
         }
     }">

    {{-- Back + flash --}}
    <div class="flex items-center gap-4 flex-wrap">
        <a href="{{ route('corex.properties.show', $property) }}"
           class="inline-flex items-center gap-1.5 text-sm no-underline flex-shrink-0"
           style="color:var(--text-secondary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            Back to Property
        </a>
        @if(session('success'))
        <div class="flex-1 rounded-xl border px-4 py-2 text-sm font-medium" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;">{{ session('success') }}</div>
        @endif
        @if(session('error'))
        <div class="flex-1 rounded-xl border px-4 py-2 text-sm font-medium" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">{{ session('error') }}</div>
        @endif
    </div>

    {{-- Page header --}}
    <div style="background:linear-gradient(135deg,#0b2a4a,#0d3b6e); border-radius:16px; padding:20px 24px;" class="flex items-center gap-4">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background:rgba(0,180,216,0.2);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535m0 0A23.74 23.74 0 0 1 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m-1.394 0A21.967 21.967 0 0 1 18 11.25c0-2.61-.353-5.135-1.014-7.52M20.489 18.824A21.961 21.961 0 0 1 22.5 12c0-2.61-.353-5.135-1.014-7.52m0 0a24.5 24.5 0 0 1 1.014-1.48"/></svg>
        </div>
        <div>
            <h1 class="text-base font-extrabold text-white">Market This Property</h1>
            <div class="text-xs mt-0.5" style="color:rgba(255,255,255,0.55);">{{ $property->title }} &mdash; {{ $property->suburb }}{{ $property->city ? ', '.$property->city : '' }}</div>
        </div>
    </div>

    {{-- Section 1: Connected Accounts --}}
    <div class="rounded-2xl p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
        <h2 class="text-sm font-bold uppercase tracking-widest" style="color:var(--text-muted);">Connected Accounts</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            {{-- Facebook --}}
            <div class="rounded-xl p-4 flex items-center gap-3" style="background:var(--surface-2); border:1px solid var(--border);">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#1877f222;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1877f2" class="w-5 h-5"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold" style="color:var(--text-primary);">Facebook</div>
                    @if($fbAccount)
                    <div class="text-xs truncate" style="color:var(--text-muted);">{{ $fbAccount->platform_page_name }}</div>
                    <span class="inline-block text-[10px] font-bold px-2 py-0.5 rounded-full mt-0.5" style="background:rgba(34,197,94,0.12); color:#22c55e;">Connected</span>
                    @else
                    <span class="inline-block text-[10px] font-bold px-2 py-0.5 rounded-full mt-0.5" style="background:rgba(148,163,184,0.12); color:var(--text-muted);">Not Connected</span>
                    <div class="text-[10px] mt-1 leading-tight" style="color:var(--text-muted);">Requires a Facebook Page, not a personal profile.</div>
                    @endif
                </div>
                @if($fbAccount)
                <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">
                    @csrf
                    <input type="hidden" name="platform" value="facebook">
                    <button type="submit" class="text-xs px-3 py-1.5 rounded-lg font-medium" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid rgba(239,68,68,0.2);">Disconnect</button>
                </form>
                @else
                <a href="{{ route('corex.social.oauth.redirect', ['platform'=>'facebook']) }}"
                   class="text-xs px-3 py-1.5 rounded-lg font-medium no-underline" style="background:rgba(24,119,242,0.12); color:#1877f2; border:1px solid rgba(24,119,242,0.25);">Connect</a>
                @endif
            </div>

            {{-- Instagram --}}
            <div class="rounded-xl p-4 flex items-center gap-3" style="background:var(--surface-2); border:1px solid var(--border);">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#e1306c22;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#e1306c" class="w-5 h-5"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold" style="color:var(--text-primary);">Instagram</div>
                    @if($igAccount)
                    <div class="text-xs truncate" style="color:var(--text-muted);">{{ $igAccount->platform_page_name }}</div>
                    <span class="inline-block text-[10px] font-bold px-2 py-0.5 rounded-full mt-0.5" style="background:rgba(34,197,94,0.12); color:#22c55e;">Connected</span>
                    @else
                    <span class="inline-block text-[10px] font-bold px-2 py-0.5 rounded-full mt-0.5" style="background:rgba(148,163,184,0.12); color:var(--text-muted);">Not Connected</span>
                    @endif
                </div>
                @if($igAccount)
                <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">
                    @csrf
                    <input type="hidden" name="platform" value="instagram">
                    <button type="submit" class="text-xs px-3 py-1.5 rounded-lg font-medium" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid rgba(239,68,68,0.2);">Disconnect</button>
                </form>
                @else
                <a href="{{ route('corex.social.oauth.redirect', ['platform'=>'instagram']) }}"
                   class="text-xs px-3 py-1.5 rounded-lg font-medium no-underline" style="background:rgba(225,48,108,0.12); color:#e1306c; border:1px solid rgba(225,48,108,0.25);">Connect</a>
                @endif
            </div>
        </div>
    </div>

    {{-- Section 2: Ad Builder --}}
    <div class="rounded-2xl overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="p-5 border-b" style="border-color:var(--border);">
            <h2 class="text-sm font-bold uppercase tracking-widest" style="color:var(--text-muted);">Ad Builder</h2>
        </div>

        {{-- Platform tabs --}}
        <div class="flex" style="border-bottom:1px solid var(--border);">
            <button type="button" @click="activeTab = 'facebook'"
                    :class="activeTab === 'facebook' ? 'text-[#1877f2] border-b-2 border-[#1877f2] bg-[#1877f2]/5' : 'border-b-2 border-transparent'"
                    class="px-6 py-3 text-sm font-semibold flex items-center gap-2 transition-colors"
                    :style="activeTab !== 'facebook' ? 'color:var(--text-secondary);' : ''">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                Facebook
            </button>
            <button type="button" @click="activeTab = 'instagram'"
                    :class="activeTab === 'instagram' ? 'text-[#e1306c] border-b-2 border-[#e1306c] bg-[#e1306c]/5' : 'border-b-2 border-transparent'"
                    class="px-6 py-3 text-sm font-semibold flex items-center gap-2 transition-colors"
                    :style="activeTab !== 'instagram' ? 'color:var(--text-secondary);' : ''">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>
                Instagram
            </button>
        </div>

        <div class="p-5 grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Left: copy editor + photo selector --}}
            <div class="space-y-4">
                {{-- Facebook tab --}}
                <div x-show="activeTab === 'facebook'">
                    {{-- Mode picker --}}
                    <div x-show="fbMode === null" class="space-y-3">
                        <p class="text-sm font-medium mb-4" style="color:var(--text-primary);">How would you like to create your Facebook ad?</p>
                        <button type="button" @click="fbMode = 'manual'"
                                class="w-full flex items-start gap-4 rounded-xl p-4 text-left transition-colors hover:border-[#00b4d8]"
                                style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(0,180,216,0.12);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                            </div>
                            <div>
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Write your own description</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);">Type your own headline and ad copy from scratch.</div>
                            </div>
                        </button>
                        <button type="button" @click="fbMode = 'ai'; regenerate('facebook')"
                                class="w-full flex items-start gap-4 rounded-xl p-4 text-left transition-colors hover:border-[#1877f2]"
                                style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(24,119,242,0.12);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#1877f2" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/></svg>
                            </div>
                            <div>
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Generate with Ellie AI</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);">Let Ellie write an ad based on this property's details.</div>
                            </div>
                        </button>
                    </div>
                    {{-- Editor --}}
                    <div x-show="fbMode !== null" x-cloak>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs" style="color:var(--text-muted);" x-text="fbMode === 'ai' ? 'AI-generated copy — edit as needed' : 'Write your own copy'"></span>
                            <button type="button" @click="fbMode = null; fbCopy = ''; fbHeadline = ''" class="text-xs" style="color:var(--text-muted);">← Change</button>
                        </div>
                        <label class="block text-xs font-semibold mb-1.5" style="color:var(--text-muted);">Headline</label>
                        <input type="text" x-model="fbHeadline" placeholder="Short headline..."
                               class="w-full rounded-lg px-3 py-2 text-sm mb-3"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        <label class="block text-xs font-semibold mb-1.5" style="color:var(--text-muted);">Ad Copy</label>
                        <div x-show="generating && fbMode === 'ai'" class="w-full rounded-lg px-3 py-6 text-sm text-center mb-2" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                            <svg class="animate-spin w-5 h-5 mx-auto mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Ellie is writing your ad...
                        </div>
                        <textarea x-show="!generating || fbMode !== 'ai'" x-model="fbCopy" rows="8" placeholder="Type your Facebook ad copy here..."
                                  class="w-full rounded-lg px-3 py-2 text-sm resize-y"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        <template x-if="fbMode === 'ai'">
                            <button type="button" @click="regenerate('facebook')"
                                    :disabled="generating"
                                    class="mt-2 flex items-center gap-2 text-xs font-semibold px-4 py-2 rounded-lg transition-colors"
                                    style="background:rgba(24,119,242,0.12); color:#1877f2; border:1px solid rgba(24,119,242,0.25);">
                                <span x-show="!generating">Regenerate with AI</span>
                                <span x-show="generating">Generating...</span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Instagram tab --}}
                <div x-show="activeTab === 'instagram'" x-cloak>
                    {{-- Mode picker --}}
                    <div x-show="igMode === null" class="space-y-3">
                        <p class="text-sm font-medium mb-4" style="color:var(--text-primary);">How would you like to create your Instagram post?</p>
                        <button type="button" @click="igMode = 'manual'"
                                class="w-full flex items-start gap-4 rounded-xl p-4 text-left transition-colors hover:border-[#00b4d8]"
                                style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(0,180,216,0.12);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                            </div>
                            <div>
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Write your own description</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);">Type your own caption and hashtags from scratch.</div>
                            </div>
                        </button>
                        <button type="button" @click="igMode = 'ai'; regenerate('instagram')"
                                class="w-full flex items-start gap-4 rounded-xl p-4 text-left transition-colors hover:border-[#e1306c]"
                                style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(225,48,108,0.12);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#e1306c" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/></svg>
                            </div>
                            <div>
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Generate with Ellie AI</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-muted);">Let Ellie write a caption and hashtags for this property.</div>
                            </div>
                        </button>
                    </div>
                    {{-- Editor --}}
                    <div x-show="igMode !== null" x-cloak>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs" style="color:var(--text-muted);" x-text="igMode === 'ai' ? 'AI-generated caption — edit as needed' : 'Write your own caption'"></span>
                            <button type="button" @click="igMode = null; igCopy = ''; igHeadline = ''; igHashtags = ''" class="text-xs" style="color:var(--text-muted);">← Change</button>
                        </div>
                        <label class="block text-xs font-semibold mb-1.5" style="color:var(--text-muted);">Headline</label>
                        <input type="text" x-model="igHeadline" placeholder="Short headline..."
                               class="w-full rounded-lg px-3 py-2 text-sm mb-3"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        <label class="block text-xs font-semibold mb-1.5" style="color:var(--text-muted);">Caption</label>
                        <div x-show="generating && igMode === 'ai'" class="w-full rounded-lg px-3 py-6 text-sm text-center mb-2" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                            <svg class="animate-spin w-5 h-5 mx-auto mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Ellie is writing your caption...
                        </div>
                        <textarea x-show="!generating || igMode !== 'ai'" x-model="igCopy" rows="6" placeholder="Type your Instagram caption here..."
                                  class="w-full rounded-lg px-3 py-2 text-sm resize-y"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        <label class="block text-xs font-semibold mb-1.5 mt-3" style="color:var(--text-muted);">Hashtags</label>
                        <textarea x-model="igHashtags" rows="3" placeholder="#realestate #southafrica ..."
                                  class="w-full rounded-lg px-3 py-2 text-sm resize-y"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        <template x-if="igMode === 'ai'">
                            <button type="button" @click="regenerate('instagram')"
                                    :disabled="generating"
                                    class="mt-2 flex items-center gap-2 text-xs font-semibold px-4 py-2 rounded-lg transition-colors"
                                    style="background:rgba(225,48,108,0.12); color:#e1306c; border:1px solid rgba(225,48,108,0.25);">
                                <span x-show="!generating">Regenerate with AI</span>
                                <span x-show="generating">Generating...</span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Photo / Template selector --}}
                <div x-data="{ mediaTab: '{{ request('media_tab', 'photos') }}' }">
                    {{-- Tab toggle --}}
                    <div class="flex gap-1 mb-3 p-1 rounded-lg" style="background:var(--surface-2); border:1px solid var(--border);">
                        <button type="button" @click="mediaTab = 'photos'; selectedImages = []"
                                :class="mediaTab === 'photos' ? 'text-white shadow-sm' : ''"
                                :style="mediaTab === 'photos' ? 'background:#00b4d8;' : 'color:var(--text-secondary);'"
                                class="flex-1 text-xs font-semibold py-1.5 rounded-md transition-colors">
                            Property Photos
                        </button>
                        <button type="button" @click="mediaTab = 'templates'; selectedImages = []"
                                :class="mediaTab === 'templates' ? 'text-white shadow-sm' : ''"
                                :style="mediaTab === 'templates' ? 'background:#00b4d8;' : 'color:var(--text-secondary);'"
                                class="flex-1 text-xs font-semibold py-1.5 rounded-md transition-colors">
                            Templates
                        </button>
                    </div>

                    {{-- Property Photos --}}
                    <div x-show="mediaTab === 'photos'">
                        @if(count($allImages))
                        <div class="grid grid-cols-4 gap-2">
                            @foreach($allImages as $img)
                            <div @click="toggleImage('{{ $img }}')"
                                 :class="selectedImages.includes('{{ $img }}') ? 'ring-2 ring-[#00b4d8]' : 'ring-1 ring-transparent'"
                                 class="relative rounded-lg overflow-hidden cursor-pointer aspect-square"
                                 style="background:var(--surface-2);">
                                <img src="{{ $img }}" alt="" class="w-full h-full object-cover">
                                <div x-show="selectedImages.includes('{{ $img }}')"
                                     class="absolute inset-0 flex items-center justify-center"
                                     style="background:rgba(0,180,216,0.25);">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="white" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <div class="text-xs mt-1" style="color:var(--text-muted);"><span x-text="selectedImages.length"></span> selected</div>
                        @else
                        <div class="text-xs rounded-lg px-3 py-2" style="background:var(--surface-2); color:var(--text-muted);">No photos uploaded for this property. Add photos on the property page first.</div>
                        @endif
                    </div>

                    {{-- Templates --}}
                    <div x-show="mediaTab === 'templates'" x-cloak>
                        {{-- Go to property ad template selector --}}
                        <a href="{{ route('corex.properties.ad', $property) }}?return_marketing={{ $property->id }}"
                           class="flex items-center justify-center gap-2 w-full text-xs font-semibold px-3 py-2 rounded-lg mb-3 no-underline transition-colors"
                           style="background:rgba(0,180,216,0.1); color:#00b4d8; border:1px solid rgba(0,180,216,0.25);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 3l1.664 1.664M21 21l-1.5-1.5m-5.485-1.242L12 17.25 4.5 21V8.742m.164-4.078a2.15 2.15 0 011.743-1.342 48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185V19.5"/></svg>
                            Select a Template
                        </a>
                        <p class="text-xs" style="color:var(--text-muted);">Choose from Power, Luxe, Split or your saved custom templates. Click <strong>Use for Marketing</strong> on the ad page to send it here.</p>
                    </div>
                </div>
            </div>

            {{-- Right: live preview --}}
            <div>
                {{-- Facebook preview --}}
                <div x-show="activeTab === 'facebook'" class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">
                    <div class="px-4 pt-4 pb-2 flex items-center gap-3" style="background:#fff;">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0" style="background:#1877f2;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" class="w-5 h-5"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900">{{ auth()->user()->name }}</div>
                            <div class="text-xs text-gray-400">Just now · Public</div>
                        </div>
                    </div>
                    <div class="px-4 pb-3 text-sm text-gray-800 whitespace-pre-wrap" style="background:#fff;" x-text="fbCopy || 'Your ad copy will appear here...'"></div>
                    <template x-if="selectedImages.length">
                        <img :src="selectedImages[0]" alt="" class="w-full object-cover" style="max-height:300px;">
                    </template>
                    <div class="px-4 py-3 border-t flex gap-4 text-xs text-gray-400" style="background:#fff; border-color:#e5e7eb;">
                        <span>Like</span><span>Comment</span><span>Share</span>
                    </div>
                </div>

                {{-- Instagram preview --}}
                <div x-show="activeTab === 'instagram'" x-cloak class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">
                    <div class="px-4 pt-4 pb-2 flex items-center gap-3" style="background:#fff;">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0" style="background:linear-gradient(45deg,#f9ce34,#ee2a7b,#6228d7);">
                            <span class="text-white text-xs font-bold">{{ substr(auth()->user()->name, 0, 2) }}</span>
                        </div>
                        <div class="text-sm font-semibold text-gray-900">{{ strtolower(str_replace(' ', '_', auth()->user()->name)) }}</div>
                    </div>
                    <template x-if="selectedImages.length">
                        <img :src="selectedImages[0]" alt="" class="w-full object-cover" style="max-height:300px; background:#000;">
                    </template>
                    <div x-show="!selectedImages.length" class="w-full flex items-center justify-center" style="height:200px; background:#f3f4f6;">
                        <span class="text-xs text-gray-400">Select a photo above</span>
                    </div>
                    <div class="px-4 py-3 text-sm text-gray-800" style="background:#fff;">
                        <span class="font-semibold">{{ strtolower(str_replace(' ', '_', auth()->user()->name)) }}</span>
                        <span class="whitespace-pre-wrap" x-text="' ' + (igCopy || 'Caption will appear here...')"></span>
                        <div class="mt-1 text-[#00376b] text-xs" x-text="igHashtags"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Section 3: Publish bar --}}
    <div class="rounded-2xl p-5" style="background:var(--surface); border:1px solid var(--border);"
         x-data="{ selectedPlatforms: [] }">
        <h2 class="text-sm font-bold uppercase tracking-widest mb-4" style="color:var(--text-muted);">Publish</h2>
        <div class="flex items-center gap-6 flex-wrap">
            @if($fbAccount)
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" value="facebook" x-model="selectedPlatforms" class="rounded">
                <span class="text-sm font-medium" style="color:var(--text-primary);">Facebook</span>
            </label>
            @endif
            @if($igAccount)
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" value="instagram" x-model="selectedPlatforms" class="rounded">
                <span class="text-sm font-medium" style="color:var(--text-primary);">Instagram</span>
            </label>
            @endif
            @if(!$fbAccount && !$igAccount)
            <span class="text-sm" style="color:var(--text-muted);">Connect at least one account above to publish.</span>
            @endif

            @if($fbAccount || $igAccount)
            <button type="button"
                    @click="$dispatch('publish-now', { platforms: selectedPlatforms })"
                    :disabled="publishing || !selectedPlatforms.length"
                    class="corex-btn-primary px-6 py-2.5 text-sm font-semibold rounded-xl disabled:opacity-50 flex items-center gap-2">
                <svg x-show="publishing" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span x-show="!publishing">Publish Now</span>
                <span x-show="publishing">Publishing...</span>
            </button>
            @endif
        </div>

        {{-- Publish results --}}
        <template x-if="Object.keys(publishResults).length">
            <div class="mt-4 space-y-2">
                <template x-for="[platform, result] in Object.entries(publishResults)" :key="platform">
                    <div class="flex items-center gap-3 text-sm rounded-lg px-3 py-2"
                         :style="result.ok ? 'background:rgba(34,197,94,0.08); color:#166534;' : 'background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); color:#991b1b;'">
                        <span class="capitalize font-semibold" x-text="platform"></span>
                        <span x-text="result.ok ? 'Published successfully!' : ('Failed: ' + result.error)"></span>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- Wire publish-now event from the publish bar into the parent x-data --}}
    <div x-init="window.addEventListener('publish-now', e => publishNow(e.detail.platforms))"></div>

    {{-- Section 4: Marketing History --}}
    <div class="rounded-2xl overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-5 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <h2 class="text-sm font-bold uppercase tracking-widest" style="color:var(--text-muted);">Marketing History</h2>
            <span class="text-xs" style="color:var(--text-muted);">{{ $posts->count() }} post{{ $posts->count() !== 1 ? 's' : '' }}</span>
        </div>

        @if($posts->isEmpty())
        <div class="px-5 py-10 text-center" style="color:var(--text-muted);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-12 h-12 mx-auto mb-3 opacity-30"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535"/></svg>
            <p class="text-sm">No posts yet. Publish your first marketing post above.</p>
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                        <th class="px-4 py-3 text-left text-xs font-semibold" style="color:var(--text-muted);">Platform</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold" style="color:var(--text-muted);">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold" style="color:var(--text-muted);">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold" style="color:var(--text-muted);">Impressions</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold" style="color:var(--text-muted);">Reach</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold" style="color:var(--text-muted);">Likes</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold" style="color:var(--text-muted);">Comments</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold" style="color:var(--text-muted);">Shares</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold" style="color:var(--text-muted);">Clicks</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold" style="color:var(--text-muted);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($posts as $post)
                    @php
                        $statusColors = ['published'=>'#22c55e','draft'=>'#94a3b8','failed'=>'#ef4444'];
                        $sc = $statusColors[$post->status] ?? '#94a3b8';
                    @endphp
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-4 py-3">
                            @if($post->platform === 'facebook')
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium" style="color:#1877f2;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3.5 h-3.5"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                Facebook
                            </span>
                            @else
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium" style="color:#e1306c;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3.5 h-3.5"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>
                                Instagram
                            </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs" style="color:var(--text-muted);">{{ $post->published_at ? $post->published_at->format('d M Y') : $post->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3">
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold" style="background:{{ $sc }}22; color:{{ $sc }};">{{ ucfirst($post->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-xs" style="color:var(--text-primary);">{{ number_format($post->impressions) }}</td>
                        <td class="px-4 py-3 text-right text-xs" style="color:var(--text-primary);">{{ number_format($post->reach) }}</td>
                        <td class="px-4 py-3 text-right text-xs" style="color:var(--text-primary);">{{ number_format($post->likes) }}</td>
                        <td class="px-4 py-3 text-right text-xs" style="color:var(--text-primary);">{{ number_format($post->comments) }}</td>
                        <td class="px-4 py-3 text-right text-xs" style="color:var(--text-primary);">{{ number_format($post->shares) }}</td>
                        <td class="px-4 py-3 text-right text-xs" style="color:var(--text-primary);">{{ number_format($post->link_clicks) }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($post->status === 'published')
                            <button type="button"
                                    onclick="syncPost({{ $post->id }}, this)"
                                    class="text-xs px-3 py-1 rounded-lg font-medium"
                                    style="background:rgba(0,180,216,0.1); color:#00b4d8; border:1px solid rgba(0,180,216,0.2);">Sync</button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

<script>
function syncPost(postId, btn) {
    btn.disabled = true;
    btn.textContent = 'Syncing...';
    fetch('/corex/marketing/posts/' + postId + '/sync-insights', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    }).then(r => r.json()).then(data => {
        if (data.ok) { window.location.reload(); }
        else { alert('Sync failed: ' + (data.error || 'Unknown')); btn.disabled = false; btn.textContent = 'Sync'; }
    }).catch(e => {
        alert('Sync failed: ' + e.message);
        btn.disabled = false; btn.textContent = 'Sync';
    });
}
</script>
@endsection
