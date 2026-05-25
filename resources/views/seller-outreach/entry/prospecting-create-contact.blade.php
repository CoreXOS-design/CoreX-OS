@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ url()->previous() }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">
            ← Back
        </a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Compose pitch about this property</h1>
        <p class="text-sm text-white/60">
            Capture the seller's contact info first. We'll dedupe against existing contacts before creating a new one.
        </p>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    {{-- Listing summary --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
            Listing from {{ strtoupper((string) ($listing->portal_source ?? 'portal')) }}
        </div>
        <div class="font-semibold text-sm" style="color: var(--text-primary);">
            {{ $listing->address ?? '(no address)' }}{{ !empty($listing->suburb) ? ', ' . $listing->suburb : '' }}
        </div>
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            @if(!empty($listing->price))R {{ number_format((float) $listing->price, 0, '.', ',') }} · @endif
            {{ $listing->property_type ?? 'property' }}
            @if(!empty($listing->bedrooms)) · {{ $listing->bedrooms }} beds @endif
            @if(!empty($listing->bathrooms)) · {{ $listing->bathrooms }} baths @endif
        </div>
    </div>

    {{-- Contact form --}}
    <form method="POST" action="{{ route('seller-outreach.entry.store-from-prospecting', $listing->id) }}">
        @csrf

        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-base font-semibold" style="color: var(--text-primary);">Seller contact</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                        First name <span style="color: var(--ds-crimson);">*</span>
                    </label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required maxlength="100"
                           class="w-full px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Last name</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="100"
                           class="w-full px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Phone</label>
                    <input type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" placeholder="082 123 4567"
                           class="w-full px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" maxlength="255"
                           class="w-full px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            {{-- A.2.5 — optional SA ID number capture at create time. --}}
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">ID number (optional)</label>
                <input type="text" name="id_number" value="{{ old('id_number') }}"
                       inputmode="numeric" maxlength="13" pattern="\d{13}"
                       placeholder="e.g. 7610025020081" title="13 digits — empty is fine"
                       class="w-full px-3 py-2 text-sm rounded"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">SA ID — 13 digits. Leave blank if not known.</p>
            </div>

            <div class="text-xs" style="color: var(--text-muted);">
                Provide at least a phone or email. We'll check if this person already exists in your contacts.
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <button type="submit"
                    class="px-6 py-2.5 text-sm font-semibold rounded"
                    style="background: #00d4aa; color: #003a2f;">
                Create / link &amp; continue →
            </button>
            <a href="{{ url()->previous() }}" class="text-sm" style="color: var(--text-muted);">Cancel</a>
        </div>
    </form>
</div>
@endsection
