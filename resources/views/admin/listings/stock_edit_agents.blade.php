@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit Listing Agents</h1>
                <div class="text-xs mt-1" style="color: var(--text-muted);">
                    Listing #{{ $listing->id }} &middot; {{ $listing->source }} &middot; {{ $listing->external_ref }} / {{ $listing->external_id }}
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.listings.agents.show', $listing->user_id) }}" class="corex-btn-outline text-xs">&larr; Back</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <div class="font-semibold mb-1">Please fix the errors:</div>
            <ul class="list-disc pl-5 text-sm space-y-1">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ds-status-card p-4" style="border-left-color: var(--border);">
        <div class="text-sm whitespace-pre-line" style="color:var(--text-secondary)">
            <strong>Property:</strong><br>
            {{ $listing->property }}
        </div>

        @php
            $agentsRaw = is_array($listing->raw_payload) ? ($listing->raw_payload['Agents'] ?? null) : null;
        @endphp

        <div class="mt-3 text-sm" style="color:var(--text-secondary)">
            <strong>Imported Agents (raw):</strong> {{ $agentsRaw ?: '(none)' }}
        </div>

        <div class="mt-3 text-sm" style="color:var(--text-secondary)">
            <strong>Current Primary Agent:</strong> {{ optional($listing->user)->name }} ({{ optional($listing->user)->email }})
        </div>
    </div>

    <form method="POST" action="{{ route('admin.listings.stock.agents.update', $listing) }}" class="ds-status-card p-4" style="border-left-color: var(--border);">
        @csrf

        <div class="mb-4">
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Primary Agent</label>
            <select name="primary_user_id" class="w-full rounded-md text-sm px-3 py-2"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                @foreach ($users as $u)
                    <option value="{{ $u->id }}" @selected(old('primary_user_id', $listing->user_id) == $u->id)>
                        {{ $u->name }} ({{ $u->email }}) — {{ $u->role }}
                    </option>
                @endforeach
            </select>
            <div class="text-xs mt-1" style="color:var(--text-muted)">This controls the default “owner” (existing system field).</div>
        </div>

        <div class="mb-4">
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Additional Agents (multi-select)</label>
            <select name="agent_ids[]" multiple size="10" class="w-full rounded-md text-sm px-3 py-2"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
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
            <div class="text-xs mt-1" style="color:var(--text-muted)">Hold Ctrl (Windows) / Cmd (Mac) to select multiple.</div>
        </div>

        <button type="submit" class="corex-btn-primary">
            Save Agents
        </button>
    </form>
</div>
@endsection
