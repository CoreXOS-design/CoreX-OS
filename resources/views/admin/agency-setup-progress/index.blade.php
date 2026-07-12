{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — platform-owner agency-setup tracking board. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ copied: null }">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Agency Setup Progress</h1>
        <p class="text-sm text-white/60">Every agency's guided-setup status — track who has started, who has finished, and re-send links.</p>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); color:var(--text-muted);" class="text-left text-xs uppercase tracking-wider">
                        <th class="px-4 py-3 font-semibold">Agency</th>
                        <th class="px-4 py-3 font-semibold">Admin</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 font-semibold">Progress</th>
                        <th class="px-4 py-3 font-semibold">Opened</th>
                        <th class="px-4 py-3 font-semibold">Last activity</th>
                        <th class="px-4 py-3 font-semibold">Completed</th>
                        <th class="px-4 py-3 font-semibold text-center">Opens</th>
                        <th class="px-4 py-3 font-semibold text-right">Link</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($setups as $s)
                        @php
                            $status = $s->statusLabel();
                            $tone = match($status) {
                                'Completed'   => 'var(--ds-green,#16a34a)',
                                'In progress' => 'var(--brand-icon,#0ea5e9)',
                                'Revoked','Expired' => 'var(--ds-crimson,#e11d48)',
                                default       => 'var(--text-muted,#64748b)',
                            };
                        @endphp
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">{{ $s->agency?->name ?? '—' }}</td>
                            <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $s->admin?->email ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                      style="background: color-mix(in srgb, {{ $tone }} 14%, transparent); color: {{ $tone }};">
                                    {{ $status }}
                                </span>
                            </td>
                            <td class="px-4 py-3" style="min-width:140px;">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-1.5 rounded-full overflow-hidden" style="background:var(--surface-2,#e2e8f0);">
                                        <div class="h-1.5 rounded-full" style="width: {{ $s->progressPercent() }}%; background: var(--brand-icon,#0ea5e9);"></div>
                                    </div>
                                    <span class="text-xs tabular-nums" style="color:var(--text-muted);">{{ $s->progressPercent() }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-muted);">{{ $s->last_opened_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-muted);">{{ $s->updated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-muted);">{{ $s->completed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-center tabular-nums" style="color:var(--text-secondary);">{{ (int) $s->open_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <button type="button"
                                        @click="navigator.clipboard.writeText('{{ $s->publicUrl() }}'); copied = {{ $s->id }}; setTimeout(() => copied = null, 1500)"
                                        class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium"
                                        style="background:var(--surface-2,#f1f5f9); color:var(--text-secondary); border:1px solid var(--border);">
                                    <span x-show="copied !== {{ $s->id }}">Copy link</span>
                                    <span x-show="copied === {{ $s->id }}" x-cloak style="color:var(--ds-green,#16a34a);">Copied!</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-sm" style="color:var(--text-muted);">
                                No agency setups yet. They are created automatically when a new live agency is added.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
