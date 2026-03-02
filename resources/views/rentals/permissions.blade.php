@extends('layouts.corex')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Rental Permissions</h2>
        <div class="text-sm text-white/60">Control which users can capture rentals.</div>
    </div>

    <form method="POST" action="{{ route('rentals.permissions.update') }}">
        @csrf

        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <h3 class="ds-section-header">User Permissions</h3>
                <button type="submit" class="corex-btn-primary text-sm">Save Permissions</button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
                            <th class="text-left px-4 py-3">User</th>
                            <th class="text-center px-4 py-3">Can Capture Rentals</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach($users as $user)
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                                <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $user->name }}</td>
                                <td class="px-4 py-3 text-center">
                                    <input type="checkbox"
                                           name="can_capture_rentals[]"
                                           value="{{ $user->id }}"
                                           {{ $user->can_capture_rentals ? 'checked' : '' }}
                                           class="rounded border-slate-300 dark:border-slate-700">
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
