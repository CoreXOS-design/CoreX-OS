{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">New Wet-Ink FICA</h1>
                <p class="text-sm text-white/60">Upload a completed paper FICA form and supporting documents.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('compliance.fica.index') }}" class="corex-btn-outline corex-btn-on-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                    Back to FICA
                </a>
            </div>
        </div>
    </div>

    {{-- Validation errors (Alert block — §3.9 danger) --}}
    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <div class="flex-1">
                <strong>Please fix the following:</strong>
                <ul class="list-disc ml-4 mt-1 space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('compliance.fica.wet-ink.store') }}" enctype="multipart/form-data"
          x-data="{
              search: '',
              open: false,
              selected: {{ \Illuminate\Support\Js::from(old('contact_id') ?: null) }},
              selectedName: {{ \Illuminate\Support\Js::from('') }},
              entityType: {{ \Illuminate\Support\Js::from(old('entity_type', 'natural')) }},
              contactInfo: null
          }">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {{-- Section 1: Contact --}}
            <div class="lg:col-span-2 rounded-md p-5" style="background:var(--surface, #fff); border:1px solid var(--border, #e2e8f0);">
                <h3 class="text-sm font-semibold mb-4" style="color:var(--text-primary);">1. Select Contact</h3>

                <div class="relative mb-3">
                    <input type="text"
                           x-model="search"
                           @focus="open = true"
                           @click.away="open = false"
                           placeholder="Search contacts..."
                           class="w-full rounded-md px-3 py-2 text-sm outline-none"
                           style="border:1px solid var(--border, #e2e8f0); background:var(--surface-2, #f8fafc); color:var(--text-primary);"
                           x-show="!selected">
                    <div x-show="selected" class="flex items-center justify-between rounded-md px-3 py-2" style="border:1px solid var(--border); background:var(--surface-2);">
                        <span class="text-sm font-medium" style="color:var(--text-primary);" x-text="selectedName"></span>
                        <button type="button" @click="selected = null; selectedName = ''; search = ''; contactInfo = null" class="transition-colors hover:text-[var(--ds-crimson)]" style="color:var(--text-muted);">&times;</button>
                    </div>
                    <input type="hidden" name="contact_id" :value="selected">

                    <div x-show="open && search.length >= 2" x-cloak
                         class="absolute z-30 mt-1 w-full max-h-60 overflow-y-auto rounded-md"
                         style="background:var(--surface, #fff); border:1px solid var(--border); box-shadow:0 8px 24px rgba(0,0,0,0.18);">
                        @foreach($contacts as $c)
                            @php
                                $haystack = strtolower(trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '') . ' ' . ($c->email ?? '') . ' ' . ($c->id_number ?? '')));
                                $label = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
                                $info = json_encode([
                                    'name' => $label,
                                    'email' => $c->email ?? 'No email',
                                    'phone' => $c->phone ?? 'No phone',
                                    'id_number' => $c->id_number ?? 'Not set',
                                ]);
                            @endphp
                            <button type="button"
                                    x-show="{{ \Illuminate\Support\Js::from($haystack) }}.includes(search.toLowerCase())"
                                    @click="selected = {{ (int) $c->id }}; selectedName = {{ \Illuminate\Support\Js::from($label) }}; open = false; contactInfo = {{ $info }}"
                                    class="w-full text-left px-3 py-2 text-sm transition-colors hover:bg-[var(--surface-2)]" style="border-bottom:1px solid var(--border);">
                                <div class="font-medium" style="color:var(--text-primary);">{{ $c->first_name }} {{ $c->last_name }}</div>
                                <div class="text-xs" style="color:var(--text-muted);">{{ $c->email ?? 'No email' }} {{ $c->id_number ? '/ ID: ' . $c->id_number : '' }}</div>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Contact info summary --}}
                <div x-show="contactInfo" x-cloak class="rounded-md p-3 text-xs" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <div><span style="color:var(--text-muted);">Email:</span> <span style="color:var(--text-primary);" x-text="contactInfo?.email"></span></div>
                        <div><span style="color:var(--text-muted);">Phone:</span> <span style="color:var(--text-primary);" x-text="contactInfo?.phone"></span></div>
                        <div><span style="color:var(--text-muted);">ID:</span> <span style="color:var(--text-primary);" x-text="contactInfo?.id_number"></span></div>
                    </div>
                </div>
            </div>

            {{-- Section 2: Entity Type --}}
            <div class="rounded-md p-5" style="background:var(--surface, #fff); border:1px solid var(--border);">
                <h3 class="text-sm font-semibold mb-4" style="color:var(--text-primary);">2. Entity Type</h3>
                <div class="flex flex-wrap gap-4 text-sm">
                    @foreach(['natural' => 'Natural Person', 'company' => 'Company', 'trust' => 'Trust', 'partnership' => 'Partnership'] as $val => $label)
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="entity_type" value="{{ $val }}" x-model="entityType" {{ old('entity_type', 'natural') === $val ? 'checked' : '' }}
                               style="accent-color:var(--brand-icon);">
                        <span style="color:var(--text-primary);">{{ $label }}</span>
                    </label>
                    @endforeach
                </div>
            </div>

            {{-- Section 3: Received Date --}}
            <div class="rounded-md p-5" style="background:var(--surface, #fff); border:1px solid var(--border);">
                <h3 class="text-sm font-semibold mb-4" style="color:var(--text-primary);">3. Date Received</h3>
                <input type="date" name="wet_ink_received_date" value="{{ old('wet_ink_received_date', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}" required
                       class="w-full sm:w-52 rounded-md px-3 py-2 text-sm outline-none"
                       style="border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary);">
                @error('wet_ink_received_date') <p class="text-xs mt-1" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
            </div>

            {{-- Section 4: Document Uploads --}}
            <div class="lg:col-span-2 rounded-md p-5" style="background:var(--surface, #fff); border:1px solid var(--border);">
                <h3 class="text-sm font-semibold mb-4" style="color:var(--text-primary);">4. Upload Documents</h3>
                <p class="text-xs mb-4" style="color:var(--text-muted);">PDF or image, max 10MB each</p>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-primary);">FICA Form (signed paper form) <span style="color:var(--ds-crimson, #c41e3a);">*</span></label>
                        <input type="file" name="fica_form_file" accept=".pdf,.jpg,.jpeg,.png" required
                               class="block w-full text-sm" style="color:var(--text-primary);">
                        @error('fica_form_file') <p class="text-xs mt-1" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-primary);">ID Copy <span style="color:var(--ds-crimson, #c41e3a);">*</span></label>
                        <input type="file" name="id_copy_file" accept=".pdf,.jpg,.jpeg,.png" required
                               class="block w-full text-sm" style="color:var(--text-primary);">
                        @error('id_copy_file') <p class="text-xs mt-1" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-primary);">Proof of Address (not older than 3 months) <span style="color:var(--ds-crimson, #c41e3a);">*</span></label>
                        <input type="file" name="proof_of_address_file" accept=".pdf,.jpg,.jpeg,.png" required
                               class="block w-full text-sm" style="color:var(--text-primary);">
                        @error('proof_of_address_file') <p class="text-xs mt-1" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
                    </div>

                    {{-- Entity-specific supporting docs --}}
                    <div x-show="entityType !== 'natural'" x-cloak>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-primary);">
                            Supporting Documents
                            <span x-show="entityType === 'company'" x-cloak class="font-normal" style="color:var(--text-muted);">(CIPC docs, beneficial ownership register)</span>
                            <span x-show="entityType === 'trust'" x-cloak class="font-normal" style="color:var(--text-muted);">(Trust deed, Master's letter of authority)</span>
                            <span x-show="entityType === 'partnership'" x-cloak class="font-normal" style="color:var(--text-muted);">(Partnership agreement)</span>
                        </label>
                        <input type="file" name="supporting_docs[]" accept=".pdf,.jpg,.jpeg,.png" multiple
                               class="block w-full text-sm" style="color:var(--text-primary);">
                        @error('supporting_docs.*') <p class="text-xs mt-1" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Section 5: Confirmation --}}
            <div class="lg:col-span-2 rounded-md p-5" style="background:var(--surface, #fff); border:1px solid var(--border);">
                <h3 class="text-sm font-semibold mb-4" style="color:var(--text-primary);">5. Attestation</h3>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="confirmed_signed_paper" value="1" required
                           class="mt-0.5" style="accent-color:var(--brand-icon);">
                    <span class="text-sm" style="color:var(--text-primary);">
                        I confirm that the original wet-ink FICA document was signed by the client and received in person on the date above.
                        This attestation is recorded against my user account.
                    </span>
                </label>
                @error('confirmed_signed_paper') <p class="text-xs mt-1" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex flex-wrap items-center gap-3 mt-5">
            <button type="submit" class="corex-btn-primary">
                Create &amp; Continue to Verification
            </button>
            <a href="{{ route('compliance.fica.index') }}" class="corex-btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
