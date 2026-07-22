{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div data-tour="assist-index-intro" class="rounded-md px-6 py-5 flex flex-col md:flex-row md:items-start md:justify-between gap-3" style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">My Assistants</h1>
            <p class="text-sm text-white/60">
                Your assistant works on your behalf. Everything they do is recorded against your name,
                and they can never do more than you can.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start md:self-auto">
            @include('layouts.partials.tour-header-launcher', ['variant' => 'navy'])
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border:1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color:var(--text-primary, #111827);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color:var(--ds-green, #059669);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach($assignments as $row)
            @php $a = $row['model']; @endphp
            <div @if($loop->first) data-tour="assist-index-card" @endif
                 class="rounded-md p-5 flex flex-col gap-4"
                 style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
                <div class="flex-1 min-w-0">
                    <div class="font-bold truncate">{{ $a->assistant?->name }}</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary, #6b7280);">
                        <span class="block truncate">{{ $a->assistant?->email }}</span>
                        Can do <strong>{{ number_format($row['grantedCount']) }}</strong> of the things you can.
                    </div>

                    @if($row['pendingDrift'] > 0)
                        <div class="text-xs mt-3 inline-flex items-center gap-1.5 px-2 py-1 rounded-md whitespace-nowrap"
                             style="background:color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent); color:var(--ds-amber, #d97706);"
                             title="You've been given access to something new. Your assistant does not get it automatically — you decide.">
                            {{ number_format($row['pendingDrift']) }} new {{ \Illuminate\Support\Str::plural('permission', $row['pendingDrift']) }} available
                        </div>
                    @endif
                </div>

                <a href="{{ route('agent.assistants.matrix', $a) }}"
                   @if($loop->first) data-tour="assist-index-matrix" @endif
                   class="corex-btn-primary w-full justify-center">
                    Choose what they can do
                </a>
            </div>
        @endforeach
    </div>

    <p class="text-xs" style="color:var(--text-secondary, #6b7280);">
        Need to add, move or remove an assistant? That's an admin job — ask your administrator.
    </p>
</div>
@endsection
