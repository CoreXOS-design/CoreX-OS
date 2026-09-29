@extends('layouts.corex')

{{--
    .ai/specs/rental-inspection-form.md §7 — the in-vs-out comparison. Every
    row here is a PROPOSAL for an agent to review, never a computed money
    figure — no amount/currency appears anywhere on this page, deliberately
    (see the spec's §7.2/§10: that half of the feature needs Johan's ruling
    first and isn't built yet).
--}}

@php
    $classificationBadge = fn ($c) => match ($c) {
        'declined' => 'ds-badge-danger',
        'improved' => 'ds-badge-success',
        'unchanged' => 'ds-badge-muted',
        'na_mismatch', 'only_at_in', 'only_at_out' => 'ds-badge-warning',
        default => 'ds-badge-muted',
    };
    $classificationLabel = fn ($c) => match ($c) {
        'only_at_in' => 'Only recorded at move-in',
        'only_at_out' => 'Only recorded at move-out',
        'na_mismatch' => 'N/A on one side only',
        'unchanged' => 'Unchanged',
        'improved' => 'Improved',
        'declined' => 'Difference — needs review',
        default => ucfirst(str_replace('_', ' ', $c)),
    };
@endphp

@section('content')
<div class="p-6 max-w-4xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);">{{ $errors->first() }}</div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <span class="text-xs" style="color: var(--text-muted);">Move-in vs move-out comparison — {{ $inspection->lease?->tenantNames() ?? 'Unknown tenant' }}</span>
        </div>
        <a href="{{ route('corex.rental-inspections.show', $inspection) }}" class="corex-btn-outline text-xs">&larr; Out-inspection</a>
    </div>

    @unless($inInspection)
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
            No in-inspection found for this tenancy — nothing to compare against yet. Every item below is shown as "only recorded at move-out."
        </div>
    @endunless

    @if(!empty($headerFacts))
    <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Keys, remotes &amp; meters</h2>
        @foreach($headerFacts as $fact)
            <div class="flex items-center justify-between text-sm" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                <span>{{ $fact['label'] }}</span>
                <span style="color: var(--text-muted);">
                    {{ $fact['in'] ?? '—' }} <span style="opacity:.6;">at move-in</span>
                    &rarr;
                    {{ $fact['out'] ?? '—' }} <span style="opacity:.6;">at move-out</span>
                    @if($fact['changed'])
                        <span class="ds-badge ds-badge-warning">Changed</span>
                    @endif
                </span>
            </div>
        @endforeach
    </div>
    @endif

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Items</h2>
        @forelse($items as $row)
            @php
                $item = $row['item'];
                $finding = $row['finding'];
            @endphp
            <div class="text-sm py-2 space-y-1.5" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <span>{{ $item?->room?->label ?? $item?->label ?? 'Unknown item' }}</span>
                    <span class="ds-badge {{ $classificationBadge($row['classification']) }}">{{ $classificationLabel($row['classification']) }}</span>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs" style="color: var(--text-muted);">
                    <div>
                        <span class="font-semibold">Move-in:</span>
                        {{ $row['in_observation'] ? ucfirst($row['in_observation']->condition) : '—' }}
                        @if($row['in_observation']?->notes) — {{ $row['in_observation']->notes }} @endif
                        @if($row['in_observation']?->photos->isNotEmpty()) &middot; {{ $row['in_observation']->photos->count() }} photo(s) @endif
                    </div>
                    <div>
                        <span class="font-semibold">Move-out:</span>
                        {{ $row['out_observation'] ? ucfirst($row['out_observation']->condition) : '—' }}
                        @if($row['out_observation']?->notes) — {{ $row['out_observation']->notes }} @endif
                        @if($row['out_observation']?->photos->isNotEmpty()) &middot; {{ $row['out_observation']->photos->count() }} photo(s) @endif
                    </div>
                </div>

                @if($row['classification'] === 'declined')
                    @if($finding)
                        <div class="text-xs rounded px-2 py-1" style="background: var(--surface-2);">
                            Marked <strong>{{ $finding->disposition === 'wear_and_tear' ? 'fair wear and tear' : 'flagged for review' }}</strong>
                            by {{ $finding->recordedBy?->name }} — {{ $finding->note }}
                        </div>
                    @endif
                    @permission('rental_inspections.review_deposit_comparison')
                        <form method="POST" action="{{ route('corex.rental-inspections.deposit-comparison.finding', [$inspection, $item->id]) }}" class="flex items-center gap-2 pt-1">
                            @csrf
                            <select name="disposition" class="text-xs rounded px-2 py-1" style="border: 1px solid var(--border);">
                                <option value="wear_and_tear">Fair wear and tear</option>
                                <option value="flagged">Flag as a genuine difference</option>
                            </select>
                            <input type="text" name="note" required placeholder="Reasoning (required)" class="text-xs rounded px-2 py-1 flex-1" style="border: 1px solid var(--border);">
                            <button type="submit" class="corex-btn-outline text-xs">{{ $finding ? 'Update' : 'Record' }}</button>
                        </form>
                    @endpermission
                @endif
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No comparable items yet — nothing has been recorded on both sides.</p>
        @endforelse
    </div>

    <p class="text-xs" style="color: var(--text-muted);">
        This is a proposal for review, not a deduction — no amount has been calculated or applied against any deposit.
    </p>
</div>
@endsection
