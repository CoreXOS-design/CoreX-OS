@extends('layouts.corex-app')

@section('corex-content')
<div class="p-6 lg:p-8 max-w-3xl">
    {{-- Header --}}
    <div class="mb-6">
        <a href="{{ route('compliance.fica.index') }}" class="text-sm text-slate-500 hover:text-slate-700 mb-2 inline-block">&larr; Back to Compliance</a>
        <h1 class="text-2xl font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">New Wet-Ink FICA</h1>
        <p class="text-sm mt-1" style="color:var(--text-muted);">Upload a completed paper FICA form and supporting documents</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 p-3 text-sm" style="background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); color:#ef4444; border-radius:3px;">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('compliance.fica.wet-ink.store') }}" enctype="multipart/form-data"
          x-data="{
              search: '', open: false, selected: {{ old('contact_id', 'null') }}, selectedName: '{{ old('contact_id') ? '' : '' }}',
              entityType: '{{ old('entity_type', 'natural') }}',
              contactInfo: null
          }">
        @csrf

        {{-- Section 1: Contact --}}
        <div class="mb-5 p-5" style="background:var(--surface, #fff); border:1px solid var(--border, #e2e8f0); border-radius:3px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">1. Select Contact</h3>

            <div class="relative mb-3">
                <input type="text"
                       x-model="search"
                       @focus="open = true"
                       @click.away="open = false"
                       placeholder="Search contacts..."
                       class="w-full px-3 py-2 text-sm outline-none"
                       style="border:1px solid var(--border, #e2e8f0); border-radius:3px; background:var(--surface-2, #f8fafc); color:var(--text-primary);"
                       x-show="!selected">
                <div x-show="selected" class="flex items-center justify-between px-3 py-2" style="border:1px solid var(--border); border-radius:3px; background:var(--surface-2);">
                    <span class="text-sm font-medium" style="color:var(--text-primary);" x-text="selectedName"></span>
                    <button type="button" @click="selected = null; selectedName = ''; search = ''; contactInfo = null" class="text-slate-400 hover:text-red-500">&times;</button>
                </div>
                <input type="hidden" name="contact_id" :value="selected">

                <div x-show="open && search.length >= 2" x-cloak
                     class="absolute z-10 mt-1 w-full max-h-60 overflow-y-auto shadow-lg" style="background:var(--surface, #fff); border:1px solid var(--border);">
                    @foreach($contacts as $c)
                        @php
                            $haystack = strtolower(trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '') . ' ' . ($c->email ?? '') . ' ' . ($c->id_number ?? '')));
                            $label = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
                            $info = json_encode([
                                'name' => $label,
                                'email' => $c->email ?? 'No email',
                                'phone' => $c->phone ?? $c->cell ?? 'No phone',
                                'id_number' => $c->id_number ?? 'Not set',
                            ]);
                        @endphp
                        <button type="button"
                                x-show="{{ \Illuminate\Support\Js::from($haystack) }}.includes(search.toLowerCase())"
                                @click="selected = {{ (int) $c->id }}; selectedName = {{ \Illuminate\Support\Js::from($label) }}; open = false; contactInfo = {{ $info }}"
                                class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50" style="border-bottom:1px solid var(--border);">
                            <div class="font-medium" style="color:var(--text-primary);">{{ $c->first_name }} {{ $c->last_name }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ $c->email ?? 'No email' }} {{ $c->id_number ? '/ ID: ' . $c->id_number : '' }}</div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Contact info summary --}}
            <div x-show="contactInfo" x-cloak class="p-3 text-xs" style="background:var(--surface-2); border:1px solid var(--border); border-radius:3px;">
                <div class="grid grid-cols-3 gap-2">
                    <div><span style="color:var(--text-muted);">Email:</span> <span style="color:var(--text-primary);" x-text="contactInfo?.email"></span></div>
                    <div><span style="color:var(--text-muted);">Phone:</span> <span style="color:var(--text-primary);" x-text="contactInfo?.phone"></span></div>
                    <div><span style="color:var(--text-muted);">ID:</span> <span style="color:var(--text-primary);" x-text="contactInfo?.id_number"></span></div>
                </div>
            </div>
        </div>

        {{-- Section 2: Entity Type --}}
        <div class="mb-5 p-5" style="background:var(--surface, #fff); border:1px solid var(--border); border-radius:3px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">2. Entity Type</h3>
            <div class="flex gap-4 text-sm">
                @foreach(['natural' => 'Natural Person', 'company' => 'Company', 'trust' => 'Trust', 'partnership' => 'Partnership'] as $val => $label)
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="entity_type" value="{{ $val }}" x-model="entityType" {{ old('entity_type', 'natural') === $val ? 'checked' : '' }}
                           style="accent-color:#00d4aa;">
                    <span style="color:var(--text-primary);">{{ $label }}</span>
                </label>
                @endforeach
            </div>
        </div>

        {{-- Section 3: Received Date --}}
        <div class="mb-5 p-5" style="background:var(--surface, #fff); border:1px solid var(--border); border-radius:3px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">3. Date Received</h3>
            <input type="date" name="wet_ink_received_date" value="{{ old('wet_ink_received_date', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}" required
                   class="px-3 py-2 text-sm outline-none"
                   style="border:1px solid var(--border); border-radius:3px; background:var(--surface-2); color:var(--text-primary); width:200px;">
            @error('wet_ink_received_date') <p class="text-xs mt-1" style="color:#ef4444;">{{ $message }}</p> @enderror
        </div>

        {{-- Section 4: Document Uploads --}}
        <div class="mb-5 p-5" style="background:var(--surface, #fff); border:1px solid var(--border); border-radius:3px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">4. Upload Documents</h3>
            <p class="text-xs mb-4" style="color:var(--text-muted);">PDF or image, max 10MB each</p>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-primary);">FICA Form (signed paper form) <span style="color:#ef4444;">*</span></label>
                    <input type="file" name="fica_form_file" accept=".pdf,.jpg,.jpeg,.png" required
                           class="block w-full text-sm" style="color:var(--text-primary);">
                    @error('fica_form_file') <p class="text-xs mt-1" style="color:#ef4444;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-primary);">ID Copy <span style="color:#ef4444;">*</span></label>
                    <input type="file" name="id_copy_file" accept=".pdf,.jpg,.jpeg,.png" required
                           class="block w-full text-sm" style="color:var(--text-primary);">
                    @error('id_copy_file') <p class="text-xs mt-1" style="color:#ef4444;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-primary);">Proof of Address (not older than 3 months) <span style="color:#ef4444;">*</span></label>
                    <input type="file" name="proof_of_address_file" accept=".pdf,.jpg,.jpeg,.png" required
                           class="block w-full text-sm" style="color:var(--text-primary);">
                    @error('proof_of_address_file') <p class="text-xs mt-1" style="color:#ef4444;">{{ $message }}</p> @enderror
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
                    @error('supporting_docs.*') <p class="text-xs mt-1" style="color:#ef4444;">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Section 5: Confirmation --}}
        <div class="mb-5 p-5" style="background:var(--surface, #fff); border:1px solid var(--border); border-radius:3px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">5. Attestation</h3>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="confirmed_signed_paper" value="1" required
                       class="mt-0.5" style="accent-color:#00d4aa;">
                <span class="text-sm" style="color:var(--text-primary);">
                    I confirm that the original wet-ink FICA document was signed by the client and received in person on the date above.
                    This attestation is recorded against my user account.
                </span>
            </label>
            @error('confirmed_signed_paper') <p class="text-xs mt-1" style="color:#ef4444;">{{ $message }}</p> @enderror
        </div>

        {{-- Submit --}}
        <div class="flex items-center gap-3">
            <button type="submit"
                    style="padding:9px 24px; border-radius:3px; border:none; background:#00d4aa; color:#0f172a; font-size:0.85rem; font-weight:700; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif;">
                Create & Continue to Verification
            </button>
            <a href="{{ route('compliance.fica.index') }}" class="text-sm" style="color:var(--text-muted);">Cancel</a>
        </div>
    </form>
</div>
@endsection
