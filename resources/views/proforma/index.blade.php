{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — Proforma Invoice List --}}
@extends('layouts.corex')

@php $money = fn ($v) => 'R ' . number_format((float) ($v ?? 0), 2, '.', ','); @endphp

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Proforma Invoices</h1>
                <p class="text-sm text-white/60">
                    Every proforma invoice generated from a deal — view or download without opening the deal.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                      style="background: color-mix(in srgb, white 15%, transparent);"
                      title="Records matching the current filter">
                    {{ number_format($invoices->total()) }} {{ $status === 'issued' ? 'issued' : ($status === 'voided' ? 'voided' : 'total') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('proforma.index') }}"
          class="rounded-md p-3 flex flex-col sm:flex-row gap-2 sm:items-center"
          style="background: var(--surface); border: 1px solid var(--border);">
        <div class="relative flex-1">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                 fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="var(--text-muted, #9ca3af)">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <input type="text" name="q" value="{{ $search }}" placeholder="Search invoice number or property…"
                   class="w-full rounded-md pl-9 pr-3 py-2 text-sm"
                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <select name="status" onchange="this.form.submit()" class="list-header-filter">
            <option value="all"    {{ $status === 'all' ? 'selected' : '' }}>All statuses</option>
            <option value="issued" {{ $status === 'issued' ? 'selected' : '' }}>Issued</option>
            <option value="voided" {{ $status === 'voided' ? 'selected' : '' }}>Voided</option>
        </select>
        <button type="submit" class="corex-btn-primary">Search</button>
        @if($search !== '' || $status !== 'all')
            <a href="{{ route('proforma.index') }}" class="corex-btn-outline">Clear</a>
        @endif
    </form>

    {{-- Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Number</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Generated</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deal / Property</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Seller</th>
                        @if($showAgencyColumn)
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agency</th>
                        @endif
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total incl. VAT</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $invoice)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-mono" style="color: var(--text-primary);">{{ $invoice->number }}</td>
                            <td class="px-4 py-3">
                                @if($invoice->status === \App\Models\Proforma\ProformaInvoice::STATUS_ISSUED)
                                    <span class="ds-badge ds-badge-success">Issued</span>
                                @else
                                    <span class="ds-badge ds-badge-orange" title="{{ $invoice->void_reason }}">Voided</span>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $invoice->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $invoice->reference }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $invoice->issued_to_name ?? '—' }}</td>
                            @if($showAgencyColumn)
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $invoice->agency?->name ?? '—' }}</td>
                            @endif
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $invoice->creator?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary);">{{ $money($invoice->total_incl) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('proforma.show', $invoice) }}" class="corex-btn-outline" style="padding: 4px 10px;">View</a>
                                <a href="{{ route('proforma.download', $invoice) }}" class="corex-btn-outline" style="padding: 4px 10px;" target="_blank" rel="noopener">Download</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showAgencyColumn ? 9 : 8 }}" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No proforma invoices found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($invoices->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $invoices->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
