@extends('layouts.corex')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Rental Permissions</h2>
        <div class="text-sm text-white/60">Control which users can capture rentals.</div>
    </div>

    <form method="POST" action="{{ route('rentals.permissions.update') }}">
        @csrf

        <div class="rounded-2xl border overflow-hidden" style="background:var(--surface); border-color:var(--border)">
            <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
                <h3 class="ds-section-header">User Permissions</h3>
                <button type="submit" class="corex-btn-primary text-sm">Save Permissions</button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr class="border-b" style="color:var(--text-secondary); background:var(--surface-2); border-color:var(--border)">
                            <th class="text-left px-4 py-3">User</th>
                            <th class="text-center px-4 py-3">Can Capture Rentals</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                        @foreach($users as $user)
                            <tr class="hover:bg-[var(--surface-2)]">
                                <td class="px-4 py-3 font-medium" style="color:var(--text-primary)">{{ $user->name }}</td>
                                <td class="px-4 py-3 text-center">
                                    <input type="checkbox"
                                           name="can_capture_rentals[]"
                                           value="{{ $user->id }}"
                                           {{ $user->can_capture_rentals ? 'checked' : '' }}
                                           class="rounded"
                                           style="border-color:var(--border)">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </form>

</div>
@endsection
