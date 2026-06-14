@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header :title="$version->policy->name . ' v' . $version->version_number" :back-route="route('compliance.policy.index')" back-label="Policies" :flush="true">
        <x-slot:actions>
            @if($version->status === 'active')
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold" style="background:color-mix(in srgb, var(--brand-icon) 15%, transparent); color:var(--brand-icon); border-radius:6px;">Active</span>
            @elseif($version->status === 'draft')
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold" style="background:rgba(234,179,8,0.15); color:var(--ds-amber); border-radius:6px;">Draft</span>
            @else
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">Superseded</span>
            @endif

            @if($version->canBeEdited())
            @permission('edit_policy')
            <a href="{{ route('compliance.policy.edit', $version) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-secondary, #6b7280);">Edit</a>
            @endpermission
            @permission('approve_policy')
            <a href="{{ route('compliance.policy.approve.form', $version) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="background:#f59e0b; color:var(--text-primary); border-radius:6px;">Approve</a>
            @endpermission
            @endif

            <a href="{{ route('compliance.policy.pdf', $version) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-secondary, #6b7280);">PDF</a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if($version->status === 'draft')
        <div class="mb-4 px-4 py-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid rgba(234,179,8,0.3); border-radius:6px; color:#ca8a04;">
            Draft — not yet approved
        </div>
        @elseif($version->status === 'active')
        <div class="mb-4 px-4 py-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--brand-icon) 10%, transparent); border:1px solid rgba(0,212,170,0.3); border-radius:6px; color:var(--brand-icon);">
            Active — approved on {{ $version->approved_at?->format('d M Y') }} by {{ $version->approver?->name ?? 'Unknown' }}
        </div>
        @else
        <div class="mb-4 px-4 py-3 text-sm font-semibold" style="background:rgba(148,163,184,0.1); border:1px solid rgba(148,163,184,0.3); border-radius:6px; color:#94a3b8;">
            Superseded on {{ $version->superseded_at?->format('d M Y') }}
        </div>
        @endif

        <div class="flex gap-6">
            <div class="hidden lg:block flex-shrink-0" style="width:240px;">
                <div class="sticky top-16">
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color:#64748b; letter-spacing:0.05em;">Contents</h3>
                    <nav class="space-y-0.5" style="max-height:calc(100vh - 120px); overflow-y:auto;">
                        @foreach($version->sections as $section)
                        <a href="#section-{{ $section->id }}" class="block text-xs py-1 px-2 hover:bg-slate-50 transition" style="color:#64748b; border-radius:6px; line-height:1.4;">
                            {{ $section->section_number }}. {{ Str::limit($section->title, 30) }}
                        </a>
                        @endforeach
                    </nav>
                </div>
            </div>

            <div class="flex-1 min-w-0">
                <div class="bg-white border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    <div class="text-center" style="background:var(--text-primary); color:#fff; padding:2.5rem;">
                        <p class="text-xs font-semibold uppercase" style="color:var(--brand-icon); letter-spacing:2px;">Agency Policy</p>
                        <h1 class="text-xl font-bold mt-2">{{ $version->title }}</h1>
                        <p class="text-sm mt-3" style="color:#cbd5e1;">Version {{ $version->version_number }}</p>
                        <p class="text-xs mt-4" style="color:#64748b;">{{ $variables['agency.name'] ?? '' }}</p>
                    </div>

                    <div style="padding:2rem;">
                        @foreach($version->sections as $section)
                        <div id="section-{{ $section->id }}" class="mb-8">
                            <h2 class="text-base font-bold pb-1.5 mb-3" style="color:var(--text-primary); border-bottom:2px solid var(--brand-icon);">
                                {{ $section->section_number }}. {{ $section->title }}
                            </h2>
                            <div class="prose prose-sm max-w-none" style="color:#334155; line-height:1.7; font-size:0.9375rem;">
                                {!! $section->renderedBody($variables) !!}
                            </div>
                        </div>
                        @endforeach

                        @if($version->approved_at)
                        <div class="mt-8 pt-4" style="border-top:2px solid var(--brand-icon);">
                            <div class="grid grid-cols-2 gap-4 text-sm" style="color:#64748b;">
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
