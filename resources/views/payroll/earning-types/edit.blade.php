@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Earning Type" :back-route="route('payroll.earning-types.index')" back-label="Earning Types" :flush="true" />

    <div class="p-4 lg:p-6">
        <form method="POST" action="{{ route('payroll.earning-types.update', $type) }}">
            @csrf
            @method('PUT')
            @include('payroll.earning-types._form', ['type' => $type, 'locked' => $locked])
        </form>
    </div>
</div>
@endsection
