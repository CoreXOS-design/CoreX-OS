@extends('layouts.corex')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page Header --}}
    <div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight tracking-tight">Deal Log</h2>
                <div class="text-sm text-white/60">#{{ $deal->deal_no }} &mdash; timeline. You may add remarks.</div>
            </div>
            <a href="{{ route('agent.deals.index') }}"
               class="inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-white transition-all duration-300"
               style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);"
               onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                &larr; Back to My Deals
            </a>
        </div>
    </div>

    {{-- Add Remark --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        @if(session('status'))
            <div class="mb-3 rounded-md px-4 py-3 text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #10b981;">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-3 rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: #ef4444;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('agent.deals.remark', $deal) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            @csrf
            <div class="flex-1">
                <label class="block mb-1.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Add remark (creates timeline entry)</label>
                <input type="text" name="remark"
                       class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                       style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
                       onfocus="this.style.borderColor='var(--brand-button, #0ea5e9)';this.style.outline='none'"
                       onblur="this.style.borderColor='var(--border)'"
                       placeholder="Type a remark and click Add..." value="">
            </div>
            <button type="submit" class="corex-btn-primary h-10 px-4 text-sm shrink-0">Add</button>
        </form>
    </div>

    {{-- Timeline --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Timeline</h3>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Chronological log of all deal events and remarks.</div>
        </div>

        <div class="p-5 space-y-3">
            @forelse($logs as $log)
                <div class="rounded-md px-4 py-3 transition-all duration-300"
                     style="background: var(--surface-2); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
                    <div class="flex items-center justify-between">
                        <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $log->event }}</div>
                        <div class="text-xs" style="color: var(--text-muted);">{{ $log->created_at }}</div>
                    </div>
                    <div class="mt-1 text-sm" style="color: var(--text-secondary);">
                        @if($log->message)
                            {{ $log->message }}
                        @else
                            <span style="color: var(--text-muted);">&mdash;</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xs" style="color: var(--text-muted);">
                        @php
                            $actor = $log->actor_user_id ? ($actors[$log->actor_user_id] ?? null) : null;
                            $who = $actor?->name ?? ($log->actor_user_id ? 'Unknown user' : 'System');
                        @endphp
                        By: <span class="font-medium" style="color: var(--brand-icon, #0ea5e9);">{{ $who }}</span>
                    </div>
                </div>
            @empty
                <div class="text-sm py-4 text-center" style="color: var(--text-muted);">No log entries yet.</div>
            @endforelse
        </div>
    </div>

</div>
@endsection
