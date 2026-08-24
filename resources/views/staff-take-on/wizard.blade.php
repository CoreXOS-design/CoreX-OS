@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full">
    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Take-On: {{ $takeOn->user->name }}</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                @if(!$takeOn->isComplete())
                    <a href="{{ route('staff-take-on.index') }}" class="corex-btn-outline text-xs">Save &amp; Exit</a>
                @endif
                <a href="{{ route('staff-take-on.index') }}" class="corex-btn-outline text-xs inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Staff Take-On
                </a>
            </div>
        </div>
    </div>

    <div class="pt-5 max-w-5xl">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold rounded-md" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); color:var(--brand-icon);">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold rounded-md" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); color:var(--ds-crimson);">{{ session('error') }}</div>
        @endif

        {{-- Progress strip --}}
        <div class="flex flex-wrap gap-1 mb-6">
            @php
                $stepLabels = ['User', 'Personal', 'Tax/Banking', 'Employment', 'Compensation', 'Leave', 'Compliance', 'Review'];
                $verifiedFlags = [
                    true, // user always done
                    $takeOn->personal_details_verified,
                    $takeOn->banking_details_verified && $takeOn->tax_details_verified,
                    $takeOn->employment_terms_verified,
                    $takeOn->compensation_setup_verified,
                    $takeOn->leave_balances_captured,
                    $takeOn->compliance_documents_uploaded,
                    $takeOn->isComplete(),
                ];
            @endphp
            @foreach($steps as $i => $s)
                @php
                    $isCurrent = $s === $step;
                    $isDone = $verifiedFlags[$i] ?? false;
                @endphp
                <a href="{{ route('staff-take-on.wizard', [$takeOn, $s]) }}"
                   class="flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-semibold transition rounded-md"
                   style="{{ $isCurrent ? 'background:var(--brand-icon); color:#fff;' : ($isDone ? 'background:color-mix(in srgb, var(--brand-icon) 8%, transparent); color:var(--brand-icon);' : 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);') }}">
                    @if($isDone && !$isCurrent)
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @else
                        <span class="text-[10px]">{{ $i + 1 }}.</span>
                    @endif
                    {{ $stepLabels[$i] }}
                </a>
            @endforeach
        </div>

        {{-- Step content --}}
        @include("staff-take-on.wizard._step_{$step}")

        {{-- Navigation --}}
        @if(!$takeOn->isComplete() && $step !== 'review')
        <div class="flex items-center justify-between mt-6 pt-4" style="border-top:1px solid var(--border);">
            @if($currentIndex > 0)
                <a href="{{ route('staff-take-on.wizard', [$takeOn, $steps[$currentIndex - 1]]) }}" class="text-xs font-semibold" style="color:var(--text-muted);">Previous Step</a>
            @else
                <span></span>
            @endif
        </div>
        @endif
    </div>
</div>
@endsection
