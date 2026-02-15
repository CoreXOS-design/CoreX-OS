<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xl font-semibold text-gray-900">Deal Log</div>
                <div class="text-sm text-gray-500">#{{ $deal->deal_no }} — timeline. You may add remarks.</div>
            </div>
            <a href="{{ route('agent.deals.index') }}"
               class="inline-flex items-center rounded-xl bg-white px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-gray-200 hover:bg-gray-50">
                ← Back to My Deals
            </a>
        </div>
    </x-slot>

    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4">
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
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Add remark (creates timeline entry)</label>
                    <input type="text" name="remark" class="w-full rounded-xl border-gray-200" placeholder="Type a remark and click Add..." value="">
                </div>
                <button type="submit" class="inline-flex h-10 items-center justify-center rounded-xl bg-gray-900 px-4 text-sm font-semibold text-white shadow-sm hover:bg-gray-800">Add</button>
            </form>

            <div class="space-y-2">
                @forelse($logs as $log)
                    <div class="rounded-xl border px-4 py-3">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-semibold text-gray-900">{{ $log->event }}</div>
                            <div class="text-xs text-gray-500">{{ $log->created_at }}</div>
                        </div>
                        <div class="mt-1 text-sm text-gray-700">
                            @if($log->message)
                                {{ $log->message }}
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            @php
    $actor = $log->actor_user_id ? ($actors[$log->actor_user_id] ?? null) : null;
    $who = $actor?->name ?? ($log->actor_user_id ? 'Unknown user' : 'System');
@endphp
By: {{ $who }}
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-gray-500">No log entries yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
