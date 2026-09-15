{{-- "Send N new" popup — which of this buyer's current matches they have
     never been sent, and why each one is newly worth sending. Agent-only:
     "Widened criteria" never reaches the buyer's own page (that filter
     lives in the buyer-facing controller, not here — this view is only
     ever reached through an agent-gated route). --}}
<div class="flex items-center justify-between mb-4">
    <h3 class="text-base font-semibold" style="color:var(--text-primary);">
        Not yet sent — {{ $match->contact->full_name ?? 'this buyer' }}
    </h3>
</div>

@if($classified->isEmpty())
    <p class="text-sm" style="color:var(--text-muted);">Nothing unsent right now.</p>
@else
    <div class="space-y-2">
        @foreach($classified as $row)
        @php
            $property = $row['property'];
            $reason = $row['reason'];
            $meta = $row['meta'];
            $reasonLabel = match($reason) {
                \App\Services\Matching\CoreMatchReasonClassifier::REASON_NEW => 'New listing',
                \App\Services\Matching\CoreMatchReasonClassifier::REASON_REDUCED => 'Reduced into match',
                \App\Services\Matching\CoreMatchReasonClassifier::REASON_BACK_ON_MARKET => 'Back on market',
                \App\Services\Matching\CoreMatchReasonClassifier::REASON_CRITERIA_WIDENED => 'Widened criteria',
                default => 'New',
            };
            $reasonColor = $reason === \App\Services\Matching\CoreMatchReasonClassifier::REASON_REDUCED ? 'var(--ds-green, #059669)' : 'var(--brand-icon,#0ea5e9)';
        @endphp
        <div class="flex items-center justify-between gap-3 py-2" style="border-bottom:1px solid var(--border);">
            <div class="min-w-0">
                <div class="text-sm font-medium truncate" style="color:var(--text-primary);">{{ $property->title ?: $property->address }}</div>
                <div class="text-xs" style="color:var(--text-muted);">
                    {{ $property->suburb }}
                    @if($reason === \App\Services\Matching\CoreMatchReasonClassifier::REASON_REDUCED && isset($meta['old_price'], $meta['new_price']))
                        · R{{ number_format($meta['old_price']) }} → R{{ number_format($meta['new_price']) }}
                    @elseif($property->price)
                        · R{{ number_format($property->price) }}
                    @endif
                </div>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-md font-medium whitespace-nowrap flex-shrink-0"
                  style="background:color-mix(in srgb, {{ $reasonColor }} 12%, transparent); color:{{ $reasonColor }}; border:1px solid color-mix(in srgb, {{ $reasonColor }} 25%, transparent);">
                {{ $reasonLabel }}
            </span>
        </div>
        @endforeach
    </div>
@endif
