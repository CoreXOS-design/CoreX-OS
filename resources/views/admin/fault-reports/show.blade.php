{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('admin.fault-reports') }}" class="text-white/60 hover:text-white transition-colors flex-shrink-0" title="Back to Fault Reports">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </a>
                <div class="min-w-0">
                    <h1 class="text-xl font-bold text-white leading-tight">Fault Report #{{ $report->id }}</h1>
                    <p class="text-sm text-white/60 truncate">{{ $report->exception_class ?? 'System fault detail' }}</p>
                </div>
            </div>
        </div>
    </div>

    @if(session('success'))
    {{-- Success alert --}}
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        <div class="flex-1">{{ session('success') }}</div>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {{-- LEFT: Detail --}}
        <div class="lg:col-span-2 space-y-4">
            {{-- Title + badges --}}
            <div class="rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-lg font-semibold break-all" style="color: var(--text-primary);">{{ $report->title }}</h2>

                <div class="flex items-center gap-2 flex-wrap">
                    @php
                        $severityBadge = match($report->severity) {
                            'error' => 'ds-badge ds-badge-danger',
                            'warning' => 'ds-badge ds-badge-warning',
                            default => 'ds-badge ds-badge-info',
                        };
                        $typeClass = match($report->type) {
                            'backend' => 'ds-badge ds-badge-info',
                            'frontend' => 'ds-badge',
                            default => 'ds-badge ds-badge-default',
                        };
                        $typeStyle = $report->type === 'frontend'
                            ? 'background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);'
                            : '';
                        $statusBadge = match($report->status) {
                            'new' => 'ds-badge ds-badge-info',
                            'investigating' => 'ds-badge ds-badge-warning',
                            'fixed' => 'ds-badge ds-badge-success',
                            default => 'ds-badge ds-badge-default',
                        };
                        $statusLabel = $report->status === 'ignored' ? "won't fix" : $report->status;
                    @endphp
                    <span class="{{ $severityBadge }}">{{ $report->severity }}</span>
                    <span class="{{ $typeClass }}" @if($typeStyle) style="{{ $typeStyle }}" @endif>{{ $report->type }}</span>
                    <span class="{{ $statusBadge }}">{{ $statusLabel }}</span>
                    @if($report->occurrence_count > 1)
                        <span class="ds-badge ds-badge-warning">{{ number_format($report->occurrence_count) }}x</span>
                    @endif
                </div>

                @if($report->exception_class)
                <div class="text-xs" style="color: var(--text-secondary);"><span style="color: var(--text-muted);">Class:</span> <span class="font-mono">{{ $report->exception_class }}</span></div>
                @endif
                @if($report->file)
                <div class="text-xs" style="color: var(--text-secondary);"><span style="color: var(--text-muted);">File:</span> <span class="font-mono break-all">{{ $report->file }}:{{ $report->line }}</span></div>
                @endif
                @if($report->url)
                <div class="text-xs" style="color: var(--text-secondary);"><span style="color: var(--text-muted);">URL:</span> <span class="font-mono break-all">{{ $report->method }} {{ $report->url }}</span></div>
                @endif
                @if($report->ip_address)
                <div class="text-xs" style="color: var(--text-secondary);"><span style="color: var(--text-muted);">IP:</span> {{ $report->ip_address }}</div>
                @endif
            </div>

            {{-- Message --}}
            @if($report->message)
            <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xs uppercase tracking-wider mb-2" style="color: var(--text-muted);">Message</div>
                <div class="rounded-md p-4 font-mono text-sm whitespace-pre-wrap break-all"
                     style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ $report->message }}</div>
            </div>
            @endif

            {{-- Stack trace --}}
            @if($report->trace)
            <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xs uppercase tracking-wider mb-2" style="color: var(--text-muted);">Stack Trace</div>
                <pre class="rounded-md p-4 font-mono text-xs whitespace-pre-wrap break-all max-h-[400px] overflow-y-auto"
                     style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">{{ $report->trace }}</pre>
            </div>
            @endif

            {{-- Request data --}}
            @if($report->request_data)
            <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xs uppercase tracking-wider mb-2" style="color: var(--text-muted);">Request Data</div>
                <pre class="rounded-md p-4 font-mono text-xs whitespace-pre-wrap break-all max-h-[300px] overflow-y-auto"
                     style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">{{ json_encode($report->request_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            @endif
        </div>

        {{-- RIGHT: Actions + Meta --}}
        <div class="space-y-4">
            {{-- Status update --}}
            <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xs uppercase tracking-wider mb-3" style="color: var(--text-muted);">Status</div>
                <form method="POST" action="{{ route('admin.fault-reports.update-status', $report->id) }}">
                    @csrf
                    <select name="status" class="w-full rounded-md px-3 py-2 text-sm mb-3"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="new" {{ $report->status === 'new' ? 'selected' : '' }}>New</option>
                        <option value="investigating" {{ $report->status === 'investigating' ? 'selected' : '' }}>Investigating</option>
                        <option value="fixed" {{ $report->status === 'fixed' ? 'selected' : '' }}>Fixed</option>
                        <option value="ignored" {{ $report->status === 'ignored' ? 'selected' : '' }}>Won't Fix</option>
                    </select>
                    <button type="submit" class="corex-btn-primary w-full justify-center">Update Status</button>
                </form>
            </div>

            {{-- Notes --}}
            <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xs uppercase tracking-wider mb-3" style="color: var(--text-muted);">Notes</div>
                <form method="POST" action="{{ route('admin.fault-reports.update-status', $report->id) }}">
                    @csrf
                    <textarea name="notes" rows="4"
                              class="w-full rounded-md px-3 py-2 text-sm mb-3"
                              style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                              placeholder="Internal notes...">{{ $report->notes }}</textarea>
                    <button type="submit" class="corex-btn-outline w-full justify-center">Save Notes</button>
                </form>
            </div>

            {{-- Meta --}}
            <div class="rounded-md p-5 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xs uppercase tracking-wider mb-3" style="color: var(--text-muted);">Details</div>
                <div class="flex justify-between text-xs">
                    <span style="color: var(--text-muted);">Occurrences</span>
                    <span class="font-semibold" style="color: var(--text-primary);">{{ number_format($report->occurrence_count) }}</span>
                </div>
                <div class="flex justify-between text-xs">
                    <span style="color: var(--text-muted);">First seen</span>
                    <span style="color: var(--text-secondary);">{{ $report->first_seen_at?->format('Y-m-d H:i') }}</span>
                </div>
                <div class="flex justify-between text-xs">
                    <span style="color: var(--text-muted);">Last seen</span>
                    <span style="color: var(--text-secondary);">{{ $report->last_seen_at?->format('Y-m-d H:i') }}</span>
                </div>
                @if($report->user)
                <div class="flex justify-between text-xs">
                    <span style="color: var(--text-muted);">User</span>
                    <span style="color: var(--text-secondary);">{{ $report->user->name }}</span>
                </div>
                @endif
                @if($report->resolvedBy)
                <div class="flex justify-between text-xs">
                    <span style="color: var(--text-muted);">Resolved by</span>
                    <span style="color: var(--text-secondary);">{{ $report->resolvedBy->name }}</span>
                </div>
                @endif
                @if($report->resolved_at)
                <div class="flex justify-between text-xs">
                    <span style="color: var(--text-muted);">Resolved at</span>
                    <span style="color: var(--text-secondary);">{{ $report->resolved_at->format('Y-m-d H:i') }}</span>
                </div>
                @endif
                @if($report->user_agent)
                <div class="pt-2" style="border-top: 1px solid var(--border);">
                    <div class="text-[10px] break-all" style="color: var(--text-muted);">{{ $report->user_agent }}</div>
                </div>
                @endif
            </div>

            {{-- Copy Full Report --}}
            <div x-data="{ copied: false }">
                <button type="button" @click="
                    var text = 'FAULT REPORT #{{ $report->id }}\n' +
                        'Type: {{ $report->type }} | Severity: {{ $report->severity }}\n' +
                        'Title: {{ addslashes($report->title) }}\n' +
                        'File: {{ addslashes($report->file ?? 'N/A') }}:{{ $report->line ?? 'N/A' }}\n' +
                        'Occurrences: {{ $report->occurrence_count }}\n' +
                        'First: {{ $report->first_seen_at?->format('Y-m-d H:i') }} | Last: {{ $report->last_seen_at?->format('Y-m-d H:i') }}\n' +
                        'URL: {{ $report->method }} {{ addslashes($report->url ?? 'N/A') }}\n' +
                        'User: {{ addslashes($report->user?->name ?? 'N/A') }} (#{{ $report->user_id ?? 'N/A' }})\n' +
                        'IP: {{ $report->ip_address ?? 'N/A' }}\n\n' +
                        'Message:\n{{ addslashes($report->message ?? 'N/A') }}\n\n' +
                        'Trace:\n{{ addslashes($report->trace ?? 'N/A') }}\n\n' +
                        'Request:\n{{ addslashes($report->request_data ? json_encode($report->request_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'N/A') }}';
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    copied = true;
                    setTimeout(function(){ copied = false; }, 2000);
                "
                class="corex-btn-outline w-full justify-center">
                    <span x-show="!copied">Copy Full Report</span>
                    <span x-show="copied" x-cloak style="color: var(--ds-green);">Copied!</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
