{{--
    .ai/specs/rentals-faults-work-orders.md section 22.13.3 - "Who pays": a LOGGED ruling (nothing is charged or invoiced). Shown on a work order that came
    from a fault. Side by side: what the tenant ticked, what the agent confirmed with the tenant, what was found, the cause and the default for it; then
    the agent's ruling form. A newer ruling replaces the current one; the older stays in History. Receives $workOrder.
    NOT the work order's `paid_by` (who settles the contractor's bill) - that stays on the completion form.
--}}
@php
    $wp = app(\App\Services\Rentals\RentalWhoPaysService::class);
    $wpFault = $wp->faultFor($workOrder);
@endphp
@if($wpFault)
@php
    $wpChecks = $wp->selfChecks($wpFault);
    $wpConfirm = $wpFault->tenantConfirmation();
    $wpOptions = $wp->causeOptions($workOrder);
    $wpSuggest = $wp->suggestion($workOrder);
    $wpRuling = $wp->currentRuling($workOrder);
    $wpHistory = $wp->history($workOrder);
    $wpHasFindings = $wp->hasFindings($workOrder);
    $wpTz = \App\Support\AppDate::agencyTimezone($workOrder->agency);
    $wpOld = old('payer', $wpSuggest['payer'] ?? '');
    // Johan 13 Oct: nothing to act on yet (not quoted, nobody has reported back, not started) = ONE collapsed line; open as soon as there is something to act on
    $wpActive = $wpHasFindings || $wpRuling || in_array($workOrder->status, [\App\Models\RentalWorkOrder::STATUS_IN_PROGRESS, \App\Models\RentalWorkOrder::STATUS_COMPLETED], true) || $errors->has('who_pays');
    $wpBtn = 'display:inline-flex; align-items:center; list-style:none;';
@endphp
<div id="who-pays" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <div>
        <h2 class="text-sm font-semibold wo-embedded-title">Who pays</h2>
    </div>

    @unless($wpActive)
        <div class="flex flex-wrap items-center gap-3" data-who-pays-collapsed>
            <span class="text-sm" style="color: var(--text-muted);">Decided once we know what was found.</span>
            @permission('rental_work_orders.manage_completion')
            <details>
                <summary class="corex-btn-outline text-xs cursor-pointer" style="{{ $wpBtn }}" data-wp-rule-open>Enter what was found</summary>
                <div class="mt-2">@include('corex.rental-work-orders._who-pays-findings-form')</div>
            </details>
            @endpermission
        </div>
    @else

    <div class="grid gap-3 md:grid-cols-2 text-sm">
        <div class="space-y-1">
            <div class="text-xs font-semibold" style="color: var(--text-muted);">What the tenant says they checked</div>
            @forelse($wpChecks as $c)
                <div class="flex items-start gap-2">
                    <span aria-hidden="true">{{ $c['ticked'] ? '☑' : '☐' }}</span>
                    <span>{{ $c['text'] }}<span class="sr-only">{{ $c['ticked'] ? ' (ticked)' : ' (not ticked)' }}</span>
                        @if($c['default_label'])<span class="ds-badge ds-badge-muted" title="If this was the cause, who normally pays">Normally: {{ $c['default_label'] }}</span>@endif
                    </span>
                </div>
            @empty
                <div style="color: var(--text-muted);">No self-checks were shown for this fault.</div>
            @endforelse
        </div>
        @if($wpConfirm)
        <div class="space-y-1">
            <div class="text-xs font-semibold" style="color: var(--text-muted);">Confirmed with the tenant</div>
            <div>By {{ $wpConfirm['via_label'] }}, {{ $wpConfirm['at']?->copy()->setTimezone($wpTz)->format('j M Y H:i') }}{{ $wpConfirm['by'] ? ' — ' . $wpConfirm['by'] : '' }}</div>
            @if($wpConfirm['note'])<div style="color: var(--text-muted);">{{ $wpConfirm['note'] }}</div>@endif
        </div>
        @endif
    </div>

    @if($wpHasFindings || $workOrder->found_text || $wpSuggest)
    <div class="grid gap-3 md:grid-cols-3 text-sm">
        @if($workOrder->found_text)<div><div class="text-xs font-semibold" style="color: var(--text-muted);">What was found</div><div>{{ $workOrder->found_text }}</div></div>@endif
        @if($wp->causeText($workOrder) !== '')<div><div class="text-xs font-semibold" style="color: var(--text-muted);">Cause</div><div>{{ $wp->causeText($workOrder) }}</div></div>@endif
        @if($wpSuggest)<div><div class="text-xs font-semibold" style="color: var(--text-muted);">Suggested</div><div>{{ $wpSuggest['label'] }} ({{ $wpSuggest['because'] }})</div></div>@endif
    </div>
    @if($workOrder->reported_back_by)
        <p class="text-xs" style="color: var(--text-muted);">Last recorded by {{ $workOrder->reported_back_by }}{{ $workOrder->reported_back_at ? ', ' . $workOrder->reported_back_at->copy()->setTimezone($wpTz)->format('j M Y H:i') : '' }}.</p>
    @endif
    @else
        <p class="text-sm" style="color: var(--text-muted);">Nothing recorded yet.</p>
    @endif

    {{-- section 28.7: what was found is entered ONCE (in the Complete form, or here when that was skipped) and is read-only once a ruling is built on it --}}
    @permission('rental_work_orders.manage_completion')
    @unless($wpRuling)
    <details @if(! $wpHasFindings || $errors->has('who_pays')) open @endif>
        <summary class="corex-btn-outline text-xs cursor-pointer" style="{{ $wpBtn }}">{{ $wpHasFindings ? 'Correct what was found / the cause' : 'Enter what was found and the cause' }}</summary>
        <div class="mt-2">@include('corex.rental-work-orders._who-pays-findings-form')</div>
    </details>
    @endunless
    @endpermission

    @if($wpRuling)
    <div class="pt-2" style="border-top: 1px solid var(--border);">
        <div class="text-xs font-semibold" style="color: var(--text-muted);">Ruling</div>
        <div class="text-sm"><strong>{{ $wpRuling->summary() }}</strong> &mdash; {{ $wpRuling->reason }}</div>
        <div class="text-xs" style="color: var(--text-muted);">By {{ $wpRuling->ruledBy?->name ?? 'an agent' }}, {{ $wpRuling->ruled_at?->copy()->setTimezone($wpTz)->format('j M Y H:i') }}.
            Owner {{ $wpRuling->owner_told_at ? 'told ' . $wpRuling->owner_told_at->copy()->setTimezone($wpTz)->format('j M H:i') : 'not emailed' }};
            tenant {{ $wpRuling->tenant_told_at ? 'told ' . $wpRuling->tenant_told_at->copy()->setTimezone($wpTz)->format('j M H:i') : ($wp->tenantInvolved($wpRuling) ? 'not emailed' : 'not told (nothing for them to pay)') }}.
            @if($wpRuling->evidence_path)<a class="underline" href="{{ route('corex.rental-work-orders.who-pays.evidence', [$workOrder, $wpRuling->id]) }}">Evidence: {{ $wpRuling->evidence_name }}</a>@endif</div>
    </div>
    @endif

    @permission('rental_work_orders.manage_completion')
    @if($wpHasFindings && $workOrder->status !== \App\Models\RentalWorkOrder::STATUS_CANCELLED)
    <details>
        <summary class="corex-btn-primary text-xs cursor-pointer" style="{{ $wpBtn }}" data-wp-rule-open>{{ $wpRuling ? 'Replace the ruling' : 'Rule who pays' }}</summary>
        <form method="POST" action="{{ route('corex.rental-work-orders.who-pays.rule', $workOrder) }}" enctype="multipart/form-data" class="space-y-2 mt-2 max-w-xl" id="wp-rule-form">
            @csrf
            <div>
                <label class="text-xs font-medium" for="wp-payer">Who pays (required)</label>
                <select id="wp-payer" name="payer" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    <option value="">Choose&hellip;</option>
                    @foreach(\App\Models\RentalPayerRuling::PAYERS as $k => $label)
                        <option value="{{ $k }}" @selected($wpOld === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div id="wp-split" style="display:none;">
                <label class="text-xs font-medium" for="wp-tenant-pct">Tenant pays (%) &mdash; the owner pays the rest</label>
                <input id="wp-tenant-pct" type="number" name="tenant_percent" min="1" max="99" value="{{ old('tenant_percent') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium" for="wp-reason">Reason (required)</label>
                <textarea id="wp-reason" name="reason" rows="2" required maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" data-wp-reason>{{ old('reason', $wpSuggest['because'] ?? '') }}</textarea>
            </div>
            <div>
                <label class="text-xs font-medium" for="wp-evidence">Evidence (optional &mdash; JPG, PNG, WEBP or PDF; kept internal)</label>
                <input id="wp-evidence" type="file" name="evidence" accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full text-sm mt-1">
            </div>
            <p class="text-xs" style="color: var(--text-muted);">The owner and the tenant are emailed this ruling. Nothing is charged or invoiced.</p>
            <button type="submit" class="corex-btn-primary text-xs" style="display:inline-flex; align-items:center;">Save ruling</button>
        </form>
        <script>
            (function () {
                var sel = document.getElementById('wp-payer'), split = document.getElementById('wp-split');
                function sync() { split.style.display = sel.value === 'shared' ? 'block' : 'none'; }
                sel.addEventListener('change', sync); sync();
            })();
        </script>
    </details>
    @elseif(! $wpHasFindings)
        <p class="text-xs" style="color: var(--text-muted);">A ruling needs what was found and the cause first.</p>
    @endif
    @endpermission

    @if($wpHistory->count() > 1)
    <details>
        <summary class="text-xs cursor-pointer" style="color: var(--text-muted);">History ({{ $wpHistory->count() }} rulings)</summary>
        <div class="mt-1 space-y-1 text-xs">
            @foreach($wpHistory as $h)
                <div>{{ $h->ruled_at?->copy()->setTimezone($wpTz)->format('j M Y H:i') }} &mdash; {{ $h->ruledBy?->name ?? 'an agent' }}: <strong>{{ $h->summary() }}</strong>, {{ $h->reason }}{{ $h->superseded_at ? ' (replaced)' : ' (current)' }}</div>
            @endforeach
        </div>
    </details>
    @endif
    @endunless
</div>
@endif
