{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <a href="{{ route('training.manage') }}" class="text-xs no-underline text-white/50">Training Management</a>
                    <span class="text-white/30">/</span>
                </div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $course->title }} — Completion Report</h1>
                <p class="text-sm text-white/60">Who has completed this course, who is outstanding, and when.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('training.edit-course', $course) }}" class="corex-btn-outline no-underline">Edit Course</a>
            </div>
        </div>
    </div>

    {{-- Search / filter --}}
    <form method="GET" action="{{ route('training.completions', $course) }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);">Search (name or email)</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Search learners..."
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);">Status</label>
            <select name="status" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="outstanding" {{ request('status') === 'outstanding' ? 'selected' : '' }}>Outstanding</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);">Completed from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);">Completed to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);">Per page</label>
            <select name="per_page" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                @foreach([10, 25, 50, 100] as $opt)
                <option value="{{ $opt }}" {{ (int) request('per_page', 25) === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Apply</button>
            @if(request()->hasAny(['q', 'status', 'date_from', 'date_to']))
            <a href="{{ route('training.completions', $course) }}" class="corex-btn-outline no-underline text-sm px-4 py-2">Clear</a>
            @endif
        </div>
    </form>

    @php
        $scopeLabel = ['own' => 'Your own record', 'branch' => 'Your branch', 'all' => 'Entire agency'][$scope] ?? $scope;
        $sortLink = fn (string $col) => route('training.completions', array_merge($course->getRouteKey() ? ['course' => $course] : [], request()->except('page'), [
            'sort' => $col,
            'direction' => request('sort') === $col && request('direction', 'asc') === 'asc' ? 'desc' : 'asc',
        ]));
    @endphp
    <div class="text-xs px-1" style="color: var(--text-muted);">Showing: {{ $scopeLabel }}</div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        @if($learners->isEmpty())
        <div class="py-12 px-6 text-center">
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
                {{ request()->hasAny(['q', 'status', 'date_from', 'date_to']) ? 'No learners match these filters' : 'No learners to report on yet' }}
            </h3>
            <p class="text-sm" style="color: var(--text-muted);">
                {{ request()->hasAny(['q', 'status', 'date_from', 'date_to']) ? 'Try clearing a filter or widening the date range.' : 'Once agents in scope exist, their completion status for this course will show here.' }}
            </p>
        </div>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2.5 font-semibold" style="color: var(--text-muted);"><a href="{{ $sortLink('name') }}" class="no-underline" style="color: inherit;">Learner</a></th>
                    <th class="text-left px-4 py-2.5 font-semibold" style="color: var(--text-muted);"><a href="{{ $sortLink('status') }}" class="no-underline" style="color: inherit;">Status</a></th>
                    <th class="text-left px-4 py-2.5 font-semibold" style="color: var(--text-muted);">Progress</th>
                    <th class="text-left px-4 py-2.5 font-semibold" style="color: var(--text-muted);"><a href="{{ $sortLink('completed_at') }}" class="no-underline" style="color: inherit;">Completed</a></th>
                </tr>
            </thead>
            <tbody>
                @foreach($learners as $learner)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2.5">
                        <div class="font-medium" style="color: var(--text-primary);">{{ $learner->name }}</div>
                        <div class="text-xs" style="color: var(--text-muted);">{{ $learner->email }}</div>
                    </td>
                    <td class="px-4 py-2.5">
                        @if($learner->tc_completed_at)
                        <span class="ds-badge" style="background: rgba(34,197,94,0.12); color: #22c55e;">Completed</span>
                        @else
                        <span class="ds-badge ds-badge-warning">Outstanding</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $learner->training_percent }}%</td>
                    <td class="px-4 py-2.5" style="color: var(--text-secondary);">
                        {{ $learner->tc_completed_at ? \Illuminate\Support\Carbon::parse($learner->tc_completed_at)->format('d M Y') : '—' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
            {{ $learners->onEachSide(1)->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
