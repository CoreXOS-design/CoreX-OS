@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Add Document Type" :back-route="route('compliance.document-types.index')" back-label="Document Types" :flush="true" />

    <div class="p-4 lg:p-6">
        <form method="POST" action="{{ route('compliance.document-types.store') }}">
            @csrf
            @php $type = new \App\Models\Compliance\AgencyDocumentTypeConfig(); @endphp
            @include('compliance.document-types._form', ['type' => $type])
        </form>
    </div>
</div>
@endsection
