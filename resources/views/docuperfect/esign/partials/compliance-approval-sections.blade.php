{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- My E-Sign Documents — the sender's view of the compliance approval gate.
     Spec .ai/specs/esign-compliance-approval-gate.md §8.4. Included from my-documents.blade.php;
     $groups and the cancel-modal Alpine state come from there. --}}

{{-- ===== AWAITING COMPLIANCE APPROVAL ===== --}}
@if($groups['approval_pending']->isNotEmpty())
<div id="section-approval-pending" class="space-y-3 scroll-mt-4">
    <h3 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2" style="color: var(--ds-amber);">
        <span class="inline-flex items-center justify-center w-5 h-5 text-white text-[0.6875rem] font-bold rounded-full" style="background: var(--ds-amber);">{{ number_format($groups['approval_pending']->count()) }}</span>
        Awaiting Compliance Approval
    </h3>
    <div class="space-y-3">
        @foreach($groups['approval_pending'] as $tpl)
            @php $doc = $tpl->document; @endphp
            <div class="rounded-md p-4" style="border: 1px solid var(--border); border-left: 3px solid var(--ds-amber); background: var(--surface);">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold" style="color: var(--text-primary);">
                            {{ $doc->name ?? 'Untitled' }}
                            @if($doc && $doc->template)
                                <span class="ds-badge ds-badge-default ml-2" title="{{ $doc->template->name }}">{{ \Illuminate\Support\Str::limit($doc->template->name, 20) }}</span>
                            @endif
                        </div>
                        <div class="text-xs mt-1" style="color: var(--text-secondary);">You have signed. A Reporting Officer has to approve it before it goes to {{ $tpl->requests->where('party_role', '!=', 'agent')->pluck('signer_name')->filter()->implode(', ') ?: 'the other parties' }}. Held {{ $tpl->updated_at?->diffForHumans() }}.</div>
                    </div>
                    <div class="flex flex-col gap-2">
                        @if($doc)
                        <a href="{{ route('docuperfect.signatures.review', $doc) }}" class="corex-btn-outline whitespace-nowrap text-center text-xs">View document</a>
                        @endif
                        <button type="button"
                                @click="cancelTemplateId = {{ $tpl->id }}; cancelDocName = {{ Js::from($doc->name ?? 'Untitled') }}; showCancelModal = true"
                                class="text-xs font-semibold text-center hover:underline transition-colors duration-150"
                                style="color: var(--ds-crimson);">Cancel Document</button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- ===== DECLINED BY COMPLIANCE ===== --}}
@if($groups['approval_declined']->isNotEmpty())
<div id="section-approval-declined" class="space-y-3 scroll-mt-4">
    <h3 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2" style="color: var(--ds-crimson);">
        <span class="inline-flex items-center justify-center w-5 h-5 text-white text-[0.6875rem] font-bold rounded-full" style="background: var(--ds-crimson);">{{ number_format($groups['approval_declined']->count()) }}</span>
        Declined by Compliance
    </h3>
    <div class="space-y-3">
        @foreach($groups['approval_declined'] as $tpl)
            @php
                $doc = $tpl->document;
                $latestApproval = \App\Models\Docuperfect\EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->latest('id')->first();
            @endphp
            <div class="rounded-md p-4" style="border: 2px solid var(--ds-crimson); background: color-mix(in srgb, var(--ds-crimson) 8%, transparent);">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold" style="color: var(--text-primary);">
                            {{ $doc->name ?? 'Untitled' }}
                            @if($doc && $doc->template)
                                <span class="ds-badge ds-badge-default ml-2" title="{{ $doc->template->name }}">{{ \Illuminate\Support\Str::limit($doc->template->name, 20) }}</span>
                            @endif
                        </div>
                        <div class="text-xs mt-2" style="color: var(--ds-crimson);">
                            <strong>Reason:</strong> {{ $tpl->compliance_decline_reason ?? 'No reason recorded' }}
                        </div>
                        <div class="text-xs mt-1" style="color: var(--text-secondary);">Ask for approval again once it has been addressed, or cancel the document. The Compliance Officer can also override this decline.</div>
                    </div>
                    <div class="flex flex-col gap-2">
                        @if($latestApproval)
                        <form method="POST" action="{{ route('docuperfect.approvals.resubmit', $latestApproval) }}">@csrf
                            <button type="submit" class="corex-btn-primary whitespace-nowrap text-xs w-full">Ask for approval again</button>
                        </form>
                        @endif
                        @if($doc)
                        <a href="{{ route('docuperfect.signatures.review', $doc) }}" class="corex-btn-outline whitespace-nowrap text-center text-xs">View document</a>
                        @endif
                        <button type="button"
                                @click="cancelTemplateId = {{ $tpl->id }}; cancelDocName = {{ Js::from($doc->name ?? 'Untitled') }}; showCancelModal = true"
                                class="text-xs font-semibold text-center hover:underline transition-colors duration-150"
                                style="color: var(--ds-crimson);">Cancel Document</button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif
