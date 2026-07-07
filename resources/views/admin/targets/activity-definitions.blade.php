{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@push('head')
    <style>
        .acty-input {
            width: 100%;
            padding: 0.5rem 0.75rem;
            font-size: 0.8125rem;
            border-radius: 6px;
            background: var(--surface-2, #f0f2f8);
            border: 1px solid var(--border);
            color: var(--text-primary);
            transition: all 300ms;
        }
        .acty-input:focus {
            outline: none;
            border-color: var(--brand-button, #0ea5e9);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button, #0ea5e9) 15%, transparent);
        }
        .acty-input-sm {
            padding: 0.375rem 0.625rem;
        }
        .acty-num {
            text-align: right;
        }
        .acty-check {
            width: 1rem;
            height: 1rem;
            accent-color: var(--brand-button, #0ea5e9);
            cursor: pointer;
        }
        .acty-defs-table tbody tr { transition: background-color 150ms ease; }
        .acty-defs-table tbody tr:hover td { background: var(--surface-2); }

        /* Column-control widths own their sizing here (higher specificity than
           .acty-input's width:100%) so the compact table inputs render at a fixed
           width regardless of stylesheet load order. Tailwind w-* utilities are
           overridden by .acty-input because the pushed <style> loads after Vite. */
        .acty-defs-table td .acty-num { width: 6rem; }
        .acty-defs-table td select.acty-input { width: 8rem; }
    </style>
@endpush

@section('corex-content')
    <div class="w-full space-y-5">

        {{-- Page Header (Pattern A: branded) --}}
        <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight">Activity Definitions</h1>
                    <p class="text-sm text-white/60">Define activities, weights, and scoring modes.</p>
                </div>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-green);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <div class="flex-1">{{ session('status') }}</div>
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-crimson);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                <div class="flex-1">{{ $errors->first() }}</div>
            </div>
        @endif

        {{-- Add New Activity --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Add New Activity</h2>
            </div>

            <div class="px-5 py-4">
                <form method="POST" action="{{ route('admin.targets.activity.definitions.save') }}" class="space-y-4">
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div class="sm:col-span-2">
                            <label for="acty-name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                            <input id="acty-name" name="name" required placeholder="Appointments" class="acty-input" />
                        </div>

                        <div>
                            <label for="acty-weight" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Weight</label>
                            <input id="acty-weight" name="weight" type="number" step="0.01" min="0" value="1" class="acty-input acty-num" />
                        </div>

                        <div>
                            <label for="acty-order" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Order</label>
                            <input id="acty-order" name="sort_order" type="number" min="0" value="100" class="acty-input acty-num" />
                        </div>

                        <div>
                            <label for="acty-scoring" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Scoring</label>
                            <select id="acty-scoring" name="scoring_mode" class="acty-input">
                                <option value="count" selected>Per action</option>
                                <option value="once">Once (tick)</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-4 pt-1">
                        <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                            <input type="checkbox" name="is_enabled" value="1" class="acty-check" checked>
                            Active
                        </label>
                        <button class="corex-btn-primary">Add Activity</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Existing Definitions --}}
        {{-- Per-row forms live outside the table; inputs associate via the HTML5 form= attribute.
             A <form> cannot be a valid child of <tr> — the parser foster-parents it out of the table. --}}
        @foreach($definitions as $d)
            <form id="acty-def-{{ $d->id }}" method="POST" action="{{ route('admin.targets.activity.definitions.save') }}" class="hidden">
                @csrf
                <input type="hidden" name="id" value="{{ $d->id }}">
            </form>
        @endforeach

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Existing Definitions</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table acty-defs-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-28" style="color: var(--text-muted);">Weight</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-28" style="color: var(--text-muted);">Order</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-36" style="color: var(--text-muted);">Scoring</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-24" style="color: var(--text-muted);">Enabled</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-24" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($definitions as $d)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3">
                                    <input form="acty-def-{{ $d->id }}" name="name" value="{{ $d->name }}" class="acty-input acty-input-sm">
                                </td>

                                <td class="px-4 py-3">
                                    <input form="acty-def-{{ $d->id }}" name="weight" type="number" step="0.01" min="0"
                                           value="{{ number_format((float)$d->weight, 2, '.', '') }}"
                                           class="acty-input acty-input-sm acty-num">
                                </td>

                                <td class="px-4 py-3">
                                    <input form="acty-def-{{ $d->id }}" name="sort_order" type="number" min="0"
                                           value="{{ (int)$d->sort_order }}"
                                           class="acty-input acty-input-sm acty-num">
                                </td>

                                <td class="px-4 py-3">
                                    @php($sm = (string)($d->scoring_mode ?? 'count'))
                                    <select form="acty-def-{{ $d->id }}" name="scoring_mode" class="acty-input acty-input-sm">
                                        <option value="count" @selected($sm === 'count')>Per action</option>
                                        <option value="once" @selected($sm === 'once')>Once (tick)</option>
                                    </select>
                                </td>

                                <td class="px-4 py-3">
                                    <input form="acty-def-{{ $d->id }}" type="checkbox" name="is_enabled" value="1" class="acty-check" @checked((int)$d->is_enabled === 1)>
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <button form="acty-def-{{ $d->id }}" class="corex-btn-primary text-xs">Save</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                    No activity definitions yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
