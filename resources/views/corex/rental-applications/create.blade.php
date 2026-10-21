{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="rentalApplicationCreate({{ Js::from([
    'contactId' => old('contact_id', $oldContact?->id ?? ''),
    'contactName' => $oldContact ? trim($oldContact->first_name . ' ' . $oldContact->last_name) : '',
    'contactHasEmail' => (bool) $oldContact?->email,
    'contactEmail' => old('contact_email', ''),
    'propertyId' => old('property_id', $oldProperty?->id ?? ''),
    {{-- Johan, QA1 walk, 2026-09-21 — address first, never the listing's
         marketing title. Same fix as the search results below. --}}
    'propertyLabel' => $oldProperty?->buildDisplayAddress() ?? '',
]) }})">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">New Rental Application</h1>
        <p class="text-xs" style="color: var(--text-muted);">Pick a contact — everything else is optional and can be filled in later or by the applicant themselves.</p>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm max-w-3xl" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--ds-crimson);">
            {{ $errors->first() }}
            {{-- AT-485 - refused as a duplicate: link to the application that already exists. --}}
            @if(session('duplicate_application'))
                <a href="{{ session('duplicate_application')['url'] }}" class="underline font-medium ml-1" data-qa="duplicate-application-link">Open the existing application</a>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-applications.store') }}" class="rounded-md p-6 space-y-5 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Contact <span style="color: var(--ds-crimson);">*</span></label>
            {{-- AT-485 - typing a new search drops the contact picked before (it used to stay selected, so Create/Enter
                 submitted the OLD contact); Enter in a search box never submits the form. --}}
            <input type="text" x-model="contactQuery" @input="contactTyped()" @input.debounce.300ms="searchContacts()"
                   @keydown.enter.prevent
                   placeholder="Search contacts by name, phone or email…"
                   class="prop-input">
            <input type="hidden" name="contact_id" x-model="selectedContactId" required>
            <div class="mt-1 rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" x-show="contactResults.length">
                <template x-for="c in contactResults" :key="c.id">
                    <button type="button" @click="selectContact(c)" class="list-row-hover block w-full text-left px-3 py-2 text-sm" style="color: var(--text-primary);">
                        <span x-text="c.first_name + ' ' + c.last_name"></span>
                        <span class="text-xs" style="color: var(--text-muted);" x-text="c.email || c.phone || ''"></span>
                    </button>
                </template>
            </div>
            <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="selectedContactName" x-text="'Selected: ' + selectedContactName"></p>
            <button type="button" @click="openQuickCreate()" class="text-xs mt-1 underline" style="color: var(--brand-icon, #0ea5e9);">Can't find them? Create a new contact</button>
        </div>

        {{-- AT-485 - the picked contact has no email: type one here, it is saved to the contact so the invite can be sent. --}}
        <div x-show="selectedContactId && !selectedContactHasEmail" x-cloak data-qa="contact-email-field">
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Applicant's email (this contact has none)</label>
            <input type="email" name="contact_email" x-model="contactEmail" :disabled="!selectedContactId || selectedContactHasEmail"
                   placeholder="name@example.com" class="prop-input">
            <p class="text-xs mt-1" style="color: var(--text-muted);">Optional. It is saved to the contact, and is where the application is emailed. Leave it empty to share the link yourself instead.</p>
        </div>

        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Property (optional)</label>
            <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()" @keydown.enter.prevent
                   placeholder="Search properties…"
                   class="prop-input">
            <input type="hidden" name="property_id" x-model="selectedPropertyId">
            {{-- Johan, QA1 walk, 2026-09-21 — same richer result row as the
                 PDF splitter's property picker: address + status, then ref +
                 agent as a muted subtitle, so an agent can be certain which
                 property they're picking, not just its marketing title. --}}
            <div class="mt-1 rounded-md max-h-72 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border);" x-show="propertyResults.length">
                <template x-for="p in propertyResults" :key="p.id">
                    <button type="button" @click="selectProperty(p)" class="list-row-hover block w-full text-left px-3 py-2 text-sm" style="color: var(--text-primary);">
                        <div class="flex items-center gap-1.5">
                            <span x-text="p.label"></span>
                            <span x-show="p.status" x-text="p.status" class="text-[10px] px-1 py-0.5 rounded" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border); white-space:nowrap;"></span>
                        </div>
                        <div class="text-xs" style="color: var(--text-muted);" x-text="[p.ref ? ('Ref: ' + p.ref) : '', p.agent].filter(Boolean).join(' · ')"></div>
                    </button>
                </template>
            </div>
            <p class="text-xs mt-1" style="color: var(--text-muted);" x-show="selectedPropertyLabel" x-text="'Selected: ' + selectedPropertyLabel"></p>
        </div>

        <div class="flex justify-end gap-2 pt-4" style="border-top: 1px solid var(--border);">
            <a href="{{ route('corex.rental-applications.index') }}" class="corex-btn-outline text-sm">Cancel</a>
            <button type="submit" class="corex-btn-primary text-sm" :disabled="!selectedContactId">Create</button>
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
                <p class="text-xs rounded-md px-3 py-2" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);" x-text="quickCreateError"></p>
            </template>

            <template x-if="!quickCreateDuplicates.length">
                <div class="space-y-2">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">First name</label>
                        <input type="text" x-model="quickCreate.first_name" class="prop-input">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Last name</label>
                        <input type="text" x-model="quickCreate.last_name" class="prop-input">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone</label>
                        <input type="text" x-model="quickCreate.phone" class="prop-input">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                        <input type="email" x-model="quickCreate.email" class="prop-input">
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
                                <div x-text="d.name" style="color: var(--text-primary);"></div>
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
        selectedContactHasEmail: !!old.contactHasEmail, contactEmail: old.contactEmail || '',
        propertyQuery: old.propertyLabel || '', propertyResults: [], selectedPropertyId: old.propertyId || '', selectedPropertyLabel: old.propertyLabel || '',
        quickCreateOpen: false, quickCreateBusy: false, quickCreateError: '', quickCreateDuplicates: [], quickCreateMode: '',
        quickCreate: { first_name: '', last_name: '', phone: '', email: '' },
        async searchContacts() {
            if (this.contactQuery.length < 2) { this.contactResults = []; return; }
            const res = await fetch('{{ route('corex.properties.contacts.search-global') }}?q=' + encodeURIComponent(this.contactQuery));
            this.contactResults = await res.json();
        },
        // AT-485 - a changed search text means the previously picked contact is no longer the one being looked for.
        contactTyped() {
            if (this.selectedContactId && this.contactQuery.trim() !== this.selectedContactName.trim()) {
                this.selectedContactId = '';
                this.selectedContactName = '';
                this.selectedContactHasEmail = false;
                this.contactEmail = '';
            }
        },
        selectContact(c) {
            this.selectedContactId = c.id;
            this.selectedContactHasEmail = !!c.email;
            this.contactEmail = '';
            this.selectedContactName = (c.first_name + ' ' + c.last_name).trim();
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
            this.selectContact({ id: d.id, first_name: d.name, last_name: '', email: d.email });
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
                this.selectContact({ id: c.id, first_name: c.first_name, last_name: c.last_name, email: this.quickCreate.email });
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
