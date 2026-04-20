<x-app-layout>
    <div class="max-w-7xl mx-auto p-6 space-y-6" x-data="faultReports()">
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
                <a href="{{ route('admin.fault-reports', ['status' => 'ignored']) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-medium {{ request('status') === 'ignored' ? 'bg-gray-500/20 text-gray-600 border border-gray-500/30' : 'border hover:opacity-80' }}"
                   @unless(request('status') === 'ignored') style="color:var(--text-secondary); border-color:var(--border);" @endunless>
                    Won't Fix
                </a>
                <a href="{{ route('admin.fault-reports') }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-medium {{ !request('status') ? 'bg-blue-500/20 text-blue-600 border border-blue-500/30' : 'border hover:opacity-80' }}"
                   @unless(!request('status')) style="color:var(--text-secondary); border-color:var(--border);" @endunless>
                    All
                </a>
            </div>
        </div>

        {{-- Bulk action bar --}}
        <div x-show="selectedIds.length > 0" x-cloak x-transition
             class="sticky top-0 z-30 flex items-center justify-between gap-3 px-4 py-2.5 rounded-lg shadow-lg"
             style="background:#0f172a; color:#fff;">
            <span class="text-xs font-semibold"><span x-text="selectedIds.length"></span> selected</span>
            <div class="flex items-center gap-2">
                <input type="text" x-model="bulkNotes" placeholder="Resolution notes (optional)" class="px-2 py-1 text-xs rounded border-0" style="background:rgba(255,255,255,0.1); color:#fff; width:220px;">
                <button @click="bulkSubmit('fixed')" class="px-3 py-1 text-xs font-semibold rounded" style="background:#00d4aa; color:#0f172a;">Mark Fixed</button>
                <button @click="bulkSubmit('ignored')" class="px-3 py-1 text-xs font-semibold rounded" style="background:#64748b; color:#fff;">Won't Fix</button>
                <button @click="selectedIds = []; selectAll = false;" class="px-3 py-1 text-xs font-semibold rounded" style="background:rgba(255,255,255,0.1); color:#94a3b8;">Cancel</button>
            </div>
        </div>

        @if($reports->isEmpty())
        <div class="rounded-xl p-12 text-center" style="border:1px solid var(--border); background:var(--surface);">
            <div class="text-lg" style="color:var(--text-muted);">No fault reports found.</div>
        </div>
        @else

        {{-- Select all --}}
        <label class="flex items-center gap-2 text-xs cursor-pointer" style="color:var(--text-muted);">
            <input type="checkbox" x-model="selectAll" @change="toggleAll()" style="accent-color:#00d4aa;">
            Select all on this page
        </label>

        <div class="space-y-2">
            @foreach($reports as $report)
            <div class="rounded-xl p-4 transition-colors hover:opacity-95" style="border:1px solid var(--border); background:var(--surface);" x-data="{ expanded: false, acting: false, actionType: '' }">
                <div class="flex items-start gap-3">
                    {{-- Checkbox --}}
                    <input type="checkbox" value="{{ $report->id }}" class="mt-1.5 flex-shrink-0"
                           :checked="selectedIds.includes({{ $report->id }})"
                           @change="toggleId({{ $report->id }})"
                           style="accent-color:#00d4aa;">

                    {{-- Severity indicator --}}
                    <div class="mt-1.5 w-2 h-2 rounded-full flex-shrink-0
                        {{ $report->severity === 'error' ? 'bg-red-500' : ($report->severity === 'warning' ? 'bg-amber-500' : 'bg-blue-500') }}">
                    </div>

                    <div class="flex-1 min-w-0 cursor-pointer" @click="expanded = !expanded">
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
                                {{ $report->status === 'new' ? 'bg-red-500/20 text-red-600' : ($report->status === 'investigating' ? 'bg-amber-500/20 text-amber-600' : ($report->status === 'fixed' ? 'bg-emerald-500/20 text-emerald-600' : 'bg-gray-500/20 text-gray-500')) }}">
                                {{ $report->status === 'ignored' ? "won't fix" : $report->status }}
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
                            @if($report->resolvedBy)
                            <span style="color:#00d4aa;">Resolved by {{ $report->resolvedBy->name }} {{ $report->resolved_at?->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Row actions --}}
                    @if(in_array($report->status, ['new', 'investigating']))
                    <div class="flex items-center gap-1 flex-shrink-0" @click.stop>
                        @if($report->status === 'new')
                        <form method="POST" action="{{ route('admin.fault-reports.update-status', $report->id) }}">
                            @csrf
                            <input type="hidden" name="status" value="investigating">
                            <button type="submit" class="px-2 py-1 text-[10px] font-semibold rounded transition-colors" style="background:rgba(234,179,8,0.15); color:#eab308;">Investigating</button>
                        </form>
                        @endif
                        <button @click="acting = true; actionType = 'fixed'" class="px-2 py-1 text-[10px] font-semibold rounded transition-colors" style="background:rgba(0,212,170,0.15); color:#00d4aa;">Resolved</button>
                        <button @click="acting = true; actionType = 'ignored'" class="px-2 py-1 text-[10px] font-semibold rounded transition-colors" style="background:rgba(148,163,184,0.15); color:#94a3b8;">Won't Fix</button>
                    </div>
                    @endif

                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                         class="w-4 h-4 transition-transform cursor-pointer flex-shrink-0" style="color:var(--text-muted);" :class="expanded ? 'rotate-180' : ''" @click="expanded = !expanded">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>

                {{-- Inline action form --}}
                <div x-show="acting" x-cloak x-transition class="mt-3 pt-3 flex items-end gap-3" style="border-top:1px solid var(--border);" @click.stop>
                    <form method="POST" action="{{ route('admin.fault-reports.update-status', $report->id) }}" class="flex items-end gap-3 flex-1">
                        @csrf
                        <input type="hidden" name="status" :value="actionType">
                        <div class="flex-1">
                            <label class="block text-[10px] font-semibold mb-1" style="color:var(--text-muted);">
                                Resolution notes <span x-show="actionType === 'ignored'" class="text-red-500">*</span>
                            </label>
                            <textarea name="notes" rows="2" class="w-full px-2 py-1.5 text-xs rounded border" style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary);" :required="actionType === 'ignored'"></textarea>
                        </div>
                        <button type="submit" class="px-3 py-1.5 text-xs font-semibold rounded" style="background:#00d4aa; color:#0f172a;">Confirm</button>
                        <button type="button" @click="acting = false" class="px-3 py-1.5 text-xs font-semibold rounded" style="background:var(--surface-2); color:var(--text-muted);">Cancel</button>
                    </form>
                </div>

                {{-- Expanded detail --}}
                <div x-show="expanded" x-cloak x-transition class="mt-3 pt-3 space-y-2" style="border-top:1px solid var(--border);">
                    @if($report->exception_class)
                    <div class="text-xs" style="color:var(--text-secondary);"><span style="color:var(--text-muted);">Class:</span> {{ $report->exception_class }}</div>
                    @endif
                    @if($report->file)
                    <div class="text-xs" style="color:var(--text-secondary);"><span style="color:var(--text-muted);">File:</span> {{ $report->file }}:{{ $report->line }}</div>
                    @endif
                    @if($report->notes)
                    <div class="text-xs px-3 py-2 rounded" style="background:rgba(0,212,170,0.06); border:1px solid rgba(0,212,170,0.15); color:var(--text-primary);">
                        <span style="color:var(--text-muted);">Notes:</span> {{ $report->notes }}
                    </div>
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

    <script>
    function faultReports() {
        return {
            selectedIds: [],
            selectAll: false,
            bulkNotes: '',

            toggleId(id) {
                const idx = this.selectedIds.indexOf(id);
                if (idx >= 0) this.selectedIds.splice(idx, 1);
                else this.selectedIds.push(id);
            },

            toggleAll() {
                if (this.selectAll) {
                    this.selectedIds = @json($reports->pluck('id'));
                } else {
                    this.selectedIds = [];
                }
            },

            async bulkSubmit(action) {
                if (this.selectedIds.length === 0) return;
                if (this.selectedIds.length > 50) {
                    alert('Maximum 50 items per bulk action.');
                    return;
                }

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '{{ route("admin.fault-reports.bulk") }}';
                form.style.display = 'none';

                const csrf = document.createElement('input');
                csrf.name = '_token';
                csrf.value = document.querySelector('meta[name="csrf-token"]').content;
                form.appendChild(csrf);

                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = action;
                form.appendChild(actionInput);

                this.selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.name = 'ids[]';
                    input.value = id;
                    form.appendChild(input);
                });

                if (this.bulkNotes) {
                    const notesInput = document.createElement('input');
                    notesInput.name = 'notes';
                    notesInput.value = this.bulkNotes;
                    form.appendChild(notesInput);
                }

                document.body.appendChild(form);
                form.submit();
            }
        };
    }
    </script>
</x-app-layout>
