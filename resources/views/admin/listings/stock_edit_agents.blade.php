@extends('layouts.corex')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Edit Listing Agents</h2>
                <div class="text-sm text-white/60 mt-1">
                    Listing #{{ $listing->id }} &middot; {{ $listing->source }} &middot; {{ $listing->external_ref }} / {{ $listing->external_id }}
                </div>
            </div>
            <a href="{{ route('admin.listings.agents.show', $listing->user_id) }}" class="corex-btn-outline text-sm">&larr; Back</a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 dark:border-emerald-900/40 dark:bg-emerald-900/20 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-100">
            <div class="font-semibold mb-1">Please fix the errors:</div>
            <ul class="list-disc pl-5 text-sm space-y-1">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ds-status-card p-4">
        <div class="text-sm text-slate-700 dark:text-slate-200 whitespace-pre-line">
            <strong>Property:</strong><br>
            {{ $listing->property }}
        </div>

        @php
            $agentsRaw = is_array($listing->raw_payload) ? ($listing->raw_payload['Agents'] ?? null) : null;
        @endphp

        <div class="mt-3 text-sm text-gray-700">
            <strong>Imported Agents (raw):</strong> {{ $agentsRaw ?: '(none)' }}
        </div>

        <div class="mt-3 text-sm text-gray-700">
            <strong>Current Primary Agent:</strong> {{ optional($listing->user)->name }} ({{ optional($listing->user)->email }})
        </div>
    </div>

    <form method="POST" action="{{ route('admin.listings.stock.agents.update', $listing) }}" class="ds-status-card p-4">
        @csrf

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Primary Agent</label>
            <select name="primary_user_id" class="w-full border rounded p-2">
                @foreach ($users as $u)
                    <option value="{{ $u->id }}" @selected(old('primary_user_id', $listing->user_id) == $u->id)>
                        {{ $u->name }} ({{ $u->email }}) — {{ $u->role }}
                    </option>
                @endforeach
            </select>
            <div class="text-xs text-gray-500 mt-1">This controls the default “owner” (existing system field).</div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Additional Agents (multi-select)</label>
            <select name="agent_ids[]" multiple size="10" class="w-full border rounded p-2">
                @php
                    $selected = old('agent_ids', $selectedAgentIds ?? []);
                    if (!is_array($selected)) $selected = [];
                @endphp
                @foreach ($users as $u)
                    <option value="{{ $u->id }}" @selected(in_array($u->id, $selected, true))>
                        {{ $u->name }} ({{ $u->email }}) — {{ $u->role }}
                    </option>
                @endforeach
            </select>
            <div class="text-xs text-gray-500 mt-1">Hold Ctrl (Windows) / Cmd (Mac) to select multiple.</div>
        </div>

        <button type="submit" class="corex-btn-primary">
            Save Agents
        </button>
    </form>
</div>
@endsection
