{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="rentalApplicationCreate({{ Js::from([
    'contactId' => old('contact_id', ''),
    'contactName' => $oldContact ? trim($oldContact->first_name . ' ' . $oldContact->last_name) : '',
    'propertyId' => old('property_id', ''),
    'propertyLabel' => $oldProperty ? ($oldProperty->title ?: trim($oldProperty->address . ', ' . $oldProperty->suburb, ', ')) : '',
]) }})">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">New Rental Application</h1>
        <p class="text-xs" style="color: var(--text-muted);">Pick a contact — everything else is optional and can be filled in later or by the applicant themselves.</p>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-applications.store') }}" class="rounded-md p-6 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Contact <span class="text-red-500">*</span></label>
            <input type="text" x-model="contactQuery" @input.debounce.300ms="searchContacts()"
                   placeholder="Search contacts by name, phone or email…"
                   class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <input type="hidden" name="contact_id" x-model="selectedContactId" required>
            <div class="mt-1 rounded-md" style="border: 1px solid var(--border);" x-show="contactResults.length">
                <template x-for="c in contactResults" :key="c.id">
                    <button type="button" @click="selectContact(c)" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50">
                        <span x-text="c.first_name + ' ' + c.last_name"></span>
                        <span class="text-xs text-slate-400" x-text="c.email || c.phone || ''"></span>
                    </button>
                </template>
            </div>
            <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="selectedContactName" x-text="'Selected: ' + selectedContactName"></p>
            <button type="button" @click="openQuickCreate()" class="text-xs mt-1 underline" style="color: var(--ds-blue, #2563eb);">Can't find them? Create a new contact</button>
        </div>

        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Property (optional)</label>
            <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()"
                   placeholder="Search properties…"
                   class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <input type="hidden" name="property_id" x-model="selectedPropertyId">
            <div class="mt-1 rounded-md" style="border: 1px solid var(--border);" x-show="propertyResults.length">
                <template x-for="p in propertyResults" :key="p.id">
                    <button type="button" @click="selectProperty(p)" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50" x-text="p.label"></button>
                </template>
            </div>
            <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="selectedPropertyLabel" x-text="'Selected: ' + selectedPropertyLabel"></p>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.rental-applications.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs" :disabled="!selectedContactId">Create</button>
        </div>
    </form>

    {{-- Inline "create new contact" — minimum-viable fields only, deliberately
         not the full Contacts form. Lives in the SAME x-data as the rest of
         this page, so opening/closing it or hitting a validation error never
         touches selectedPropertyId/selectedPropertyLabel/etc. — nothing typed
         on the rest of the form is ever at risk. --}}
    <div x-show="quickCreateOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background: rgba(0,0,0,0.4);">
        <div class="rounded-md p-6 space-y-3 w-full max-w-sm" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="closeQuickCreate()">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">New contact</h2>

            <template x-if="quickCreateError">
                <p class="text-xs rounded px-2 py-1" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);" x-text="quickCreateError"></p>
            </template>

            <template x-if="!quickCreateDuplicates.length">
                <div class="space-y-2">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">First name</label>
                        <input type="text" x-model="quickCreate.first_name" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Last name</label>
                        <input type="text" x-model="quickCreate.last_name" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone</label>
                        <input type="text" x-model="quickCreate.phone" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                        <input type="email" x-model="quickCreate.email" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    </div>
                    <p class="text-xs" style="color: var(--text-muted);">Phone or email is required. Everything else about this contact can be filled in later from Contacts.</p>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" @click="closeQuickCreate()" class="corex-btn-outline text-xs">Cancel</button>
                        <button type="button" @click="submitQuickCreate()" class="corex-btn-primary text-xs" :disabled="quickCreateBusy">Create contact</button>
                    </div>
                </div>
            </template>

            <template x-if="quickCreateDuplicates.length">
                <div class="space-y-2">
                    <p class="text-xs" style="color: var(--text-secondary);">This looks like it might already be a contact — link the existing person instead of creating a second record:</p>
                    <template x-for="d in quickCreateDuplicates" :key="d.id">
                        <div class="rounded-md px-3 py-2 text-sm flex items-center justify-between" style="border: 1px solid var(--border);">
                            <div>
                                <div x-text="d.name"></div>
                                <div class="text-xs" style="color: var(--text-muted);" x-text="[d.phone, d.email].filter(Boolean).join(' · ')"></div>
                            </div>
                            <button type="button" x-show="d.can_view" @click="useDuplicate(d)" class="corex-btn-outline text-xs">Use this contact</button>
                        </div>
                    </template>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" @click="closeQuickCreate()" class="corex-btn-outline text-xs">Cancel</button>
                        <button type="button" x-show="quickCreateMode !== 'hard_block_request'" @click="submitQuickCreate(true)" class="corex-btn-primary text-xs">Create anyway</button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function rentalApplicationCreate(old) {
    old = old || {};
    return {
        contactQuery: old.contactName || '', contactResults: [], selectedContactId: old.contactId || '', selectedContactName: old.contactName || '',
        propertyQuery: old.propertyLabel || '', propertyResults: [], selectedPropertyId: old.propertyId || '', selectedPropertyLabel: old.propertyLabel || '',
        quickCreateOpen: false, quickCreateBusy: false, quickCreateError: '', quickCreateDuplicates: [], quickCreateMode: '',
        quickCreate: { first_name: '', last_name: '', phone: '', email: '' },
        async searchContacts() {
            if (this.contactQuery.length < 2) { this.contactResults = []; return; }
            const res = await fetch('{{ route('corex.properties.contacts.search-global') }}?q=' + encodeURIComponent(this.contactQuery));
            this.contactResults = await res.json();
        },
        selectContact(c) {
            this.selectedContactId = c.id;
            this.selectedContactName = c.first_name + ' ' + c.last_name;
            this.contactResults = [];
            this.contactQuery = this.selectedContactName;
        },
        async searchProperties() {
            if (this.propertyQuery.length < 2) { this.propertyResults = []; return; }
            const res = await fetch('{{ route('corex.rental-applications.search-properties') }}?q=' + encodeURIComponent(this.propertyQuery));
            this.propertyResults = await res.json();
        },
        selectProperty(p) {
            this.selectedPropertyId = p.id;
            this.selectedPropertyLabel = p.label;
            this.propertyResults = [];
            this.propertyQuery = p.label;
        },
        openQuickCreate() {
            this.quickCreate = { first_name: '', last_name: '', phone: '', email: '' };
            this.quickCreateError = '';
            this.quickCreateDuplicates = [];
            this.quickCreateOpen = true;
        },
        closeQuickCreate() {
            this.quickCreateOpen = false;
        },
        useDuplicate(d) {
            this.selectContact({ id: d.id, first_name: d.name, last_name: '' });
            this.selectedContactName = d.name;
            this.closeQuickCreate();
        },
        async submitQuickCreate(bypass) {
            this.quickCreateBusy = true;
            this.quickCreateError = '';
            try {
                const res = await fetch('{{ route('corex.rental-applications.contacts.quick-create') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify({ ...this.quickCreate, bypass_duplicate_check: !!bypass }),
                });
                const body = await res.json();
                if (res.status === 422 && body.duplicates) {
                    this.quickCreateDuplicates = body.duplicates;
                    this.quickCreateMode = body.mode;
                    return;
                }
                if (!res.ok) {
                    this.quickCreateError = body.message || (body.errors ? Object.values(body.errors)[0][0] : 'Could not create that contact.');
                    return;
                }
                const c = body.contact;
                this.selectContact({ id: c.id, first_name: c.first_name, last_name: c.last_name });
                this.closeQuickCreate();
            } catch (e) {
                this.quickCreateError = 'Something went wrong — try again.';
            } finally {
                this.quickCreateBusy = false;
            }
        },
    };
}
</script>
@endsection
