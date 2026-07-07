@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@push('head')
<style>
    .pipeline-action-btn { color: var(--text-muted); transition: color 150ms ease; }
    .pipeline-action-btn:hover { color: var(--brand-icon); }
    .pipeline-action-btn.is-danger:hover { color: var(--ds-crimson); }
</style>
@endpush

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded banner) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Pipeline Setup</h1>
                <p class="text-sm text-white/60">Define the steps deals follow through each stage of your pipeline.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <form method="POST" action="{{ route('deals-v2.pipeline.load-defaults') }}" class="inline">
                    @csrf
                    <button type="submit" class="corex-btn-outline corex-btn-on-brand inline-flex items-center gap-2"
                            title="Add the standard Bond / Cash / Sale-of-2nd templates for your agency (idempotent — never overwrites your own templates).">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        Load standard templates
                    </button>
                </form>
                <a href="{{ route('deals-v2.pipeline.create') }}" class="corex-btn-primary inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Template
                </a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            <div class="flex-1">{{ session('error') }}</div>
        </div>
    @endif

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('deals-v2.pipeline.index') }}" class="flex flex-wrap items-center gap-3">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search templates..."
                       onchange="this.form.submit()"
                       class="list-header-filter w-full"
                       style="padding-left: 2.25rem;">
            </div>

            <select name="deal_type" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All Types</option>
                <option value="bond" {{ request('deal_type') === 'bond' ? 'selected' : '' }}>Bond Sale</option>
                <option value="cash" {{ request('deal_type') === 'cash' ? 'selected' : '' }}>Cash Sale</option>
                <option value="sale_of_2nd" {{ request('deal_type') === 'sale_of_2nd' ? 'selected' : '' }}>Sale of 2nd</option>
            </select>

            @if(request('search') || request('deal_type'))
                <a href="{{ route('deals-v2.pipeline.index') }}" class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">Clear</a>
            @endif

            <span class="text-xs ml-auto" style="color: var(--text-muted);">
                Showing {{ number_format($templates->count()) }} of {{ number_format($templates->total()) }}
            </span>
        </form>
    </div>

    @if($templates->isEmpty() && !request()->hasAny(['search', 'deal_type']))
        {{-- Empty state --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No pipeline templates yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Load the standard South African conveyancing templates to get going instantly, or build your own from scratch. You can customise the standard templates afterwards.</p>
            <div class="flex items-center justify-center gap-3 flex-wrap">
                <form method="POST" action="{{ route('deals-v2.pipeline.load-defaults') }}" class="inline">
                    @csrf
                    <button type="submit" class="corex-btn-primary">Load standard templates</button>
                </form>
                <a href="{{ route('deals-v2.pipeline.create') }}" class="corex-btn-outline">+ New Template</a>
            </div>
            <p class="text-xs mt-3" style="color: var(--text-muted);">Adds Standard Bond Sale (15 steps), Cash Sale (9), and Sale of Second Property (16).</p>
        </div>
    @else
        {{-- Templates table --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deal Type</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Steps</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Default</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Active</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-32" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($templates as $tpl)
                            @php
                                $badgeVariant = match($tpl->deal_type) {
                                    'bond' => 'ds-badge-info',
                                    'cash' => 'ds-badge-success',
                                    'sale_of_2nd' => 'ds-badge-warning',
                                    default => 'ds-badge-default',
                                };
                                $labels = ['bond' => 'Bond', 'cash' => 'Cash', 'sale_of_2nd' => 'Sale of 2nd'];
                            @endphp
                            <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">
                                    <a href="{{ route('deals-v2.pipeline.edit', $tpl) }}" class="hover:underline">{{ $tpl->name }}</a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="ds-badge {{ $badgeVariant }}">{{ $labels[$tpl->deal_type] ?? $tpl->deal_type }}</span>
                                </td>
                                <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $tpl->branch?->name ?? 'All Branches' }}</td>
                                <td class="px-4 py-3 text-center font-mono" style="color: var(--text-secondary);">{{ number_format($tpl->steps_count) }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if($tpl->is_default)
                                        <svg class="w-4 h-4 mx-auto" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                    @else
                                        <span style="color: var(--text-muted);">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <form method="POST" action="{{ route('deals-v2.pipeline.update', $tpl) }}" class="inline">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="name" value="{{ $tpl->name }}">
                                        <input type="hidden" name="deal_type" value="{{ $tpl->deal_type }}">
                                        <input type="hidden" name="branch_id" value="{{ $tpl->branch_id }}">
                                        <input type="hidden" name="is_default" value="{{ $tpl->is_default ? '1' : '0' }}">
                                        <input type="hidden" name="is_active" value="{{ $tpl->is_active ? '0' : '1' }}">
                                        <button type="submit" class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                                                style="background: {{ $tpl->is_active ? 'var(--brand-button)' : 'var(--surface-2)' }}; border: 1px solid {{ $tpl->is_active ? 'transparent' : 'var(--border)' }};">
                                            <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform {{ $tpl->is_active ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('deals-v2.pipeline.edit', $tpl) }}" class="pipeline-action-btn p-1 rounded-md" title="Edit">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                                        </a>
                                        <form method="POST" action="{{ route('deals-v2.pipeline.duplicate', $tpl) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="pipeline-action-btn p-1 rounded-md" title="Duplicate">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0"/></svg>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('deals-v2.pipeline.destroy', $tpl) }}" class="inline" onsubmit="return confirm('Archive this template?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="pipeline-action-btn is-danger p-1 rounded-md" title="Archive">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                    No templates match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($templates->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                    {{ $templates->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
