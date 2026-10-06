{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — send a Platform E-Sign contract (AT-447, spec §3A). --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    @include('platform-esign._header', ['title' => 'Send a contract', 'tab' => 'send',
        'sub' => 'Pick a template, the agency it is for, and who signs. Each signer gets their own link by email.'])

    @if($templates->isEmpty())
        <div class="rounded-md p-10 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
            There are no templates yet. <a href="{{ route('platform-esign.templates.create') }}" class="underline" style="color: var(--brand-icon);">Create one</a> first.
        </div>
    @else
    <form method="GET" class="rounded-md p-4 max-w-4xl" style="background: var(--surface); border: 1px solid var(--border);">
        <label class="ds-label block mb-1">Template</label>
        <select name="template" class="ds-field w-full" onchange="this.form.submit()">
            @foreach($templates as $t)<option value="{{ $t->id }}" @selected($tpl && $tpl->id === $t->id)>{{ $t->name }} — {{ $t->isPdf() ? 'PDF' : 'wording' }}, v{{ $t->version }}</option>@endforeach
        </select>
        @if($agencyId)<input type="hidden" name="agency" value="{{ $agencyId }}">@endif
    </form>

    <form method="POST" action="{{ route('platform-esign.documents.store') }}" enctype="multipart/form-data" class="rounded-md p-6 space-y-5 max-w-4xl" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        <input type="hidden" name="template_id" value="{{ $tpl->id }}">

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="ds-label block mb-1">Agency this contract is for @if($usesFields)<span style="color: var(--ds-crimson);">*</span>@endif</label>
                <select name="agency_id" class="ds-field w-full" @required($usesFields)>
                    <option value="">{{ $usesFields ? 'Choose an agency…' : 'None' }}</option>
                    @foreach($agencies as $a)<option value="{{ $a->id }}" @selected($agencyId === $a->id)>{{ $a->name }}</option>@endforeach
                </select>
                @if($usesFields)<p class="text-xs mt-1" style="color: var(--text-muted);">The wording uses agency details ({{ implode(', ', $usesFields) }}) and is filled in from this agency.</p>
                @else<p class="text-xs mt-1" style="color: var(--text-muted);">Linking an agency lets its Agency Timeline tick “sign agreement” when this is signed.</p>@endif
            </div>
            <div><label class="ds-label block mb-1">Title <span style="color: var(--text-muted);">(optional)</span></label>
                <input name="title" value="{{ old('title') }}" maxlength="255" class="ds-field w-full" placeholder="{{ $tpl->name }}"></div>
        </div>

        <div>
            <div class="ds-section-header mb-2">Signers</div>
            <div class="space-y-3">
                @foreach($tpl->roles() as $i => $r)
                    <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <input type="hidden" name="signers[{{ $i }}][role_key]" value="{{ $r['key'] }}">
                        <div class="text-xs font-semibold mb-2" style="color: var(--text-primary);">{{ $r['order'] }}. {{ $r['label'] }}</div>
                        <div class="grid sm:grid-cols-3 gap-3">
                            <input name="signers[{{ $i }}][name]" required placeholder="Full name" value="{{ old("signers.$i.name") }}" class="ds-field w-full">
                            <input name="signers[{{ $i }}][email]" type="email" required placeholder="Email address" value="{{ old("signers.$i.email") }}" class="ds-field w-full">
                            <input name="signers[{{ $i }}][id_number]" placeholder="ID / passport (optional)" value="{{ old("signers.$i.id_number") }}" class="ds-field w-full">
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="text-xs mt-1" style="color: var(--text-muted);">A signer without an ID number on file is asked for it when they sign.</p>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="sequential" value="1" class="mt-1" @checked(old('sequential', true))>
                <span>Sign in order<span class="block text-xs" style="color: var(--text-muted);">Each signer is emailed only after the one before has signed.</span></span></label>
            <div><label class="ds-label block mb-1">Link valid for (days)</label><input type="number" name="expiry_days" min="1" max="90" value="{{ old('expiry_days', 14) }}" class="ds-field" style="width: 6rem;"></div>
        </div>

        <div>
            <label class="ds-label block mb-1">Attach supporting PDFs <span style="color: var(--text-muted);">(optional, up to 5)</span></label>
            <input type="file" name="attachments[]" accept="application/pdf" multiple class="ds-field w-full">
        </div>

        <div class="pt-4 flex items-center gap-2" style="border-top: 1px solid var(--border);">
            <button type="submit" class="corex-btn-primary">Send for signing</button>
            <a href="{{ route('platform-esign.hub') }}" class="corex-btn-outline">Cancel</a>
        </div>
    </form>
    @endif
</div>
@endsection
