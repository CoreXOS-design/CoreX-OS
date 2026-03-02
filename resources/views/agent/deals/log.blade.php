<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">Deal Log</h2>
                    <div class="text-sm text-white/60">#{{ $deal->deal_no }} &mdash; timeline. You may add remarks.</div>
                </div>
                <a href="{{ route('agent.deals.index') }}"
                   class="inline-flex items-center rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/20 hover:bg-white/15">
                    &larr; Back to My Deals
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <div>
            <h2 class="ds-section-header">Timeline</h2>
            <div class="ds-section-sub mb-4">Add remarks below.</div>

            <div class="ds-status-card">
                @if(session('status'))
                    <div class="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif

                @if($errors->any())
                    <div class="mb-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('agent.deals.remark', $deal) }}" class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                    @csrf
                    <div class="flex-1">
                        <label class="ds-label block mb-1">Add remark (creates timeline entry)</label>
                        <input type="text" name="remark" class="w-full rounded-xl border-gray-200" placeholder="Type a remark and click Add..." value="">
                    </div>
                    <button type="submit" class="corex-btn-primary h-10 px-4 text-sm">Add</button>
                </form>

                <div class="space-y-2">
                    @forelse($logs as $log)
                        <div class="rounded-xl border px-4 py-3" style="border-left: 3px solid var(--ds-cyan);">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold" style="color:#0b2a4a">{{ $log->event }}</div>
                                <div class="text-xs text-gray-500">{{ $log->created_at }}</div>
                            </div>
                            <div class="mt-1 text-sm text-gray-700">
                                @if($log->message)
                                    {{ $log->message }}
                                @else
                                    <span class="text-gray-400">&mdash;</span>
                                @endif
                            </div>
                            <div class="mt-1 text-xs text-gray-500">
                                @php
    $actor = $log->actor_user_id ? ($actors[$log->actor_user_id] ?? null) : null;
    $who = $actor?->name ?? ($log->actor_user_id ? 'Unknown user' : 'System');
@endphp
By: <span class="font-medium" style="color:#0b2a4a">{{ $who }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">No log entries yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
