{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-3xl space-y-5">

    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Add Assistant</h1>
        <p class="text-sm text-white/60">
            They get their own login, and start with a copy of their agent's permissions.
            The agent then chooses what to switch off.
        </p>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2, #f0f2f8); color:var(--ds-crimson, #dc2626); border:1px solid var(--ds-crimson, #dc2626);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.assistants.store') }}"
          class="rounded-md p-6 space-y-5"
          style="background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.07));">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">First name</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Surname</label>
                <input type="text" name="surname" value="{{ old('surname') }}" required
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
                <p class="text-xs mt-1" style="color:var(--text-secondary, #6b7280);">
                    We'll email them a link to set their own password.
                </p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Cell</label>
                <input type="text" name="cell" value="{{ old('cell') }}" required placeholder="083 555 0142"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">
                Assigned Agent
            </label>
            <select name="agent_user_id" required
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background:var(--surface-2, #f0f2f8); color:var(--text-primary, #111827); border:1px solid var(--border, rgba(0,0,0,0.07));">
                <option value="">Choose the agent this assistant works for…</option>
                @foreach($agents as $agent)
                    <option value="{{ $agent->id }}" @selected(old('agent_user_id') == $agent->id)>
                        {{ $agent->name }} — {{ $agent->email }}
                    </option>
                @endforeach
            </select>
            <p class="text-xs mt-1" style="color:var(--text-secondary, #6b7280);">
                The assistant can never do more than this agent can, and everything they do is recorded
                as being on this agent's behalf. Owners and other assistants can't be chosen.
            </p>
        </div>

        <div class="flex items-start gap-3 pt-2">
            <input type="checkbox" name="fica_required" value="1" id="fica_required" class="mt-1"
                   @checked(old('fica_required', auth()->user()->agency?->assistant_fica_required_default ?? true))>
            <label for="fica_required" class="text-sm" style="color:var(--text-primary, #111827);">
                <span class="font-semibold">Require FICA verification</span>
                <span class="block text-xs" style="color:var(--text-secondary, #6b7280);">
                    Asks them for an ID copy and proof of residence on their profile, and includes them on
                    your compliance dashboards. Leave off for someone who doesn't handle client documents.
                </span>
            </label>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <a href="{{ route('admin.assistants.index') }}" class="corex-btn-outline">Cancel</a>
            <button type="submit" class="corex-btn-primary">Create &amp; send invite</button>
        </div>
    </form>
</div>
@endsection
