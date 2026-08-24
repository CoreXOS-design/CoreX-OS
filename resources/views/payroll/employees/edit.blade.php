{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (flat neutral bar — AT-336) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit Employee Profile</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('payroll.employees.show', $employee) }}" class="corex-btn-outline text-xs">&larr; {{ $employee->user->name }}</a>
            </div>
        </div>
    </div>

    <div>
        <form method="POST" action="{{ route('payroll.employees.update', $employee) }}">
            @csrf
            @method('PUT')

            <div class="max-w-2xl space-y-5">
                {{-- Employee header (read-only) --}}
                <div class="flex items-center gap-3 p-3" style="background:var(--surface); border:1px solid var(--border); border-radius:8px;">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white" style="background:var(--brand-icon);">
                        {{ strtoupper(substr($employee->user->name ?? '?', 0, 1)) }}
                    </div>
                    <div>
                        <p class="text-sm font-semibold" style="color:var(--text-primary);">{{ $employee->user->name }}</p>
                        <p class="text-[11px]" style="color:var(--text-muted);">{{ $employee->user->email }}</p>
                    </div>
                </div>

                <div class="p-4 space-y-4" style="background:var(--surface); border:1px solid var(--border); border-radius:8px;">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Employment Date <span class="text-red-500">*</span></label>
                        <input type="date" name="employment_date" value="{{ old('employment_date', $employee->employment_date?->format('Y-m-d')) }}" required
                               class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:6px;">
                        @error('employment_date') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Designation <span class="text-red-500">*</span></label>
                        <input type="text" name="designation_snapshot" value="{{ old('designation_snapshot', $employee->designation_snapshot) }}" required maxlength="100"
                               class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:6px;">
                        @error('designation_snapshot') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Date of Birth</label>
                        <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $employee->user->date_of_birth?->format('Y-m-d')) }}"
                               class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:6px;">
                        @error('date_of_birth') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Tax Reference Number</label>
                        <input type="text" name="tax_reference_number" value="{{ old('tax_reference_number', $employee->user->tax_reference_number) }}" maxlength="20"
                               class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:6px;">
                        @error('tax_reference_number') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pay Day of Month <span class="text-red-500">*</span></label>
                        <input type="number" name="pay_day_of_month" value="{{ old('pay_day_of_month', $employee->pay_day_of_month) }}" required min="1" max="31"
                               class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:6px;">
                        @error('pay_day_of_month') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Notes</label>
                    <textarea name="notes" rows="2" maxlength="2000"
                              class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:6px;">{{ old('notes', $employee->notes) }}</textarea>
                </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary text-sm">Update Profile</button>
                    <a href="{{ route('payroll.employees.show', $employee) }}" class="corex-btn-outline text-sm">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
