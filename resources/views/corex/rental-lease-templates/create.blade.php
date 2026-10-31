@extends('layouts.corex')

@section('corex-content')
@include('corex.rentals.partials._page-styles')
<div class="rx-page w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Link a Lease Agreement</h1>
            <p class="text-xs" style="color: var(--text-muted);">Use one of your agency's own e-sign documents as a lease template.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('corex.rental-lease-templates.store') }}" class="max-w-lg space-y-4 rounded-md px-5 pb-5 pt-1" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        @if ($errors->any())
            <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-red, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-red, #dc2626) 30%, transparent); color: var(--text-primary);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="prop-label">Name</label>
            <input type="text" name="name" required placeholder="e.g. Residential Lease" class="prop-input">
        </div>

        <div>
            <label class="prop-label">Category</label>
            <select name="category" required class="prop-select">
                @foreach($categories as $c)
                    <option value="{{ $c }}">{{ ucfirst(str_replace('_', ' ', $c)) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="prop-label">Your lease agreement</label>
            <p class="text-xs mb-1" style="color: var(--text-muted);">Only your agency's own e-sign documents are listed.</p>
            <select name="docuperfect_template_id" required class="prop-select">
                <option value="">Select a document…</option>
                @foreach($availableTemplates as $t)
                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                @endforeach
            </select>
            @if($availableTemplates->isEmpty())
                <p class="text-xs mt-2" style="color: var(--ds-crimson);">Your agency has no e-sign documents yet — import your lease agreement with the e-sign Document Importer first.</p>
            @endif
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_default" value="1" @checked(old('is_default'))>
            Make this the default for its category
        </label>

        <button type="submit" class="corex-btn-primary text-xs">Check and link</button>
    </form>
</div>
@endsection
