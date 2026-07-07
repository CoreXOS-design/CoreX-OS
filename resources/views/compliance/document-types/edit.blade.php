{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Document Type" :back-route="route('compliance.document-types.index')" back-label="Document Types" :flush="true" />

    <div class="p-4 lg:p-6">
        <form method="POST" action="{{ route('compliance.document-types.update', $type) }}">
            @csrf
            @method('PUT')
            @include('compliance.document-types._form', ['type' => $type])
        </form>
    </div>
</div>
@endsection
