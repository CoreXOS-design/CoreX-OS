{{--
    .ai/specs/rental-work-orders.md §17.6.2 — "Work terms agreed with the owner", on a SETTLED rental property's Rental tab.
    Two terms, agreed between the agency and this property's owner:
      (i)  the no-approval limit — work whose total owner-facing amount is up to R X needs no owner authorisation (per JOB);
      (ii) the variation tolerance — extra work up to Y % above the APPROVED amount is auto-approved.
    A blank box means "use the agency default" (shown beside the box). Every save is recorded in an append-only history with who,
    when and how/when it was agreed. Own form (outside the rental-details form), own permission (rental_work_orders.manage_work_terms).
    Receives: $property.
--}}
@php
    $wtGate = app(\App\Services\Rentals\RentalApprovalGateService::class);
    $wtTerms = $wtGate->termsFor($property);
    $wtAgencyLimit = \App\Models\RentalWorkOrderSetting::spendThresholdFor($property->agency_id);
    $wtAgencyPct = \App\Models\RentalWorkOrderSetting::variationTolerancePercentFor($property->agency_id);
    $wtChanges = \App\Models\RentalPropertyWorkTermChange::query()->where('property_id', $property->id)
        ->with('changedByUser')->orderByDesc('changed_at')->orderByDesc('id')->limit(30)->get();
    $wtUpdatedBy = $property->rental_work_terms_updated_by_user_id
        ? \App\Models\User::withoutGlobalScopes()->find($property->rental_work_terms_updated_by_user_id)
        : null;
    $wtSourceText = fn (string $source) => match ($source) {
        \App\Services\Rentals\WorkTerms::SOURCE_PROPERTY => 'set on this property',
        \App\Services\Rentals\WorkTerms::SOURCE_AGENCY_DEFAULT => 'agency default',
        default => 'built-in default',
    };
    $wtFieldLabel = fn (string $field) => $field === \App\Models\RentalPropertyWorkTermChange::FIELD_NO_APPROVAL_LIMIT ? 'No-approval limit' : 'Variation tolerance';
    $wtFormat = function (string $field, $value) {
        if ($value === null) {
            return 'agency default';
        }

        return $field === \App\Models\RentalPropertyWorkTermChange::FIELD_NO_APPROVAL_LIMIT
            ? 'R' . number_format((float) $value, 2)
            : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . ' %';
    };
@endphp
<div id="work-terms-panel" class="rounded-md p-4 text-sm space-y-3 mt-4" style="background: var(--surface-2); border: 1px solid var(--border);">
    <div>
        <strong>Work terms agreed with the owner</strong>
        <p class="text-xs mt-1" style="color: var(--text-muted);">
            What this owner has agreed for repairs and maintenance on this property. Work up to the no-approval limit goes ahead without asking the owner;
            extra work up to the tolerance above what the owner approved goes ahead and the owner is told; anything beyond that waits for the owner.
            Emergency work always needs the owner to agree.
        </p>
    </div>

    @if($errors->has('no_approval_limit') || $errors->has('variation_tolerance') || $errors->has('agreed_with'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach(['no_approval_limit', 'variation_tolerance', 'agreed_with'] as $wtErrKey)
                @error($wtErrKey)<div>{{ $message }}</div>@enderror
            @endforeach
        </div>
    @endif

    @permission('rental_work_orders.manage_work_terms')
    <form method="POST" action="{{ route('corex.properties.rental-work-terms.update', $property) }}" class="space-y-3">
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="prop-label" for="wt-limit">No-approval limit (R, per job)</label>
                <input id="wt-limit" type="number" name="no_approval_limit" min="0" max="99999999.99" step="0.01"
                       value="{{ old('no_approval_limit', $property->rental_no_approval_spend_threshold) }}"
                       placeholder="Agency default" class="prop-input prop-field-money">
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    In force: <strong>R{{ number_format($wtTerms->noApprovalLimit, 2) }}</strong> ({{ $wtSourceText($wtTerms->limitSource) }}).
                    Agency default: R{{ number_format($wtAgencyLimit, 2) }}. Leave blank to use the agency default.
                </p>
            </div>
            <div>
                <label class="prop-label" for="wt-tolerance">Variation tolerance (% above the approved amount)</label>
                <input id="wt-tolerance" type="number" name="variation_tolerance" min="0" max="100" step="0.01"
                       value="{{ old('variation_tolerance', $property->rental_variation_tolerance_percent) }}"
                       placeholder="Agency default" class="prop-input">
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    In force: <strong>{{ rtrim(rtrim(number_format($wtTerms->variationPct, 2, '.', ''), '0'), '.') }} %</strong> ({{ $wtSourceText($wtTerms->pctSource) }}).
                    Agency default: {{ rtrim(rtrim(number_format($wtAgencyPct, 2, '.', ''), '0'), '.') }} %. 0 means every increase goes to the owner. Leave blank to use the agency default.
                </p>
            </div>
            <div class="sm:col-span-2">
                <label class="prop-label" for="wt-agreed">Agreed how / when (optional)</label>
                <input id="wt-agreed" type="text" name="agreed_with" maxlength="255" value="{{ old('agreed_with') }}"
                       placeholder="e.g. agreed by email with the owner, 5 Oct 2026" class="prop-input">
            </div>
        </div>
        <div class="flex items-center justify-between">
            <span class="text-xs" style="color: var(--text-muted);">
                @if($property->rental_work_terms_updated_at)
                    Last changed by {{ $wtUpdatedBy?->name ?? 'a former user' }} on {{ $property->rental_work_terms_updated_at->format('j M Y H:i') }}.
                @else
                    Not changed yet — this property uses the agency defaults.
                @endif
            </span>
            <button type="submit" class="corex-btn-primary text-sm">Save work terms</button>
        </div>
    </form>
    @else
        <div class="text-xs space-y-1">
            <div>No-approval limit: <strong>R{{ number_format($wtTerms->noApprovalLimit, 2) }}</strong> ({{ $wtSourceText($wtTerms->limitSource) }})</div>
            <div>Variation tolerance: <strong>{{ rtrim(rtrim(number_format($wtTerms->variationPct, 2, '.', ''), '0'), '.') }} %</strong> ({{ $wtSourceText($wtTerms->pctSource) }})</div>
            @if($property->rental_work_terms_updated_at)
                <div style="color: var(--text-muted);">Last changed by {{ $wtUpdatedBy?->name ?? 'a former user' }} on {{ $property->rental_work_terms_updated_at->format('j M Y H:i') }}.</div>
            @endif
        </div>
    @endpermission

    <details>
        <summary class="text-xs cursor-pointer" style="color: var(--text-muted);">History ({{ $wtChanges->count() }})</summary>
        @if($wtChanges->isEmpty())
            <p class="text-xs mt-2" style="color: var(--text-muted);">No changes recorded yet.</p>
        @else
            <ul class="space-y-1 text-xs mt-2">
                @foreach($wtChanges as $change)
                    <li>
                        <span style="color: var(--text-muted);">{{ $change->changed_at?->format('j M Y H:i') }}</span>
                        — {{ $wtFieldLabel($change->field) }}: {{ $wtFormat($change->field, $change->old_value) }} &rarr; <strong>{{ $wtFormat($change->field, $change->new_value) }}</strong>
                        <span style="color: var(--text-muted);">({{ $change->changedByUser?->name ?? 'a former user' }}@if($change->agreed_with) — agreed: {{ $change->agreed_with }}@endif)</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </details>
</div>
