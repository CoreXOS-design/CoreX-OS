@extends('layouts.corex')

@section('content')
<div class="p-6 max-w-lg mx-auto space-y-4">
    <h1 class="text-lg font-semibold">New Rental Lease Template</h1>

    <form method="POST" action="{{ route('corex.rental-lease-templates.store') }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        @if ($errors->any())
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="text-xs font-medium">Name</label>
            <input type="text" name="name" required placeholder="e.g. Residential Lease" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
        </div>

        <div>
            <label class="text-xs font-medium">Category</label>
            <select name="category" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                @foreach($categories as $c)
                    <option value="{{ $c }}">{{ ucfirst(str_replace('_', ' ', $c)) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="text-xs font-medium">Source document</label>
            <p class="text-xs mb-1" style="color: var(--text-muted);">Pick the imported e-sign document this template maps to.</p>
            <select name="docuperfect_template_id" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="">Select a document…</option>
                @foreach($availableTemplates as $t)
                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                @endforeach
            </select>
            @if($availableTemplates->isEmpty())
                <p class="text-xs mt-2" style="color: var(--ds-crimson);">No imported documents found — import one via the e-sign Document Importer first.</p>
            @endif
        </div>

        <button type="submit" class="corex-btn-primary text-xs">Save</button>
    </form>
</div>
@endsection
