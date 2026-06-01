<x-app-layout>
    <div class="max-w-7xl mx-auto p-4 lg:p-6 space-y-6" x-data="faultReports()">
        {{-- Page header (Pattern A — branded) --}}
        <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight">Fault Reports</h1>
                    <p class="text-sm text-white/60">System errors, warnings, and manual reports.</p>
                </div>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('admin.fault-reports.scan') }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline"
                                style="padding: 0.375rem 0.875rem; font-size: 0.75rem; background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.25);">
                            Scan for Faults
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.fault-reports.clear-all') }}"
                          onsubmit="return confirm('Clear ALL fault reports? They will be soft-deleted and can be restored from the database.');">
                        @csrf
                        <button type="submit" class="corex-btn-outline"
                                style="padding: 0.375rem 0.875rem; font-size: 0.75rem; background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.25);">
                            Clear All
                        </button>
                    </form>
                    <span class="text-xs text-white/70 ml-2">
                        Showing {{ number_format($reports->count()) }} of {{ number_format($reports->total()) }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Status filters --}}
        <div class="flex flex-wrap items-center gap-2">
            @php
                $statusFilters = [
                    ['key' => null,            'label' => 'All',           'active' => !request('status')],
                    ['key' => 'new',           'label' => 'New',           'active' => request('status') === 'new'],
                    ['key' => 'investigating', 'label' => 'Investigating', 'active' => request('status') === 'investigating'],
                    ['key' => 'fixed',         'label' => 'Fixed',         'active' => request('status') === 'fixed'],
                    ['key' => 'ignored',       'label' => "Won't Fix",     'active' => request('status') === 'ignored'],
                ];
            @endphp
            @foreach($statusFilters as $f)
                <a href="{{ $f['key'] ? route('admin.fault-reports', ['status' => $f['key']]) : route('admin.fault-reports') }}"
                   class="px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                   @if($f['active'])
                       style="background: color-mix(in srgb, var(--brand-button) 15%, transparent); color: var(--brand-button); border: 1px solid color-mix(in srgb, var(--brand-button) 30%, transparent);"
                   @else
                       style="background: var(--surface); color: var(--text-secondary); border: 1px solid var(--border);"
                   @endif>
                    {{ $f['label'] }}
                </a>
            @endforeach
        </div>

        {{-- Bulk action bar --}}
        <div x-show="selectedIds.length > 0" x-cloak x-transition
             class="sticky top-0 z-30 flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 rounded-md"
             style="background: var(--brand-default, #0b2a4a); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <span class="text-xs font-semibold"><span x-text="selectedIds.length"></span> selected</span>
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" x-model="bulkNotes" placeholder="Resolution notes (optional)"
                       class="rounded-md px-3 py-1.5 text-xs"
                       style="background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.15); width: 220px;">
                <button @click="bulkSubmit('fixed')" class="corex-btn-primary" style="padding: 0.375rem 0.75rem; font-size: 0.75rem;">Mark Fixed</button>
                <button @click="bulkSubmit('ignored')" class="corex-btn-outline" style="padding: 0.375rem 0.75rem; font-size: 0.75rem; background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);">Won't Fix</button>
                <button @click="selectedIds = []; selectAll = false;" class="corex-btn-outline" style="padding: 0.375rem 0.75rem; font-size: 0.75rem; background: transparent; color: rgba(255,255,255,0.7); border-color: rgba(255,255,255,0.2);">Cancel</button>
            </div>
        </div>

        @if($reports->isEmpty())
            {{-- Empty state --}}
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No fault reports found</h3>
                <p class="text-sm" style="color: var(--text-muted);">Nothing to investigate right now. Errors and warnings will appear here as they happen.</p>
            </div>
        @else

        {{-- Select all --}}
        <label class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--text-muted);">
            <input type="checkbox" x-model="selectAll" @change="toggleAll()" style="accent-color: var(--brand-button);">
            Select all on this page
        </label>

        <div class="space-y-2">
            @foreach($reports as $report)
            <div class="rounded-md p-4 transition-colors" style="border: 1px solid var(--border); background: var(--surface);" x-data="{ expanded: false, acting: false, actionType: '' }">
                <div class="flex items-start gap-3">
                    {{-- Checkbox --}}
                    <input type="checkbox" value="{{ $report->id }}" class="mt-1.5 flex-shrink-0"
                           :checked="selectedIds.includes({{ $report->id }})"
                           @change="toggleId({{ $report->id }})"
                           style="accent-color: var(--brand-button);">

                    {{-- Severity indicator --}}
                    @php
                        $severityColor = match($report->severity) {
                            'error' => 'var(--ds-crimson)',
                            'warning' => 'var(--ds-amber)',
                            default => 'var(--brand-icon)',
                        };
                    @endphp
                    <div class="mt-1.5 w-2 h-2 rounded-full flex-shrink-0" style="background: {{ $severityColor }};"></div>

                    <div class="flex-1 min-w-0 cursor-pointer" @click="expanded = !expanded">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-medium truncate max-w-[500px]" style="color: var(--text-primary);">{{ $report->title }}</span>

                            {{-- Type badge --}}
                            @php
                                $typeClass = match($report->type) {
                                    'backend' => 'ds-badge ds-badge-info',
                                    'frontend' => 'ds-badge',
                                    default => 'ds-badge ds-badge-default',
                                };
                                $typeStyle = $report->type === 'frontend'
                                    ? 'background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);'
                                    : '';
                            @endphp
                            <span class="{{ $typeClass }}" @if($typeStyle) style="{{ $typeStyle }}" @endif>
                                {{ $report->type }}
                            </span>

                            @if($report->occurrence_count > 1)
                                <span class="ds-badge ds-badge-warning">{{ number_format($report->occurrence_count) }}x</span>
                            @endif

                            {{-- Status badge --}}
                            @php
                                $statusBadge = match($report->status) {
                                    'new' => 'ds-badge ds-badge-info',
                                    'investigating' => 'ds-badge ds-badge-warning',
                                    'fixed' => 'ds-badge ds-badge-success',
                                    default => 'ds-badge ds-badge-default',
                                };
                                $statusLabel = $report->status === 'ignored' ? "won't fix" : $report->status;
                            @endphp
                            <span class="{{ $statusBadge }}">{{ $statusLabel }}</span>
                        </div>
                        <div class="mt-1 flex items-center gap-3 text-xs flex-wrap" style="color: var(--text-muted);">
                            @if($report->file)
                                <span class="truncate max-w-[300px]">{{ basename($report->file) }}:{{ $report->line }}</span>
                            @endif
                            <span>{{ $report->last_seen_at?->diffForHumans() }}</span>
                            @if($report->url)
                                <span class="truncate max-w-[200px]">{{ $report->method }} {{ parse_url($report->url, PHP_URL_PATH) }}</span>
                            @endif
                            @if($report->resolvedBy)
                                <span style="color: var(--ds-green);">Resolved by {{ $report->resolvedBy->name }} {{ $report->resolved_at?->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Row actions --}}
                    @if(in_array($report->status, ['new', 'investigating']))
                    <div class="grid grid-cols-3 gap-2 flex-shrink-0" @click.stop>
                        @if($report->status === 'new')
                        <form method="POST" action="{{ route('admin.fault-reports.update-status', $report->id) }}" class="contents">
                            @csrf
                            <input type="hidden" name="status" value="investigating">
                            <button type="submit" class="corex-btn-outline w-28 justify-center"
                                    style="color: var(--ds-amber); border-color: color-mix(in srgb, var(--ds-amber) 40%, transparent);">Investigating</button>
                        </form>
                        @else
                        <span class="w-28"></span>
                        @endif
                        <button @click="acting = true; actionType = 'fixed'"
                                class="corex-btn-outline w-28 justify-center"
                                style="color: var(--ds-green); border-color: color-mix(in srgb, var(--ds-green) 40%, transparent);">Resolved</button>
                        <button @click="acting = true; actionType = 'ignored'"
                                class="corex-btn-outline w-28 justify-center">Won't Fix</button>
                    </div>
                    @endif

                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                         class="w-4 h-4 transition-transform cursor-pointer flex-shrink-0" style="color: var(--text-muted);" :class="expanded ? 'rotate-180' : ''" @click="expanded = !expanded">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>

                {{-- Inline action form --}}
                <div x-show="acting" x-cloak x-transition class="mt-3 pt-3 flex flex-col md:flex-row md:items-end gap-3" style="border-top: 1px solid var(--border);" @click.stop>
                    <form method="POST" action="{{ route('admin.fault-reports.update-status', $report->id) }}" class="flex flex-col md:flex-row md:items-end gap-3 flex-1">
                        @csrf
                        <input type="hidden" name="status" :value="actionType">
                        <div class="flex-1">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                                Resolution notes <span x-show="actionType === 'ignored'" style="color: var(--ds-crimson);">*</span>
                            </label>
                            <textarea name="notes" rows="2"
                                      class="w-full rounded-md px-3 py-2 text-sm"
                                      style="border: 1px solid var(--border); background: var(--surface); color: var(--text-primary);"
                                      :required="actionType === 'ignored'"></textarea>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="corex-btn-primary">Confirm</button>
                            <button type="button" @click="acting = false" class="corex-btn-outline">Cancel</button>
                        </div>
                    </form>
                </div>

                {{-- Expanded detail --}}
                <div x-show="expanded" x-cloak x-transition class="mt-3 pt-3 space-y-2" style="border-top: 1px solid var(--border);">
                    @if($report->exception_class)
                        <div class="text-xs" style="color: var(--text-secondary);"><span style="color: var(--text-muted);">Class:</span> {{ $report->exception_class }}</div>
                    @endif
                    @if($report->file)
                        <div class="text-xs" style="color: var(--text-secondary);"><span style="color: var(--text-muted);">File:</span> {{ $report->file }}:{{ $report->line }}</div>
                    @endif
                    @if($report->notes)
                        <div class="text-xs rounded-md px-3 py-2"
                             style="background: color-mix(in srgb, var(--ds-green) 8%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 25%, transparent); color: var(--text-primary);">
                            <span style="color: var(--text-muted);">Notes:</span> {{ $report->notes }}
                        </div>
                    @endif
                    @if($report->message)
                        <div class="text-xs rounded-md p-3 font-mono whitespace-pre-wrap break-all"
                             style="color: var(--text-primary); background: var(--surface-2); border: 1px solid var(--border);">{{ Str::limit($report->message, 1000) }}</div>
                    @endif
                    @if($report->trace)
                        <details class="text-xs">
                            <summary class="cursor-pointer hover:opacity-80" style="color: var(--text-muted);">Stack trace</summary>
                            <div class="mt-1 rounded-md p-3 font-mono whitespace-pre-wrap break-all max-h-60 overflow-y-auto"
                                 style="color: var(--text-secondary); background: var(--surface-2); border: 1px solid var(--border);">{{ Str::limit($report->trace, 3000) }}</div>
                        </details>
                    @endif
                    @if($report->request_data)
                        <details class="text-xs">
                            <summary class="cursor-pointer hover:opacity-80" style="color: var(--text-muted);">Request data</summary>
                            <div class="mt-1 rounded-md p-3 font-mono whitespace-pre-wrap break-all max-h-40 overflow-y-auto"
                                 style="color: var(--text-secondary); background: var(--surface-2); border: 1px solid var(--border);">{{ json_encode($report->request_data, JSON_PRETTY_PRINT) }}</div>
                        </details>
                    @endif
                    <div class="flex items-center gap-4 text-xs flex-wrap" style="color: var(--text-muted);">
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
                    if (window.showToast) {
                        window.showToast('Maximum 50 items per bulk action.', 'warning');
                    }
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
