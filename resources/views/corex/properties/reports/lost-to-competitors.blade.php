{{--
    AT-350 — "Lost to Competitors" loss-analysis report.
    Spec: .ai/specs/property-sold-by-third-party.md §6.6
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — tokens only, var(--token, #fallback).

    The read-back for property_third_party_sales. Without this the loss record
    would be a write-only field: captured forever, learned from never.
--}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-7xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Lost to Competitors</h1>
                <p class="text-sm text-white/60">
                    Listings we held that another agency sold — who beat us, at what price, and why.
                </p>
            </div>
            <a href="{{ route('corex.properties.index') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold"
               style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);">
                Back to Properties
            </a>
        </div>
    </div>

    {{-- Period filter --}}
    <form method="GET" class="flex items-end gap-3 flex-wrap rounded-md p-4"
          style="background:var(--surface); border:1px solid var(--border);">
        <div>
            <label for="from" class="block text-[10px] font-medium mb-1" style="color:var(--text-secondary);">From</label>
            <input type="date" id="from" name="from" value="{{ $from->toDateString() }}"
                   class="rounded px-2 py-1 text-xs"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); color-scheme: light dark;">
        </div>
        <div>
            <label for="to" class="block text-[10px] font-medium mb-1" style="color:var(--text-secondary);">To</label>
            <input type="date" id="to" name="to" value="{{ $to->toDateString() }}"
                   class="rounded px-2 py-1 text-xs"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); color-scheme: light dark;">
        </div>
        <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-semibold text-white"
                style="background:var(--brand-button, #0ea5e9);">Apply</button>
        <p class="text-[0.6875rem] ml-auto" style="color:var(--text-muted);">
            Filtered on when the loss was recorded — the sale date is optional, so filtering on it
            would hide every listing we only knew had sold.
        </p>
    </form>

    {{-- Summary tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
        @php
            $tile = 'background:var(--surface); border:1px solid var(--border);';
        @endphp
        <div class="rounded-md p-4" style="{{ $tile }}">
            <div class="text-2xl font-bold" style="color:var(--text-primary);">{{ $summary['total'] }}</div>
            <div class="text-xs" style="color:var(--text-muted);">Listings lost</div>
        </div>
        <div class="rounded-md p-4" style="{{ $tile }}">
            <div class="text-2xl font-bold" style="color:var(--ds-amber, #d97706);">
                R {{ number_format($summary['lost_value'], 0, '.', ',') }}
            </div>
            <div class="text-xs" style="color:var(--text-muted);">
                Value sold by others
                @if($summary['with_price'] < $summary['total'])
                    <span title="Only losses with a captured sold price can contribute to this total.">
                        ({{ $summary['with_price'] }} of {{ $summary['total'] }} priced)
                    </span>
                @endif
            </div>
        </div>
        <div class="rounded-md p-4" style="{{ $tile }}">
            <div class="text-2xl font-bold" style="color:var(--text-primary);">
                @if($summary['avg_price_gap'] === null)
                    —
                @else
                    {{ $summary['avg_price_gap'] > 0 ? '+' : '' }}R {{ number_format($summary['avg_price_gap'], 0, '.', ',') }}
                @endif
            </div>
            <div class="text-xs" style="color:var(--text-muted);"
                 title="Our asking price minus what the competitor achieved. Positive = we were asking more than the market paid. Averaged only over losses where both numbers are known.">
                Avg price gap
                @if($summary['avg_price_gap'] !== null)
                    <span>(n={{ $summary['gap_sample'] }})</span>
                @endif
            </div>
        </div>
        <div class="rounded-md p-4" style="{{ $tile }}">
            <div class="text-2xl font-bold" style="color:var(--text-primary);">
                {{ $summary['avg_days_on_market'] ?? '—' }}
            </div>
            <div class="text-xs" style="color:var(--text-muted);">
                Avg days on our books
                @if($summary['avg_days_on_market'] !== null)
                    <span>(n={{ $summary['dom_sample'] }})</span>
                @endif
            </div>
        </div>
        <div class="rounded-md p-4" style="{{ $tile }}">
            <div class="text-2xl font-bold" style="color:var(--text-primary);">{{ $summary['still_lost'] }}</div>
            <div class="text-xs" style="color:var(--text-muted);"
                 title="Losses still standing. The rest were re-listed after the loss was recorded.">
                Not re-listed
            </div>
        </div>
    </div>

    {{-- Breakdowns --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @php
            $panel = 'background:var(--surface); border:1px solid var(--border);';
        @endphp

        <div class="rounded-md p-4" style="{{ $panel }}">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--text-primary);">Who is beating us</h2>
            @forelse($byCompetitor->take(10) as $name => $row)
                <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                    <span class="text-xs" style="color:var(--text-secondary);">{{ $name }}</span>
                    <span class="text-xs font-mono" style="color:var(--text-primary);">
                        {{ $row['count'] }}
                        @if($row['value'] > 0)
                            <span style="color:var(--text-muted);">· R {{ number_format($row['value'], 0, '.', ',') }}</span>
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-xs" style="color:var(--text-muted);">No losses recorded in this period.</p>
            @endforelse
        </div>

        <div class="rounded-md p-4" style="{{ $panel }}">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--text-primary);">Why we lost</h2>
            @forelse($byReason->take(10) as $reason => $count)
                <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                    <span class="text-xs" style="color:var(--text-secondary);">{{ $reason }}</span>
                    <span class="text-xs font-mono" style="color:var(--text-primary);">{{ $count }}</span>
                </div>
            @empty
                <p class="text-xs" style="color:var(--text-muted);">No losses recorded in this period.</p>
            @endforelse
        </div>

        <div class="rounded-md p-4" style="{{ $panel }}">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--text-primary);">Where we lose</h2>
            @forelse($bySuburb->take(10) as $suburb => $count)
                <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                    <span class="text-xs" style="color:var(--text-secondary);">{{ $suburb }}</span>
                    <span class="text-xs font-mono" style="color:var(--text-primary);">{{ $count }}</span>
                </div>
            @empty
                <p class="text-xs" style="color:var(--text-muted);">No losses recorded in this period.</p>
            @endforelse
        </div>

        <div class="rounded-md p-4" style="{{ $panel }}">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--text-primary);">By listing agent</h2>
            @forelse($byAgent->take(10) as $agent => $count)
                <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                    <span class="text-xs" style="color:var(--text-secondary);">{{ $agent }}</span>
                    <span class="text-xs font-mono" style="color:var(--text-primary);">{{ $count }}</span>
                </div>
            @empty
                <p class="text-xs" style="color:var(--text-muted);">No losses recorded in this period.</p>
            @endforelse
        </div>
    </div>

    {{-- Detail rows --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <th class="px-4 py-2 text-left font-semibold" style="color:var(--text-secondary);">Property</th>
                        <th class="px-4 py-2 text-left font-semibold" style="color:var(--text-secondary);">Sold by</th>
                        <th class="px-4 py-2 text-right font-semibold" style="color:var(--text-secondary);">Their price</th>
                        <th class="px-4 py-2 text-right font-semibold" style="color:var(--text-secondary);">Our asking</th>
                        <th class="px-4 py-2 text-left font-semibold" style="color:var(--text-secondary);">Sold</th>
                        <th class="px-4 py-2 text-left font-semibold" style="color:var(--text-secondary);">Why</th>
                        <th class="px-4 py-2 text-left font-semibold" style="color:var(--text-secondary);">Agent</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $r)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-4 py-2">
                                @if($r->property)
                                    <a href="{{ route('corex.properties.show', $r->property_id) }}"
                                       class="font-medium" style="color:var(--brand-icon, #0ea5e9);">
                                        {{ Str::limit($r->property->title ?: 'Property #'.$r->property_id, 40) }}
                                    </a>
                                    <div style="color:var(--text-muted);">{{ $r->property->suburb }}</div>
                                @else
                                    {{-- BUILD_STANDARD §4 — a deleted property renders, never crashes. --}}
                                    <span style="color:var(--text-muted);">Property removed</span>
                                @endif
                            </td>
                            <td class="px-4 py-2" style="color:var(--text-secondary);">
                                {{ $r->sold_by_agency ?: '—' }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono" style="color:var(--text-primary);">
                                {{ $r->sold_price ? 'R '.number_format((float) $r->sold_price, 0, '.', ',') : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono" style="color:var(--text-muted);">
                                {{ $r->our_listing_price ? 'R '.number_format((float) $r->our_listing_price, 0, '.', ',') : '—' }}
                            </td>
                            <td class="px-4 py-2" style="color:var(--text-secondary);">
                                {{ $r->sold_date?->format('j M Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-2" style="color:var(--text-secondary);">
                                {{ $r->lossReasonLabel() ?? '—' }}
                            </td>
                            <td class="px-4 py-2" style="color:var(--text-secondary);">
                                {{ $r->property?->agent?->name ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center" style="color:var(--text-muted);">
                                No listings recorded as sold by another agency in this period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $records->links() }}
</div>
@endsection
