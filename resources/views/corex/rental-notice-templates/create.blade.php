@extends('layouts.corex')

@section('content')
<div class="p-6 max-w-3xl space-y-4">
    <h1 class="text-lg font-semibold">Add Notice Template</h1>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-notice-templates.store') }}" class="space-y-4">
        @csrf
        @include('corex.rental-notice-templates.partials._form')
        <button type="submit" class="corex-btn-primary text-sm">Save</button>
    </form>
</div>
@endsection
