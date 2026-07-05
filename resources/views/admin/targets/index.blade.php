{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@push('head')
    <style>
        .targets-input {
            width: 10rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 6px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text-primary);
            transition: all 300ms;
        }
        .targets-input:focus {
            outline: none;
            border-color: var(--brand-button);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent);
        }
        .targets-period-select {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.25);
            color: #fff;
        }
        .targets-period-select option { color: #000; }
    </style>
@endpush

@section('corex-content')
    <div class="w-full space-y-5">

        {{-- Page Header (Pattern A: branded) --}}
        <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight">Targets</h1>
                    <p class="text-sm text-white/60">
                        @if($isAdmin) Admin scope
                        @elseif($isBM) Branch Manager scope
                        @else Agent scope
                        @endif
                    </p>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    @if(!empty($isAdmin))
                        <a href="{{ route('admin.targets.activity.setup') }}" class="corex-btn-outline text-sm"
                           style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">Activity Setup</a>
                    @endif

                    @if($canEditTargets)
                        <form method="POST" action="{{ route('admin.targets.carry-forward') }}" class="inline" onsubmit="return confirm('Copy last month\'s targets to this month? Existing entries will not be overwritten.')">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-sm"
                                    style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">Copy Previous Month</button>
                        </form>
                    @endif

                    @if(!$isAgent)
                        <form method="GET" action="{{ route('admin.targets') }}" class="flex items-center gap-2">
                            <select name="period" class="targets-period-select rounded-md text-sm px-3 py-1.5 transition-all duration-300">
                                @foreach($periods as $p)
                                    <option value="{{ $p }}" {{ $p === $period ? 'selected' : '' }}>{{ $p }}</option>
                                @endforeach
                            </select>
                            <button class="corex-btn-primary">View</button>
                        </form>
                    @else
                        <div class="text-white/80 text-sm font-medium">{{ $period }}</div>
                    @endif
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

        {{-- MONTHLY TARGETS (Admin/BM only) --}}
        @if($canEditTargets)
            <form method="POST" action="{{ route('admin.targets.save') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}"/>

                <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="px-5 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Monthly Targets for {{ $period }}</h2>
                        <button class="corex-btn-primary">Save Targets</button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm ds-table">
                            <thead>
                                <tr style="background: var(--surface-2);">
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Monthly Points Target</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse($users as $u)
                                    @php
                                        $t = $targets[$u->id] ?? null;
                                        $branchName = $branchNames[$u->branch_id] ?? '-';
                                    @endphp

                                    <tr style="border-top: 1px solid var(--border);">
                                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">
                                            {{ $u->name }}
                                            <div class="text-xs mt-0.5 font-normal" style="color: var(--text-muted);">Branch: {{ $branchName }}</div>
                                        </td>

                                        <td class="px-4 py-3">
                                            <input type="number" min="0"
                                                   class="targets-input"
                                                   name="targets[{{ $u->id }}][points_target]"
                                                   value="{{ old('targets.'.$u->id.'.points_target', (int)($t->points_target ?? 0)) }}">
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                            No agents found in scope.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        @endif

    </div>
@endsection
