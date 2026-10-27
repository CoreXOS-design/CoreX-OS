@extends('layouts.corex')

{{-- DR2 Deal Log — the deal's audit trail (newest first) + "Add remark". Theme tokens only, same page
     header as the Deal Register / Pipeline views. --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Deal Log</h1>
                <p class="text-xs" style="color: var(--text-muted);">#{{ $deal->deal_no }} &middot; Audit trail (newest first)</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('deals-dr2.index') }}" class="corex-btn-outline text-xs shrink-0 inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Back to Deal Register
                </a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-red, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-red, #dc2626) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">

        {{-- Add remark --}}
        <form method="POST" action="{{ route('deals-dr2.remark', $deal) }}" class="px-5 py-4 flex flex-col gap-2 sm:flex-row sm:items-end" style="border-bottom: 1px solid var(--border);">
            @csrf
            <div class="flex-1">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Add remark <span style="color: var(--text-muted); font-weight: 400;">(creates a timeline entry)</span></label>
                <input type="text" name="remark"
                       class="list-header-filter w-full text-sm"
                       placeholder="Type a remark and click Add..." value="">
            </div>
            <button type="submit" class="corex-btn-primary text-xs justify-center" style="height: 2.25rem; padding-left: 1.25rem; padding-right: 1.25rem;">Add</button>
        </form>

        {{-- Timeline --}}
        <div class="px-5 py-4">
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <div>
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">Timeline</div>
                    <div class="text-xs" style="color: var(--text-muted);">System-created events + user actions.</div>
                </div>
                <span class="text-xs" style="color: var(--text-muted);">{{ $logs->count() }} {{ \Illuminate\Support\Str::plural('entry', $logs->count()) }}</span>
            </div>

            @if($logs->isEmpty())
                <div class="rounded-md px-4 py-8 text-center text-sm" style="background: var(--surface-2); color: var(--text-secondary);">No log entries yet.</div>
            @else
                <ol class="relative">
                    @foreach($logs as $log)
                        @php
                            $actor = $log->actor_user_id ? ($actors[$log->actor_user_id] ?? null) : null;
                            $who = $actor?->name ?? ($log->actor_user_id ? 'Unknown user' : 'System');
                        @endphp
                        <li class="relative pl-6 pb-4 last:pb-0">
                            {{-- rail + dot --}}
                            @unless($loop->last)<span class="absolute left-[5px] top-3 bottom-0 w-px" style="background: var(--border);" aria-hidden="true"></span>@endunless
                            <span class="absolute left-0 top-1.5 w-[11px] h-[11px] rounded-full" style="background: var(--surface); border: 2px solid var(--brand-icon);" aria-hidden="true"></span>

                            <div class="rounded-md px-4 py-3 transition-colors duration-200" style="background: var(--surface-2); border: 1px solid var(--border);">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm font-semibold" style="color: var(--text-primary);" title="{{ $log->event_type }}">{{ \Illuminate\Support\Str::headline((string) $log->event_type) }}</div>
                                    <div class="text-xs tabular-nums" style="color: var(--text-muted);">{{ optional($log->created_at)->format('Y-m-d H:i') }}</div>
                                </div>
                                <div class="mt-0.5 text-xs" style="color: var(--text-muted);">By <span class="font-medium" style="color: var(--text-secondary);">{{ $who }}</span></div>

                                @if(!is_null($log->from_value) || !is_null($log->to_value))
                                    <div class="mt-2 text-sm flex flex-wrap items-center gap-x-2 gap-y-1" style="color: var(--text-secondary);">
                                        <span style="color: var(--text-muted);">From:</span> <span class="font-medium">{{ $log->from_value ?? '—' }}</span>
                                        <svg class="w-3.5 h-3.5" style="color: var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        <span style="color: var(--text-muted);">To:</span> <span class="font-medium">{{ $log->to_value ?? '—' }}</span>
                                    </div>
                                @endif

                                @if(!empty($log->message))
                                    <div class="mt-2 text-sm" style="color: var(--text-primary);">{{ $log->message }}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</div>
@endsection
