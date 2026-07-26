@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — flat neutral) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Add Public Holiday</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('payroll.leave.public-holidays.index') }}" class="corex-btn-outline text-xs inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Public Holidays
                </a>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('payroll.leave.public-holidays.store') }}">
        @csrf
        @include('payroll.leave.public-holidays._form', ['holiday' => $holiday])
    </form>
</div>
@endsection
