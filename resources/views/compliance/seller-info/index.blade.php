@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-3xl space-y-4"
     x-data="{
        tier: 'tier_1', previewing: false, previewHtml: '', sending: false, linkCopied: false,
        async preview() {
            this.previewing = true;
            const fd = new FormData(document.getElementById('seller-info-form'));
            const resp = await fetch('{{ route("compliance.seller-info.preview") }}', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} });
            const data = await resp.json();
            this.previewHtml = data.html;
        },
        async copyWhatsappLink() {
            const fd = new FormData(document.getElementById('seller-info-form'));
            const resp = await fetch('{{ route("compliance.seller-info.whatsapp-link") }}', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} });
            const data = await resp.json();
            if (data.url) { await navigator.clipboard.writeText(data.url); this.linkCopied = true; setTimeout(() => this.linkCopied = false, 3000); }
        }
     }">

    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h1 class="text-lg font-bold" style="color:var(--text-primary);">Send Seller Information Pack</h1>
        <p class="text-sm mt-1" style="color:var(--text-secondary);">Send a legally-researched information pack to a seller about why proper compliance paperwork matters.</p>
    </div>

    @if(session('success'))
    <div class="rounded-md p-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-green) 10%, transparent); color:var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="rounded-md p-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-red) 10%, transparent); color:var(--ds-red);">{{ session('error') }}</div>
    @endif

    <form id="seller-info-form" method="POST" action="{{ route('compliance.seller-info.send') }}" @submit="sending = true" class="space-y-4">
        @csrf

        {{-- Tier --}}
        <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Which Issue?</h3>
            <div class="space-y-2">
                <label class="flex items-start gap-3 cursor-pointer rounded-md p-3" :style="tier === 'tier_1' ? 'background:color-mix(in srgb, var(--brand-default) 6%, transparent); border:1px solid var(--brand-default)' : 'border:1px solid var(--border)'">
                    <input type="radio" name="tier" value="tier_1" x-model="tier" class="mt-0.5">
                    <span><span class="text-sm font-semibold" style="color:var(--text-primary);">No mandate / FICA / MDF signed</span><br><span class="text-xs" style="color:var(--text-muted);">Covers mandate, FICA verification, MDF, court cases, risks</span></span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-md p-3" :style="tier === 'tier_2' ? 'background:color-mix(in srgb, var(--brand-default) 6%, transparent); border:1px solid var(--brand-default)' : 'border:1px solid var(--border)'">
                    <input type="radio" name="tier" value="tier_2" x-model="tier" class="mt-0.5">
                    <span><span class="text-sm font-semibold" style="color:var(--text-primary);">Agent has no FFC displayed</span><br><span class="text-xs" style="color:var(--text-muted);">Focuses on how to verify an agent's credentials</span></span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-md p-3" :style="tier === 'tier_3' ? 'background:color-mix(in srgb, var(--brand-default) 6%, transparent); border:1px solid var(--brand-default)' : 'border:1px solid var(--border)'">
                    <input type="radio" name="tier" value="tier_3" x-model="tier" class="mt-0.5">
                    <span><span class="text-sm font-semibold" style="color:var(--text-primary);">Agent appears unregistered</span><br><span class="text-xs" style="color:var(--text-muted);">Serious advisory tone — may be operating illegally</span></span>
                </label>
            </div>
        </div>

        {{-- Recipient --}}
        <div class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Recipient</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium" style="color:var(--text-primary);">Seller name</label>
                    <input type="text" name="seller_name" class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="For personalisation">
                </div>
                <div>
                    <label class="text-sm font-medium" style="color:var(--text-primary);">Seller email *</label>
                    <input type="email" name="seller_email" required class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="seller@example.com">
                </div>
            </div>
            <div>
                <label class="text-sm font-medium" style="color:var(--text-primary);">Link to property</label>
                <select name="property_id" class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">-- Optional --</option>
                    @foreach($properties as $p)
                    <option value="{{ $p->id }}">{{ $p->address ?? $p->title }} — {{ $p->suburb }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Agent message --}}
        <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Personal Message (appears at top of email)</h3>
            <textarea name="agent_message" rows="3" maxlength="500" class="w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);" placeholder="Dear [name], following our conversation about the marketing of your property, I wanted to share some information that may be useful..."></textarea>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Max 500 characters. Optional.</p>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3 flex-wrap">
            <button type="button" @click="preview()" class="px-4 py-2 rounded-md text-sm font-semibold" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                Preview Email
            </button>
            <button type="submit" :disabled="sending" class="px-5 py-2.5 rounded-md text-sm font-semibold text-white" style="background:var(--brand-default);">
                <span x-text="sending ? 'Sending...' : 'Send via Email'"></span>
            </button>
            <button type="button" @click="copyWhatsappLink()" class="px-4 py-2 rounded-md text-sm font-semibold" style="background:#25D366; color:white;">
                <span x-text="linkCopied ? 'Link Copied!' : 'Copy WhatsApp Link'"></span>
            </button>
        </div>
    </form>

    {{-- Preview modal --}}
    <template x-teleport="body">
    <div x-show="previewing" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" x-transition.opacity>
        <div class="absolute inset-0" style="background:rgba(0,0,0,0.55); backdrop-filter:blur(2px);" @click="previewing = false"></div>
        <div class="relative rounded-md shadow-2xl" style="width:700px; max-width:95vw; max-height:90vh; overflow-y:auto; background:var(--surface); border:1px solid var(--border);">
            <div class="p-4 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Email Preview</h3>
                <button type="button" @click="previewing = false" class="text-sm font-medium px-3 py-1 rounded" style="color:var(--text-secondary);">Close</button>
            </div>
            <iframe :srcdoc="previewHtml" sandbox class="w-full" style="height:70vh; border:none; background:#fff;"></iframe>
        </div>
    </div>
    </template>

</div>
@endsection
