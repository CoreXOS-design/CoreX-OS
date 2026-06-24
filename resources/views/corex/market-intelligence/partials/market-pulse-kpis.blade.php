{{-- Phase D6 — Market Pulse KPI tile row. Same data as Admin\P24Controller.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@php
    $lastImportRel = $kpis['last_import_at'] ? \Carbon\Carbon::parse($kpis['last_import_at'])->diffForHumans() : 'never';
    $lastImportColor = match (true) {
        $kpis['last_import_status'] === 'success' => 'var(--ds-green, #059669)',
        $kpis['last_import_status'] === 'failure' => 'var(--ds-crimson, #c41e3a)',
        default => 'var(--text-muted)',
    };
@endphp
<div data-tour="mic-market-pulse-kpis" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 8px; margin-bottom: 16px;">
    <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">Last import</div>
        <div style="font-size: 0.9375rem; font-weight: 600; color: {{ $lastImportColor }};">{{ $lastImportRel }}</div>
        @if($kpis['last_import_status'])
            <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 2px;">{{ ucfirst((string) $kpis['last_import_status']) }}</div>
        @endif
    </div>

    <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">Emails (30 d)</div>
        <div style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">{{ number_format($kpis['emails_30d']) }}</div>
        <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 2px;">successful imports</div>
    </div>

    <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">Active listings</div>
        <div style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">{{ number_format($kpis['active_listings']) }}</div>
    </div>

    <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">New this month</div>
        <div style="font-size: 1.0625rem; font-weight: 600; color: var(--ds-green, #059669);">{{ number_format($kpis['new_this_month']) }}</div>
    </div>

    <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">Avg asking</div>
        <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary);">R {{ number_format((float) ($kpis['avg_price'] ?? 0), 0, '.', ',') }}</div>
    </div>

    <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted); margin-bottom: 2px;">IMAP</div>
        <div style="font-size: 0.875rem; font-weight: 600;
                    color: {{ $kpis['imap_status'] === 'configured' ? 'var(--ds-green, #059669)' : 'var(--ds-crimson, #c41e3a)' }};">
            {{ ucfirst((string) $kpis['imap_status']) }}
        </div>
    </div>
</div>
