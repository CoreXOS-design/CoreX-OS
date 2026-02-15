@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto p-4">
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h1 class="text-xl font-semibold">Edit Listing Agents</h1>
            <div class="text-sm text-gray-600 mt-1">
                <div><strong>Listing ID:</strong> {{ $listing->id }}</div>
                <div><strong>Source:</strong> {{ $listing->source }} |
                    <strong>External Ref:</strong> {{ $listing->external_ref }} |
                    <strong>External ID:</strong> {{ $listing->external_id }}</div>
            </div>
        </div>
        <a href="{{ route('admin.listings.agents.show', $listing->user_id) }}" class="text-sm underline">Back to Agent Listings</a>
    </div>

    @if (session('status'))
        <div class="mb-4 p-3 rounded bg-green-50 border border-green-200 text-green-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-800">
            <div class="font-semibold mb-1">Please fix the errors:</div>
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="p-4 rounded border bg-white mb-4">
        <div class="text-sm text-gray-700 whitespace-pre-line">
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

    <form method="POST" action="{{ route('admin.listings.stock.agents.update', $listing) }}" class="p-4 rounded border bg-white">
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

        <button type="submit" class="px-4 py-2 rounded bg-black text-white">
            Save Agents
        </button>
    </form>
</div>
@endsection
