@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Feedback Reports</h1>
        <div class="flex items-center gap-2">
            <a href="{{ route('command-center.feedback-reports.export', ['format' => 'markdown']) }}" class="text-xs px-2 py-1 rounded no-underline" style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">Export MD</a>
            <a href="{{ route('command-center.feedback-reports.export', ['format' => 'json']) }}" class="text-xs px-2 py-1 rounded no-underline" style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">Export JSON</a>
            <a href="{{ route('command-center.feedback-reports.export', ['format' => 'csv']) }}" class="text-xs px-2 py-1 rounded no-underline" style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">Export CSV</a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-2">
        @foreach(['new','reviewing','in_progress','fixed','wont_fix'] as $s)
            <a href="{{ route('command-center.feedback-reports', ['status' => $s]) }}" class="text-xs px-2 py-1 rounded no-underline {{ request('status') === $s ? 'text-white' : '' }}" style="{{ request('status') === $s ? 'background:var(--brand-button);color:#fff;' : 'background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);' }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</a>
        @endforeach
        <a href="{{ route('command-center.feedback-reports') }}" class="text-xs px-2 py-1 rounded no-underline" style="background:var(--surface-2);color:var(--text-muted);">All</a>
    </div>

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead><tr style="background:var(--surface-2);">
                <th class="text-left px-4 py-2 text-xs font-medium" style="color:var(--text-muted);">Date</th>
                <th class="text-left px-4 py-2 text-xs font-medium" style="color:var(--text-muted);">User</th>
                <th class="text-left px-4 py-2 text-xs font-medium" style="color:var(--text-muted);">Type</th>
                <th class="text-left px-4 py-2 text-xs font-medium" style="color:var(--text-muted);">Severity</th>
                <th class="text-left px-4 py-2 text-xs font-medium" style="color:var(--text-muted);">Title</th>
                <th class="text-left px-4 py-2 text-xs font-medium" style="color:var(--text-muted);">Module</th>
                <th class="text-left px-4 py-2 text-xs font-medium" style="color:var(--text-muted);">Status</th>
            </tr></thead>
            <tbody>
                @forelse($reports as $r)
                    @php $user = \App\Models\User::withoutGlobalScopes()->find($r->user_id); @endphp
                    <tr style="border-bottom:1px solid var(--border); cursor:pointer;" onclick="window.location='{{ route('command-center.feedback-reports.show', $r->id) }}'">
                        <td class="px-4 py-2 text-xs" style="color:var(--text-muted);">{{ \Carbon\Carbon::parse($r->submitted_at)->format('d M H:i') }}</td>
                        <td class="px-4 py-2 text-xs" style="color:var(--text-secondary);">{{ $user?->name ?? '?' }}</td>
                        <td class="px-4 py-2 text-xs" style="color:var(--text-primary);">{{ $r->type }}</td>
                        <td class="px-4 py-2 text-xs" style="color:{{ $r->severity === 'critical' ? '#ef4444' : ($r->severity === 'major' ? '#f59e0b' : 'var(--text-muted)') }};">{{ $r->severity ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs font-medium truncate max-w-[200px]" style="color:var(--text-primary);">{{ $r->title }}</td>
                        <td class="px-4 py-2 text-xs" style="color:var(--text-muted);">{{ $r->module_tag }}</td>
                        <td class="px-4 py-2"><span class="text-[10px] px-1.5 py-0.5 rounded font-medium" style="background:var(--surface-2);color:var(--text-primary);">{{ str_replace('_', ' ', $r->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No feedback reports yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3" style="border-top:1px solid var(--border);">{{ $reports->links() }}</div>
    </div>
</div>
@endsection
