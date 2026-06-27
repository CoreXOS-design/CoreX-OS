{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0" data-tour="portal-agency-docs-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Agency Documents</h1>
                <p class="text-sm text-white/60">
                    @if($splitEnabled && $branchName)
                        Your compliance documents at {{ $branchName }}. Where a branch-specific version exists, it is shown. Otherwise the company-wide version applies.
                    @else
                        Your agency compliance documents.
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('agent.portal') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                    My Portal
                </a>
            </div>
        </div>
    </div>

    @if($documents->isEmpty())
        {{-- Empty state --}}
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No compliance documents yet</h3>
            <p class="text-sm" style="color:var(--text-muted);">Your agency has not configured any compliance documents yet. Check back later.</p>
        </div>
    @else
        @php
            $colourMap = ['teal' => 'var(--brand-icon,#0ea5e9)', 'amber' => 'var(--ds-amber,#f59e0b)', 'red' => 'var(--ds-crimson,#c41e3a)', 'slate' => 'var(--text-muted,#94a3b8)'];
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" data-tour="portal-agency-docs-grid">
            @foreach($documents as $doc)
            @php
                $config = $doc->config;
                $prov = $doc->provision;
                $status = $doc->status;
                $colour = $status->colour;
            @endphp
            <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
                <div class="px-4 py-3" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">{{ $config->name }}</h4>
                        @if($config->required)
                            <span class="text-[0.6875rem] font-semibold px-1.5 py-0.5 rounded-md flex-shrink-0 whitespace-nowrap" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9);">Required</span>
                        @endif
                    </div>
                    @if($config->description)
                        <p class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $config->description }}</p>
                    @endif
                </div>

                <div class="px-4 py-3">
                    {{-- Status --}}
                    <div class="flex items-center gap-1.5 mb-3" @if($loop->first) data-tour="portal-agency-docs-status" @endif>
                        <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $colourMap[$colour] }};"></span>
                        <span class="text-xs font-semibold" style="color:{{ $colourMap[$colour] }};">{{ $status->label }}</span>
                    </div>

                    @if($prov)
                        {{-- Download + metadata --}}
                        <a href="{{ route('my-portal.agency-documents.download', $prov) }}"
                           class="corex-btn-primary inline-flex items-center gap-1.5 mb-3"
                           @if($loop->first) data-tour="portal-agency-docs-download" @endif>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                            Download
                        </a>
                        <div class="text-xs space-y-1" style="color:var(--text-secondary);">
                            <div style="color:var(--text-muted);">Updated {{ $prov->created_at->format('d M Y') }}</div>
                            <span class="inline-block px-1.5 py-0.5 rounded-md font-semibold whitespace-nowrap" style="background:{{ $doc->scope === 'branch' ? 'color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent)' : 'var(--surface-2)' }}; color:{{ $doc->scope === 'branch' ? 'var(--brand-icon,#0ea5e9)' : 'var(--text-muted,#94a3b8)' }};">
                                {{ $doc->scope === 'branch' ? ($branchName ?? 'Branch') : 'Company' }}
                            </span>
                        </div>
                    @else
                        <div class="text-xs" style="color:var(--text-secondary);">
                            {{ $config->required ? 'Contact your compliance officer.' : 'No document uploaded yet.' }}
                        </div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
