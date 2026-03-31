<x-app-layout>
    <div class="max-w-7xl mx-auto p-6 space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold" style="color:var(--text-primary);">Fault Reports</h1>
                <p class="text-sm" style="color:var(--text-secondary);">System errors, warnings, and manual reports.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.fault-reports', ['status' => 'new']) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-medium {{ request('status') === 'new' ? 'bg-red-500/20 text-red-600 border border-red-500/30' : 'border hover:opacity-80' }}"
                   @unless(request('status') === 'new') style="color:var(--text-secondary); border-color:var(--border);" @endunless>
                    New
                </a>
                <a href="{{ route('admin.fault-reports', ['status' => 'investigating']) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-medium {{ request('status') === 'investigating' ? 'bg-amber-500/20 text-amber-600 border border-amber-500/30' : 'border hover:opacity-80' }}"
                   @unless(request('status') === 'investigating') style="color:var(--text-secondary); border-color:var(--border);" @endunless>
                    Investigating
                </a>
                <a href="{{ route('admin.fault-reports', ['status' => 'fixed']) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-medium {{ request('status') === 'fixed' ? 'bg-emerald-500/20 text-emerald-600 border border-emerald-500/30' : 'border hover:opacity-80' }}"
                   @unless(request('status') === 'fixed') style="color:var(--text-secondary); border-color:var(--border);" @endunless>
                    Fixed
                </a>
                <a href="{{ route('admin.fault-reports') }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-medium {{ !request('status') ? 'bg-blue-500/20 text-blue-600 border border-blue-500/30' : 'border hover:opacity-80' }}"
                   @unless(!request('status')) style="color:var(--text-secondary); border-color:var(--border);" @endunless>
                    All
                </a>
            </div>
        </div>

        @if($reports->isEmpty())
        <div class="rounded-xl p-12 text-center" style="border:1px solid var(--border); background:var(--surface);">
            <div class="text-lg" style="color:var(--text-muted);">No fault reports found.</div>
        </div>
        @else
        <div class="space-y-2">
            @foreach($reports as $report)
            <div class="rounded-xl p-4 transition-colors hover:opacity-95" style="border:1px solid var(--border); background:var(--surface);" x-data="{ expanded: false }">
                <div class="flex items-start gap-3 cursor-pointer" @click="expanded = !expanded">
                    {{-- Severity indicator --}}
                    <div class="mt-1 w-2 h-2 rounded-full flex-shrink-0
                        {{ $report->severity === 'error' ? 'bg-red-500' : ($report->severity === 'warning' ? 'bg-amber-500' : 'bg-blue-500') }}">
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-medium truncate max-w-[500px]" style="color:var(--text-primary);">{{ $report->title }}</span>
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase
                                {{ $report->type === 'backend' ? 'bg-purple-500/20 text-purple-600' : ($report->type === 'frontend' ? 'bg-cyan-500/20 text-cyan-600' : '') }}"
                                @if($report->type !== 'backend' && $report->type !== 'frontend') style="background:var(--surface-2); color:var(--text-secondary);" @endif>
                                {{ $report->type }}
                            </span>
                            @if($report->occurrence_count > 1)
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-orange-500/20 text-orange-600">
                                {{ $report->occurrence_count }}x
                            </span>
                            @endif
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase
                                {{ $report->status === 'new' ? 'bg-red-500/20 text-red-600' : ($report->status === 'investigating' ? 'bg-amber-500/20 text-amber-600' : ($report->status === 'fixed' ? 'bg-emerald-500/20 text-emerald-600' : '')) }}"
                                @if(!in_array($report->status, ['new', 'investigating', 'fixed'])) style="background:var(--surface-2); color:var(--text-muted);" @endif>
                                {{ $report->status }}
                            </span>
                        </div>
                        <div class="mt-1 flex items-center gap-3 text-[11px]" style="color:var(--text-muted);">
                            @if($report->file)
                            <span class="truncate max-w-[300px]">{{ basename($report->file) }}:{{ $report->line }}</span>
                            @endif
                            <span>{{ $report->last_seen_at?->diffForHumans() }}</span>
                            @if($report->url)
                            <span class="truncate max-w-[200px]">{{ $report->method }} {{ parse_url($report->url, PHP_URL_PATH) }}</span>
                            @endif
                        </div>
                    </div>

                    <a href="{{ route('admin.fault-reports.show', $report->id) }}" class="text-[11px] transition-colors px-2" style="color:var(--text-muted);" onmouseover="this.style.color='#3b82f6'" onmouseout="this.style.color=''" @click.stop>View</a>

                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                         class="w-4 h-4 transition-transform" style="color:var(--text-muted);" :class="expanded ? 'rotate-180' : ''">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>

                {{-- Expanded detail --}}
                <div x-show="expanded" x-cloak x-transition class="mt-3 pt-3 space-y-2" style="border-top:1px solid var(--border);">
                    @if($report->exception_class)
                    <div class="text-xs" style="color:var(--text-secondary);"><span style="color:var(--text-muted);">Class:</span> {{ $report->exception_class }}</div>
                    @endif
                    @if($report->file)
                    <div class="text-xs" style="color:var(--text-secondary);"><span style="color:var(--text-muted);">File:</span> {{ $report->file }}:{{ $report->line }}</div>
                    @endif
                    @if($report->message)
                    <div class="text-xs rounded-lg p-3 font-mono whitespace-pre-wrap break-all" style="color:var(--text-primary); background:var(--surface-2); border:1px solid var(--border);">{{ Str::limit($report->message, 1000) }}</div>
                    @endif
                    @if($report->trace)
                    <details class="text-xs">
                        <summary class="cursor-pointer hover:opacity-80" style="color:var(--text-muted);">Stack trace</summary>
                        <div class="mt-1 rounded-lg p-3 font-mono whitespace-pre-wrap break-all max-h-60 overflow-y-auto" style="color:var(--text-secondary); background:var(--surface-2); border:1px solid var(--border);">{{ Str::limit($report->trace, 3000) }}</div>
                    </details>
                    @endif
                    @if($report->request_data)
                    <details class="text-xs">
                        <summary class="cursor-pointer hover:opacity-80" style="color:var(--text-muted);">Request data</summary>
                        <div class="mt-1 rounded-lg p-3 font-mono whitespace-pre-wrap break-all max-h-40 overflow-y-auto" style="color:var(--text-secondary); background:var(--surface-2); border:1px solid var(--border);">{{ json_encode($report->request_data, JSON_PRETTY_PRINT) }}</div>
                    </details>
                    @endif
                    <div class="flex items-center gap-4 text-[11px]" style="color:var(--text-muted);">
                        <span>First seen: {{ $report->first_seen_at?->format('Y-m-d H:i') }}</span>
                        <span>Last seen: {{ $report->last_seen_at?->format('Y-m-d H:i') }}</span>
                        @if($report->user) <span>User: {{ $report->user->name }}</span> @endif
                        @if($report->ip_address) <span>IP: {{ $report->ip_address }}</span> @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $reports->withQueryString()->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
