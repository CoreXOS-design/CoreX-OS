@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-3xl space-y-4"
     x-data="{
        tier: 'tier_1',
        previewing: false, previewHtml: '', sending: false, linkCopied: false,

        // Property search
        propertySearch: '', propertyResults: [], selectedProperty: null, searchDebounce: null,
        async searchProperties() {
            clearTimeout(this.searchDebounce);
            if (this.propertySearch.length < 2) { this.propertyResults = []; return; }
            this.searchDebounce = setTimeout(async () => {
                const resp = await fetch('{{ route('deals-v2.search.properties') }}?q=' + encodeURIComponent(this.propertySearch), { headers:{'Accept':'application/json'} });
                this.propertyResults = await resp.json();
            }, 300);
        },
        async selectProperty(p) {
            this.selectedProperty = p;
            this.propertySearch = p.address;
            this.propertyResults = [];
            // Auto-load seller contacts
            const resp = await fetch('{{ url("deals-v2/search/property-contacts") }}/' + p.id, { headers:{'Accept':'application/json'} });
            const contacts = await resp.json();
            const sellerRoles = ['owner', 'lessor', 'seller', 'co_seller', 'landlord'];
            const sellers = contacts.filter(c => sellerRoles.includes(c.role));
            sellers.forEach(s => {
                if (!this.recipients.find(r => r.contact_id === s.id)) {
                    this.recipients.push({ name: s.name, email: s.email || '', contact_id: s.id, enabled: true, fromProperty: true });
                }
            });
        },
        clearProperty() {
            this.selectedProperty = null;
            this.propertySearch = '';
            this.recipients = this.recipients.filter(r => !r.fromProperty);
        },

        // Recipients
        recipients: [],
        addRecipient() { if (this.recipients.length < 10) this.recipients.push({ name: '', email: '', contact_id: null, enabled: true, fromProperty: false }); },
        removeRecipient(i) { this.recipients.splice(i, 1); },
        get enabledRecipients() { return this.recipients.filter(r => r.enabled && r.email); },

        async preview() {
            if (this.enabledRecipients.length === 0) { alert('Add at least one recipient.'); return; }
            this.previewing = true;
            const fd = new FormData(document.getElementById('seller-info-form'));
            fd.set('seller_name', this.enabledRecipients[0].name || 'Seller');
            fd.set('seller_email', this.enabledRecipients[0].email);
            const resp = await fetch('{{ route('compliance.seller-info.preview') }}', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} });
            const data = await resp.json();
            this.previewHtml = data.html;
        },
        async copyWhatsappLink() {
            const fd = new FormData(document.getElementById('seller-info-form'));
            if (this.selectedProperty) fd.set('property_id', this.selectedProperty.id);
            const names = this.enabledRecipients.map(r => r.name).filter(Boolean).join(', ');
            fd.set('seller_name', names || 'Seller');
            fd.set('seller_email', this.enabledRecipients[0]?.email || '');
            const resp = await fetch('{{ route('compliance.seller-info.whatsapp-link') }}', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} });
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

        {{-- Property search --}}
        <div class="rounded-md p-5 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Property (optional)</h3>
            <p class="text-xs" style="color:var(--text-secondary);">Selecting a property auto-loads linked sellers as recipients.</p>

            <div class="relative" x-show="!selectedProperty">
                <input type="text" x-model="propertySearch" @input="searchProperties()" @click.outside="propertyResults = []"
                       class="w-full rounded-md text-sm px-3 py-2" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);"
                       placeholder="Search by address...">
                <div x-show="propertyResults.length > 0" class="absolute z-50 w-full mt-1 rounded-md shadow-lg max-h-48 overflow-y-auto" style="background:var(--surface); border:1px solid var(--border);">
                    <template x-for="p in propertyResults" :key="p.id">
                        <button type="button" @click="selectProperty(p)" class="w-full text-left px-3 py-2 text-sm hover:bg-white/5" style="color:var(--text-primary); border-bottom:1px solid var(--border);" x-text="p.address"></button>
                    </template>
                </div>
            </div>

            <div x-show="selectedProperty" x-cloak class="flex items-center gap-2 rounded-md p-3" style="background:var(--surface-2); border:1px solid var(--border);">
                <input type="hidden" name="property_id" :value="selectedProperty?.id || ''">
                <span class="text-sm font-medium flex-1" style="color:var(--text-primary);" x-text="selectedProperty?.address"></span>
                <button type="button" @click="clearProperty()" class="text-xs font-bold px-2 py-1 rounded" style="color:var(--ds-red); background:color-mix(in srgb, var(--ds-red) 8%, transparent);">Clear</button>
            </div>
        </div>

        {{-- Recipients --}}
        <div class="rounded-md p-5 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Recipients</h3>
            <p class="text-xs" style="color:var(--text-secondary);">This message uses our legally-researched seller information content. If you need to send a customised message, please use your own email client.</p>

            <template x-if="recipients.length === 0">
                <p class="text-xs py-2" style="color:var(--text-muted);">No recipients yet. Select a property to auto-load sellers, or add recipients manually.</p>
            </template>

            <template x-for="(r, idx) in recipients" :key="idx">
                <div class="flex items-center gap-3 py-2" :style="!r.enabled ? 'opacity:0.5' : ''" style="border-bottom:1px solid var(--border);">
                    <input type="checkbox" x-model="r.enabled" class="flex-shrink-0">
                    <input type="hidden" :name="'recipients[' + idx + '][contact_id]'" :value="r.contact_id || ''">
                    <template x-if="r.fromProperty">
                        <div class="flex-1 min-w-0">
                            <input type="hidden" :name="'recipients[' + idx + '][name]'" :value="r.name">
                            <input type="hidden" :name="'recipients[' + idx + '][email]'" :value="r.email">
                            <span class="text-sm font-medium" style="color:var(--text-primary);" x-text="r.name"></span>
                            <span class="text-xs ml-2" style="color:var(--text-muted);" x-text="r.email"></span>
                        </div>
                    </template>
                    <template x-if="!r.fromProperty">
                        <div class="flex-1 grid grid-cols-2 gap-2">
                            <input type="text" :name="'recipients[' + idx + '][name]'" x-model="r.name" placeholder="Name" class="rounded-md text-sm px-2 py-1.5" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);">
                            <input type="email" :name="'recipients[' + idx + '][email]'" x-model="r.email" placeholder="Email" required class="rounded-md text-sm px-2 py-1.5" style="background:var(--input-bg); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </template>
                    <button type="button" @click="removeRecipient(idx)" class="text-xs flex-shrink-0 px-1.5 py-1 rounded" style="color:var(--text-muted);" title="Remove">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>

            <button type="button" @click="addRecipient()" x-show="recipients.length < 10"
                    class="inline-flex items-center gap-2 text-sm font-semibold px-3 py-2 rounded-md" style="color:var(--brand-default); background:color-mix(in srgb, var(--brand-default) 8%, transparent);">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Recipient
            </button>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3 flex-wrap">
            <button type="button" @click="preview()" class="px-4 py-2 rounded-md text-sm font-semibold" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                Preview Email
            </button>
            <button type="submit" :disabled="sending || enabledRecipients.length === 0" class="px-5 py-2.5 rounded-md text-sm font-semibold text-white" style="background:var(--brand-default);">
                <span x-text="sending ? 'Sending...' : ('Send to ' + enabledRecipients.length + ' recipient' + (enabledRecipients.length !== 1 ? 's' : ''))"></span>
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
