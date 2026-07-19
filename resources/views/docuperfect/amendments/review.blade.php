{{-- E-Sign V3 (ES-3 + ES-9) — Agent Review surface for a pending amendment.
     Spec: .ai/specs/esign-v3-complete-spec.md §7.5.6, §8
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Amendment Review</h1>
        <p class="text-sm text-white/60 mt-1">
            {{ $document?->name ?? 'Document' }}
            &middot; amendment #{{ $amendment->id }}
            &middot; status: <strong>{{ $amendment->status }}</strong>
        </p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green, #16a34a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #16a34a) 30%, transparent);
                    color: var(--text-primary, #111827);">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-md p-6"
         style="background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.07));">

        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary, #111827);">
            Proposed changes
        </h2>

        {{-- ES-4 — flag-type amendment renders its origin + clause ref + reason --}}
        @if($amendment->isFlag())
            <div class="rounded-md p-4 mb-3"
                 style="background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent);">
                <p class="text-xs font-semibold uppercase tracking-wider"
                   style="color: var(--ds-amber, #d97706);">
                    Concern flagged
                    @if($amendment->flag_clause_ref)
                        &mdash; clause {{ $amendment->flag_clause_ref }}
                    @endif
                </p>
                <p class="text-[11px] mt-1" style="color: var(--text-muted, #6b7280);">
                    Origin: {{ str_replace('_', ' ', ucfirst($amendment->flag_origin ?? 'signing_party')) }}
                </p>
                <p class="mt-2 text-sm" style="color: var(--text-primary, #111827);">
                    {{ $amendment->flag_reason ?? $amendment->new_text }}
                </p>
            </div>

            {{-- AT-302 Phase 1 — render THE DOCUMENT with the flagged clause
                 highlighted in place + the recipient's note attached. The bare
                 concern + buttons "means nothing without the document" (Johan). --}}
            @if(!empty($flaggedDocumentHtml))
                <style>
                    .amendment-doc { border: 1px solid var(--border, #e2e8f0); border-radius: 8px; padding: 18px; max-height: 70vh; overflow: auto; background: #fff; }
                    .amendment-doc .amendment-flagged { background: color-mix(in srgb, #d97706 14%, transparent); outline: 2px solid #d97706; outline-offset: 2px; border-radius: 4px; scroll-margin-top: 16px; }
                    .amendment-doc .amendment-note { margin: 8px 0 14px; padding: 10px 12px; border-left: 3px solid #d97706; background: color-mix(in srgb, #d97706 8%, transparent); border-radius: 4px; font-size: 12.5px; color: #4b5563; }
                    .amendment-doc .amendment-note strong { color: #92400e; display: block; margin-bottom: 3px; }
                    .amendment-doc .corex-field { background: transparent; border-color: #cbd5e1; }
                    .amendment-doc .corex-field-label { display: none; }
                </style>
                <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color: var(--text-muted, #6b7280);">The document &mdash; flagged clause highlighted</div>
                <div class="amendment-doc mb-4">
                    {!! $flaggedDocumentHtml !!}
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const el = document.querySelector('.amendment-doc .amendment-flagged');
                        if (el) el.scrollIntoView({ block: 'center' });
                    });
                </script>
            @endif
        @endif

        @if(!$amendment->isFlag() && $conditions->isEmpty() && $strikethroughs->isEmpty())
            <p class="text-sm" style="color: var(--text-muted, #6b7280);">
                No condition or strikethrough rows are attached to this amendment.
            </p>
        @endif

        @foreach($strikethroughs as $strike)
            <div class="rounded-md p-4 mb-3"
                 style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 6%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider"
                           style="color: var(--ds-crimson, #dc2626);">
                            Strikethrough &mdash; clause {{ $strike->clause_ref }}
                        </p>
                        <p class="mt-2 text-sm line-through"
                           style="color: var(--text-secondary, #4b5563);">
                            {{ $strike->clause_original_text }}
                        </p>
                        @if($strike->replacementCondition)
                            <p class="mt-3 text-xs font-semibold uppercase tracking-wider"
                               style="color: var(--ds-green, #16a34a);">
                                Replacement (auto-routed to Other Conditions)
                            </p>
                            <p class="mt-1 text-sm" style="color: var(--text-primary, #111827);">
                                {{ $strike->replacementCondition->content }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

        @foreach($conditions as $cond)
            @if($cond->is_override)
                {{-- Already rendered as part of its parent strikethrough above --}}
                @continue
            @endif
            <div class="rounded-md p-4 mb-3"
                 style="background: color-mix(in srgb, var(--ds-green, #16a34a) 6%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green, #16a34a) 30%, transparent);">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider"
                           style="color: var(--ds-green, #16a34a);">
                            New condition &mdash; {{ ucwords(str_replace('_', ' ', $cond->block_purpose)) }}
                            ({{ $cond->custom_label ?? $cond->block_id }}) #{{ $cond->condition_number }}
                        </p>
                        <p class="mt-2 text-sm" style="color: var(--text-primary, #111827);">
                            {{ $cond->content }}
                        </p>
                        @if($cond->source === 'library' && $cond->library_clause_id)
                            <p class="mt-1 text-xs" style="color: var(--text-muted, #6b7280);">
                                Source: Clause Library (#{{ $cond->library_clause_id }})
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

    </div>

    {{-- Action buttons --}}
    <div class="rounded-md p-6 flex flex-wrap items-start gap-3"
         style="background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.07));">

        @if($amendment->status === \App\Models\Docuperfect\DocumentAmendment::STATUS_PENDING)
            <form method="POST" action="{{ route('docuperfect.amendments.approve', $amendment) }}" class="inline">
                @csrf
                <button type="submit"
                        onclick="return confirm('Approve this amendment? All parties will be requeued for initialing.');"
                        class="corex-btn-primary">
                    Approve &mdash; start initialing cascade
                </button>
            </form>

            <details class="inline-block">
                <summary class="text-sm font-medium cursor-pointer px-3 py-2"
                         style="color: var(--text-secondary, #4b5563);">Reject change</summary>
                <form method="POST" action="{{ route('docuperfect.amendments.rejectChange', $amendment) }}"
                      class="mt-3 space-y-2 max-w-md">
                    @csrf
                    <textarea name="reason" rows="3"
                              class="w-full text-sm rounded-md px-3 py-2"
                              style="background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.2));"
                              placeholder="Reason for rejecting the change (optional)"></textarea>
                    <button type="submit"
                            onclick="return confirm('Reject this change? The document continues with the original wording.');"
                            class="text-sm px-3 py-2 rounded-md font-medium"
                            style="background: var(--ds-amber, #d97706); color: #fff;">
                        Confirm reject change
                    </button>
                </form>
            </details>

            <details class="inline-block">
                <summary class="text-sm font-medium cursor-pointer px-3 py-2"
                         style="color: var(--ds-crimson, #dc2626);">Reject document (terminal)</summary>
                <form method="POST" action="{{ route('docuperfect.amendments.rejectDocument', $amendment) }}"
                      class="mt-3 space-y-2 max-w-md">
                    @csrf
                    <textarea name="reason" rows="3"
                              class="w-full text-sm rounded-md px-3 py-2"
                              style="background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.2));"
                              placeholder="Reason for terminating the document (optional)"></textarea>
                    <button type="submit"
                            onclick="return confirm('Reject the entire document? This is terminal. All parties will be notified.');"
                            class="text-sm px-3 py-2 rounded-md font-medium"
                            style="background: var(--ds-crimson, #dc2626); color: #fff;">
                        Confirm reject document
                    </button>
                </form>
            </details>

            {{-- Phase 1B.9 (FIX 1) — for flag-type amendments raised by a
                 recipient who has already completed signing, the agent can
                 request authenticated consent to remove the flag. This is
                 NOT the same as approve/reject — those decide the merits;
                 this asks the recipient to retract their flag entirely. --}}
            @if($amendment->isFlag() && $amendment->amendedByRequest?->completed_at)
                <details class="inline-block" x-data="flagRemovalRequest()">
                    <summary class="text-sm font-medium cursor-pointer px-3 py-2"
                             style="color: var(--ds-amber, #d97706);">
                        Request flag removal (consent)
                    </summary>
                    <div class="mt-3 space-y-2 max-w-md">
                        <p class="text-xs" style="color: var(--text-muted, #6b7280);">
                            This emails {{ $amendment->amendedByRequest->signer_name ?? 'the recipient' }}
                            asking them to consent to removal of their flag. They must
                            confirm via an authenticated link before the flag is removed.
                            This protects you legally.
                        </p>
                        <textarea x-model="reason" rows="3"
                                  class="w-full text-sm rounded-md px-3 py-2"
                                  style="background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.2));"
                                  placeholder="Reason for requesting removal (sent to the recipient + recorded in audit)"></textarea>
                        <button type="button" @click="submit({{ $amendment->id }})"
                                :disabled="submitting || !reason.trim() || reason.length < 10"
                                :style="(submitting || !reason.trim() || reason.length < 10) ? 'opacity:0.4; cursor:not-allowed;' : ''"
                                class="text-sm px-3 py-2 rounded-md font-medium"
                                style="background: var(--ds-amber, #d97706); color: #fff;">
                            <span x-text="submitting ? 'Sending…' : 'Send consent request'"></span>
                        </button>
                        <p x-show="result" x-cloak x-text="result"
                           class="text-xs"
                           :style="resultIsError ? 'color: var(--ds-crimson, #dc2626);' : 'color: var(--ds-green, #16a34a);'"></p>
                    </div>
                </details>
                <script>
                function flagRemovalRequest() {
                    return {
                        reason: '',
                        submitting: false,
                        result: '',
                        resultIsError: false,
                        async submit(amendmentId) {
                            this.result = '';
                            this.submitting = true;
                            try {
                                const csrf = document.querySelector('meta[name=csrf-token]').content;
                                const r = await fetch('/docuperfect/flags/' + amendmentId + '/request-removal', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                                    body: JSON.stringify({ reason: this.reason }),
                                });
                                if (r.ok) {
                                    const d = await r.json();
                                    this.result = 'Consent request sent to ' + (d.sent_to || 'the recipient') + '.';
                                    this.resultIsError = false;
                                    this.reason = '';
                                } else {
                                    const j = await r.json().catch(() => ({}));
                                    this.result = j.error || ('Failed (' + r.status + ')');
                                    this.resultIsError = true;
                                }
                            } catch (e) {
                                this.result = 'Network error: ' + e.message;
                                this.resultIsError = true;
                            }
                            this.submitting = false;
                        },
                    };
                }
                </script>
            @endif
        @else
            <p class="text-sm" style="color: var(--text-muted, #6b7280);">
                This amendment has already been actioned (status: <strong>{{ $amendment->status }}</strong>).
            </p>
        @endif
    </div>
</div>
@endsection
