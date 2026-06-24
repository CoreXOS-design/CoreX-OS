{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">

    <div class="rounded-md px-6 py-5" data-tour="cc-oversight-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Manager Oversight</h1>
                <p class="text-sm text-white/60">Outstanding items for the agents in your scope.</p>
            </div>
            <div class="flex items-center gap-2">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('corex.settings.user.oversight') }}" class="corex-btn-outline" data-tour="cc-oversight-settings">Oversight Settings</a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    <form method="GET" class="rounded-md p-4 flex flex-wrap gap-3 items-end" data-tour="cc-oversight-filters"
          style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex flex-col">
            <label for="filter-category" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Category</label>
            <select id="filter-category" name="category" onchange="this.form.submit()" data-tour="cc-oversight-category"
                    class="list-header-filter rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat }}" @selected($filters['category'] === $cat)>{{ ucwords(str_replace('_', ' ', $cat)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col">
            <label for="filter-agent" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
            <select id="filter-agent" name="agent_id" onchange="this.form.submit()" data-tour="cc-oversight-agent"
                    class="list-header-filter rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All agents</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected($filters['agent_id'] === $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary">Apply</button>
            @if(!empty($filters['category']) || !empty($filters['agent_id']))
                <a href="{{ route('corex.dashboard.oversight') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</a>
            @endif
        </div>
        <div class="ml-auto text-xs" style="color: var(--text-muted);">
            Showing {{ number_format($rows->count()) }} {{ Str::plural('item', $rows->count()) }}
        </div>
    </form>

    @if($rows->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">All clear</h3>
            <p class="text-sm" style="color: var(--text-muted);">Nothing outstanding for the agents in your scope.</p>
        </div>
    @else
        <div class="rounded-md overflow-hidden" data-tour="cc-oversight-table" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Category</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Item</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Age</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Severity</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="transition-colors" style="border-top: 1px solid var(--border);" x-data="{ openNudge: false }">
                                <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $row['agent_name'] }}</td>
                                <td class="px-4 py-3" style="color: var(--text-secondary);">{{ ucwords(str_replace('_', ' ', $row['category'])) }}</td>
                                <td class="px-4 py-3" style="color: var(--text-primary);">{{ $row['summary'] }}</td>
                                <td class="px-4 py-3" style="color: var(--text-muted);">{{ number_format($row['age_hours']) }}h</td>
                                <td class="px-4 py-3">
                                    @if($row['severity'] === 'high')
                                        <span class="ds-badge ds-badge-warning">High</span>
                                    @else
                                        <span class="ds-badge ds-badge-info">Medium</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if($canManage)
                                        <button type="button" @click="openNudge = true" class="text-xs font-semibold" style="color: var(--brand-icon);">Nudge</button>

                                        <div x-show="openNudge" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="openNudge = false" @keydown.escape.window="openNudge = false">
                                            <form method="POST" action="{{ route('corex.dashboard.oversight.nudge') }}"
                                                  class="rounded-md p-6 w-full max-w-md"
                                                  style="background: var(--surface); border: 1px solid var(--border);">
                                                @csrf
                                                <input type="hidden" name="to_user_id" value="{{ $row['agent_id'] }}">
                                                <input type="hidden" name="category" value="{{ $row['category'] }}">
                                                <input type="hidden" name="subject_type" value="{{ $row['subject_type'] }}">
                                                <input type="hidden" name="subject_id" value="{{ $row['subject_id'] }}">
                                                <h3 class="text-lg font-semibold mb-1" style="color: var(--text-primary);">Nudge {{ $row['agent_name'] }}</h3>
                                                <p class="text-xs mb-3" style="color: var(--text-muted);">{{ $row['summary'] }}</p>
                                                <label for="nudge-message-{{ $row['agent_id'] }}-{{ $loop->index }}" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Message</label>
                                                <textarea id="nudge-message-{{ $row['agent_id'] }}-{{ $loop->index }}" name="message" rows="4" required
                                                          class="w-full rounded-md px-3 py-2 text-sm"
                                                          style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">Hi {{ $row['agent_name'] }}, please action this {{ ucwords(str_replace('_', ' ', $row['category'])) }}: {{ $row['summary'] }}</textarea>
                                                <div class="mt-4 flex justify-end gap-2">
                                                    <button type="button" @click="openNudge = false" class="corex-btn-outline">Cancel</button>
                                                    <button type="submit" class="corex-btn-primary">Send Nudge</button>
                                                </div>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
@endsection
