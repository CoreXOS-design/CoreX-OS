{{--
    .ai/specs/rental-work-orders.md §17.7.5 — the Variation panel: status, original → extra → new total, the lines, the owner's decision
    (who/how/when), the cited term, "Resend request" and "Record variation decision". Shared by the work order and the job card
    (both post to the work-order-based routes). Needs $workOrder. Owner-facing amounts only.
--}}
@php
    $vpVariations = $workOrder->variations()->with(['lines' => fn ($q) => $q->orderBy('id')])->get();
    $vpSourceText = fn (?string $source) => match ($source) {
        'property' => 'set on this property',
        'agency_default' => 'agency default',
        'constant' => 'built-in default',
        default => '',
    };
@endphp
@if($vpVariations->isNotEmpty())
<div id="variation-panel" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-sm font-semibold">Extra work (variations)</h2>
    @foreach($vpVariations as $vp)
        @php
            $vpBadge = match ($vp->status) {
                'awaiting_owner' => ['ds-badge-warning', 'Awaiting owner'],
                'auto_approved' => ['ds-badge-info', 'Auto-approved'],
                'approved' => ['ds-badge-success', 'Approved by the owner'],
                'declined' => ['ds-badge-danger', 'Declined by the owner'],
                default => ['ds-badge-muted', 'Withdrawn'],
            };
        @endphp
        <div class="rounded-md p-3 space-y-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex flex-wrap items-center gap-2">
                <span class="ds-badge {{ $vpBadge[0] }}">{{ $vpBadge[1] }}</span>
                <span class="text-xs" style="color: var(--text-muted);">raised {{ $vp->raised_at?->format('j M Y H:i') }}@if($vp->revision > 1) · revision {{ $vp->revision }}@endif</span>
            </div>
            <div>
                Approved <strong>R{{ number_format((float) $vp->baseline_amount, 2) }}</strong>
                &rarr; extra <strong>R{{ number_format((float) $vp->extra_amount, 2) }}</strong>
                &rarr; new total <strong>R{{ number_format((float) $vp->new_total, 2) }}</strong>
            </div>
            @if($vp->lines->isNotEmpty())
                <ul class="text-xs space-y-0.5">
                    @foreach($vp->lines as $vpLine)
                        <li>
                            {{ $vpLine->description }} × {{ rtrim(rtrim(number_format((float) $vpLine->quantity, 2, '.', ''), '0'), '.') }}{{ $vpLine->unit ? ' ' . $vpLine->unit : '' }}
                            @if($vpLine->line_total !== null) — R{{ number_format((float) $vpLine->line_total, 2) }}@endif
                            @if($vpLine->office_status === \App\Models\RentalJobCardLine::OFFICE_DECLINED_BY_OWNER)<span style="color: var(--ds-crimson);">(declined by the owner — not counted)</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($vp->term_basis)
                <div class="text-xs" style="color: var(--text-muted);">
                    Term relied on:
                    @if($vp->term_basis === 'variation_tolerance')
                        {{ rtrim(rtrim(number_format((float) $vp->term_value, 2, '.', ''), '0'), '.') }} % tolerance @if($vpSourceText($vp->term_source))({{ $vpSourceText($vp->term_source) }})@endif
                    @else
                        no-approval limit R{{ number_format((float) $vp->term_value, 2) }} @if($vpSourceText($vp->term_source))({{ $vpSourceText($vp->term_source) }})@endif
                    @endif
                </div>
            @endif
            @if($vp->decided_at && in_array($vp->status, ['approved', 'declined'], true))
                <div class="text-xs">
                    Decided {{ $vp->decided_at->format('j M Y H:i') }}
                    {{ $vp->decided_via === 'portal' ? 'in the owner\'s portal' : 'by the owner, recorded by ' . (\App\Models\User::withoutGlobalScopes()->find($vp->decided_by_user_id)?->name ?? 'the office') }}
                    @if($vp->decision_note) — "{{ $vp->decision_note }}"@endif
                </div>
            @elseif($vp->status === 'withdrawn' && $vp->decision_note)
                <div class="text-xs" style="color: var(--text-muted);">{{ $vp->decision_note }}</div>
            @endif

            @if($vp->status === 'awaiting_owner')
                <div class="flex flex-wrap items-start gap-2">
                    @permission('rental_work_orders.record_approval')
                    <form method="POST" action="{{ route('corex.rental-work-orders.variations.resend', [$workOrder, $vp]) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs">Resend request</button>
                    </form>
                    <details>
                        <summary class="corex-btn-outline text-xs cursor-pointer" style="display:inline-block;">Record the owner's reply</summary>
                        <form method="POST" action="{{ route('corex.rental-work-orders.variations.decision', [$workOrder, $vp]) }}" class="space-y-2 pt-2">
                            @csrf
                            <input type="hidden" name="revision" value="{{ $vp->revision }}">
                            <div>
                                <label class="text-xs font-medium">Decision</label>
                                <select name="decision" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                                    <option value="approve">Approved</option>
                                    <option value="decline">Declined</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-medium">How the owner replied</label>
                                <select name="evidence_type" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                                    <option value="whatsapp">WhatsApp reply</option>
                                    <option value="email">Email</option>
                                    <option value="verbal_note">Verbal (undocumented)</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-medium">What the owner said</label>
                                <textarea name="evidence_text" required rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);"></textarea>
                            </div>
                            <button type="submit" class="corex-btn-primary text-xs">Save the owner's reply</button>
                        </form>
                    </details>
                    @endpermission
                </div>
                <p class="text-xs" style="color: var(--text-muted);">The rest of the job carries on as approved; the crew sees this extra marked "awaiting owner — do not start".</p>
            @endif
        </div>
    @endforeach
</div>
@endif
