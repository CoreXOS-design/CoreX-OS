<x-app-layout>
    <div class="max-w-7xl mx-auto p-6 space-y-6">
        {{-- Header --}}
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.fault-reports') }}" class="text-white/40 hover:text-white/70 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </a>
            <h1 class="text-2xl font-bold text-white">Fault Report #{{ $report->id }}</h1>
        </div>

        @if(session('success'))
        <div class="rounded-lg bg-emerald-500/10 border border-emerald-500/30 px-4 py-3 text-sm text-emerald-400">
            {{ session('success') }}
        </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- LEFT: Detail --}}
            <div class="lg:col-span-2 space-y-4">
                {{-- Title + badges --}}
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5 space-y-3">
                    <div class="flex items-start gap-3 flex-wrap">
                        <h2 class="text-lg font-semibold text-white break-all">{{ $report->title }}</h2>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="px-2 py-0.5 rounded text-[11px] font-medium uppercase
                            {{ $report->severity === 'error' ? 'bg-red-500/20 text-red-400' : ($report->severity === 'warning' ? 'bg-amber-500/20 text-amber-400' : 'bg-blue-500/20 text-blue-400') }}">
                            {{ $report->severity }}
                        </span>
                        <span class="px-2 py-0.5 rounded text-[11px] font-medium uppercase
                            {{ $report->type === 'backend' ? 'bg-purple-500/20 text-purple-400' : ($report->type === 'frontend' ? 'bg-cyan-500/20 text-cyan-400' : 'bg-white/10 text-white/60') }}">
                            {{ $report->type }}
                        </span>
                        <span class="px-2 py-0.5 rounded text-[11px] font-medium uppercase
                            {{ $report->status === 'new' ? 'bg-red-500/20 text-red-400' : ($report->status === 'investigating' ? 'bg-amber-500/20 text-amber-400' : ($report->status === 'fixed' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/10 text-white/40')) }}">
                            {{ $report->status }}
                        </span>
                    </div>

                    @if($report->exception_class)
                    <div class="text-xs text-white/50"><span class="text-white/30">Class:</span> <span class="font-mono">{{ $report->exception_class }}</span></div>
                    @endif

                    @if($report->file)
                    <div class="text-xs text-white/50"><span class="text-white/30">File:</span> <span class="font-mono">{{ $report->file }}:{{ $report->line }}</span></div>
                    @endif

                    @if($report->url)
                    <div class="text-xs text-white/50"><span class="text-white/30">URL:</span> <span class="font-mono">{{ $report->method }} {{ $report->url }}</span></div>
                    @endif

                    @if($report->ip_address)
                    <div class="text-xs text-white/50"><span class="text-white/30">IP:</span> {{ $report->ip_address }}</div>
                    @endif
                </div>

                {{-- Message --}}
                @if($report->message)
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <div class="text-xs text-white/40 uppercase tracking-wider mb-2">Message</div>
                    <div class="bg-black/20 rounded-lg p-4 font-mono text-sm text-white/70 whitespace-pre-wrap break-all">{{ $report->message }}</div>
                </div>
                @endif

                {{-- Stack trace --}}
                @if($report->trace)
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <div class="text-xs text-white/40 uppercase tracking-wider mb-2">Stack Trace</div>
                    <pre class="bg-black/20 rounded-lg p-4 font-mono text-xs text-white/50 whitespace-pre-wrap break-all max-h-[400px] overflow-y-auto">{{ $report->trace }}</pre>
                </div>
                @endif

                {{-- Request data --}}
                @if($report->request_data)
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <div class="text-xs text-white/40 uppercase tracking-wider mb-2">Request Data</div>
                    <pre class="bg-black/20 rounded-lg p-4 font-mono text-xs text-white/50 whitespace-pre-wrap break-all max-h-[300px] overflow-y-auto">{{ json_encode($report->request_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
                @endif
            </div>

            {{-- RIGHT: Actions + Meta --}}
            <div class="space-y-4">
                {{-- Status update --}}
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <div class="text-xs text-white/40 uppercase tracking-wider mb-3">Status</div>
                    <form method="POST" action="{{ route('admin.fault-reports.update-status', $report->id) }}">
                        @csrf
                        <select name="status" class="w-full rounded-lg border border-white/10 bg-white/5 text-white text-sm px-3 py-2 mb-3">
                            <option value="new" {{ $report->status === 'new' ? 'selected' : '' }}>New</option>
                            <option value="investigating" {{ $report->status === 'investigating' ? 'selected' : '' }}>Investigating</option>
                            <option value="fixed" {{ $report->status === 'fixed' ? 'selected' : '' }}>Fixed</option>
                            <option value="ignored" {{ $report->status === 'ignored' ? 'selected' : '' }}>Ignored</option>
                        </select>
                        <button type="submit" class="w-full px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                            Update Status
                        </button>
                    </form>
                </div>

                {{-- Notes --}}
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <div class="text-xs text-white/40 uppercase tracking-wider mb-3">Notes</div>
                    <form method="POST" action="{{ route('admin.fault-reports.update-status', $report->id) }}">
                        @csrf
                        <textarea name="notes" rows="4"
                                  class="w-full rounded-lg border border-white/10 bg-white/5 text-white text-sm px-3 py-2 placeholder-white/30 mb-3"
                                  placeholder="Internal notes...">{{ $report->notes }}</textarea>
                        <button type="submit" class="w-full px-4 py-2 text-sm font-medium rounded-lg bg-white/10 text-white hover:bg-white/15 border border-white/10">
                            Save Notes
                        </button>
                    </form>
                </div>

                {{-- Meta --}}
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5 space-y-2">
                    <div class="text-xs text-white/40 uppercase tracking-wider mb-3">Details</div>
                    <div class="flex justify-between text-xs">
                        <span class="text-white/40">Occurrences</span>
                        <span class="text-white font-semibold">{{ $report->occurrence_count }}</span>
                    </div>
                    <div class="flex justify-between text-xs">
                        <span class="text-white/40">First seen</span>
                        <span class="text-white/70">{{ $report->first_seen_at?->format('Y-m-d H:i') }}</span>
                    </div>
                    <div class="flex justify-between text-xs">
                        <span class="text-white/40">Last seen</span>
                        <span class="text-white/70">{{ $report->last_seen_at?->format('Y-m-d H:i') }}</span>
                    </div>
                    @if($report->user)
                    <div class="flex justify-between text-xs">
                        <span class="text-white/40">User</span>
                        <span class="text-white/70">{{ $report->user->name }}</span>
                    </div>
                    @endif
                    @if($report->resolvedBy)
                    <div class="flex justify-between text-xs">
                        <span class="text-white/40">Resolved by</span>
                        <span class="text-white/70">{{ $report->resolvedBy->name }}</span>
                    </div>
                    @endif
                    @if($report->resolved_at)
                    <div class="flex justify-between text-xs">
                        <span class="text-white/40">Resolved at</span>
                        <span class="text-white/70">{{ $report->resolved_at->format('Y-m-d H:i') }}</span>
                    </div>
                    @endif
                    @if($report->user_agent)
                    <div class="pt-2 border-t border-white/10">
                        <div class="text-[10px] text-white/30 break-all">{{ $report->user_agent }}</div>
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
                    class="w-full px-4 py-2 text-sm font-medium rounded-lg border border-white/10 text-white/70 hover:text-white hover:bg-white/10 transition-colors">
                        <span x-show="!copied">Copy Full Report</span>
                        <span x-show="copied" x-cloak class="text-emerald-400">Copied!</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
