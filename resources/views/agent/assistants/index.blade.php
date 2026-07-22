{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-4xl space-y-5">

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
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            {{ session('success') }}
        </div>
    @endif

    @foreach($assignments as $row)
        @php $a = $row['model']; @endphp
        <div @if($loop->first) data-tour="assist-index-card" @endif
             class="rounded-md p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4"
             style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
            <div>
                <div class="font-bold">{{ $a->assistant?->name }}</div>
                <div class="text-xs" style="color:var(--text-secondary, #6b7280);">
                    {{ $a->assistant?->email }} —
                    can do <strong>{{ $row['grantedCount'] }}</strong> of the things you can.
                </div>

                @if($row['pendingDrift'] > 0)
                    <div class="text-xs mt-2 inline-flex items-center gap-1.5 px-2 py-1 rounded-md"
                         style="background:var(--surface-2, #f0f2f8); color:var(--ds-amber, #d97706);"
                         title="You've been given access to something new. Your assistant does not get it automatically — you decide.">
                        {{ $row['pendingDrift'] }} new {{ \Illuminate\Support\Str::plural('permission', $row['pendingDrift']) }} available
                    </div>
                @endif
            </div>

            <a href="{{ route('agent.assistants.matrix', $a) }}"
               @if($loop->first) data-tour="assist-index-matrix" @endif
               class="corex-btn-primary">
                Choose what they can do
            </a>
        </div>
    @endforeach

    <p class="text-xs" style="color:var(--text-secondary, #6b7280);">
        Need to add, move or remove an assistant? That's an admin job — ask your administrator.
    </p>
</div>
@endsection
