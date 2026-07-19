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
        <div data-tour="deals-detail-intro" class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
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
                <div data-tour="deals-detail-status" class="flex items-center gap-2 flex-shrink-0">
                    @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
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
            <div data-tour="deals-detail-summary" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
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
                    <div class="text-xs" style="color: var(--text-muted);">{{ $deal->isPrePipeline() ? 'Legacy (pre-pipeline)' : $daysInPipeline.' days in pipeline' }}</div>
                </div>
            </div>

            {{-- DOCUMENTS (WS3 · D4 — the deal document spine) --}}
            <div data-tour="deals-detail-documents">
                <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Documents</h2>
                <div class="rounded-xl p-4" style="border: 1px solid var(--border); background: var(--surface);">
                    @if($deal->documents->isEmpty())
                        <div class="text-sm mb-3" style="color: var(--text-muted);">No documents filed against this deal yet.</div>
                    @else
                        <div class="space-y-2 mb-3">
                            @foreach($deal->documents as $doc)
                                <div class="flex items-center justify-between gap-3 p-2 rounded-lg" style="background: var(--surface-2);">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <svg class="w-4 h-4 flex-shrink-0" style="color: #2dd4bf;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                                        <div class="min-w-0">
                                            <a href="{{ route('deals-v2.documents.download', [$deal, $doc]) }}" class="text-sm truncate hover:underline" style="color: var(--text-primary);">{{ $doc->original_name }}</a>
                                            <div class="text-xs" style="color: var(--text-muted);">
                                                {{ $doc->documentType->label ?? 'Unclassified' }}
                                                · {{ $doc->uploader->name ?? 'System' }}
                                                · {{ $doc->created_at?->format('d M Y') }}
                                            </div>
                                        </div>
                                    </div>
                                    <span class="text-xs px-1.5 py-0.5 rounded flex-shrink-0" style="background: var(--surface); color: var(--text-muted);" title="How this document reached the deal">{{ str_replace('_', ' ', $doc->source_type) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($canEdit && $deal->status === 'active')
                        <form method="POST" action="{{ route('deals-v2.documents.store', $deal) }}" enctype="multipart/form-data" class="flex flex-col sm:flex-row sm:items-end gap-2 pt-3" style="border-top: 1px solid var(--border);">
                            @csrf
                            <div class="flex-1">
                                <label class="block text-xs mb-1" style="color: var(--text-muted);">File</label>
                                <input type="file" name="file" required class="w-full text-xs" style="color: var(--text-secondary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Document type</label>
                                <select name="document_type_id" class="rounded-md text-sm px-2 py-1.5 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    <option value="">— Unclassified —</option>
                                    @foreach($documentTypes as $dt)
                                        <option value="{{ $dt->id }}">{{ $dt->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($documentSteps->isNotEmpty())
                                <div>
                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Satisfies step (optional)</label>
                                    <select name="link_step_id" class="rounded-md text-sm px-2 py-1.5 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                        <option value="">— None —</option>
                                        @foreach($documentSteps as $ds)
                                            <option value="{{ $ds->id }}">{{ $ds->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors" style="background: #2dd4bf; color: #04121f;">Upload</button>
                        </form>
                    @endif

                    {{-- DISTRIBUTE (WS4 · §8) --}}
                    @if($canDistribute && $deal->status === 'active')
                        <div class="pt-3 mt-3" style="border-top: 1px solid var(--border);"
                             x-data="{ open:false, loading:false, plan:[], async load(){ this.open=true; this.loading=true; try { const r = await fetch('{{ route('deals-v2.distribute.plan', $deal) }}', { headers:{'Accept':'application/json'}, credentials:'same-origin' }); const j = await r.json(); this.plan = j.plan || []; } catch(e) { this.plan = []; } this.loading=false; } }">
                            <button type="button" @click="load()" class="px-3 py-1.5 rounded-lg text-xs font-medium" style="background: var(--surface-2); color: #2dd4bf; border: 1px solid var(--border);">Distribute documents…</button>

                            <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" @click.self="open=false">
                                <div class="w-full max-w-lg rounded-xl p-5" style="background: var(--surface); border: 1px solid var(--border); max-height: 85vh; overflow-y:auto;">
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Distribute documents</h3>
                                        <button type="button" @click="open=false" style="color: var(--text-muted);">&times;</button>
                                    </div>
                                    <p class="text-xs mb-3" style="color: var(--text-muted);">Resolved from your distribution rules and this deal's parties. Only sendable rows can be ticked.</p>
                                    <div x-show="loading" class="text-sm" style="color: var(--text-muted);">Resolving…</div>
                                    <form x-show="!loading" method="POST" action="{{ route('deals-v2.distribute.send', $deal) }}">
                                        @csrf
                                        <template x-if="plan.length === 0">
                                            <div class="text-sm" style="color: var(--text-muted);">No distribution rules apply to this deal's current stage.</div>
                                        </template>
                                        <template x-for="row in plan" :key="row.rule_id">
                                            <label class="flex items-start gap-2 p-2 rounded-lg mb-1" style="background: var(--surface-2);" :style="row.sendable ? '' : 'opacity:.55'">
                                                <input type="checkbox" name="rule_ids[]" :value="row.rule_id" :disabled="!row.sendable" :checked="row.sendable" class="mt-0.5" style="accent-color:#14b8a6;">
                                                <span class="min-w-0">
                                                    <span class="text-sm" style="color: var(--text-primary);" x-text="row.document_type + ' → ' + row.party_label"></span>
                                                    <span class="block text-xs" style="color: var(--text-muted);">
                                                        <span x-text="row.delivery_mode === 'secure_link' ? 'Secure link + PIN' : 'Email attachment'"></span>
                                                        <span x-show="row.will_generate"> · will auto-generate</span>
                                                        <template x-for="rc in row.recipients"><span x-text="' · ' + rc.name"></span></template>
                                                    </span>
                                                    <span x-show="row.skip_reason" class="block text-xs" style="color: #f59e0b;" x-text="row.skip_reason"></span>
                                                </span>
                                            </label>
                                        </template>
                                        <div class="flex justify-end gap-2 mt-3">
                                            <button type="button" @click="open=false" class="px-3 py-1.5 rounded-lg text-xs" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Cancel</button>
                                            <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-medium" style="background: #2dd4bf; color: #04121f;">Send selected</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- SENT (distributions) --}}
                    @if($deal->distributions->isNotEmpty())
                        <div class="pt-3 mt-3" style="border-top: 1px solid var(--border);">
                            <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Sent</div>
                            <div class="space-y-1">
                                @foreach($deal->distributions as $dist)
                                    <div class="flex items-center justify-between gap-3 text-xs p-2 rounded-lg" style="background: var(--surface-2);">
                                        <div class="min-w-0" style="color: var(--text-secondary);">
                                            <span style="color: var(--text-primary);">{{ $dist->document->documentType->label ?? ($dist->document->original_name ?? 'Document') }}</span>
                                            → {{ $dist->recipientName() }}
                                            <span style="color: var(--text-muted);">· {{ $dist->delivery_mode === 'secure_link' ? 'secure link' : 'attachment' }} · {{ $dist->sent_at?->format('d M') }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <span class="px-1.5 py-0.5 rounded" style="background: var(--surface); color: {{ in_array($dist->status, ['downloaded','opened']) ? '#34d399' : ($dist->status === 'revoked' ? '#f87171' : 'var(--text-muted)') }};">{{ str_replace('_', ' ', $dist->status) }}</span>
                                            @if($canDistribute && $dist->delivery_mode === 'secure_link' && $dist->status !== 'revoked')
                                                <form method="POST" action="{{ route('deals-v2.distributions.revoke', $dist) }}" onsubmit="return confirm('Revoke this secure link?');">
                                                    @csrf
                                                    <button type="submit" style="color: #f87171;">Revoke</button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- PIPELINE TRACKER --}}
            {{-- WS-V2 — stage gate: a pending prompt (prompt mode) or an undo affordance --}}
            @php
                $pendingMove = $deal->stageMoves->firstWhere('state', 'pending');
                $undoableMove = $deal->stageMoves->first(fn ($m) => in_array($m->state, ['applied', 'confirmed']) && $m->undone_at === null);
            @endphp
            @if($canEdit && $pendingMove)
                <div class="rounded-xl p-4 mb-4 flex items-center justify-between gap-3" style="border: 1px solid #f59e0b; background: rgba(245,158,11,0.10);">
                    <div class="text-sm" style="color: var(--text-primary);">
                        <span class="font-semibold">All conditions met.</span>
                        Move this deal to <span class="font-semibold">{{ ucfirst($pendingMove->to_status) }}</span>?
                        @if($pendingMove->triggerStep)
                            <span style="color: var(--text-muted);">(via "{{ $pendingMove->triggerStep->name }}")</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <form method="POST" action="{{ route('deals-v2.stage.confirm', $pendingMove) }}">@csrf
                            <button type="submit" class="px-3 py-1.5 rounded text-xs font-medium" style="background: #10b981; color: #fff;">Move to {{ ucfirst($pendingMove->to_status) }}</button>
                        </form>
                        <form method="POST" action="{{ route('deals-v2.stage.dismiss', $pendingMove) }}">@csrf
                            <button type="submit" class="px-3 py-1.5 rounded text-xs font-medium" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Dismiss</button>
                        </form>
                    </div>
                </div>
            @elseif($canEdit && $undoableMove)
                <div class="rounded-xl p-3 mb-4 flex items-center justify-between gap-3" style="border: 1px solid var(--border); background: var(--surface-2);">
                    <div class="text-xs" style="color: var(--text-muted);">
                        Deal moved to <span class="font-semibold" style="color: var(--text-secondary);">{{ ucfirst($undoableMove->to_status) }}</span>
                        {{ $undoableMove->moved_at ? '· ' . $undoableMove->moved_at->diffForHumans() : '' }}
                    </div>
                    <form method="POST" action="{{ route('deals-v2.stage.undo', $undoableMove) }}" class="flex-shrink-0">@csrf
                        <button type="submit" class="px-2.5 py-1 rounded text-xs font-medium" style="background: var(--surface); color: #f87171; border: 1px solid var(--border);">Undo</button>
                    </form>
                </div>
            @endif

            <div data-tour="deals-detail-pipeline">
                @if($deal->isPrePipeline())
                    {{-- Backfilled DR1 twin: honest pre-pipeline banner; no pipeline tracker (.ai/specs/dr2-twin-backfill.md) --}}
                    <div class="rounded-lg p-4" style="background: var(--surface-alt, rgba(148,163,184,0.08)); border: 1px solid var(--border);">
                        <h2 class="text-sm font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Pipeline</h2>
                        <p class="text-sm" style="color: var(--text-secondary);">Captured in the legacy Deal Register (DR1) before the pipeline system. Linked here for a complete register — <strong>no pipeline is attached</strong>. Pipelines activate only on deals captured in DR2 from now on.</p>
                    </div>
                @else
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
                                                    <input type="date" name="value" class="w-full md:w-1/2 rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                </div>
                                            @elseif($step->completion_type === 'amount_input')
                                                <div>
                                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Amount (R)</label>
                                                    <input type="number" name="value" step="0.01" min="0" class="w-full md:w-1/2 rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                </div>
                                            @elseif($step->completion_type === 'text_input')
                                                <div>
                                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Details</label>
                                                    <input type="text" name="value" maxlength="1000" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                </div>
                                            @elseif($step->completion_type === 'document_upload')
                                                <div>
                                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Upload Document</label>
                                                    <input type="file" name="file" class="text-sm" style="color: var(--text-primary);">
                                                </div>
                                            @endif

                                            {{-- Complete-with-reason (anti-gaming escape valve). If the above
                                                 requirement can't be met, a structured reason is required; normal
                                                 met-requirements completion stays frictionless. Reasons config-driven. --}}
                                            @if(in_array($step->completion_type, ['date_input','amount_input','text_input','document_upload','document_signed','multi_field'], true))
                                                <div class="rounded-md p-2.5" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent);">
                                                    <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);">Completing without the above requirement? Give a reason</label>
                                                    <select name="reason_category" class="w-full md:w-1/2 rounded-md text-sm px-3 py-1.5 mb-2" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                        <option value="">— select a reason —</option>
                                                        @foreach(config('deals.completion.override_reasons', []) as $rk => $rlabel)
                                                            <option value="{{ $rk }}">{{ $rlabel }}</option>
                                                        @endforeach
                                                    </select>
                                                    <textarea name="reason" rows="2" maxlength="1000" placeholder="Short note (required only when completing without the requirement)"
                                                              class="w-full rounded-md text-sm px-3 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                                                    @error('reason')<div class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</div>@enderror
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

                                    {{-- AT-229 — OPTIONAL "Send work order" action (Non-neg #2 entry point).
                                         Surfaces only when the pipeline step is configured to send a work
                                         order and this step is at its trigger point. Skippable — never blocks. --}}
                                    @php
                                        $woStep = $step->pipelineStep;
                                        $woTrigger = $woStep?->work_order_trigger_point ?: 'activated';
                                        $showWorkOrder = $canEdit && $deal->status === 'active'
                                            && $woStep?->sends_work_order
                                            && (($isActive && $woTrigger === 'activated') || ($isCompleted && $woTrigger === 'completed'))
                                            && auth()->user()?->hasPermission('deals_v2.distribute_documents');
                                    @endphp
                                    @if($showWorkOrder)
                                        <div class="mt-2 pt-2" style="border-top: 1px solid var(--border);"
                                             x-data="{
                                                open:false, loading:false, sending:false, err:'', ok:'',
                                                fields:{}, suppliers:[], supplierId:'', contactId:'',
                                                newSupplier:{ name:'', company:'', email:'', phone:'' },
                                                fieldKeys:['date','service_label','property_address','seller_name','seller_email','seller_tel','purchaser_name','purchaser_tel','attorneys','rep_name','rep_email','rep_tel','keys_name','keys_tel','payer','notes'],
                                                labels:{date:'Date',service_label:'Service',property_address:'Property',seller_name:'Seller',seller_email:'Seller email',seller_tel:'Seller tel',purchaser_name:'Purchaser',purchaser_tel:'Purchaser tel',attorneys:'Attorneys',rep_name:'Representative',rep_email:'Rep email',rep_tel:'Rep tel',keys_name:'Keys held by',keys_tel:'Keys tel',payer:'Invoice payer',notes:'Notes'},
                                                wide:['property_address','attorneys','notes'],
                                                get chosen(){ return this.suppliers.find(s => String(s.id) === String(this.supplierId)); },
                                                get contacts(){ return this.chosen?.service_contacts || []; },
                                                async load(){
                                                    this.open=true; this.loading=true; this.err=''; this.ok='';
                                                    try {
                                                        const r = await fetch('{{ route('deals-v2.work-order.form', [$deal, $step]) }}', { headers:{'Accept':'application/json'}, credentials:'same-origin' });
                                                        const j = await r.json();
                                                        this.fields = j.fields || {}; this.suppliers = j.suppliers || [];
                                                    } catch(e){ this.err='Could not load the work order form.'; }
                                                    this.loading=false;
                                                },
                                                async send(){
                                                    this.sending=true; this.err='';
                                                    const body = { ...this.fields };
                                                    if (this.supplierId === '__new__'){ Object.assign(body, { supplier_name:this.newSupplier.name, supplier_company:this.newSupplier.company, supplier_email:this.newSupplier.email, supplier_phone:this.newSupplier.phone }); }
                                                    else { body.service_provider_id = this.supplierId; body.service_provider_contact_id = this.contactId || null; }
                                                    try {
                                                        const r = await fetch('{{ route('deals-v2.work-order.send', [$deal, $step]) }}', { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'}, credentials:'same-origin', body: JSON.stringify(body) });
                                                        const j = await r.json();
                                                        if (r.ok && j.ok){ this.ok = j.message || 'Work order sent.'; setTimeout(()=>{ this.open=false; window.location.reload(); }, 1200); }
                                                        else { this.err = j.message || 'Send failed.'; }
                                                    } catch(e){ this.err='Send failed.'; }
                                                    this.sending=false;
                                                }
                                             }">
                                            <button type="button" @click="load()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                                                    style="background: rgba(45,212,191,0.12); color: #2dd4bf; border: 1px solid rgba(45,212,191,0.3);">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                                                Send work order{{ $woStep->work_order_service_type ? ' — '.$woStep->work_order_service_type : '' }}
                                            </button>

                                            <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.55);" @click.self="open=false">
                                                <div class="w-full max-w-2xl rounded-xl" style="background: var(--surface); border: 1px solid var(--border); max-height: 90vh; display:flex; flex-direction:column;">
                                                    <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                                                        <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Send work order — {{ $step->name }}</h3>
                                                        <button type="button" @click="open=false" class="text-lg leading-none" style="color: var(--text-muted);">&times;</button>
                                                    </div>
                                                    <div class="px-4 py-4 overflow-y-auto" style="flex:1;">
                                                        <div x-show="loading" class="text-sm" style="color: var(--text-muted);">Loading…</div>
                                                        <div x-show="!loading" class="space-y-3">
                                                            <div>
                                                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Supplier (chosen at send — never pre-selected)</label>
                                                                <select x-model="supplierId" class="w-full rounded-md text-sm px-3 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                                    <option value="">— pick a supplier —</option>
                                                                    <template x-for="s in suppliers" :key="s.id"><option :value="s.id" x-text="s.name + (s.specialty ? ' ('+s.specialty+')' : '')"></option></template>
                                                                    <option value="__new__">+ Capture a new supplier</option>
                                                                </select>
                                                            </div>
                                                            <div x-show="supplierId === '__new__'" class="grid grid-cols-2 gap-2">
                                                                <input x-model="newSupplier.name" placeholder="Supplier name" class="rounded-md text-sm px-3 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                                <input x-model="newSupplier.company" placeholder="Company (optional)" class="rounded-md text-sm px-3 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                                <input x-model="newSupplier.email" type="email" placeholder="Email" class="rounded-md text-sm px-3 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                                <input x-model="newSupplier.phone" placeholder="Phone (optional)" class="rounded-md text-sm px-3 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                            </div>
                                                            <div x-show="supplierId && supplierId !== '__new__' && contacts.length">
                                                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Send to contact (primary by default)</label>
                                                                <select x-model="contactId" class="w-full rounded-md text-sm px-3 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                                    <option value="">Firm email</option>
                                                                    <template x-for="c in contacts" :key="c.id"><option :value="c.id" x-text="((c.contact_person||c.attorney_name||'Contact')) + (c.email ? ' — '+c.email : '')"></option></template>
                                                                </select>
                                                            </div>
                                                            <div class="grid grid-cols-2 gap-2 pt-1" style="border-top: 1px solid var(--border);">
                                                                <template x-for="key in fieldKeys" :key="key">
                                                                    <div :class="wide.includes(key) ? 'col-span-2' : ''">
                                                                        <label class="block text-xs mb-1 mt-1" style="color: var(--text-muted);" x-text="labels[key]"></label>
                                                                        <input x-model="fields[key]" class="w-full rounded-md text-sm px-3 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                                    </div>
                                                                </template>
                                                            </div>
                                                            <div x-show="err" x-text="err" class="text-xs" style="color: #f87171;"></div>
                                                            <div x-show="ok" x-text="ok" class="text-xs" style="color: #34d399;"></div>
                                                        </div>
                                                    </div>
                                                    <div class="px-4 py-3 flex items-center justify-end gap-2" style="border-top: 1px solid var(--border);">
                                                        <button type="button" @click="open=false" class="px-3 py-1.5 rounded-lg text-xs" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Cancel</button>
                                                        <button type="button" @click="send()" :disabled="sending || (!supplierId)" class="px-4 py-1.5 rounded-lg text-xs font-medium" style="background: #2dd4bf; color: #04121f;" x-text="sending ? 'Sending…' : 'Send work order'"></button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Not started info --}}
                                    @if($step->status === 'not_started')
                                        @php $blockedLabel = $step->blockedByLabel(); @endphp
                                        <div class="text-xs" style="color: var(--text-muted);">
                                            @if($blockedLabel)
                                                {{-- WS-V1: real blocker(s) — never a false countdown --}}
                                                {{ $blockedLabel }}{{ $step->trigger_type === 'after_step' ? ', then + ' . $step->days_offset . ' days' : '' }}
                                            @elseif($step->trigger_type === 'after_step' && $step->triggerStepInstance)
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
                @endif
            </div>

            {{-- DEAL TIMELINE — WS-V6: remarks interleaved with the activity log (the deal's story on one screen) --}}
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Deal Timeline</h2>

                {{-- Add a remark (feedback thread) --}}
                @if($canRemark)
                    <form method="POST" action="{{ route('deals-v2.remarks.store', $deal) }}" class="mb-3">
                        @csrf
                        <div class="rounded-xl p-3" style="border: 1px solid var(--border); background: var(--surface);">
                            <textarea name="body" rows="2" maxlength="2000" required
                                      placeholder="Add a remark — feedback, a call outcome, anything worth recording on this deal…"
                                      class="w-full text-sm rounded-lg px-3 py-2 mb-2" style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"></textarea>
                            @error('body')<div class="text-xs mb-2" style="color: #f87171;">{{ $message }}</div>@enderror
                            <div class="flex justify-end">
                                <button type="submit" class="px-3 py-1.5 rounded text-xs font-medium" style="background: var(--surface-2); color: #2dd4bf; border: 1px solid var(--border);">Add Remark</button>
                            </div>
                        </div>
                    </form>
                @endif

                @php
                    // One chronological stream: immutable activity-log events + soft-deletable remarks.
                    $timeline = collect();
                    foreach ($deal->activityLog as $log) { $timeline->push((object)['kind' => 'log', 'at' => $log->created_at, 'row' => $log]); }
                    foreach ($deal->remarks as $rem) { $timeline->push((object)['kind' => 'remark', 'at' => $rem->created_at, 'row' => $rem]); }
                    $timeline = $timeline->filter(fn ($i) => $i->at !== null)->sortByDesc('at')->values();
                @endphp

                <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                    @forelse($timeline as $item)
                        @if($item->kind === 'remark')
                            @php $rem = $item->row; @endphp
                            <div class="px-4 py-3 flex items-start gap-3" style="border-bottom: 1px solid var(--border); background: rgba(45,212,191,0.05);">
                                <span class="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style="background: #2dd4bf;"></span>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm whitespace-pre-line" style="color: var(--text-primary);">{{ $rem->body }}</div>
                                    <div class="text-xs mt-0.5 flex items-center gap-2" style="color: var(--text-muted);">
                                        <span>Remark · {{ $rem->author->name ?? 'Unknown' }} · {{ $rem->created_at->format('d M Y H:i') }}</span>
                                        @if($canModerateRemarks || (int) $rem->user_id === (int) auth()->id())
                                            <form method="POST" action="{{ route('deals-v2.remarks.destroy', $rem) }}" onsubmit="return confirm('Remove this remark?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-xs" style="color: #f87171;">Remove</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            @php
                                $log = $item->row;
                                $accentColor = match($log->action) {
                                    'step_completed' => '#34d399',
                                    'step_activated' => '#60a5fa',
                                    'status_changed', 'stage_advanced' => '#2dd4bf',
                                    'step_approved', 'approval_pending', 'step_rejected', 'stage_prompt' => '#fbbf24',
                                    'stage_undone', 'remark_removed', 'steps_cancelled' => '#f87171',
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
                        @endif
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
