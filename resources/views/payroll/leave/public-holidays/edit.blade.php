@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Public Holiday" :back-route="route('payroll.leave.public-holidays.index')" back-label="Public Holidays" :flush="true" />

    <div class="p-4 lg:p-6">
        <form method="POST" action="{{ route('payroll.leave.public-holidays.update', $holiday) }}">
            @csrf
            @method('PUT')
            @include('payroll.leave.public-holidays._form', ['holiday' => $holiday])
        </form>
    </div>
</div>
@endsection
