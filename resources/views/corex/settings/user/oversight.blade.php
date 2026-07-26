@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6 max-w-5xl mx-auto">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Oversight Preferences</h1>
                <p class="text-xs" style="color: var(--text-muted);">Choose when and how you want to be alerted about each oversight category.</p>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div class="flex-1">
            Threshold is measured in hours — for example, <strong>24</strong> means alert once an item has been outstanding for 24 hours.
        </div>
    </div>

    <form method="POST" action="{{ route('corex.settings.user.oversight.save') }}">
        @csrf

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Category</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Enabled</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Threshold (hours)</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Notify via</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($prefs as $i => $p)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3 capitalize" style="color: var(--text-primary);">
                                    {{ str_replace('_', ' ', $p['category']) }}
                                </td>
                                <td class="px-4 py-3">
                                    <input type="hidden" name="preferences[{{ $i }}][category]" value="{{ $p['category'] }}">
                                    <input type="hidden" name="preferences[{{ $i }}][enabled]" value="0">
                                    <label class="inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="preferences[{{ $i }}][enabled]" value="1" @checked($p['enabled'])
                                               class="rounded"
                                               style="accent-color: var(--brand-button);">
                                    </label>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0" max="8760"
                                           name="preferences[{{ $i }}][threshold_hours]"
                                           value="{{ $p['threshold_hours'] }}"
                                           class="w-28 rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                </td>
                                <td class="px-4 py-3">
                                    <select name="preferences[{{ $i }}][notify_channel]"
                                            class="rounded-md px-3 py-2 text-sm"
                                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                        <option value="in_app" @selected($p['notify_channel'] === 'in_app')>In-app</option>
                                        <option value="email" @selected($p['notify_channel'] === 'email')>Email</option>
                                        <option value="both" @selected($p['notify_channel'] === 'both')>Both</option>
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                    No oversight categories available.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4 flex justify-end">
            <button type="submit" class="corex-btn-primary">Save Preferences</button>
        </div>
    </form>

</div>
@endsection
