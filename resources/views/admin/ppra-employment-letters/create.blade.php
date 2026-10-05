{{-- .ai/specs/ppra-ffc-employment-letter.md — create-on-behalf (2026-10-05) --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ search: '', agentId: null }">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Start a PPRA Employment Letter</h1>
                <p class="text-xs" style="color: var(--text-muted);">Pick the agent this letter is for. They will still sign it themselves with their own PIN — this only starts it on their behalf.</p>
            </div>
            <a href="{{ route('admin.ppra-employment-letters.index') }}" class="corex-btn-outline text-xs">Back to list</a>
        </div>
    </div>

    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 10%, transparent); color:var(--ds-crimson,#c41e3a); border:1px solid color-mix(in srgb, var(--ds-crimson,#c41e3a) 25%, transparent);">
            {{ session('error') }}
        </div>
    @endif

    @if($principalStatus === 'none')
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
            This agency has no principal set — a letter cannot be started for anyone until a user is flagged as principal practitioner in <a href="{{ route('admin.users') }}" style="text-decoration:underline;">Admin → Users</a>.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.ppra-employment-letters.store') }}" class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
        @csrf

        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search agent</label>
            <input type="text" x-model="search" placeholder="Type a name to filter the list below"
                   class="text-sm rounded-md px-3 py-1.5 w-full max-w-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>

        @if($agents->isEmpty())
            <p class="text-sm" style="color:var(--text-muted);">No agents in your scope are eligible right now.</p>
        @else
        <div class="rounded-md divide-y max-h-80 overflow-y-auto" style="border:1px solid var(--border); border-color:var(--border);">
            @foreach($agents as $a)
            <label x-show="search === '' || '{{ strtolower($a['name']) }}'.includes(search.toLowerCase())" x-cloak
                   class="flex items-center justify-between gap-3 px-4 py-2.5 cursor-pointer" style="color:var(--text-primary);">
                <span class="flex items-center gap-3">
                    <input type="radio" name="user_id" value="{{ $a['id'] }}" x-model="agentId" required>
                    <span>
                        <span class="text-sm font-semibold">{{ $a['name'] }}</span>
                        <span class="text-xs block" style="color:var(--text-muted);">{{ $a['designation'] ?: ucfirst($a['role']) }}{{ $a['ffc_number'] ? ' · FFC #' . $a['ffc_number'] : '' }}</span>
                    </span>
                </span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                      style="background:color-mix(in srgb, {{ $a['ffc']['status'] === 'green' ? 'var(--ds-green)' : ($a['ffc']['status'] === 'amber' ? 'var(--ds-amber)' : 'var(--ds-crimson,#c41e3a)') }} 15%, transparent); color:{{ $a['ffc']['status'] === 'green' ? 'var(--ds-green)' : ($a['ffc']['status'] === 'amber' ? 'var(--ds-amber)' : 'var(--ds-crimson,#c41e3a)') }};">
                    {{ $a['ffc']['label'] }}
                </span>
            </label>
            @endforeach
        </div>
        @endif

        @if($principalStatus === 'multiple')
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Principal for this letter</label>
            <select name="principal_user_id" required class="text-sm rounded-md px-3 py-1.5 w-full max-w-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">Choose a principal</option>
                @foreach($principals as $p)
                <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                @endforeach
            </select>
        </div>
        @endif

        <button type="submit" class="corex-btn-primary text-xs" {{ $principalStatus === 'none' || $agents->isEmpty() ? 'disabled' : '' }}>Start letter</button>
    </form>

</div>
@endsection
