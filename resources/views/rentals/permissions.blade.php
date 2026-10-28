@extends('layouts.corex')

@section('corex-content')
@include('corex.rentals.partials._page-styles')
<div class="rx-page w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner" data-tour="rops-perm-intro">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Permissions</h1>
                <p class="text-xs" style="color: var(--text-muted);">Control which users can capture rentals.</p>
            </div>
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
        </div>
    </div>

    <form method="POST" action="{{ route('rentals.permissions.update') }}">
        @csrf

        <div class="max-w-4xl rounded-md border overflow-hidden" style="background:var(--surface); border-color:var(--border)">
            <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
                <h3 class="text-sm font-semibold" style="color: var(--text-primary);">User permissions</h3>
                <button type="submit" class="corex-btn-primary text-xs" data-tour="rops-perm-save">Save Permissions</button>
            </div>

            <div class="overflow-x-auto" data-tour="rops-perm-table">
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
                                           @if($loop->first) data-tour="rops-perm-check" @endif
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
