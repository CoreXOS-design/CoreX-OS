{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Assistants</h1>
                <p class="text-sm text-white/60">
                    An assistant works for one agent. They start with a copy of that agent's permissions,
                    and the agent switches off whatever they don't want to hand over.
                </p>
            </div>
            @permission('assistants.create')
            <a href="{{ route('admin.assistants.create') }}" class="corex-btn-primary">Add Assistant</a>
            @endpermission
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            {{ session('success') }}
        </div>
    @endif

    @unless($assistantsEnabled)
        {{-- No silent locks: say why nothing works, and say who can fix it. --}}
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--ds-amber, #d97706);">
            <strong>Assistants are switched off for this agency.</strong>
            You can create assistants here, but they will not be able to do anything until Assistants is
            enabled in Company Settings. Until then every assistant has no access at all.
        </div>
    @endunless

    <div class="rounded-md overflow-hidden"
         style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2, #f0f2f8); color:var(--text-secondary, #6b7280);">
                    <th class="text-left px-4 py-3 font-semibold">Assistant</th>
                    <th class="text-left px-4 py-3 font-semibold">Assigned Agent</th>
                    <th class="text-left px-4 py-3 font-semibold">Branch</th>
                    <th class="text-left px-4 py-3 font-semibold">Status</th>
                    <th class="text-right px-4 py-3 font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($assignments as $assignment)
                @php
                    $assistant = $assignment->assistant;
                    $pending   = $assistant && !$assistant->email_verified_at;
                @endphp
                <tr style="border-top:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
                    <td class="px-4 py-3">
                        <div class="font-semibold">
                            {{ $assistant?->name }}
                            <span class="ml-1.5 px-1.5 py-0.5 rounded text-xs font-medium align-middle"
                                  style="background:var(--surface-2, #f0f2f8); color:var(--text-secondary, #6b7280);">{{ $assistant?->assistantTitle() ?? 'Assistant' }}</span>
                        </div>
                        <div class="text-xs" style="color:var(--text-secondary, #6b7280);">{{ $assistant?->email }}</div>
                    </td>
                    <td class="px-4 py-3">{{ $assignment->assignedAgent?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $assignment->branch?->name ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($assignment->trashed() || $assignment->status === \App\Models\AssistantAssignment::STATUS_REVOKED)
                            <span class="px-2 py-0.5 rounded-md text-xs font-semibold"
                                  style="background:var(--surface-2, #f0f2f8); color:var(--text-secondary, #6b7280);"
                                  title="Access revoked. The record is archived and can be restored.">Revoked</span>
                        @elseif($assignment->status === \App\Models\AssistantAssignment::STATUS_SUSPENDED)
                            <span class="px-2 py-0.5 rounded-md text-xs font-semibold"
                                  style="background:var(--surface-2, #f0f2f8); color:var(--ds-amber, #d97706);"
                                  title="Their agent's account is inactive, so the assistant currently has no access. Reactivating the agent restores it.">Frozen</span>
                        @elseif($pending)
                            <span class="px-2 py-0.5 rounded-md text-xs font-semibold"
                                  style="background:var(--surface-2, #f0f2f8); color:var(--ds-amber, #d97706);"
                                  title="They have been emailed a link to set their password but have not used it yet.">Invite pending</span>
                        @else
                            <span class="px-2 py-0.5 rounded-md text-xs font-semibold"
                                  style="background:var(--surface-2, #f0f2f8); color:var(--ds-green, #059669);">Active</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.assistants.show', $assignment) }}"
                           class="text-xs font-semibold" style="color:var(--brand-icon, #0ea5e9);">View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-10 text-center text-sm" style="color:var(--text-secondary, #6b7280);">
                        No assistants yet.
                        @permission('assistants.create')
                            <a href="{{ route('admin.assistants.create') }}"
                               style="color:var(--brand-icon, #0ea5e9);" class="font-semibold">Add the first one</a>.
                        @endpermission
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $assignments->links() }}
</div>
@endsection
