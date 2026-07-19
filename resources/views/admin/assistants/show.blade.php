{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $assistant = $assignment->assistant;
    $agent     = $assignment->assignedAgent;
    $revoked   = $assignment->trashed() || $assignment->status === \App\Models\AssistantAssignment::STATUS_REVOKED;
    $pending   = $assistant && !$assistant->email_verified_at;
@endphp

<div class="w-full max-w-4xl space-y-5">

    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">
                    {{ $assistant?->name }}
                </h1>
                <p class="text-sm text-white/60">
                    Assistant to <strong class="text-white/90">{{ $agent?->name ?? '—' }}</strong>
                </p>
            </div>
            <a href="{{ route('admin.assistants.index') }}" class="corex-btn-outline">Back to Assistants</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2, #f0f2f8); color:var(--ds-crimson, #dc2626); border:1px solid var(--ds-crimson, #dc2626);">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- Who does what: the split of responsibility, stated on the page so nobody has to guess. --}}
    <div class="rounded-md p-6 space-y-3"
         style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <div class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Email</div>
                <div>{{ $assistant?->email }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Branch</div>
                <div>{{ $assignment->branch?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Permissions granted</div>
                <div>
                    {{ $grantedCount }} of their agent's
                    @if($lockedCount)
                        <span title="Property upload is switched off for every assistant by CoreX and cannot be granted by anyone.">
                            ({{ $lockedCount }} locked by CoreX)
                        </span>
                    @endif
                </div>
            </div>
        </div>

        <p class="text-xs pt-2" style="color:var(--text-secondary, #6b7280); border-top:1px solid var(--border, rgba(0,0,0,0.07));">
            <strong>{{ $agent?->name }}</strong> controls what this assistant may do, from their own
            <em>My Assistants</em> page. You control whether the assistant exists, and who they work for.
        </p>
    </div>

    @if($pending && !$revoked)
        <div class="rounded-md p-4 flex items-center justify-between gap-4"
             style="background:var(--surface, #fff); border:1px solid var(--ds-amber, #d97706); color:var(--text-primary, #111827);">
            <div class="text-sm">
                <strong>Invite pending.</strong>
                {{ $assistant?->name }} hasn't set their password yet, so they can't log in.
            </div>
            @permission('assistants.create')
            <form method="POST" action="{{ route('admin.assistants.resend-invite', $assignment) }}">
                @csrf
                <button type="submit" class="corex-btn-outline">Resend invite</button>
            </form>
            @endpermission
        </div>
    @endif

    {{-- Reassign --}}
    @permission('assistants.reassign')
    @unless($revoked)
    <div class="rounded-md p-6"
         style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
        <h2 class="text-sm font-bold mb-1">Move to a different agent</h2>
        <p class="text-xs mb-3" style="color:var(--text-secondary, #6b7280);">
            Their permissions will be reset to a copy of the new agent's — the old set is archived, not
            deleted, so nothing is lost. The new agent then chooses what to switch off.
        </p>
        <form method="POST" action="{{ route('admin.assistants.reassign', $assignment) }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <input type="hidden" name="current_agent_id" value="{{ $assignment->agent_user_id }}">
            <select name="agent_user_id" required
                    class="flex-1 rounded-md px-3 py-2 text-sm"
                    style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
                <option value="">Choose a new agent…</option>
                @foreach($agents as $candidate)
                    @continue((int) $candidate->id === (int) $assignment->agent_user_id)
                    <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-outline"
                    onclick="return confirm('Move {{ $assistant?->name }} to a different agent? Their permissions will be reset to a copy of the new agent\'s.');">
                Reassign
            </button>
        </form>
    </div>
    @endunless
    @endpermission

    {{-- Revoke / restore --}}
    @permission('assistants.revoke')
    <div class="rounded-md p-6"
         style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
        @if($revoked)
            <h2 class="text-sm font-bold mb-1">Restore access</h2>
            <p class="text-xs mb-3" style="color:var(--text-secondary, #6b7280);">
                This assistant's access was revoked{{ $assignment->revoked_at ? ' on ' . $assignment->revoked_at->format('j M Y') : '' }}.
                @if($assignment->revoke_reason)<br>Reason: {{ $assignment->revoke_reason }}@endif
                <br>Restoring brings back exactly the permissions they had.
            </p>
            <form method="POST" action="{{ route('admin.assistants.restore', $assignment->id) }}">
                @csrf
                <button type="submit" class="corex-btn-outline">Restore assistant</button>
            </form>
        @else
            <h2 class="text-sm font-bold mb-1">Revoke access</h2>
            <p class="text-xs mb-3" style="color:var(--text-secondary, #6b7280);">
                They immediately stop being able to act for {{ $agent?->name }}. Their login stays, and the
                record is archived — you can restore it later with their permissions intact. Nothing is deleted.
            </p>
            <form method="POST" action="{{ route('admin.assistants.revoke', $assignment) }}" class="flex flex-col sm:flex-row gap-3">
                @csrf
                <input type="text" name="reason" placeholder="Reason (optional)"
                       class="flex-1 rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
                <button type="submit" class="corex-btn-outline"
                        style="color:var(--ds-crimson, #dc2626); border-color:var(--ds-crimson, #dc2626);"
                        onclick="return confirm('Revoke {{ $assistant?->name }}\'s assistant access? This can be undone.');">
                    Revoke access
                </button>
            </form>
        @endif
    </div>
    @endpermission
</div>
@endsection
