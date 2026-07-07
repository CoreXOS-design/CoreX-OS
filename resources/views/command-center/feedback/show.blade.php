@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Feedback #{{ $report->id }}</h1>
        <a href="{{ route('command-center.feedback-reports') }}" class="text-xs px-3 py-1.5 rounded-md no-underline" style="background:var(--surface-2);color:var(--text-secondary);">Back to List</a>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background:color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border:1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color:var(--text-primary);">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="rounded-md p-5" style="background:var(--surface);border:1px solid var(--border);">
                @php
                    $severityBadge = ['critical' => 'ds-badge-danger', 'major' => 'ds-badge-warning', 'minor' => 'ds-badge-default'];
                @endphp
                <div class="flex items-center gap-2 mb-3">
                    <span class="ds-badge ds-badge-default">{{ ucfirst($report->type) }}</span>
                    @if($report->severity)
                        <span class="ds-badge {{ $severityBadge[$report->severity] ?? 'ds-badge-default' }}">{{ ucfirst($report->severity) }}</span>
                    @endif
                </div>
                <h2 class="text-lg font-semibold mb-2" style="color:var(--text-primary);">{{ $report->title }}</h2>
                <div class="text-sm whitespace-pre-wrap" style="color:var(--text-secondary);">{{ $report->description }}</div>
                @if($report->steps_to_reproduce)
                    <div class="mt-3 pt-3" style="border-top:1px solid var(--border);">
                        <h4 class="text-xs font-semibold mb-1" style="color:var(--text-muted);">Steps to Reproduce</h4>
                        <div class="text-xs whitespace-pre-wrap" style="color:var(--text-secondary);">{{ $report->steps_to_reproduce }}</div>
                    </div>
                @endif
                @if($report->expected_behaviour)
                    <div class="mt-2"><h4 class="text-xs font-semibold mb-1" style="color:var(--text-muted);">Expected</h4><div class="text-xs" style="color:var(--text-secondary);">{{ $report->expected_behaviour }}</div></div>
                @endif
                @if($report->actual_behaviour)
                    <div class="mt-2"><h4 class="text-xs font-semibold mb-1" style="color:var(--text-muted);">Actual</h4><div class="text-xs" style="color:var(--text-secondary);">{{ $report->actual_behaviour }}</div></div>
                @endif
            </div>

            {{-- Attachments --}}
            @if($attachments->isNotEmpty())
                <div class="rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
                    <h3 class="text-xs font-semibold mb-2" style="color:var(--text-muted);">Attachments</h3>
                    @foreach($attachments as $att)
                        <div class="text-xs" style="color:var(--text-secondary);">{{ $att->original_name }} ({{ number_format($att->size_bytes / 1024, 1) }} KB)</div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">
            <div class="rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
                <h3 class="text-xs font-semibold mb-3" style="color:var(--text-muted);">Context</h3>
                <div class="space-y-1 text-xs">
                    <div><span style="color:var(--text-muted);">By:</span> <span style="color:var(--text-primary);">{{ $submitter?->name ?? '?' }}</span></div>
                    <div><span style="color:var(--text-muted);">Submitted:</span> <span style="color:var(--text-primary);">{{ \Carbon\Carbon::parse($report->submitted_at)->format('d M Y H:i:s') }}</span></div>
                    <div><span style="color:var(--text-muted);">Page:</span> <span style="color:var(--text-primary);">{{ $report->page_title }}</span></div>
                    <div><span style="color:var(--text-muted);">URL:</span> <span class="break-all" style="color:var(--text-primary);">{{ $report->page_url }}</span></div>
                    <div><span style="color:var(--text-muted);">Module:</span> <span style="color:var(--text-primary);">{{ $report->module_tag }}</span></div>
                    <div><span style="color:var(--text-muted);">Browser:</span> <span style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($report->browser, 50) }}</span></div>
                    <div><span style="color:var(--text-muted);">Viewport:</span> <span style="color:var(--text-primary);">{{ $report->viewport_width }}×{{ $report->viewport_height }}</span></div>
                </div>
            </div>

            <div class="rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
                <h3 class="text-xs font-semibold mb-3" style="color:var(--text-muted);">Status</h3>
                <form method="POST" action="{{ route('command-center.feedback-reports.update-status', $report->id) }}" class="space-y-2">
                    @csrf
                    <select name="status" class="w-full rounded-md px-2 py-1.5 text-xs" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
                        @foreach(['new','reviewing','in_progress','fixed','wont_fix','duplicate','deferred'] as $s)
                            <option value="{{ $s }}" {{ $report->status === $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                        @endforeach
                    </select>
                    <textarea name="resolution_notes" placeholder="Resolution notes..." rows="2" class="w-full rounded-md px-2 py-1.5 text-xs" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">{{ $report->resolution_notes }}</textarea>
                    <button type="submit" class="corex-btn-primary corex-btn-xs">Update</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
