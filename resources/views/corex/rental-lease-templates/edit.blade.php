@extends('layouts.corex')

@section('content')
<div class="p-6 max-w-3xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif

    <h1 class="text-lg font-semibold">Edit Rental Lease Agreement</h1>

    <form method="POST" action="{{ route('corex.rental-lease-templates.update', $rentalLeaseTemplate) }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @method('PUT')

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
            <input type="text" name="name" required value="{{ old('name', $rentalLeaseTemplate->name) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
        </div>

        <div>
            <label class="text-xs font-medium">Category</label>
            <select name="category" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                @foreach($categories as $c)
                    <option value="{{ $c }}" @selected(old('category', $rentalLeaseTemplate->category) === $c)>{{ ucfirst(str_replace('_', ' ', $c)) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="text-xs font-medium">Source document</label>
            <p class="text-sm mt-1">{{ $rentalLeaseTemplate->template?->name ?? '(deleted)' }}</p>
            <p class="text-xs mt-1" style="color: var(--text-muted);">To point this at a different document, archive this template and create a new one.</p>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rentalLeaseTemplate->is_active))>
            Active
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $rentalLeaseTemplate->is_default))>
            Make this the default for its category
        </label>

        @if($status)
            <div class="text-sm">
                @if($status['state'] === 'ready')
                    <span class="ds-badge ds-badge-success">Ready</span>
                @elseif($status['state'] === 'needs_map')
                    <span class="ds-badge ds-badge-warning">Needs field map</span>
                @else
                    <span class="ds-badge ds-badge-danger">Not usable</span>
                @endif
                @if(!empty($status['problems']))
                    <ul class="text-xs mt-2 list-disc pl-4" style="color: var(--text-muted);">
                        @foreach($status['problems'] as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <button type="submit" class="corex-btn-primary text-xs">Check and save</button>
    </form>

    @if($rentalLeaseTemplate->category === 'residential')
        @include('corex.rental-lease-templates._field-map')
    @endif
</div>
@endsection
