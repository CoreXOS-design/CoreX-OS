@extends('layouts.corex')

@section('corex-content')
@include('corex.rentals.partials._page-styles')
<div class="rx-page w-full space-y-4">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
    <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit Notice Template</h1>
    </div>
    <div class="max-w-3xl space-y-5">


    @include('corex.notices._tabs', ['active' => 'templates'])

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-red, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-red, #dc2626) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-notice-templates.update', $template) }}" class="space-y-4 rounded-md px-5 pb-5 pt-1" style="background: var(--surface); border: 1px solid var(--border);" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('corex.rental-notice-templates.partials._form')
        <button type="submit" class="corex-btn-primary text-sm">Save</button>
    </form>
</div>
</div>
@endsection
