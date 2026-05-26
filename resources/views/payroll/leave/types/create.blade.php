@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Add Leave Type" :back-route="route('payroll.leave.types.index')" back-label="Leave Types" :flush="true" />

    <div class="p-4 lg:p-6">
        <form method="POST" action="{{ route('payroll.leave.types.store') }}">
            @csrf
            @include('payroll.leave.types._form', ['type' => $type, 'nextSort' => $nextSort])
        </form>
    </div>
</div>
@endsection
