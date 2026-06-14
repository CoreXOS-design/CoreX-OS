@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
@php
    use App\Models\DealLinkReviewQueue;
    $tabs = [
        DealLinkReviewQueue::STATUS_PENDING           => 'Pending ('            . number_format($counts['pending']           ?? 0) . ')',
        DealLinkReviewQueue::STATUS_RESOLVED_LINKED   => 'Resolved — linked ('  . number_format($counts['resolved_linked']   ?? 0) . ')',
        DealLinkReviewQueue::STATUS_RESOLVED_UNLINKED => 'Resolved — no match (' . number_format($counts['resolved_unlinked'] ?? 0) . ')',
        DealLinkReviewQueue::STATUS_RESOLVED_SKIP     => 'Deferred ('           . number_format($counts['resolved_skip']     ?? 0) . ')',
    ];
@endphp
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Deal → Property Link Review</h1>
                <p class="text-sm text-white/60">Deals where the auto-matcher found ambiguous or low-confidence property candidates. Review and resolve so sales history surfaces correctly across the system.</p>
            </div>
        </div>
    </div>

    {{-- Flash --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    {{-- Status tabs --}}
    <div class="flex gap-1 overflow-x-auto" style="border-bottom: 1px solid var(--border);">
        @foreach($tabs as $key => $label)
            @php $active = $status === $key; @endphp
            <a href="{{ route('corex.admin.deal-link-review.index', ['status' => $key]) }}"
               class="px-3.5 py-2 text-[13px] whitespace-nowrap no-underline transition-colors duration-150"
               style="color: {{ $active ? 'var(--text-primary)' : 'var(--text-muted)' }};
                      border-bottom: 2px solid {{ $active ? 'var(--brand-button, #0ea5e9)' : 'transparent' }};
                      font-weight: {{ $active ? '600' : '500' }};">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if($rows->isEmpty())
        {{-- Empty state --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Nothing to review here</h3>
            <p class="text-sm" style="color: var(--text-muted);">No deals are sitting in this tab. New ambiguous matches appear here automatically as deals are processed.</p>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deal address</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deal date</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Value</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Candidates</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Top candidate</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Waiting</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $r)
                        @php
                            $candidates = collect($r->candidates_json ?? []);
                            $top = $candidates->first();
                            $confidence = $top['confidence'] ?? null;
                            $confClass = match ($confidence) {
                                'exact'  => 'ds-badge-success',
                                'high'   => 'ds-badge-info',
                                'medium' => 'ds-badge-warning',
                                default  => 'ds-badge-default',
                            };
                        @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3" style="color: var(--text-primary);">
                                {{ $r->deal?->property_address ?: '—' }}
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                {{ $r->deal?->registration_date?->format('j M Y') ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-secondary);">
                                @if($r->deal?->sale_price)
                                    R {{ number_format((int) $r->deal->sale_price) }}
                                @elseif($r->deal?->property_value)
                                    R {{ number_format((float) $r->deal->property_value, 0) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center" style="color: var(--text-secondary);">
                                {{ number_format($candidates->count()) }}
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                @if($top)
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span>{{ \Illuminate\Support\Str::limit($top['address'] ?? '', 50) ?: '—' }}</span>
                                        @if($confidence)
                                            <span class="ds-badge {{ $confClass }}">{{ $confidence }}</span>
                                        @endif
                                        <span class="text-xs" style="color: var(--text-muted);">score {{ number_format((int) ($top['score'] ?? 0)) }}</span>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-muted);">
                                {{ $r->matched_at?->diffForHumans() ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('corex.admin.deal-link-review.show', $r->id) }}"
                                   class="text-xs font-semibold no-underline whitespace-nowrap" style="color: var(--brand-icon, #0ea5e9);">
                                    Review →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $rows->links() }}
            </div>
        </div>
    @endif

</div>
@endsection
