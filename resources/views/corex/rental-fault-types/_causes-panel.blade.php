{{--
    .ai/specs/rentals-faults-work-orders.md section 22.13.5 - "Who normally pays". Two short lists on a fault type (self-checks a tenant can do; causes a contractor
    may find), each row carrying a default for "if this was the cause, who normally pays". A default is only a suggestion on the work order; the agent rules.
    Receives $faultType. Search and paging are not offered: a type carries a handful of rows (cap 20 per list).
--}}
@php
    $cpArchived = request()->boolean('archived_causes');
    $cpRows = \App\Models\RentalFaultTypeCause::query()->where('rental_fault_type_id', $faultType->id)
        ->when($cpArchived, fn ($q) => $q->withTrashed())->orderBy('sort_order')->orderBy('id')->get()->groupBy('kind');
    $cpLists = [
        \App\Models\RentalFaultTypeCause::KIND_SELF_CHECK => ['Self-checks', 'No self-checks yet - add the things a tenant can check before reporting.'],
        \App\Models\RentalFaultTypeCause::KIND_CAUSE => ['Causes', 'No causes yet - add what a contractor might find.'],
    ];
    $cpField = 'rounded-md px-2 py-1 text-sm';
    $cpBorder = 'border: 1px solid var(--border);';
@endphp
<div id="who-normally-pays" class="rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Who normally pays</h2>
        <a class="text-xs underline" href="{{ request()->fullUrlWithQuery(['archived_causes' => $cpArchived ? null : 1]) }}#who-normally-pays">{{ $cpArchived ? 'Hide archived' : 'Show archived' }}</a>
    </div>
    <p class="text-xs" style="color: var(--text-muted);">For each one: if this was the cause, who normally pays? It is only a suggestion shown to the agent &mdash; the agent decides on the work order.</p>

    @if($errors->has('label'))<div class="text-xs p-2 rounded" style="color: var(--ds-crimson);">{{ $errors->first('label') }}</div>@endif

    @foreach($cpLists as $kind => [$title, $empty])
        @php $list = $cpRows->get($kind, collect()); @endphp
        <div class="space-y-2">
            <h3 class="text-xs font-semibold" style="color: var(--text-muted);">{{ $title }}</h3>
            @forelse($list as $row)
                @if($row->trashed())
                    <form method="POST" action="{{ route('corex.rental-fault-types.causes.restore', [$faultType, $row->id]) }}" class="flex flex-wrap items-center gap-2 text-sm" style="opacity:.7;">
                        @csrf
                        <span class="flex-1">{{ $row->label }} <span class="ds-badge ds-badge-muted">Archived</span> <span class="text-xs" style="color: var(--text-muted);">{{ $row->payerLabel() }}</span></span>
                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                    </form>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('corex.rental-fault-types.causes.update', [$faultType, $row->id]) }}" class="flex flex-wrap items-center gap-2 flex-1">
                            @csrf @method('PUT')
                            <input type="hidden" name="kind" value="{{ $kind }}">
                            <input type="text" name="label" value="{{ $row->label }}" required maxlength="191" aria-label="Label" class="{{ $cpField }} flex-1" style="{{ $cpBorder }} min-width:180px;">
                            <select name="default_payer" aria-label="If this was the cause, who normally pays" class="{{ $cpField }}" style="{{ $cpBorder }}">
                                <option value="" @selected(! $row->default_payer)>No default</option>
                                @foreach(\App\Models\RentalPayerRuling::PAYERS as $k => $label)<option value="{{ $k }}" @selected($row->default_payer === $k)>{{ $label }}</option>@endforeach
                            </select>
                            <input type="number" name="sort_order" value="{{ $row->sort_order }}" min="0" max="9999" aria-label="Order" class="{{ $cpField }}" style="{{ $cpBorder }} width:70px;">
                            <button type="submit" class="corex-btn-outline text-xs">Save</button>
                        </form>
                        <form method="POST" action="{{ route('corex.rental-fault-types.causes.archive', [$faultType, $row->id]) }}">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-xs">Archive</button>
                        </form>
                    </div>
                @endif
            @empty
                <div class="text-xs" style="color: var(--text-muted);">{{ $empty }}</div>
            @endforelse

            <form method="POST" action="{{ route('corex.rental-fault-types.causes.store', $faultType) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <input type="text" name="label" required maxlength="191" placeholder="{{ $kind === 'self_check' ? 'Add a self-check, e.g. Remote control battery is flat' : 'Add a cause, e.g. Roots in the pipe' }}" aria-label="New {{ strtolower($title) }}" class="{{ $cpField }} flex-1" style="{{ $cpBorder }} min-width:180px;">
                <select name="default_payer" aria-label="If this was the cause, who normally pays" class="{{ $cpField }}" style="{{ $cpBorder }}">
                    <option value="">No default</option>
                    @foreach(\App\Models\RentalPayerRuling::PAYERS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </select>
                <button type="submit" class="corex-btn-outline text-xs">Add</button>
            </form>
        </div>
    @endforeach
</div>
