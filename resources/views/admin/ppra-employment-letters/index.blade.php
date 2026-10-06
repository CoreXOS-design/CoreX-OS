{{-- .ai/specs/ppra-ffc-employment-letter.md --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">PPRA FFC Employment Letters</h1>
            </div>
            @if(auth()->user()->hasPermission('ppra_employment_letters.manage'))
            <a href="{{ route('admin.ppra-employment-letters.create') }}" class="corex-btn-primary text-xs">New letter</a>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, #15803d 10%, transparent); color:#15803d; border:1px solid color-mix(in srgb, #15803d 25%, transparent);">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error') || $errors->has('signed_copy'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, #c41e3a 10%, transparent); color:#c41e3a; border:1px solid color-mix(in srgb, #c41e3a 25%, transparent);">
            {{ $errors->first('signed_copy') ?: session('error') }}
        </div>
    @endif

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Agent or principal name"
                   class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Status</label>
            <select name="status" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">All</option>
                @foreach($statuses as $s)
                <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ \App\Models\Compliance\PpraEmploymentLetter::statusLabel($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Branch</label>
            <select name="branch_id" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">All</option>
                @foreach($branches as $b)
                <option value="{{ $b->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Year</label>
            <select name="year" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">All</option>
                @foreach($years as $y)
                <option value="{{ $y }}" @selected((string) ($filters['year'] ?? '') === (string) $y)>{{ $y }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">From</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">To</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Sort</label>
            <select name="sort" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="created_desc" @selected(($filters['sort'] ?? 'created_desc') === 'created_desc')>Newest first</option>
                <option value="created_asc" @selected(($filters['sort'] ?? '') === 'created_asc')>Oldest first</option>
                <option value="agent_name" @selected(($filters['sort'] ?? '') === 'agent_name')>Agent name</option>
                <option value="status" @selected(($filters['sort'] ?? '') === 'status')>Status</option>
            </select>
        </div>
        <label class="flex items-center gap-1.5 text-xs" style="color:var(--text-muted);">
            <input type="checkbox" name="archived" value="1" @checked($showArchived)>
            Show archived
        </label>
        <button type="submit" class="corex-btn-primary text-xs">Filter</button>
    </form>

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        @if($letters->isEmpty())
            <div class="py-12 px-6 text-center">
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">
                    {{ request()->hasAny(['search','status','branch_id','year']) ? 'No letters match this filter' : ($showArchived ? 'No archived letters' : 'No letters yet') }}
                </h3>
                @if(request()->hasAny(['search','status','branch_id','year']))
                <p class="text-sm" style="color:var(--text-muted);">Try a different search or filter.</p>
                @elseif($showArchived)
                <p class="text-sm" style="color:var(--text-muted);">Nothing has been archived.</p>
                @else
                @if(auth()->user()->hasPermission('ppra_employment_letters.manage'))
                <a href="{{ route('admin.ppra-employment-letters.create') }}" class="corex-btn-primary text-xs inline-block mt-2">New letter</a>
                @endif
                @endif
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Agent</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Branch</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Principal</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Created</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Signed copy</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($letters as $letter)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-4 py-3" style="color:var(--text-primary);">{{ $letter->user?->name ?? '—' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $letter->branch?->name ?? '—' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $letter->principal?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent); color:var(--brand-icon,#0ea5e9);">
                                {{ \App\Models\Compliance\PpraEmploymentLetter::statusLabel($letter->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $letter->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3">
                            @include('compliance.ppra-employment-letters._signed-copies', [
                                'letter'        => $letter,
                                'uploadUrl'     => route('admin.ppra-employment-letters.upload', $letter->id),
                                'scanRouteName' => 'admin.ppra-employment-letters.signed-copy',
                                'showHistory'   => false,
                            ])
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.ppra-employment-letters.show', $letter->id) }}" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">View</a>
                            @if($letter->trashed())
                                <form method="POST" action="{{ route('admin.ppra-employment-letters.restore', $letter->id) }}" class="inline ml-2">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold" style="color:#15803d;">Restore</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.ppra-employment-letters.archive', $letter->id) }}" class="inline ml-2" onsubmit="return confirm('Archive this letter?');">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold" style="color:var(--ds-crimson,#c41e3a);">Archive</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3" style="border-top:1px solid var(--border);">
                {{ $letters->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
