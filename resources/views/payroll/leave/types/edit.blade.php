{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Leave Type" :back-route="route('payroll.leave.types.index')" back-label="Leave Types" :flush="true" />

    <div class="p-4 lg:p-6">
        <form method="POST" action="{{ route('payroll.leave.types.update', $type) }}">
            @csrf
            @method('PUT')
            @include('payroll.leave.types._form', ['type' => $type, 'locked' => $locked])
        </form>
    </div>
</div>
@endsection
