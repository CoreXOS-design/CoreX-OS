{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-3xl space-y-4"
     x-data="{
        tier: '{{ old('tier', 'tier_1') }}',
        hasProperty: {{ $property ? 'true' : 'false' }},
        propertyId: '{{ $property?->id ?? '' }}',
        subjects: [{ agency_name: '', practitioner_name: '', portal_url: '', portal_source: 'p24' }],
        submitting: false,
        addSubject() { if (this.subjects.length < 10) this.subjects.push({ agency_name: '', practitioner_name: '', portal_url: '', portal_source: 'p24' }); },
        removeSubject(i) { if (this.subjects.length > 1) this.subjects.splice(i, 1); }
     }">

    {{-- Back --}}
    <div class="flex items-center gap-4 flex-wrap">
        <a href="{{ route('compliance.whistleblow.index') }}" class="inline-flex items-center gap-1.5 text-sm no-underline" style="color:var(--text-secondary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            Back to Queue
        </a>
    </div>

    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h1 class="text-lg font-bold" style="color:var(--text-primary);">File a Compliance Report</h1>
        <p class="text-sm mt-1" style="color:var(--text-secondary);">Report one or more agencies/practitioners marketing a property without proper compliance documentation.</p>
    </div>

    @if($errors->any())
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent); color:var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--ds-crimson, #c41e3a);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
        <div class="flex-1 space-y-0.5">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    </div>
    @endif

    <form method="POST" action="{{ route('compliance.whistleblow.store') }}" enctype="multipart/form-data" @submit="submitting = true" class="space-y-4">
        @csrf
        <input type="hidden" name="_idempotency_token" value="{{ $idempotencyToken }}">

        {{-- Tier --}}
        <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Complaint Type</h3>
            <div class="space-y-3">
                <label class="flex items-start gap-3 cursor-pointer rounded-md p-3" :style="tier === 'tier_1' ? 'background:color-mix(in srgb, var(--brand-default, #0b2a4a) 6%, transparent); border:1px solid var(--brand-default, #0b2a4a)' : 'border:1px solid var(--border)'">
                    <input type="radio" name="tier" value="tier_1" x-model="tier" class="mt-0.5">
                    <span><span class="text-sm font-semibold" style="color:var(--text-primary);">Tier 1 — Paperwork breach (seller confirmed)</span><br><span class="text-xs" style="color:var(--text-muted);">Seller confirms no mandate, FICA, or MDF. Cites PPA §47, §67, FICA §21A.</span></span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-md p-3" :style="tier === 'tier_2' ? 'background:color-mix(in srgb, var(--brand-default, #0b2a4a) 6%, transparent); border:1px solid var(--brand-default, #0b2a4a)' : 'border:1px solid var(--border)'">
                    <input type="radio" name="tier" value="tier_2" x-model="tier" class="mt-0.5">
                    <span><span class="text-sm font-semibold" style="color:var(--text-primary);">Tier 2 — No FFC displayed</span><br><span class="text-xs" style="color:var(--text-muted);">Advert missing valid FFC number. Cites PPA §61.</span></span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-md p-3" :style="tier === 'tier_3' ? 'background:color-mix(in srgb, var(--brand-default, #0b2a4a) 6%, transparent); border:1px solid var(--brand-default, #0b2a4a)' : 'border:1px solid var(--border)'">
                    <input type="radio" name="tier" value="tier_3" x-model="tier" class="mt-0.5">
                    <span><span class="text-sm font-semibold" style="color:var(--text-primary);">Tier 3 — Unregistered practitioner</span><br><span class="text-xs" style="color:var(--text-muted);">Not found on PPRA register. Criminal offence under PPA §49.</span></span>
                </label>
            </div>
        </div>

        {{-- Property --}}
        <div class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Property</h3>
            <div>
                <label class="text-sm font-medium" style="color:var(--text-primary);">Link to existing property</label>
                <select name="property_id" x-model="propertyId" @change="hasProperty = (propertyId !== '')"
                        class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">-- Not in CoreX (enter address below) --</option>
                    @foreach($properties as $p)
                    <option value="{{ $p->id }}" {{ ($property?->id == $p->id) ? 'selected' : '' }}>{{ $p->address ?? $p->title }} — {{ $p->suburb }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="!hasProperty" x-cloak>
                <label class="text-sm font-medium" style="color:var(--text-primary);">Property address *</label>
                <input type="text" name="property_address" value="{{ old('property_address', $property?->address) }}"
                       class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--text-primary);"
                       placeholder="Full street address, suburb, town">
            </div>
        </div>

        {{-- Subjects --}}
        <div class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Subjects of Complaint</h3>
            <p class="text-xs" style="color:var(--text-secondary);">Add each agency or practitioner marketing this property without compliance.</p>

            <template x-for="(subject, idx) in subjects" :key="idx">
                <div class="rounded-md p-4 space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase" style="color:var(--text-muted);" x-text="'Subject ' + (idx + 1)"></span>
                        <button type="button" @click="removeSubject(idx)" x-show="subjects.length > 1" class="text-xs font-medium px-2 py-1 rounded-md transition-all" style="color:var(--ds-crimson, #c41e3a); background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 8%, transparent);">Remove</button>
                    </div>
                    <div>
                        <label class="text-sm font-medium" style="color:var(--text-primary);">Agency name *</label>
                        <input type="text" :name="'subjects[' + idx + '][agency_name]'" x-model="subject.agency_name" required
                               class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="Name of the competing agency">
                    </div>
                    <div>
                        <label class="text-sm font-medium" style="color:var(--text-primary);">Practitioner name</label>
                        <input type="text" :name="'subjects[' + idx + '][practitioner_name]'" x-model="subject.practitioner_name"
                               class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="If known">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-sm font-medium" style="color:var(--text-primary);">Portal URL *</label>
                            <input type="url" :name="'subjects[' + idx + '][portal_url]'" x-model="subject.portal_url" required
                                   class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="https://...">
                        </div>
                        <div>
                            <label class="text-sm font-medium" style="color:var(--text-primary);">Portal *</label>
                            <select :name="'subjects[' + idx + '][portal_source]'" x-model="subject.portal_source" required
                                    class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="p24">Property24</option>
                                <option value="pp">Private Property</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                </div>
            </template>

            <button type="button" @click="addSubject()" x-show="subjects.length < 10"
                    class="inline-flex items-center gap-2 text-sm font-semibold px-3 py-2 rounded-md" style="color:var(--brand-default, #0b2a4a); background:color-mix(in srgb, var(--brand-default, #0b2a4a) 8%, transparent);">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add another subject
            </button>
        </div>

        {{-- Seller info (Tier 1 only) --}}
        <div x-show="tier === 'tier_1'" x-cloak class="rounded-md p-5 space-y-4" style="background:color-mix(in srgb, var(--ds-amber) 4%, var(--surface)); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--ds-amber);">Seller Information (Tier 1)</h3>
            <div>
                <label class="text-sm font-medium" style="color:var(--text-primary);">Seller statement *</label>
                <textarea name="seller_statement" rows="4"
                          class="mt-1 w-full rounded-md text-sm px-3 py-2" style="background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--text-primary);"
                          placeholder="Capture exactly what the seller told you about the competing agencies' listing...">{{ old('seller_statement') }}</textarea>
                <p class="text-xs mt-1" style="color:var(--text-muted);">This statement will appear verbatim in the PPRA complaint PDF.</p>
            </div>
        </div>

        {{-- Notes --}}
        <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Agent Notes</h3>
            <textarea name="agent_notes" rows="3"
                      class="w-full rounded-md text-sm px-3 py-2" style="background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--text-primary);"
                      placeholder="Internal notes — context for the approver">{{ old('agent_notes') }}</textarea>
        </div>

        {{-- Evidence --}}
        <div class="rounded-md p-5 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Evidence</h3>
            <div x-show="tier === 'tier_1'" class="text-xs" style="color:var(--text-secondary);">A clear seller statement above is the primary evidence. File attachments are optional but recommended.</div>
            <div x-show="tier === 'tier_2'" x-cloak class="text-xs" style="color:var(--ds-amber);">Required: a screenshot of the advert showing the missing FFC number.</div>
            <div x-show="tier === 'tier_3'" x-cloak class="text-xs" style="color:var(--ds-amber);">Required: a screenshot of the advert AND the PPRA register search showing no result.</div>
            <input type="file" name="evidence_files[]" multiple accept="image/*,.pdf,.doc,.docx"
                   class="w-full text-sm" style="color:var(--text-primary);">
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('compliance.whistleblow.index') }}" class="corex-btn-outline text-sm no-underline">Cancel</a>
            <button type="submit" :disabled="submitting" class="corex-btn-primary text-sm disabled:opacity-40 disabled:cursor-not-allowed">
                <span x-text="submitting ? 'Submitting...' : 'Submit Report'"></span>
            </button>
        </div>
    </form>
</div>
@endsection
