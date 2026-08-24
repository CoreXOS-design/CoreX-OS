{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     AUDIT 2026-07-26 (F5) — the missing U in the assistant CRUD. Identity only: who they work
     for is Reassign (it re-seeds the matrix against a new ceiling) and what they may do is the
     agent's own matrix page. Neither belongs on an "edit details" form. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $assistant = $assignment->assistant;
    // users.name is the FULL name — split for the two-field form, same shape as Add Assistant.
    $parts     = preg_split('/\s+/', trim((string) $assistant?->name), 2);
    $firstName = old('name', $parts[0] ?? '');
    $surname   = old('surname', $parts[1] ?? '');
@endphp
<div class="w-full space-y-5">

    {{-- Page header (branded, full-width — §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5 flex flex-col md:flex-row md:items-center md:justify-between gap-3"
         style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Edit {{ $assistant?->name }}</h1>
            <p class="text-sm mt-0.5 text-white/60">
                Their details only. To move them to a different agent use Reassign; what they may
                do is set by {{ $assignment->assignedAgent?->name ?? 'their agent' }} on My Assistants.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start md:self-auto">
            <a href="{{ route('admin.assistants.show', $assignment) }}"
               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
               style="background:rgba(255,255,255,0.08); color:#fff; border:1px solid rgba(255,255,255,0.18);">
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
                Back
            </a>
        </div>
    </div>

    {{-- Validation errors (§3.9 danger alert) --}}
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border:1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color:var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color:var(--ds-crimson, #c41e3a);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">
                <strong>Please fix the following:</strong>
                <ul class="list-disc list-inside space-y-1 mt-1">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- LEFT: the form --}}
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('admin.assistants.update', $assignment) }}" class="space-y-5">
                @csrf
                @method('PUT')

                {{-- Card: Assistant details --}}
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-5">
                        <svg class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Assistant details</h3>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">First name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ $firstName }}" required
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Surname <span class="text-red-500">*</span></label>
                            <input type="text" name="surname" value="{{ $surname }}" required
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" value="{{ old('email', $assistant?->email) }}" required
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                            <p class="text-xs mt-1" style="color:var(--text-muted);">
                                This is the address they sign in with. Changing it does not re-send the setup link — use Resend invite if they still need one.
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Cell <span class="text-red-500">*</span></label>
                            <input type="text" name="cell" value="{{ old('cell', $assistant?->cell) }}" required placeholder="083 555 0142"
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                                Phone <span style="color:var(--text-muted);">(optional)</span>
                            </label>
                            <input type="text" name="phone" value="{{ old('phone', $assistant?->phone) }}"
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                                Title <span style="color:var(--text-muted);">(optional)</span>
                            </label>
                            <input type="text" name="title" value="{{ old('title', $assistant?->assistant_title) }}" maxlength="60" placeholder="Assistant"
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                            <p class="text-xs mt-1" style="color:var(--text-muted);">
                                What this person is called — e.g. PA, Receptionist or Secretary. A label only; it doesn't change what they can do.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Card: Compliance --}}
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-5">
                        <svg class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Compliance</h3>
                    </div>

                    <div class="flex items-start gap-3">
                        <input type="checkbox" name="fica_required" value="1" id="fica_required" class="mt-1"
                               @checked(old('fica_required', $assistant?->fica_required))>
                        <label for="fica_required" class="text-sm" style="color:var(--text-primary);">
                            <span class="font-semibold">Require FICA verification</span>
                            <span class="block text-xs mt-0.5" style="color:var(--text-muted);">
                                Asks them for an ID copy and proof of residence on their profile, and includes them on
                                your compliance dashboards. Leave off for someone who doesn't handle client documents.
                            </span>
                        </label>
                    </div>
                </div>

                {{-- Action row --}}
                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('admin.assistants.show', $assignment) }}" class="corex-btn-outline">Cancel</a>
                    <button type="submit" class="corex-btn-primary">Save changes</button>
                </div>
            </form>
        </div>

        {{-- RIGHT: what this screen deliberately does NOT change --}}
        <div class="space-y-4">
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-sm font-bold uppercase tracking-wider mb-3" style="color:var(--text-primary);">What this doesn't change</h3>
                <ul class="space-y-3 text-xs" style="color:var(--text-secondary);">
                    <li>
                        <span class="font-semibold" style="color:var(--text-primary);">Who they work for.</span>
                        Currently {{ $assignment->assignedAgent?->name ?? '—' }}. Use
                        <a href="{{ route('admin.assistants.show', $assignment) }}" style="color:var(--brand-icon, #0ea5e9);">Reassign</a>
                        — it archives this matrix and starts a fresh copy of the new agent's permissions.
                    </li>
                    <li>
                        <span class="font-semibold" style="color:var(--text-primary);">What they may do.</span>
                        That is {{ $assignment->assignedAgent?->name ?? 'their agent' }}'s to set, on their own
                        My Assistants page. An admin does not choose an assistant's capabilities.
                    </li>
                    <li>
                        <span class="font-semibold" style="color:var(--text-primary);">Their role.</span>
                        An assistant is always an assistant — never an admin — and that is enforced on save,
                        whatever any form anywhere sends.
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
