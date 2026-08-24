<x-app-layout>
    <x-slot name="header">
        <div class="rounded-md px-6 py-5 corex-page-banner">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Deal Log</h1>
                    <p class="text-xs" style="color: var(--text-muted);">#{{ $deal->deal_no }} &middot; Audit trail (newest first)</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.deals') }}" class="corex-btn-outline text-xs shrink-0">
                        &larr; Back to Deal Register
                    </a>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6 corex-legacy-v2">
        {{-- AT-267 — view-only lock when the current user may not edit this deal (an assistant
             viewing a colleague's deal at their agent's breadth). --}}
        @include('partials._readonly-lock', [
            'canEdit'         => $canEdit ?? true,
            'readonlyMessage' => 'You can view this deal, but only its agent can change it. Ask your agent if something needs updating.',
        ])

        {{-- AT-267 — "added by {assistant}" (show_attribution). Renders nothing unless an assistant
             actually changed this deal and their agent has attribution switched on. --}}
        <x-assistant-attribution type="deal" :id="$deal->id" />

        <div>
            <h2 class="ds-section-header">Timeline</h2>
            <div class="ds-section-sub mb-4">System-created events + user actions.</div>

            <div class="ds-status-card" style="border-left-color: var(--border);">

                @if(session('status'))
                    <div class="mb-3 rounded-md px-4 py-3 text-sm"
                         style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
                        {{ session('status') }}
                    </div>
                @endif

                @if($errors->any())
                    <div class="mb-3 rounded-md px-4 py-3 text-sm"
                         style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.deals.remark', $deal) }}" class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                    @csrf
                    <div class="flex-1">
                        <label class="ds-label block mb-1">Add remark (creates timeline entry)</label>
                        <input type="text" name="remark" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" placeholder="Type a remark and click Add..." value="">
                    </div>
                    <button type="submit" class="corex-btn-primary h-10 px-4 text-xs">Add</button>
                </form>

                @if($logs->isEmpty())
                    <div class="text-sm" style="color:var(--text-secondary)">No log entries yet.</div>
                @else
                    <div class="space-y-3">
                        @foreach($logs as $log)
                            @php
                                $actor = $log->actor_user_id ? ($actors[$log->actor_user_id] ?? null) : null;
                                $who = $actor?->name ?? ($log->actor_user_id ? 'Unknown user' : 'System');
                            @endphp

                            <div class="rounded-md px-4 py-3" style="background:var(--surface-2); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon);">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm font-semibold" style="color:var(--text-primary)">{{ $log->event_type }}</div>
                                    <div class="text-xs" style="color:var(--text-muted)">{{ optional($log->created_at)->format('Y-m-d H:i') }}</div>
                                </div>
                                <div class="mt-1 text-xs" style="color:var(--text-secondary)">By: <span class="font-medium" style="color:var(--text-primary)">{{ $who }}</span></div>

                                @if(!is_null($log->from_value) || !is_null($log->to_value))
                                    <div class="mt-2 text-sm" style="color:var(--text-secondary)">
                                        <span style="color:var(--text-muted)">From:</span> <span class="font-medium">{{ $log->from_value ?? '—' }}</span>
                                        <span class="mx-2" style="color:var(--text-faint)">&rarr;</span>
                                        <span style="color:var(--text-muted)">To:</span> <span class="font-medium">{{ $log->to_value ?? '—' }}</span>
                                    </div>
                                @endif

                                @if(!empty($log->message))
                                    <div class="mt-2 text-sm" style="color:var(--text-secondary)">{{ $log->message }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
