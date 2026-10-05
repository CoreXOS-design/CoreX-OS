{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — send a contract to an agency (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-2xl space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <a href="{{ route('admin.agency-contracts.index') }}" class="text-xs underline" style="color:var(--text-muted);">← Agency Contracts</a>
        <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">Send a contract</h1>
        <p class="text-xs" style="color: var(--text-muted);">The agency's details are merged into the template, frozen, and emailed to the signatory as a link to review and sign.</p>
    </div>
    @include('admin.partials.platform-flash')

    @if($templates->isEmpty())
        <div class="rounded-md p-6 text-sm" style="background: var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
            There is no active contract template yet. <a class="underline" style="color:var(--brand-icon);" href="{{ route('admin.agency-contracts.templates.create') }}">Create one first</a>.
        </div>
    @else
    <form method="POST" action="{{ route('admin.agency-contracts.store') }}" enctype="multipart/form-data" class="rounded-md p-5 space-y-4" style="background: var(--surface); border:1px solid var(--border);">
        @csrf
        <div><label class="ds-label block mb-1">Agency</label>
            <select name="agency_id" required class="ds-field w-full" onchange="if(this.value){window.location='{{ route('admin.agency-contracts.create') }}?agency_id='+this.value}">
                <option value="">Choose an agency…</option>
                @foreach($agencies as $a)<option value="{{ $a->id }}" @selected(old('agency_id', $agency?->id) == $a->id)>{{ $a->name }}</option>@endforeach
            </select>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Choosing an agency fills in the signatory below with its first Admin.</p></div>
        <div><label class="ds-label block mb-1">Contract template</label>
            <select name="template_id" required class="ds-field w-full">@foreach($templates as $t)<option value="{{ $t->id }}" @selected(old('template_id') == $t->id)>{{ $t->name }} (v{{ $t->version }})</option>@endforeach</select></div>
        <div><label class="ds-label block mb-1">Title <span style="color:var(--text-muted);">(optional — defaults to the template name)</span></label>
            <input name="title" value="{{ old('title') }}" maxlength="255" class="ds-field w-full"></div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="ds-label block mb-1">Signatory name</label><input name="signatory_name" required value="{{ old('signatory_name', $defaults['name']) }}" class="ds-field w-full"></div>
            <div><label class="ds-label block mb-1">Signatory email</label><input type="email" name="signatory_email" required value="{{ old('signatory_email', $defaults['email']) }}" class="ds-field w-full"></div>
            <div><label class="ds-label block mb-1">Signing as</label><input name="signatory_role" value="{{ old('signatory_role', 'Principal') }}" class="ds-field w-full"></div>
            <div><label class="ds-label block mb-1">Link valid for (days)</label><input type="number" name="expiry_days" min="1" max="90" required value="{{ old('expiry_days', 14) }}" class="ds-field w-full"></div>
        </div>
        <div><label class="ds-label block mb-1">Attach PDF(s) <span style="color:var(--text-muted);">(e.g. the debit order form — up to 5, 10 MB each)</span></label>
            <input type="file" name="attachments[]" accept="application/pdf" multiple class="text-sm"></div>
        <div class="flex items-center gap-3"><button class="corex-btn-primary text-sm" type="submit">Email contract</button>
            <a href="{{ route('admin.agency-contracts.index') }}" class="text-xs underline" style="color:var(--text-muted);">Cancel</a></div>
    </form>
    @endif
</div>
@endsection
