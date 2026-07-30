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

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                    {{ $assistant?->name }}
                </h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    {{ $assistant?->assistantTitle() ?? 'Assistant' }} to <strong style="color: var(--text-secondary);">{{ $agent?->name ?? '—' }}</strong>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                {{-- AUDIT 2026-07-26 (F5) — the entry point to the previously-missing edit surface.
                     Non-negotiable #2: the page exists the same day its navigation does. --}}
                @if(!$assignment->trashed())
                    <a href="{{ route('admin.assistants.edit', $assignment) }}" class="corex-btn-outline text-xs">Edit details</a>
                @endif
                <a href="{{ route('admin.assistants.index') }}" class="corex-btn-outline text-xs">Back to Assistants</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2); color:var(--ds-crimson, #dc2626); border:1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- Who does what: the split of responsibility, stated on the page so nobody has to guess. --}}
    <div class="rounded-lg p-6 space-y-3"
         style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Email</div>
                <div>{{ $assistant?->email }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Branch</div>
                <div>{{ $assignment->branch?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Permissions granted</div>
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

        <p class="text-xs pt-2" style="color:var(--text-muted); border-top:1px solid var(--border);">
            <strong style="color:var(--text-secondary);">{{ $agent?->name }}</strong> controls what this assistant may do, from their own
            <em>My Assistants</em> page. You control whether the assistant exists, and who they work for.
        </p>
    </div>

    {{-- Multi-agent addendum (assistants-multi-agent-spec.md §7) — Sub-Agent links.
         The Main Agent above is unchanged and singular; this section only widens whose
         records the assistant may see and edit. Admin/super_admin manage this exclusively. --}}
    @unless($revoked)
    <div class="rounded-lg p-6 space-y-4"
         style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        <div>
            <h2 class="text-sm font-bold mb-1">Also supports these agents</h2>
            <p class="text-xs" style="color:var(--text-muted);">
                {{ $assistant?->name }} can also see and edit these agents' own records — properties,
                contacts, deals — the same way they already can for {{ $agent?->name }}. This never
                changes what {{ $assistant?->name }} is allowed to DO, only whose records they may reach.
            </p>
        </div>

        @if($assignment->linkedAgentLinks->isNotEmpty())
            <ul class="space-y-2">
                @foreach($assignment->linkedAgentLinks as $link)
                    <li class="flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface-2); border:1px solid var(--border);">
                        <span>
                            {{ $link->agent?->name ?? 'Unknown agent' }}
                            <span class="text-xs" style="color:var(--text-muted);">
                                {{ $link->agent?->branch?->name ?? '—' }}
                            </span>
                        </span>
                        @permission('assistants.manage_linked_agents')
                        <form method="POST" action="{{ route('admin.assistants.linked-agents.destroy', [$assignment, $link]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="corex-btn-outline text-xs"
                                    style="color:var(--ds-crimson, #dc2626); border-color:var(--ds-crimson, #dc2626);"
                                    onclick="return confirm('Unlink {{ $link->agent?->name }}? {{ $assistant?->name }} will immediately lose access to their records. This can be restored later.');">
                                Unlink
                            </button>
                        </form>
                        @endpermission
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-xs" style="color:var(--text-muted);">No additional agents linked yet.</p>
        @endif

        @permission('assistants.manage_linked_agents')
        @if($linkableAgents->isNotEmpty())
            <form method="POST" action="{{ route('admin.assistants.linked-agents.store', $assignment) }}"
                  class="flex flex-col sm:flex-row gap-3 pt-2" style="border-top:1px solid var(--border);">
                @csrf
                <select name="agent_user_id" required
                        class="flex-1 rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                    <option value="">Choose an agent to add…</option>
                    @foreach($linkableAgents as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="corex-btn-outline">Link agent</button>
            </form>
        @endif
        @endpermission
    </div>
    @endunless

    @if($pending && !$revoked)
        <div class="rounded-lg p-4 flex items-center justify-between gap-4"
             style="background:var(--surface); border:1px solid color-mix(in srgb, var(--ds-amber, #d97706) 40%, transparent); color:var(--text-primary);">
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
    <div class="rounded-lg p-6"
         style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        <h2 class="text-sm font-bold mb-1">Move to a different agent</h2>
        <p class="text-xs mb-3" style="color:var(--text-muted);">
            Their permissions will be reset to a copy of the new agent's — the old set is archived, not
            deleted, so nothing is lost. The new agent then chooses what to switch off.
        </p>
        <form method="POST" action="{{ route('admin.assistants.reassign', $assignment) }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <input type="hidden" name="current_agent_id" value="{{ $assignment->agent_user_id }}">
            <select name="agent_user_id" required
                    class="flex-1 rounded-md px-3 py-2 text-sm"
                    style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
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
    <div class="rounded-lg p-6"
         style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        @if($revoked)
            <h2 class="text-sm font-bold mb-1">Restore access</h2>
            <p class="text-xs mb-3" style="color:var(--text-muted);">
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
            <p class="text-xs mb-3" style="color:var(--text-muted);">
                They immediately stop being able to act for {{ $agent?->name }}. Their login stays, and the
                record is archived — you can restore it later with their permissions intact. Nothing is deleted.
            </p>
            <form method="POST" action="{{ route('admin.assistants.revoke', $assignment) }}" class="flex flex-col sm:flex-row gap-3">
                @csrf
                <input type="text" name="reason" placeholder="Reason (optional)"
                       class="flex-1 rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
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
