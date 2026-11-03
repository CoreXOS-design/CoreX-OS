{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Road to Sold 23.25 step 6 for a record with NO property file: nothing is made by opening the step. One line, and the one button that makes the file. --}}
@extends('layouts.corex-app')
@section('title', 'Presentation')

@section('corex-content')
<div class="mx-auto max-w-xl py-8 px-4 space-y-4" data-qa="pitch-create-property">
    <div>
        <h1 class="text-lg font-bold" style="color: var(--text-primary);" data-qa="step-heading">Presentation</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">{{ $address }}</p>
    </div>
    @if(session('error'))<div class="rounded-md px-4 py-3 text-sm" data-qa="step-problem" style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 35%, transparent); color: var(--text-primary);" role="alert">{{ session('error') }}</div>@endif
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <p class="text-sm font-semibold" style="color: var(--text-primary);" data-qa="create-property-line">Create the property file to prepare a presentation</p>
        <p class="text-xs" style="color: var(--text-muted);">This makes the property file from the address you are working on. It is not a listing and nothing is published.</p>
        <form method="POST" action="{{ route('road-to-sold.prospects.create-property', $prospect->id) }}">@csrf
            @foreach($carry as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <button type="submit" class="corex-btn-primary" data-qa="create-property">Create the property file</button>
        </form>
    </div>
</div>
@endsection
