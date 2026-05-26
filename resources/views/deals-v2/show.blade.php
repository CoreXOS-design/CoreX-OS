<x-app-layout>
    @php
        $statusStyle = match($deal->status) {
            'active' => 'background:rgba(59,130,246,0.15);color:#60a5fa;',
            'completed' => 'background:rgba(16,185,129,0.15);color:#34d399;',
            'cancelled' => 'background:rgba(239,68,68,0.15);color:#f87171;',
            'on_hold' => 'background:rgba(245,158,11,0.15);color:#fbbf24;',
            default => '',
        };
        $ragColor = match($deal->overall_rag) {
            'green' => '#22c55e', 'amber' => '#f59e0b', 'red' => '#ef4444', 'overdue' => '#dc2626', default => '#6b7280',
        };
        $daysInPipeline = $deal->offer_date ? (int) $deal->offer_date->diffInDays(now()) : 0;
    @endphp

    <div x-data="dealTracker()" x-cloak>
        {{-- Sticky header --}}
        <div class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('deals-v2.index') }}" class="inline-flex items-center gap-1 text-sm flex-shrink-0" style="color: var(--text-muted);">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <span class="flex-shrink-0" style="color: var(--border);">|</span>
                    <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">
                        <span class="font-mono">{{ $deal->reference }}</span>
                        <span class="hidden sm:inline"> — {{ Str::limit($deal->property->address ?? '', 35) }}</span>
                    </h1>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium capitalize" style="{{ $statusStyle }}">{{ str_replace('_', ' ', $deal->status) }}</span>
                    <span class="w-3 h-3 rounded-full inline-block {{ $deal->overall_rag === 'overdue' ? 'animate-pulse' : '' }}" style="background: {{ $ragColor }};"></span>
                    @if($canEdit)
                        <a href="{{ route('deals-v2.edit', $deal) }}" class="px-3 py-1 rounded-lg text-xs font-medium transition-colors" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);" {{ $deal->isFinanciallyLocked() ? 'title=Financial fields locked' : '' }}>Edit</a>
                    @endif
                </div>
            </div>
        </div>

        <div class="p-4 lg:p-6 max-w-5xl mx-auto space-y-6">
            {{-- Flash --}}
            @if(session('status'))
                <div class="p-3 rounded-lg text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">
                    {{ session('status') }}
                </div>
            @endif

            {{-- DEAL SUMMARY --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {{-- Property --}}
                <div class="rounded-xl p-4" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Property</div>
                    <div class="font-medium text-sm" style="color: var(--text-primary);">{{ $deal->property->address ?? '—' }}</div>
                </div>

                {{-- Contacts --}}
                <div class="rounded-xl p-4" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Contacts</div>
                    @foreach($deal->contacts as $c)
                        <div class="text-sm" style="color: var(--text-primary);">
                            {{ $c->full_name }} <span class="text-xs" style="color: var(--text-muted);">({{ $c->pivot->role }})</span>
                        </div>
                    @endforeach
                    @if($deal->contacts->isEmpty())
                        <div class="text-sm" style="color: var(--text-muted);">—</div>
                    @endif
                </div>

                {{-- Commission --}}
                <div class="rounded-xl p-4" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Commission</div>
                    <div class="text-sm font-mono" style="color: var(--text-primary);">R {{ number_format($deal->purchase_price, 0) }}</div>
                    <div class="text-xs font-mono" style="color: var(--text-muted);">
                        {{ $deal->commission_percentage ? number_format($deal->commission_percentage, 2) . '% = ' : '' }}R {{ number_format($deal->commission_amount, 2) }} + VAT
                    </div>
                    <div class="text-xs mt-1" style="color: var(--text-muted);">
                        Status: <span style="color: {{ $deal->commission_status === 'Paid' ? '#34d399' : 'var(--text-secondary)' }};">{{ $deal->commission_status ?? 'Not Paid' }}</span>
                    </div>
                    @if($canEdit)
                        <a href="{{ route('deals-v2.settlement.index', $deal) }}" class="inline-flex items-center gap-1 text-xs mt-2 px-2 py-1 rounded transition-colors" style="background: var(--surface-2); color: #2dd4bf; border: 1px solid var(--border);">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>
                            Settlement
                        </a>
                    @endif
                </div>

                {{-- Key Dates --}}
                <div class="rounded-xl p-4" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Key Dates</div>
                    <div class="text-sm" style="color: var(--text-primary);">Offer: {{ $deal->offer_date->format('d M Y') }}</div>
                    <div class="text-sm" style="color: var(--text-primary);">Exp. Reg: {{ $deal->expected_registration ? $deal->expected_registration->format('d M Y') : '—' }}</div>
                    <div class="text-xs" style="color: var(--text-muted);">{{ $daysInPipeline }} days in pipeline</div>
                </div>
            </div>

            {{-- PIPELINE TRACKER --}}
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Pipeline Tracker</h2>
                <div class="space-y-2">
                    @foreach($deal->stepInstances as $step)
                        @php
                            $isCompleted = $step->status === 'completed';
                            $isActive = $step->status === 'active';
                            $isSkipped = $step->status === 'skipped';
                            $isPending = $step->approval_status === 'pending';
                            $isOverdue = $isActive && $step->due_date && $step->due_date->isPast();
                            $daysLeft = $step->due_date ? (int) now()->startOfDay()->diffInDays($step->due_date->startOfDay(), false) : null;
                            $borderColor = match(true) {
                                $isOverdue => '#ef4444',
                                $isActive && $step->current_rag === 'red' => '#ef4444',
                                $isActive && $step->current_rag === 'amber' => '#f59e0b',
                                $isActive => '#22c55e',
                                $isPending => '#f59e0b',
                                default => 'var(--border)',
                            };
                        @endphp

                        <div class="rounded-xl overflow-hidden transition-all"
                             style="border: 1px solid {{ $borderColor }}; background: var(--surface); {{ $isCompleted ? 'opacity:0.7;' : '' }} {{ $isSkipped ? 'opacity:0.4;' : '' }}">

                            {{-- Collapsed header --}}
                            <div class="px-4 py-2.5 flex items-center gap-3 cursor-pointer" @click="toggleStep({{ $step->id }})">
                                {{-- Status icon --}}
                                @if($isCompleted)
                                    <svg class="w-5 h-5 flex-shrink-0" style="color: #34d399;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                @elseif($isSkipped)
                                    <svg class="w-5 h-5 flex-shrink-0" style="color: var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M6 18 18 6"/></svg>
                                @elseif($isActive)
                                    <span class="w-3 h-3 rounded-full flex-shrink-0 {{ $isOverdue ? 'animate-pulse' : '' }}" style="background: {{ $borderColor }};"></span>
                                @else
                                    <svg class="w-5 h-5 flex-shrink-0" style="color: var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                @endif

                                <span class="text-xs font-mono w-5 text-center flex-shrink-0" style="color: var(--text-muted);">{{ $step->position }}</span>
                                <span class="font-medium text-sm {{ $isSkipped ? 'line-through' : '' }}" style="color: var(--text-primary);">{{ $step->name }}</span>

                                @if($step->is_milestone)
                                    <span class="flex-shrink-0" style="color: #60a5fa;" title="Milestone">
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg>
                                    </span>
                                @endif

                                @if($isPending)
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium" style="background: rgba(245,158,11,0.15); color: #fbbf24;">Awaiting BM Approval</span>
                                @endif

                                <span class="ml-auto text-xs flex-shrink-0" style="color: var(--text-muted);">
                                    @if($isCompleted)
                                        {{ $step->completed_at ? $step->completed_at->format('d M Y') : '' }}
                                    @elseif($isActive && $step->due_date)
                                        @if($isOverdue)
                                            <span style="color: #ef4444; font-weight: 600;">OVERDUE {{ abs($daysLeft) }}d</span>
                                        @else
                                            Due: {{ $step->due_date->format('d M Y') }} ({{ $daysLeft }}d)
                                        @endif
                                    @elseif($step->status === 'not_started')
                                        Not started
                                    @endif
                                </span>
                            </div>

                            {{-- Expanded content --}}
                            <div x-show="expandedStep === {{ $step->id }}" x-transition style="border-top: 1px solid var(--border);">
                                <div class="px-4 py-4 space-y-3">

                                    {{-- Completed step details --}}
                                    @if($isCompleted)
                                        <div class="text-sm space-y-1">
                                            <div style="color: var(--text-muted);">Completed by {{ $step->completedBy->name ?? 'System' }} on {{ $step->completed_at ? $step->completed_at->format('d M Y H:i') : '—' }}</div>
                                            @if($step->completion_data)
                                                @if(!empty($step->completion_data['value']))
                                                    <div style="color: var(--text-primary);">Value: {{ $step->completion_data['value'] }}</div>
                                                @endif
                                                @if(!empty($step->completion_data['notes']))
                                                    <div style="color: var(--text-secondary);">Notes: {{ $step->completion_data['notes'] }}</div>
                                                @endif
                                            @endif
                                            @if($step->approval_status === 'approved')
                                                <div style="color: #34d399;">Approved by {{ $step->approvedBy->name ?? '—' }}{{ $step->approval_notes ? ' — ' . $step->approval_notes : '' }}</div>
                                            @endif
                                            @if($step->documents->count())
                                                <div class="flex flex-wrap gap-2 mt-2">
                                                    @foreach($step->documents as $doc)
                                                        <a href="{{ Storage::url($doc->file_path) }}" target="_blank" class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded" style="background: var(--surface-2); color: #2dd4bf;">
                                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/></svg>
                                                            {{ $doc->file_name ?? 'Document' }}
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if($step->status_trigger && $step->approval_status !== 'pending')
                                                <div class="text-xs mt-1" style="color: #2dd4bf;">Status trigger: Deal → {{ ucfirst($step->completion_data['outcome'] === 'negative' ? $step->negative_status_trigger : $step->status_trigger) }} ✓</div>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Pending approval (BM view) --}}
                                    @if($isPending)
                                        <div class="text-sm space-y-2">
                                            <div style="color: var(--text-muted);">Completed by {{ $step->completedBy->name ?? 'Agent' }} on {{ $step->completed_at ? $step->completed_at->format('d M Y H:i') : '' }}</div>
                                            @if(!empty($step->completion_data['notes']))
                                                <div style="color: var(--text-secondary);">Notes: {{ $step->completion_data['notes'] }}</div>
                                            @endif
                                            @php
                                                $pendingOutcome = $step->completion_data['outcome'] ?? 'positive';
                                                $pendingStatus = $pendingOutcome === 'negative' ? $step->negative_status_trigger : $step->status_trigger;
                                            @endphp
                                            <div class="text-xs px-2 py-1 rounded inline-block" style="background: rgba(245,158,11,0.1); color: #fbbf24;">
                                                Status change to "{{ ucfirst($pendingStatus) }}" pending approval
                                            </div>

                                            @if($canApprove)
                                                <form method="POST" action="{{ route('deals-v2.steps.approve', $step) }}" class="flex items-end gap-2 mt-2">
                                                    @csrf
                                                    <div class="flex-1">
                                                        <label class="block text-xs mb-1" style="color: var(--text-muted);">BM Notes (optional)</label>
                                                        <input type="text" name="notes" placeholder="Optional approval notes..."
                                                               class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                    </div>
                                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-green-600 hover:bg-green-500 text-white text-xs font-medium">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('deals-v2.steps.reject', $step) }}" class="flex items-end gap-2">
                                                    @csrf
                                                    <div class="flex-1">
                                                        <input type="text" name="reason" required placeholder="Reason for rejection..."
                                                               class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                    </div>
                                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-600 hover:bg-red-500 text-white text-xs font-medium">Reject</button>
                                                </form>
                                            @else
                                                <div class="text-xs" style="color: var(--text-muted);">Waiting for branch manager approval...</div>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Active step — completion form --}}
                                    @if($isActive && !$isPending && $canEdit && $deal->status === 'active')
                                        <form method="POST" action="{{ route('deals-v2.steps.complete', $step) }}" enctype="multipart/form-data" class="space-y-3">
                                            @csrf

                                            {{-- Dynamic input based on completion type --}}
                                            @if($step->completion_type === 'date_input')
                                                <div>
                                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Date</label>
                                                    <input type="date" name="value" required class="w-full md:w-1/2 rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                </div>
                                            @elseif($step->completion_type === 'amount_input')
                                                <div>
                                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Amount (R)</label>
                                                    <input type="number" name="value" step="0.01" min="0" required class="w-full md:w-1/2 rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                </div>
                                            @elseif($step->completion_type === 'text_input')
                                                <div>
                                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Details</label>
                                                    <input type="text" name="value" required maxlength="1000" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                </div>
                                            @elseif($step->completion_type === 'document_upload')
                                                <div>
                                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Upload Document (required)</label>
                                                    <input type="file" name="file" required class="text-sm" style="color: var(--text-primary);">
                                                </div>
                                            @endif

                                            <div>
                                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Notes / Comments</label>
                                                <textarea name="notes" rows="2" maxlength="2000" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                                            </div>

                                            @if($step->completion_type !== 'document_upload')
                                                <div>
                                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Attach document (optional)</label>
                                                    <input type="file" name="file" class="text-sm" style="color: var(--text-primary);">
                                                </div>
                                            @endif

                                            {{-- Positive / Negative buttons --}}
                                            <div class="flex items-center gap-3 pt-1">
                                                @if($step->negative_status_trigger)
                                                    {{-- Two outcomes --}}
                                                    <button type="submit" name="outcome" value="positive" class="px-4 py-1.5 rounded-lg bg-green-600 hover:bg-green-500 text-white text-sm font-medium">
                                                        {{ $step->name }} ✓
                                                    </button>
                                                    <button type="button" @click="showNegative = {{ $step->id }}" class="px-4 py-1.5 rounded-lg bg-red-600 hover:bg-red-500 text-white text-sm font-medium">
                                                        {{ $step->negative_outcome_label ?? 'Decline' }} ✗
                                                    </button>
                                                @else
                                                    <input type="hidden" name="outcome" value="positive">
                                                    <button type="submit" class="px-4 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium">
                                                        Mark Complete ✓
                                                    </button>
                                                @endif
                                            </div>

                                            @if($step->requires_bm_approval && ($step->status_trigger || $step->negative_status_trigger))
                                                <div class="text-xs px-2 py-1 rounded inline-block" style="background: rgba(245,158,11,0.1); color: #fbbf24;">
                                                    ⚠ Status change will require BM approval
                                                </div>
                                            @endif
                                        </form>

                                        {{-- Negative outcome modal --}}
                                        @if($step->negative_status_trigger)
                                            <form method="POST" action="{{ route('deals-v2.steps.complete', $step) }}" x-show="showNegative === {{ $step->id }}" class="mt-3 p-3 rounded-lg" style="background: rgba(239,68,68,0.05); border: 1px solid rgba(239,68,68,0.2);">
                                                @csrf
                                                <input type="hidden" name="outcome" value="negative">
                                                <div class="mb-2">
                                                    <label class="block text-xs mb-1" style="color: #f87171;">Reason for {{ $step->negative_outcome_label ?? 'decline' }} (required)</label>
                                                    <textarea name="reason" required rows="2" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                              style="background: var(--surface-2); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);"></textarea>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-600 hover:bg-red-500 text-white text-xs font-medium">
                                                        Confirm {{ $step->negative_outcome_label ?? 'Decline' }}
                                                    </button>
                                                    <button type="button" @click="showNegative = null" class="px-3 py-1.5 rounded-lg text-xs" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Cancel</button>
                                                </div>
                                            </form>
                                        @endif

                                        {{-- Due date override --}}
                                        @if($canOverrideDates && $isActive)
                                            <div class="mt-2">
                                                <button @click="overrideStep = overrideStep === {{ $step->id }} ? null : {{ $step->id }}" class="text-xs underline" style="color: var(--text-muted);">Override due date</button>
                                                <form method="POST" action="{{ route('deals-v2.steps.override-date', $step) }}" x-show="overrideStep === {{ $step->id }}" class="flex items-end gap-2 mt-2">
                                                    @csrf
                                                    <input type="date" name="due_date" required class="rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                    <input type="text" name="reason" required placeholder="Reason..." class="flex-1 rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-teal-600 text-white text-xs font-medium">Save</button>
                                                </form>
                                            </div>
                                        @endif
                                    @endif

                                    {{-- Upload additional document (any active/completed step) --}}
                                    @if($canEdit && in_array($step->status, ['active', 'completed']) && $deal->status === 'active')
                                        <form method="POST" action="{{ route('deals-v2.steps.upload', $step) }}" enctype="multipart/form-data" class="flex items-end gap-2 mt-2 pt-2" style="border-top: 1px solid var(--border);">
                                            @csrf
                                            <input type="file" name="file" required class="text-xs" style="color: var(--text-muted);">
                                            <button type="submit" class="px-2 py-1 rounded text-xs font-medium" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Upload</button>
                                        </form>
                                    @endif

                                    {{-- Not started info --}}
                                    @if($step->status === 'not_started')
                                        <div class="text-xs" style="color: var(--text-muted);">
                                            @if($step->trigger_type === 'after_step' && $step->triggerStepInstance)
                                                Activates after "{{ $step->triggerStepInstance->name }}" + {{ $step->days_offset }} days
                                            @elseif($step->trigger_type === 'manual')
                                                Manual activation required
                                            @else
                                                Waiting for trigger
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ACTIVITY LOG --}}
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Activity Log</h2>
                <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                    @forelse($deal->activityLog as $log)
                        @php
                            $accentColor = match($log->action) {
                                'step_completed' => '#34d399',
                                'step_activated' => '#60a5fa',
                                'status_changed' => '#2dd4bf',
                                'step_approved', 'approval_pending', 'step_rejected' => '#fbbf24',
                                'deal_created' => '#a78bfa',
                                default => 'var(--text-muted)',
                            };
                        @endphp
                        <div class="px-4 py-2.5 flex items-start gap-3" style="border-bottom: 1px solid var(--border);">
                            <span class="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style="background: {{ $accentColor }};"></span>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm" style="color: var(--text-primary);">{{ $log->description }}</div>
                                <div class="text-xs" style="color: var(--text-muted);">
                                    {{ $log->created_at->format('d M Y H:i') }}
                                    · {{ $log->user ? $log->user->name : 'System' }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-sm" style="color: var(--text-muted);">No activity yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <script>
        function dealTracker() {
            return {
                expandedStep: {{ $deal->stepInstances->firstWhere('status', 'active')?->id ?? 'null' }},
                showNegative: null,
                overrideStep: null,
                toggleStep(id) {
                    this.expandedStep = this.expandedStep === id ? null : id;
                    this.showNegative = null;
                    this.overrideStep = null;
                },
            };
        }
    </script>
</x-app-layout>
