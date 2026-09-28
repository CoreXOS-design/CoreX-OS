{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">PPRA Inspection Pack</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Live readiness against a PPRA s25 inspection notice — {{ $agency->trading_name ?? $agency->name }}.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.ppra-inspection-pack.remediation-log') }}" class="corex-btn-outline text-xs">
                    Remediation Log
                </a>
                <a href="{{ route('admin.ppra-inspection-pack.report') }}" class="corex-btn-primary text-xs inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    Download Inspection Report
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, #15803d 10%, transparent); color:#15803d; border:1px solid color-mix(in srgb, #15803d 25%, transparent);">
            {{ session('success') }}
        </div>
    @endif

    @php
        $colourMap = [
            'green'   => ['bg' => 'color-mix(in srgb, #15803d 12%, transparent)', 'fg' => '#15803d', 'label' => 'Compliant'],
            'amber'   => ['bg' => 'color-mix(in srgb, var(--ds-amber,#f59e0b) 15%, transparent)', 'fg' => 'var(--ds-amber,#f59e0b)', 'label' => 'Expiring / Partial'],
            'red'     => ['bg' => 'color-mix(in srgb, var(--ds-crimson,#c41e3a) 12%, transparent)', 'fg' => 'var(--ds-crimson,#c41e3a)', 'label' => 'Missing / Expired'],
            'pending' => ['bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)', 'label' => 'Not yet available'],
            'info'    => ['bg' => 'color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent)', 'fg' => 'var(--brand-icon,#0ea5e9)', 'label' => 'Info'],
        ];
    @endphp

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted); width:4%;">Item</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted); width:22%;">Description</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted); width:12%;">Status</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Why</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted); width:18%;">Remediation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                @php $c = $colourMap[$row->status]; @endphp
                <tr style="border-bottom:1px solid var(--border);">
                    <td class="px-4 py-3 align-top" style="color:var(--text-secondary);">{{ $row->item }})</td>
                    <td class="px-4 py-3 align-top font-semibold" style="color:var(--text-primary);">{{ $row->label }}</td>
                    <td class="px-4 py-3 align-top">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background:{{ $c['bg'] }}; color:{{ $c['fg'] }};">
                            {{ $row->status === 'pending' ? 'Not yet available' : ucfirst($row->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 align-top" style="color:var(--text-secondary);">{{ $row->why }}</td>
                    <td class="px-4 py-3 align-top">
                        @if(in_array($row->status, ['amber', 'red']))
                            <form method="POST" action="{{ route('admin.ppra-inspection-pack.gap-notes.store') }}" class="flex flex-col gap-1.5">
                                @csrf
                                <input type="hidden" name="checklist_item_slug" value="{{ $row->item }}">
                                <input type="date" name="remediation_due_date"
                                       value="{{ $row->gap_note?->remediation_due_date?->format('Y-m-d') }}"
                                       class="text-xs rounded-md px-2 py-1" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <input type="text" name="note" placeholder="Note (optional)"
                                       value="{{ $row->gap_note?->note }}"
                                       class="text-xs rounded-md px-2 py-1" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <button type="submit" class="text-xs font-semibold self-start" style="color:var(--brand-icon,#0ea5e9);">
                                    {{ $row->gap_note ? 'Update' : 'Set remediation date' }}
                                </button>
                            </form>
                        @else
                            <span class="text-xs" style="color:var(--text-muted);">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</div>
@endsection
