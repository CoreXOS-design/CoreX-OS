{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (branded, full-width — §2.4 Pattern A) --}}
    <div data-tour="assist-create-intro"
         class="rounded-md px-6 py-5 flex flex-col md:flex-row md:items-center md:justify-between gap-3"
         style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Add Assistant</h1>
            <p class="text-sm mt-0.5 text-white/60">
                They get their own login, and start with a copy of their agent's permissions.
                The agent then chooses what to switch off.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start md:self-auto">
            @include('layouts.partials.tour-header-launcher', ['variant' => 'navy'])
            <a href="{{ route('admin.assistants.index') }}"
               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
               style="background:rgba(255,255,255,0.08); color:#fff; border:1px solid rgba(255,255,255,0.18);">
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
                Back to Assistants
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
            <form method="POST" action="{{ route('admin.assistants.store') }}" class="space-y-5">
                @csrf

                {{-- Card: Assistant details --}}
                <div data-tour="assist-create-details" class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-5">
                        <svg class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Assistant details</h3>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">First name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name') }}" required
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Surname <span class="text-red-500">*</span></label>
                            <input type="text" name="surname" value="{{ old('surname') }}" required
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" value="{{ old('email') }}" required
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                            <p class="text-xs mt-1" style="color:var(--text-muted);">
                                We'll email them a link to set their own password.
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Cell <span class="text-red-500">*</span></label>
                            <input type="text" name="cell" value="{{ old('cell') }}" required placeholder="083 555 0142"
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        </div>
                        <div data-tour="assist-create-title">
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                                Title <span style="color:var(--text-muted);">(optional)</span>
                            </label>
                            <input type="text" name="title" value="{{ old('title') }}" maxlength="60" placeholder="Assistant"
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                            <p class="text-xs mt-1" style="color:var(--text-muted);">
                                What this person is called — e.g. PA, Receptionist or Secretary. A label only; it doesn't change what they can do.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Card: Assignment & compliance --}}
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-5">
                        <svg class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Assignment &amp; compliance</h3>
                    </div>

                    <div class="space-y-4">
                        <div data-tour="assist-create-agent">
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Assigned agent <span class="text-red-500">*</span></label>
                            <select name="agent_user_id" required
                                    class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                    onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                                <option value="">Choose the agent this assistant works for…</option>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}" @selected(old('agent_user_id') == $agent->id)>
                                        {{ $agent->name }} — {{ $agent->email }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-xs mt-1" style="color:var(--text-muted);">
                                Everything the assistant does is recorded as being on this agent's behalf. Owners and other assistants can't be chosen.
                            </p>
                        </div>

                        <div data-tour="assist-create-fica" class="flex items-start gap-3 pt-1">
                            <input type="checkbox" name="fica_required" value="1" id="fica_required" class="mt-1"
                                   @checked(old('fica_required', auth()->user()->agency?->assistant_fica_required_default ?? true))>
                            <label for="fica_required" class="text-sm" style="color:var(--text-primary);">
                                <span class="font-semibold">Require FICA verification</span>
                                <span class="block text-xs mt-0.5" style="color:var(--text-muted);">
                                    Asks them for an ID copy and proof of residence on their profile, and includes them on
                                    your compliance dashboards. Leave off for someone who doesn't handle client documents.
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Action row --}}
                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('admin.assistants.index') }}" class="corex-btn-outline">Cancel</a>
                    <button type="submit" data-tour="assist-create-submit" class="corex-btn-primary">Create &amp; send invite</button>
                </div>
            </form>
        </div>

        {{-- RIGHT: helper panel — the guidance that used to be crammed into field hints --}}
        <div class="space-y-4">
            <div data-tour="assist-create-help" class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-sm font-bold uppercase tracking-wider mb-4" style="color:var(--text-primary);">How assistants work</h3>
                <ul class="space-y-3.5 text-sm" style="color:var(--text-secondary);">
                    <li class="flex items-start gap-2.5">
                        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        <span>They start with a <strong style="color:var(--text-primary);">copy of their agent's permissions</strong>, all switched on.</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        <span>The agent then switches off whatever they don't want to hand over — an assistant can <strong style="color:var(--text-primary);">never do more than their agent</strong>.</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        <span>Everything they do lands on the <strong style="color:var(--text-primary);">agent's book</strong> and is recorded as being on the agent's behalf.</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        <span>They can <strong style="color:var(--text-primary);">never create a listing</strong>, and they never appear as a billable user.</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
