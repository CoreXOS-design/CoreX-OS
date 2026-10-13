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
    $wpTz = $workOrder->agency?->outreachTimezone() ?: 'Africa/Johannesburg';
    $wpOld = old('payer', $wpSuggest['payer'] ?? '');
@endphp
<div id="who-pays" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <div>
        <h2 class="text-sm font-semibold">Who pays <span class="font-normal text-xs" style="color: var(--text-muted);">&mdash; a record only; nothing is charged</span></h2>
    </div>

    <div class="grid gap-3 md:grid-cols-2 text-sm">
        <div class="space-y-1">
            <div class="text-xs font-semibold" style="color: var(--text-muted);">What the tenant says they checked</div>
            @forelse($wpChecks as $c)
                <div class="flex items-start gap-2">
                    <span aria-hidden="true">{{ $c['ticked'] ? '&#10003;' : '&#9744;' }}</span>
                    <span>{{ $c['text'] }}<span class="sr-only">{{ $c['ticked'] ? ' (ticked)' : ' (not ticked)' }}</span>
                        @if($c['default_label'])<span class="ds-badge ds-badge-muted" title="If this was the cause, who normally pays">Normally: {{ $c['default_label'] }}</span>@endif
                    </span>
                </div>
            @empty
                <div style="color: var(--text-muted);">The tenant was shown no self-checks.</div>
            @endforelse
        </div>
        <div class="space-y-1">
            <div class="text-xs font-semibold" style="color: var(--text-muted);">Confirmed with the tenant</div>
            @if($wpConfirm)
                <div>By {{ $wpConfirm['via_label'] }}, {{ $wpConfirm['at']?->copy()->setTimezone($wpTz)->format('j M Y H:i') }}{{ $wpConfirm['by'] ? ' &mdash; ' . $wpConfirm['by'] : '' }}</div>
                @if($wpConfirm['note'])<div style="color: var(--text-muted);">{{ $wpConfirm['note'] }}</div>@endif
            @else
                <div style="color: var(--text-muted);">Not confirmed with the tenant yet.</div>
            @endif
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-3 text-sm">
        <div><div class="text-xs font-semibold" style="color: var(--text-muted);">What was found</div>
            <div>{{ $workOrder->found_text ?: 'Not reported yet.' }}</div></div>
        <div><div class="text-xs font-semibold" style="color: var(--text-muted);">Cause</div>
            <div>{{ $wp->causeText($workOrder) ?: 'Not reported yet.' }}</div></div>
        <div><div class="text-xs font-semibold" style="color: var(--text-muted);">Suggested</div>
            <div>{{ $wpSuggest ? $wpSuggest['label'] . ' (' . $wpSuggest['because'] . ')' : 'No default for this cause.' }}</div></div>
    </div>
    @if($workOrder->reported_back_by)
        <p class="text-xs" style="color: var(--text-muted);">Last recorded by {{ $workOrder->reported_back_by }}{{ $workOrder->reported_back_at ? ', ' . $workOrder->reported_back_at->copy()->setTimezone($wpTz)->format('j M Y H:i') : '' }}.</p>
    @endif

    @permission('rental_work_orders.manage_completion')
    <details @if(! $wpHasFindings || $errors->has('who_pays')) open @endif>
        <summary class="corex-btn-outline text-xs cursor-pointer" style="display:inline-block;">{{ $wpHasFindings ? 'Correct what was found / the cause' : 'Enter what was found and the cause' }}</summary>
        <form method="POST" action="{{ route('corex.rental-work-orders.who-pays.findings', $workOrder) }}" class="space-y-2 mt-2 max-w-xl">
            @csrf
            <div>
                <label class="text-xs font-medium" for="wp-found">What was found (required)</label>
                <textarea id="wp-found" name="found_text" rows="2" required maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('found_text', $workOrder->found_text) }}</textarea>
            </div>
            <div>
                <label class="text-xs font-medium" for="wp-cause">Cause (required)</label>
                <select id="wp-cause" name="cause_id" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                    @foreach($wpOptions as $o)
                        <option value="{{ $o->id }}" @selected((int) old('cause_id', $workOrder->cause_id) === $o->id)>{{ $o->label }}</option>
                    @endforeach
                    <option value="" @selected(old('cause_id', $workOrder->cause_id) === null || old('cause_id', $workOrder->cause_id) === '')>Something else (describe it)</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium" for="wp-cause-note">Cause in words (needed for &ldquo;Something else&rdquo;; optional extra otherwise)</label>
                <input id="wp-cause-note" type="text" name="cause_note" maxlength="500" value="{{ old('cause_note', $workOrder->cause_note) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save what was found</button>
        </form>
    </details>
    @endpermission

    <div class="pt-2" style="border-top: 1px solid var(--border);">
        <div class="text-xs font-semibold" style="color: var(--text-muted);">Ruling</div>
        @if($wpRuling)
            <div class="text-sm"><strong>{{ $wpRuling->summary() }}</strong> &mdash; {{ $wpRuling->reason }}</div>
            <div class="text-xs" style="color: var(--text-muted);">By {{ $wpRuling->ruledBy?->name ?? 'an agent' }}, {{ $wpRuling->ruled_at?->copy()->setTimezone($wpTz)->format('j M Y H:i') }}.
                Owner {{ $wpRuling->owner_told_at ? 'told ' . $wpRuling->owner_told_at->copy()->setTimezone($wpTz)->format('j M H:i') : 'not emailed' }};
                tenant {{ $wpRuling->tenant_told_at ? 'told ' . $wpRuling->tenant_told_at->copy()->setTimezone($wpTz)->format('j M H:i') : 'not emailed' }}.
                @if($wpRuling->evidence_path)<a class="underline" href="{{ route('corex.rental-work-orders.who-pays.evidence', [$workOrder, $wpRuling->id]) }}">Evidence: {{ $wpRuling->evidence_name }}</a>@endif</div>
        @else
            <div class="text-sm" style="color: var(--text-muted);">No ruling yet.</div>
        @endif
    </div>

    @permission('rental_work_orders.manage_completion')
    @if($wpHasFindings && $workOrder->status !== \App\Models\RentalWorkOrder::STATUS_CANCELLED)
    <details>
        <summary class="corex-btn-primary text-xs cursor-pointer" style="display:inline-block;">{{ $wpRuling ? 'Replace the ruling' : 'Rule who pays' }}</summary>
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
                <textarea id="wp-reason" name="reason" rows="2" required maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('reason') }}</textarea>
            </div>
            <div>
                <label class="text-xs font-medium" for="wp-evidence">Evidence (optional &mdash; JPG, PNG, WEBP or PDF; kept internal)</label>
                <input id="wp-evidence" type="file" name="evidence" accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full text-sm mt-1">
            </div>
            <p class="text-xs" style="color: var(--text-muted);">The owner and the tenant are emailed this ruling. Nothing is charged or invoiced.</p>
            <button type="submit" class="corex-btn-primary text-xs">Save ruling</button>
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
</div>
@endif
