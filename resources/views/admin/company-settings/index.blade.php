@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="companySettingsPage({{ $agency?->id ?? 'null' }})">

    <div style="background:var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Company Settings</h2>
        <div class="text-sm text-white/60">Agency identity, contact block, logo and email signature.</div>
    </div>

    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md border px-4 py-3 text-sm" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            {{ $errors->first() }}
        </div>
    @endif

    @if($agencies->count() > 1)
        <div class="ds-status-card p-4">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Editing agency</label>
            <select x-model="selectedAgencyId" @change="switchAgency()"
                    class="w-full sm:w-80 rounded-md px-3 py-2 text-sm"
                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                @foreach($agencies as $a)
                    <option value="{{ $a->id }}" {{ $agency && $a->id === $agency->id ? 'selected' : '' }}>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if($agency)
        <form method="POST" action="{{ route('admin.company-settings.update', $agency) }}" enctype="multipart/form-data"
              class="ds-status-card p-4 space-y-5"
              x-data="{ removelogo: false }">
            @csrf
            @method('PUT')

            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Company Identity</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Trading Name</label>
                    <input type="text" name="trading_name" value="{{ old('trading_name', $agency->trading_name) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Tagline</label>
                    <input type="text" name="tagline" value="{{ old('tagline', $agency->tagline) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Registration No</label>
                    <input type="text" name="reg_no" value="{{ old('reg_no', $agency->reg_no) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">VAT No</label>
                    <input type="text" name="vat_no" value="{{ old('vat_no', $agency->vat_no) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">FFC No</label>
                    <input type="text" name="ffc_no" value="{{ old('ffc_no', $agency->ffc_no) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">FIC No</label>
                    <input type="text" name="fic_no" value="{{ old('fic_no', $agency->fic_no) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
            </div>

            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Contact Details</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Address</label>
                    <textarea name="address" rows="2"
                              class="w-full rounded-md px-3 py-2 text-sm"
                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('address', $agency->address) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Primary Cell Number</label>
                    <input type="text" name="phone" value="{{ old('phone', $agency->phone) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Primary Cell Label</label>
                    <input type="text" name="phone_label" value="{{ old('phone_label', $agency->phone_label) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Secondary Cell Number</label>
                    <input type="text" name="phone_secondary" value="{{ old('phone_secondary', $agency->phone_secondary) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Secondary Cell Label</label>
                    <input type="text" name="phone_secondary_label" value="{{ old('phone_secondary_label', $agency->phone_secondary_label) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Fax</label>
                    <input type="text" name="fax" value="{{ old('fax', $agency->fax) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email</label>
                    <input type="text" name="email" value="{{ old('email', $agency->email) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
            </div>

            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Email Signature</div>
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email Disclaimer</label>
                    <textarea name="email_disclaimer" rows="4"
                              class="w-full rounded-md px-3 py-2 text-sm"
                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('email_disclaimer', $agency->email_disclaimer) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">POPI Policy URL</label>
                    <input type="text" name="popi_url" value="{{ old('popi_url', $agency->popi_url) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
            </div>

            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Company Logo</div>
            <div>
                @if($agency->logo_path)
                    <div class="mb-2 flex items-center gap-3">
                        <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="Company Logo" class="h-10 w-auto rounded border bg-white p-1">
                        <label class="inline-flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                            <input type="checkbox" name="remove_logo" value="1" class="rounded" x-model="removelogo">
                            Remove logo
                        </label>
                    </div>
                @endif
                <div x-show="!removelogo">
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"
                           class="block w-full text-sm rounded-md px-3 py-2"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
                    <p class="text-xs mt-1" style="color:var(--text-muted);">JPG, PNG, or WebP — max 2 MB.</p>
                </div>
            </div>

            <div class="flex justify-end pt-1">
                <button type="submit" class="corex-btn-primary text-sm">Save Company Settings</button>
            </div>
        </form>
    @else
        <div class="ds-status-card p-6 text-sm" style="color:var(--text-secondary);">
            No agency found. <a href="{{ route('agencies.create') }}" style="color:var(--brand-icon);">Create one</a>.
        </div>
    @endif

</div>

<script>
function companySettingsPage(initialId) {
    return {
        selectedAgencyId: initialId,
        switchAgency() {
            const url = new URL(window.location.href);
            url.searchParams.set('agency', this.selectedAgencyId);
            window.location.href = url.toString();
        },
    };
}
</script>
@endsection
