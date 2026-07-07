{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="RMCP v{{ $version->version_number }}" :back-route="route('compliance.rmcp.index')" back-label="RMCP List" :flush="true">
        <x-slot:actions>
            @if($version->status === 'active')
            <span class="ds-badge ds-badge-success">Active</span>
            @elseif($version->status === 'draft')
            <span class="ds-badge ds-badge-warning">Draft</span>
            @else
            <span class="ds-badge ds-badge-default">Superseded</span>
            @endif

            @if($version->canBeEdited())
            @permission('edit_rmcp')
            <a href="{{ route('compliance.rmcp.edit', $version) }}" class="corex-btn-outline">Edit</a>
            @endpermission
            @permission('approve_rmcp')
            <a href="{{ route('compliance.rmcp.approve.form', $version) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-md transition" style="background: var(--ds-amber, #f59e0b); color: #fff;">Approve</a>
            @endpermission
            @endif

            <a href="{{ route('compliance.rmcp.pdf', $version) }}" target="_blank" class="corex-btn-outline">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m0 0a48.1 48.1 0 0 1 10.5 0m-10.5 0V5.625A2.625 2.625 0 0 1 9.875 3h4.25a2.625 2.625 0 0 1 2.625 2.625v3.18"/></svg>
                PDF
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        {{-- Status banner --}}
        @if($version->status === 'draft')
        <div class="mb-4 px-4 py-3 text-sm font-semibold rounded-md" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent); color: var(--text-primary);">
            Draft — not yet approved by board
        </div>
        @elseif($version->status === 'active')
        <div class="mb-4 px-4 py-3 text-sm font-semibold rounded-md" style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);">
            Active — approved on {{ $version->approved_at?->format('d M Y') }} by {{ $version->approver?->name ?? 'Unknown' }}
        </div>
        @else
        <div class="mb-4 px-4 py-3 text-sm font-semibold rounded-md" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
            Superseded on {{ $version->superseded_at?->format('d M Y') }}
        </div>
        @endif

        <div class="flex gap-6">
            {{-- Left sidebar: Table of contents --}}
            <div class="hidden lg:block flex-shrink-0" style="width:240px;">
                <div class="sticky top-4">
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color: var(--text-muted); letter-spacing:0.05em;">Contents</h3>
                    <nav class="space-y-0.5" style="max-height:calc(100vh - 120px); overflow-y:auto;">
                        @foreach($version->sections as $section)
                        <a href="#section-{{ $section->id }}" class="block text-xs py-1 px-2 rounded-md transition" style="color: var(--text-secondary); line-height:1.4;"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                            {{ $section->section_number }}. {{ Str::limit($section->title, 30) }}
                        </a>
                        @endforeach
                    </nav>
                </div>
            </div>

            {{-- Main body --}}
            <div class="flex-1 min-w-0">
                <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                    {{-- Cover --}}
                    <div class="text-center rounded-t-md" style="background: var(--brand-default, #0b2a4a); color: #fff; padding:2.5rem;">
                        <p class="text-xs font-semibold uppercase" style="color: var(--brand-icon, #0ea5e9); letter-spacing:2px;">Financial Intelligence Centre Act 38 of 2001</p>
                        <h1 class="text-xl font-bold mt-2">{{ $version->title }}</h1>
                        <p class="text-sm mt-1" style="color: rgba(255,255,255,0.7);">Prepared in terms of Section 42 of the FIC Act</p>
                        <p class="text-sm mt-3" style="color: rgba(255,255,255,0.85);">Version {{ $version->version_number }}</p>
                        <p class="text-xs mt-4" style="color: rgba(255,255,255,0.6);">{{ $variables['agency.name'] ?? '' }}</p>
                    </div>

                    {{-- Sections --}}
                    <div style="padding:2rem;">
                        @foreach($version->sections as $section)
                        <div id="section-{{ $section->id }}" class="mb-8">
                            <h2 class="text-base font-bold pb-1.5 mb-3" style="color: var(--text-primary); border-bottom: 2px solid var(--brand-icon);">
                                {{ $section->section_number }}. {{ $section->title }}
                            </h2>
                            <div class="prose prose-sm max-w-none" style="color: var(--text-secondary); line-height:1.7; font-size:0.9375rem;">
                                {!! $section->renderedBody($variables) !!}
                            </div>
                        </div>
                        @endforeach

                        {{-- Approval footer --}}
                        @if($version->approved_at)
                        <div class="mt-8 pt-4" style="border-top: 2px solid var(--brand-icon);">
                            <div class="grid grid-cols-2 gap-4 text-sm" style="color: var(--text-secondary);">
                                <div>
                                    <p><strong>Approved by:</strong> {{ $version->approver?->name }}</p>
                                    <p><strong>Title:</strong> {{ $version->approver_title }}</p>
                                </div>
                                <div>
                                    <p><strong>Approved on:</strong> {{ $version->approved_at->format('d F Y') }}</p>
                                    <p><strong>Effective from:</strong> {{ $version->effective_from?->format('d F Y') }}</p>
                                    <p><strong>Next review due:</strong> {{ $version->next_review_due?->format('d F Y') }}</p>
                                </div>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
