{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="rentalApplicationCreate()">
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
</div>

<script>
function rentalApplicationCreate() {
    return {
        contactQuery: '', contactResults: [], selectedContactId: '', selectedContactName: '',
        propertyQuery: '', propertyResults: [], selectedPropertyId: '', selectedPropertyLabel: '',
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
    };
}
</script>
@endsection
