{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     Preview — the REAL modal chrome and the REAL card partial, so what the owner
     sees here is what an agent gets. The only difference is that the link button is
     inert (showLink=false): previewing must never navigate you away, and must never
     record a dismissal.

     Spec: .ai/specs/system-updates.md §7.1 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Preview</h1>
                <p class="text-sm text-white/60">Exactly what {{ strtolower($update->audienceLabel()) }} will see. Nothing is recorded from this page.</p>
            </div>
            <a href="{{ route('admin.system-updates.edit', $update->id) }}" class="corex-btn-outline text-sm" style="color:#fff; border-color:rgba(255,255,255,0.35);">Back to edit</a>
        </div>
    </div>

    {{-- The modal, rendered inline on a scrim so it reads exactly as it will in situ. --}}
    <div class="rounded-md flex items-center justify-center p-6" style="background:rgba(0,0,0,0.55);">
        <div class="w-full rounded-md shadow-2xl overflow-hidden"
             style="max-width:520px; background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.08));">

            <div class="flex items-start justify-between gap-3 px-5 py-4"
                 style="border-bottom:1px solid var(--border, rgba(0,0,0,0.07));">
                <div class="text-sm font-bold" style="color:var(--text-primary, #111827);">What's new in CoreX</div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5" style="color:var(--text-secondary, #6b7280);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </div>

            <div class="px-5 py-5">
                @include('layouts.partials._system-update-card', ['update' => $update, 'showLink' => false])

                @if($update->hasLink())
                    <div class="mt-4">
                        <span class="corex-btn-primary inline-flex items-center gap-2" style="opacity:0.75; cursor:default;">
                            {{ $update->linkLabelOrDefault() }}
                        </span>
                        <div class="text-xs mt-1" style="color:var(--text-secondary, #6b7280);">
                            Goes to <code>{{ $update->link_url }}</code> — inert here.
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex items-center justify-between gap-3 px-5 py-3"
                 style="border-top:1px solid var(--border, rgba(0,0,0,0.07)); background:var(--surface-2, #f7f8fa);">
                <span class="text-xs" style="color:var(--text-secondary, #6b7280);">See all updates</span>
                <span class="corex-btn-primary text-sm" style="opacity:0.75; cursor:default;">Got it</span>
            </div>
        </div>
    </div>
</div>
@endsection
