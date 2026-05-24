{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Agency Documents" :back-route="route('agent.portal')" back-label="My Portal" :flush="true" />

    <div class="p-4 lg:p-6 space-y-4">
        <p class="text-xs" style="color:var(--text-secondary, #6b7280);">
            @if($splitEnabled && $branchName)
                Your compliance documents at {{ $branchName }}. Where a branch-specific version exists, it is shown. Otherwise the company-wide version applies.
            @else
                Your agency compliance documents.
            @endif
        </p>

        @if($documents->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">
                Your agency has not configured any compliance documents yet. Check back later.
            </div>
        @else
            @php
                $colourMap = ['teal' => 'var(--brand-icon)', 'amber' => 'var(--ds-amber, #f59e0b)', 'red' => 'var(--ds-crimson)', 'slate' => 'var(--text-muted, #94a3b8)'];
                $bgMap = ['teal' => 'color-mix(in srgb, var(--brand-icon) 8%, transparent)', 'amber' => 'color-mix(in srgb, var(--ds-amber) 8%, transparent)', 'red' => 'color-mix(in srgb, var(--ds-crimson) 8%, transparent)', 'slate' => 'var(--surface-2)'];
            @endphp

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($documents as $doc)
                @php
                    $config = $doc->config;
                    $prov = $doc->provision;
                    $status = $doc->status;
                    $colour = $status->colour;
                @endphp
                <div style="border:1px solid var(--border, #e5e7eb); border-radius:6px; overflow:hidden;">
                    <div class="px-4 py-3" style="border-bottom:1px solid var(--border, #e5e7eb);">
                        <div class="flex items-start justify-between gap-2">
                            <h4 class="text-sm font-bold" style="color:var(--text-primary, var(--text-primary));">{{ $config->name }}</h4>
                            @if($config->required)
                                <span class="text-[10px] font-semibold px-1.5 py-0.5 flex-shrink-0" style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); color:var(--brand-icon); border-radius:6px;">Required</span>
                            @endif
                        </div>
                        @if($config->description)
                            <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">{{ $config->description }}</p>
                        @endif
                    </div>

                    <div class="px-4 py-3">
                        {{-- Status --}}
                        <div class="flex items-center gap-1.5 mb-3">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $colourMap[$colour] }};"></span>
                            <span class="text-xs font-semibold" style="color:{{ $colourMap[$colour] }};">{{ $status->label }}</span>
                        </div>

                        @if($prov)
                            {{-- Download + metadata --}}
                            <a href="{{ route('my-portal.agency-documents.download', $prov) }}"
                               class="corex-btn-primary inline-flex items-center gap-1.5 mb-3">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                Download
                            </a>
                            <div class="text-[10px] space-y-0.5" style="color:var(--text-secondary, #94a3b8);">
                                <div>Updated {{ $prov->created_at->format('d M Y') }}</div>
                                    <span class="inline-block px-1 py-0.5 font-semibold" style="background:{{ $doc->scope === 'branch' ? 'color-mix(in srgb, var(--brand-icon) 10%, transparent)' : 'var(--surface-2)' }}; color:{{ $doc->scope === 'branch' ? 'var(--brand-icon)' : 'var(--text-muted, #94a3b8)' }}; border-radius:6px;">
                                        {{ $doc->scope === 'branch' ? ($branchName ?? 'Branch') : 'Company' }}
                                    </span>
                            </div>
                        @else
                            <div class="text-xs" style="color:var(--text-secondary, #94a3b8);">
                                {{ $config->required ? 'Contact your compliance officer.' : 'No document uploaded yet.' }}
                            </div>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
