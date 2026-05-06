@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Deduction Type" :back-route="route('payroll.deduction-types.index')" back-label="Deduction Types" :flush="true" />

    <div class="p-4 lg:p-6">
        <form method="POST" action="{{ route('payroll.deduction-types.update', $type) }}">
            @csrf
            @method('PUT')
            @include('payroll.deduction-types._form', ['type' => $type, 'locked' => $locked])
        </form>
    </div>
</div>
@endsection
