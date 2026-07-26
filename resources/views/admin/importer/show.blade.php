{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    @php
        $runStatusVariant = match ($run->status) {
            'completed'                  => 'ds-badge-success',
            'failed'                     => 'ds-badge-danger',
            'pending_confirm', 'parsing',
            'importing'                  => 'ds-badge-warning',
            default                      => 'ds-badge-default',
        };
        $runKindLabel = match ($run->kind) {
            'agents'          => 'Agents',
            'listings_images' => 'Listings & Images',
            default           => ucfirst(str_replace('_', ' ', (string) $run->kind)),
        };
    @endphp

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-white leading-tight">Import Run #{{ $run->id }}</h1>
                <p class="text-sm text-white/60">
                    {{ $runKindLabel }} · {{ $run->agency?->name ?? 'No agency' }} · Imported by {{ $run->user?->name ?? 'Unknown' }}
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="ds-badge {{ $runStatusVariant }}">{{ str_replace('_', ' ', $run->status) }}</span>
                <a href="{{ route('admin.importer.index') }}" class="corex-btn-outline text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/>
                    </svg>
                    Back to importer
                </a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-green);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    @if ($run->error_message)
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-crimson);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
            </svg>
            <div class="flex-1">
                <strong>This run reported an error.</strong> {{ $run->error_message }}
            </div>
        </div>
    @endif

    @if ($run->kind === 'agents')
        {{-- Invites deliberately do not live here. They are the LAST step of
             onboarding and are sent per-agency from Property Onboarding once
             the agency's properties are in — not per-run, mid-import. --}}
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--brand-icon, #0ea5e9);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.25 11.25h1.5v5.25m-1.5 0h3m-3-9h.008v.008h-.008V7.5ZM21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
            <div class="flex-1">
                These agents were imported <strong>inactive</strong> — no email has been sent.
                Invite links go out from
                <a href="{{ route('admin.importer.review') }}" class="font-semibold" style="color: var(--brand-icon);">Property Onboarding</a>
                once this agency's properties are imported, as the last step.
            </div>
        </div>
    @endif

    {{-- Run counts --}}
    @if (!empty($run->counts_json))
        <div class="corex-kpi-grid">
            @foreach ($run->counts_json as $k => $v)
                @php
                    $countValue = is_array($v)
                        ? number_format(count($v))
                        : (is_numeric($v) ? number_format((float) $v) : ($v === null || $v === '' ? '—' : $v));
                @endphp
                <x-corex-kpi-card :title="ucfirst(str_replace('_', ' ', $k))" :value="$countValue" />
            @endforeach
        </div>
    @endif

    {{-- Rows --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4 flex items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Rows</h3>
            <span class="text-xs" style="color: var(--text-muted);">{{ number_format($run->rows->count()) }} total</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">#</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">External ID</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name / Title</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Target</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($run->rows as $r)
                    @php
                        $m = $r->mapped_json ?? [];
                        $rowStatusVariant = match ($r->status) {
                            'confirmed' => 'ds-badge-success',
                            'error'     => 'ds-badge-danger',
                            'pending'   => 'ds-badge-warning',
                            'excluded'  => 'ds-badge-muted',
                            default     => 'ds-badge-default',
                        };
                        $rowLabel = $m['name'] ?? ($m['title'] ?? null);
                    @endphp
                    <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-primary);">{{ $r->id }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ ucfirst(str_replace('_', ' ', (string) $r->row_type)) }}</td>
                        <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-muted);">{{ $r->external_id ?? '—' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $rowLabel !== null && $rowLabel !== '' ? $rowLabel : '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $rowStatusVariant }}">{{ str_replace('_', ' ', $r->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $r->action ? ucfirst(str_replace('_', ' ', $r->action)) : '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono text-xs" style="color: var(--text-muted);">{{ $r->target_id ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            This run has no rows.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
