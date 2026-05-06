@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Take-On: {{ $takeOn->user->name }}" :back-route="route('staff-take-on.index')" back-label="Staff Take-On" :flush="true">
        <x-slot:actions>
            @if(!$takeOn->isComplete())
                <a href="{{ route('staff-take-on.index') }}" class="inline-flex items-center px-3 py-2 text-xs font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Save & Exit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6 max-w-5xl">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); border-radius:6px; color:#ef4444;">{{ session('error') }}</div>
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
                   class="flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-semibold transition"
                   style="{{ $isCurrent ? 'background:#00d4aa; color:white; border-radius:6px;' : ($isDone ? 'background:rgba(0,212,170,0.08); color:#00d4aa; border-radius:6px;' : 'background:var(--surface-2, #f1f5f9); color:var(--text-secondary, #94a3b8); border-radius:6px;') }}">
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
        <div class="flex items-center justify-between mt-6 pt-4" style="border-top:1px solid var(--border, #e5e7eb);">
            @if($currentIndex > 0)
                <a href="{{ route('staff-take-on.wizard', [$takeOn, $steps[$currentIndex - 1]]) }}" class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Previous Step</a>
            @else
                <span></span>
            @endif
        </div>
        @endif
    </div>
</div>
@endsection
