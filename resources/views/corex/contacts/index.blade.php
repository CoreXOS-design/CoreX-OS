{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" data-tour-root="contacts"
     x-data="{ showAdd: {{ (session('duplicate_detected') || old('first_name')) ? 'true' : 'false' }}, showImport: false, editId: null, importLoading: false }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Contacts</h1>
                <p class="text-sm text-white/60">Manage your contacts and leads.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
            @include('layouts.partials.tour-header-launcher')
            @if(auth()->user()->effectiveRole() === 'super_admin')
            <button type="button" @click="showImport = !showImport" class="corex-btn-outline text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
                </svg>
                Import
            </button>
            @endif
            @permission('contacts.export')
            <div class="relative" x-data="{ exportOpen: false }" @keydown.escape="exportOpen = false">
                <button type="button" @click="exportOpen = !exportOpen" @click.outside="exportOpen = false" class="corex-btn-outline text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    Export
                </button>
                <div x-show="exportOpen" x-cloak x-transition.opacity
                     class="absolute right-0 mt-1 w-56 rounded-md py-1 z-20 shadow-lg"
                     style="background:var(--surface); border:1px solid var(--border);">
                    <a href="{{ route('corex.contacts.export', request()->only(['search', 'type', 'agent_id'])) }}"
                       class="block px-4 py-2 text-sm transition-colors" style="color:var(--text-primary);"
                       onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        Export current view
                    </a>
                    <a href="{{ route('corex.contacts.export', ['all' => 1]) }}"
                       class="block px-4 py-2 text-sm transition-colors" style="color:var(--text-primary);"
                       onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        Export all contacts
                    </a>
                </div>
            </div>
            @endpermission
            <button type="button" @click="showAdd = !showAdd" data-tour="contact-add-btn" class="corex-btn-primary text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Add Contact
            </button>
            @permission('access_settings')
            <a href="{{ url('/corex/settings?section=my-portal&s=feature-contacts') }}"
               title="Contacts Settings"
               aria-label="Contacts Settings"
               class="inline-flex items-center justify-center rounded-md text-white transition-colors"
               style="width:30px; height:30px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.18);"
               onmouseover="this.style.background='rgba(255,255,255,0.18)'"
               onmouseout="this.style.background='rgba(255,255,255,0.10)'">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </a>
            @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add Contact Form (collapsible) --}}
    <div x-show="showAdd" x-cloak data-tour="contact-form"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         x-data="{
            dupChecking: false,
            dupFound: false,
            dupData: {},
            async checkDuplicate() {
                // AT-125 — read the first non-empty value from the phones/emails
                // repeaters (the live hint; store() checks ALL identifiers).
                const firstVal = (group) => [...document.querySelectorAll(`[data-identifier-group='${group}'] [data-identifier-value]`)]
                    .map(el => el.value.trim()).find(v => v) || '';
                const phone = firstVal('phones');
                const email = firstVal('emails');
                if (!phone && !email) { this.dupFound = false; return; }
                this.dupChecking = true;
                try {
                    const res = await fetch('{{ route('corex.contacts.check-duplicate') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ phone, email }),
                    });
                    const data = await res.json();
                    if (data.found) {
                        this.dupData = data;
                        this.dupFound = true;
                    } else {
                        this.dupFound = false;
                    }
                } catch (e) {
                    this.dupFound = false;
                } finally {
                    this.dupChecking = false;
                }
            }
         }"
         x-on:contact-check-dup.window="checkDuplicate()"
         class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-lg font-semibold mb-4" style="color:var(--text-primary);">New Contact</div>

        {{-- Live duplicate warning — fires on phone/email blur (checkDuplicate()).
             Mirrors the server-side block so the agent sees WHO the existing
             contact sits under before they try to save. --}}
        <div x-show="dupFound" x-cloak
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             class="mb-4 rounded-md p-4"
             style="background: color-mix(in srgb, var(--ds-amber) 12%, var(--surface-2)); border:1px solid color-mix(in srgb, var(--ds-amber) 45%, transparent);">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color:var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold" style="color:var(--text-primary);">Possible duplicate contact</p>
                    <p class="text-xs mt-0.5" style="color:var(--text-secondary);">A contact with this phone or email already exists. Saving is blocked to prevent a duplicate — open the existing record instead.</p>
                    <div class="mt-3 rounded-md p-3 text-xs space-y-1.5" style="background:var(--surface); border:1px solid var(--border);">
                        <div class="flex justify-between gap-3">
                            <span style="color:var(--text-muted);">Name</span>
                            <span class="font-semibold text-right truncate" style="color:var(--text-primary);" x-text="dupData.name"></span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span style="color:var(--text-muted);">Agent</span>
                            <span class="font-semibold text-right truncate" style="color:var(--text-primary);" x-text="dupData.agent"></span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span style="color:var(--text-muted);">Phone</span>
                            <span class="text-right truncate" style="color:var(--text-secondary);" x-text="dupData.phone"></span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span style="color:var(--text-muted);">Email</span>
                            <span class="text-right truncate" style="color:var(--text-secondary);" x-text="dupData.email"></span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span style="color:var(--text-muted);">Type</span>
                            <span class="text-right truncate" style="color:var(--text-secondary);" x-text="dupData.type"></span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span style="color:var(--text-muted);">Last contacted</span>
                            <span class="text-right truncate" style="color:var(--text-secondary);" x-text="dupData.last_contacted"></span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a :href="dupData.url" target="_blank"
                           class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
                           style="color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 30%, transparent);">
                            Open existing contact
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Duplicate detection modal (server-driven, 4-mode) --}}
        @include('components.duplicate-detection-modal')

        <form method="POST" action="{{ route('corex.contacts.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required
                           data-tour="contact-first-name"
                           placeholder="e.g. John"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required
                           data-tour="contact-last-name"
                           placeholder="e.g. Smith"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                </div>
                <div class="sm:col-span-2 lg:col-span-3" data-tour="contact-phone">
                    @include('corex.contacts._identifier-repeater', ['kind' => 'phones', 'type' => 'text', 'title' => 'Phone Numbers', 'addLabel' => 'phone', 'placeholder' => 'e.g. 082 123 4567'])
                </div>
                <div class="sm:col-span-2 lg:col-span-3" data-tour="contact-email">
                    @include('corex.contacts._identifier-repeater', ['kind' => 'emails', 'type' => 'email', 'title' => 'Emails (optional — but a contact needs at least one phone or email)', 'addLabel' => 'email', 'placeholder' => 'e.g. john@example.com'])
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">ID Number <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                    <input type="text" name="id_number" value="{{ old('id_number') }}"
                           inputmode="numeric" maxlength="13" pattern="\d{13}"
                           placeholder="e.g. 7610025020081" title="13 digits — empty is fine"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                    <p class="mt-1 text-[11px]" style="color:var(--text-muted);">SA ID — 13 digits. Leave blank if not known.</p>
                </div>
                <div class="sm:col-span-2 lg:col-span-3" data-tour="contact-type">
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contact Type <span class="text-red-500">*</span></label>
                    @include('corex.contacts._type_picker', ['contactTypes' => $contactTypes])
                    @error('parent_type_ids')<p class="mt-1 text-[11px]" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" data-tour="contact-save" class="corex-btn-primary text-sm" :disabled="dupFound"
                        :style="dupFound ? 'opacity:0.4; cursor:not-allowed;' : ''">Save Contact</button>
                <button type="button" @click="showAdd = false" class="text-sm transition-all duration-300" style="color:var(--text-muted);">Cancel</button>
            </div>
        </form>
    </div>

    {{-- Import Contacts Panel (collapsible) --}}
    <div x-show="showImport" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex items-center justify-between mb-4">
            <div>
                <div class="text-lg font-semibold" style="color:var(--text-primary);">Import Contacts from Excel</div>
                <p class="text-xs mt-1" style="color:var(--text-muted);">Upload an .xlsx file. Contacts will be matched to agents by name, and new types/sources/tags will be created automatically if they don't exist.</p>
            </div>
        </div>
        <form method="POST" action="{{ route('corex.contacts.import') }}" enctype="multipart/form-data"
              @submit="importLoading = true" class="space-y-4">
            @csrf
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[250px]">
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Excel File (.xlsx)</label>
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <label class="flex items-start gap-2 cursor-pointer rounded-md px-3 py-2 w-full"
                       style="background:var(--surface-2); border:1px solid var(--border);">
                    <input type="checkbox" name="mark_fica_approved" value="1" checked class="mt-0.5">
                    <span class="text-xs" style="color:var(--text-secondary);">
                        <span class="font-semibold" style="color:var(--text-primary);">Mark all imported contacts as FICA approved</span>
                        <span class="block mt-0.5" style="color:var(--text-muted);">
                            Use for agency go-live migrations only — contacts brought over from a prior CRM are treated as already FICA-verified. Leave unticked for fresh contact lists that still need FICA collection.
                        </span>
                    </span>
                </label>
                <div class="flex items-center gap-3">
                    <button type="submit" class="corex-btn-primary text-sm" :disabled="importLoading">
                        <template x-if="!importLoading">
                            <span class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
                                </svg>
                                Import
                            </span>
                        </template>
                        <template x-if="importLoading">
                            <span class="flex items-center gap-1.5">
                                <svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Importing…
                            </span>
                        </template>
                    </button>
                    <button type="button" @click="showImport = false" class="text-sm transition-all duration-300" style="color:var(--text-muted);">Cancel</button>
                </div>
            </div>

            <div class="rounded-md p-3 text-xs" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                <div class="font-semibold mb-1" style="color:var(--text-secondary);">Expected columns:</div>
                <div>Name, Surname, Email, Cell, Phone, Type, *ID Number, BirthDay, Tags, Source, Address, Agents, Notes</div>
                <div class="mt-1">Additional columns (Category, WhatsApp, Web, Work, Org, Loaded, Modified, Last Contacted) will be saved to the contact's notes.</div>
            </div>
        </form>
    </div>

    {{-- Filters --}}
    <div x-data="{
            agentPicker: false,
            agentSearch: '',
            agents: {{ Illuminate\Support\Js::from($agentList) }},
            get filtered() {
                if (!this.agentSearch) return this.agents;
                const q = this.agentSearch.toLowerCase();
                return this.agents.filter(a => a.name.toLowerCase().includes(q) || a.email.toLowerCase().includes(q));
            },
            // Submit the live filter form with the chosen agent_id so the typed
            // search term (and the type filter) is preserved — never navigate off
            // a stale, server-rendered link. id '' = All agents.
            pickAgent(id) {
                const f = this.$refs.filterForm;
                let h = f.querySelector('input[name=agent_id]');
                if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'agent_id'; f.appendChild(h); }
                h.value = (id == null) ? '' : id;
                f.submit();
            }
         }"
         class="rounded-md px-4 py-3" style="background:var(--surface);border:1px solid var(--border);">

        <form method="GET" action="{{ route('corex.contacts.index') }}" x-ref="filterForm" class="flex flex-wrap items-center gap-3">

            {{-- Street & Complex Search — AT-273. Lives at the far left of the filter
                 bar as just the property icon + a "?" help popover. Clicking the house
                 reveals an inline input; because the address report is a DIFFERENT
                 route than this filter form, it navigates via JS (scGo) rather than a
                 nested form. Searches ONLY Address + Linked Properties. --}}
            <div x-data="{ scOpen: false, scHelp: false,
                           scGo(v) {
                               v = (v || '').trim();
                               if (!v) { this.$refs.scInput && this.$refs.scInput.focus(); return; }
                               {{-- No agent_id: the property search ALWAYS runs at the
                                    agency's full contact-visibility scope, never the
                                    list's "My Contacts" narrowing (AT-273). --}}
                               let u = '{{ route('corex.contacts.street-complex-search') }}?q=' + encodeURIComponent(v);
                               window.location.href = u;
                           } }"
                 class="flex items-center gap-1">

                {{-- Property icon — click to open the street/complex search. --}}
                <button type="button"
                        @click="scOpen = !scOpen; scOpen && $nextTick(() => $refs.scInput && $refs.scInput.focus())"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-md flex-shrink-0 transition-all duration-300"
                        :style="scOpen ? 'background:var(--brand-icon,#0ea5e9);color:#fff;' : 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent);color:var(--brand-icon,#0ea5e9);'"
                        :aria-expanded="scOpen"
                        data-tour="contact-street-search"
                        title="Street &amp; Complex Search — click to search by street or complex name">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                </button>

                {{-- "?" help — sits right beside the house icon. --}}
                <div class="relative flex-shrink-0">
                    <button type="button" @click="scHelp = !scHelp" @click.outside="scHelp = false"
                            class="inline-flex items-center justify-center w-6 h-6 transition-all duration-300"
                            style="color:var(--text-muted);background:transparent;border:none;"
                            :style="scHelp ? 'color:var(--brand-icon,#0ea5e9);' : ''"
                            title="How does this work?">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                        </svg>
                    </button>

                    <div x-show="scHelp" x-cloak x-transition
                         @keydown.escape.window="scHelp = false"
                         class="absolute left-0 top-full mt-2 z-50 w-80 rounded-md p-3.5 text-xs font-normal normal-case"
                         style="background:var(--surface);border:1px solid var(--border);box-shadow:0 12px 32px rgba(0,0,0,0.28);color:var(--text-secondary);line-height:1.5;">
                        <div class="text-sm font-bold mb-1.5" style="color:var(--text-primary);">Street &amp; Complex Search</div>
                        <p>Type a <strong style="color:var(--text-primary);">street name</strong> or <strong style="color:var(--text-primary);">complex / estate name</strong> and CoreX finds every contact whose <strong style="color:var(--text-primary);">Address</strong> or <strong style="color:var(--text-primary);">Linked Properties</strong> match. Names, phone numbers and emails are <em>not</em> searched here.</p>
                        <p class="mt-2">The results open on their own page — each contact tagged with <em>Last Contacted</em>, <em>Last Modified</em> and its linked-property status — sortable (by unit number, complex, street…) and downloadable as a PDF.</p>
                    </div>
                </div>

                {{-- Inline street/complex input — revealed when the house is clicked.
                     Enter or the arrow navigates to the report (JS, not a form submit). --}}
                <div x-show="scOpen" x-cloak x-transition class="relative">
                    <input type="text" x-ref="scInput"
                           @keydown.enter.prevent="scGo($refs.scInput.value)"
                           placeholder="Street or complex name…"
                           class="w-48 md:w-56 pl-3 pr-9 py-2 text-sm rounded-md"
                           style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
                    <button type="button" @click="scGo($refs.scInput.value)"
                            class="absolute right-1 top-1/2 -translate-y-1/2 inline-flex items-center justify-center w-7 h-7 rounded-md"
                            style="background:var(--brand-icon,#0ea5e9);color:#fff;" title="Search street / complex">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       data-tour="contact-search"
                       placeholder="Search name, phone, email…"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                       style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
            </div>

            {{-- Preserve the current agent filter across search/type submits —
                 INCLUDING '' (= All agents). If this only rendered for a selected
                 agent, clicking Search while on "All" would submit no agent_id and
                 the controller would fall back to its My-contacts default. Always
                 render it (when the user can pick agents), matching the properties page. --}}
            @if($canPickAgent)
            <input type="hidden" name="agent_id" value="{{ $filterAgentId }}">
            @endif

            {{-- Type filter --}}
            <select name="type" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All Types</option>
                @foreach($contactTypes as $type)
                    <option value="{{ $type->id }}" {{ request('type') == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                @endforeach
            </select>

            {{-- Mine / All pill toggle (role must grant scope='branch' or 'all' on contacts.view) --}}
            @if($canPickAgent)
            @php
                $cuId      = (string) auth()->id();
                $vtIsMine  = (string) $filterAgentId === $cuId;
                $vtIsAll   = $filterAgentId === '';
                $vtDScope  = \App\Services\PermissionService::getDataScope(auth()->user(), 'contacts');
                $vtCarry   = request()->except(['agent_id', 'page']);
                $vtMineUrl = route('corex.contacts.index', array_merge($vtCarry, ['agent_id' => $cuId]));
                $vtAllUrl  = route('corex.contacts.index', array_merge($vtCarry, ['agent_id' => '']));
            @endphp
            <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid var(--border);">
                <a href="{{ $vtMineUrl }}" @click.prevent="pickAgent('{{ $cuId }}')"
                   class="px-3 py-2 text-xs font-semibold no-underline transition-all duration-300"
                   style="{{ $vtIsMine ? 'background:var(--brand-icon,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-muted);' }}"
                   title="Show only my contacts">
                    My Contacts
                </a>
                <a href="{{ $vtAllUrl }}" @click.prevent="pickAgent('')"
                   class="px-3 py-2 text-xs font-semibold no-underline transition-all duration-300"
                   style="border-left:1px solid var(--border); {{ $vtIsAll ? 'background:var(--brand-icon,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-muted);' }}"
                   title="Show all {{ $vtDScope === 'branch' ? 'branch' : 'agency' }} contacts">
                    All Contacts
                </a>
            </div>
            @endif

            {{-- Agent picker (admin/BM only) — centered modal (matches the Properties
                 page). A fixed, centered dialog never mis-anchors or clips when the
                 filter bar wraps, unlike an absolutely-positioned dropdown. --}}
            @if($canPickAgent)
            <div class="inline-flex items-center gap-1">
                <button type="button" @click="agentPicker = true"
                        class="list-header-filter inline-flex items-center gap-1.5 cursor-pointer"
                        style="{{ $selectedAgent ? 'border-color:var(--brand-icon,#0ea5e9);color:var(--brand-icon,#0ea5e9);' : '' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-1a6 6 0 016-6h0M16 19l2 2 4-4"/>
                    </svg>
                    {{ $selectedAgent ? $selectedAgent->name : 'All Agents' }}
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                @if($selectedAgent)
                <button type="button" @click="pickAgent('')"
                   class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold transition-all duration-300 cursor-pointer"
                   style="color:var(--text-muted);" title="Clear agent filter">&times;</button>
                @endif
            </div>

            {{-- Picker modal --}}
            <div x-show="agentPicker" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 style="background:rgba(0,0,0,0.5);"
                 @click.self="agentPicker = false"
                 @keydown.escape.window="agentPicker = false"
                 x-transition.opacity>
                <div class="w-full max-w-md rounded-md overflow-hidden flex flex-col" style="max-height:80vh;
                     background:var(--surface);border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,0.3);">

                    <div class="flex items-center justify-between px-4 py-3 flex-shrink-0" style="border-bottom:1px solid var(--border);">
                        <h3 class="text-sm font-semibold" style="color:var(--text-primary);">Select Agent</h3>
                        <button type="button" @click="agentPicker = false"
                                class="inline-flex items-center justify-center w-7 h-7 rounded-md transition-all duration-300"
                                style="color:var(--text-muted);"
                                onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="p-3 flex-shrink-0" style="border-bottom:1px solid var(--border);">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                            </svg>
                            <input type="text" x-model="agentSearch" placeholder="Search agents..."
                                   class="w-full pl-8 pr-3 py-1.5 text-xs rounded-md outline-none transition-all duration-300"
                                   style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                        </div>
                    </div>

                    <div class="flex-1" style="overflow-y:auto;">
                        <button type="button" @click="pickAgent('')"
                           class="w-full flex items-center gap-2 px-4 py-2.5 text-xs font-semibold transition-all duration-300 text-left"
                           style="color:var(--text-secondary);border-bottom:1px solid var(--border);"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold flex-shrink-0" style="background:var(--surface-2);color:var(--text-secondary);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1h5M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                            </span>
                            All agents
                            <template x-if="!{{ $filterAgentId ? $filterAgentId : 0 }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 ml-auto flex-shrink-0" style="color:var(--brand-icon,#0ea5e9);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </template>
                        </button>

                        <template x-for="agent in filtered" :key="agent.id">
                            <button type="button" @click="pickAgent(agent.id)"
                               class="w-full flex items-center gap-2.5 px-4 py-2.5 text-xs transition-all duration-300 text-left"
                               :style="({{ $filterAgentId ? $filterAgentId : 0 }} === agent.id ? 'background:var(--surface-2);' : '')"
                               onmouseover="this.style.background='var(--surface-2)'" :onmouseout="({{ $filterAgentId ? $filterAgentId : 0 }} === agent.id ? `this.style.background='var(--surface-2)'` : `this.style.background=''`)">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold flex-shrink-0"
                                      style="background:var(--brand-default,#0b2a4a);color:#fff;"
                                      x-text="agent.name.charAt(0).toUpperCase()">
                                </span>
                                <div class="min-w-0">
                                    <div class="font-semibold truncate" style="color:var(--text-primary);" x-text="agent.name"></div>
                                    <div class="truncate" style="color:var(--text-muted);" x-text="agent.email"></div>
                                </div>
                                <template x-if="{{ $filterAgentId ? $filterAgentId : 0 }} === agent.id">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 ml-auto flex-shrink-0" style="color:var(--brand-icon,#0ea5e9);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </template>
                            </button>
                        </template>

                        <div x-show="filtered.length === 0" class="px-4 py-4 text-xs text-center" style="color:var(--text-muted);">
                            No agents found
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            @if(request()->hasAny(['search','type']))
            <a href="{{ route('corex.contacts.index', $canPickAgent ? ['agent_id' => $filterAgentId] : []) }}"
               class="text-xs underline transition-all duration-300" style="color:var(--text-muted);">Clear</a>
            @endif

        </form>

    </div>

    {{-- Contacts table --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-5 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <div class="text-sm font-bold" style="color:var(--text-primary);">
                Contacts
                @if($selectedAgent)
                <span class="ml-2 text-xs font-normal" style="color:var(--text-muted);">— {{ $selectedAgent->name }}</span>
                @endif
            </div>
            <div class="text-xs" style="color:var(--text-muted);">{{ number_format($contacts->total()) }} total</div>
        </div>

        @forelse($contacts as $contact)
        <div class="px-5 py-4 transition-all duration-300" style="border-bottom:1px solid var(--border);"
             onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">

            {{-- View row --}}
            <div x-show="editId !== {{ $contact->id }}" class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 flex-1 min-w-0">
                    {{-- Avatar --}}
                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold text-white"
                         style="background: var(--brand-icon,#0ea5e9);">
                        {{ strtoupper(substr($contact->first_name, 0, 1) . substr($contact->last_name, 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ route('corex.contacts.show', $contact) }}"
                               class="text-sm font-semibold no-underline transition-all duration-300"
                               style="color:var(--text-primary);"
                               onmouseover="this.style.color='var(--brand-icon,#0ea5e9)'" onmouseout="this.style.color='var(--text-primary)'">{{ $contact->full_name }}</a>
                            @if($contact->type)
                            <span class="text-xs px-2 py-0.5 rounded-md font-medium whitespace-nowrap"
                                  style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);">
                                {{ $contact->type->name }}
                            </span>
                            @endif
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                            <span class="text-xs flex items-center gap-1" style="color:var(--text-secondary);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                {{ $contact->phone }}
                            </span>
                            @if($contact->email)
                            <span class="text-xs flex items-center gap-1" style="color:var(--text-secondary);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                {{ $contact->email }}
                            </span>
                            @endif
                            @if($contact->notes)
                            <span class="text-xs truncate max-w-xs" style="color:var(--text-muted);">{{ $contact->notes }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0">
                    <a href="{{ route('corex.contacts.show', $contact) }}"
                       class="corex-btn-outline text-[10px] px-2 py-1">View</a>
                    @if(auth()->user()->hasPermission('contacts.delete'))
                    <form method="POST" action="{{ route('corex.contacts.destroy', $contact) }}"
                          onsubmit="return confirm('Delete {{ addslashes($contact->full_name) }}?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-[10px] px-2 py-1"
                                style="color:var(--ds-crimson,#c41e3a); border-color:color-mix(in srgb, var(--ds-crimson,#c41e3a) 30%, transparent);">Delete</button>
                    </form>
                    @endif
                </div>
            </div>

            {{-- Edit row (inline) --}}
            <div x-show="editId === {{ $contact->id }}" x-cloak
                 class="rounded-md p-4 mt-1"
                 style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 5%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
                <form method="POST" action="{{ route('corex.contacts.update', $contact) }}" class="space-y-3">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">First Name</label>
                            <input type="text" name="first_name" value="{{ $contact->first_name }}" required
                                   class="w-full rounded-md px-3 py-1.5 text-sm transition-all duration-300"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Surname</label>
                            <input type="text" name="last_name" value="{{ $contact->last_name }}" required
                                   class="w-full rounded-md px-3 py-1.5 text-sm transition-all duration-300"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Phone</label>
                            <input type="text" name="phone" value="{{ $contact->phone }}" required
                                   class="w-full rounded-md px-3 py-1.5 text-sm transition-all duration-300"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Email (optional)</label>
                            <input type="email" name="email" value="{{ $contact->email }}"
                                   class="w-full rounded-md px-3 py-1.5 text-sm transition-all duration-300"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Contact Type <span class="text-red-500">*</span></label>
                            @include('corex.contacts._type_picker', ['contactTypes' => $contactTypes, 'contact' => $contact])
                            @error('parent_type_ids')<p class="mt-1 text-[11px]" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Notes (optional)</label>
                            <textarea name="notes" rows="2"
                                      class="w-full rounded-md px-3 py-1.5 text-sm resize-none transition-all duration-300"
                                      style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); outline:none;">{{ $contact->notes }}</textarea>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="corex-btn-primary text-sm">Save</button>
                        <button type="button" @click="editId = null" class="text-sm transition-all duration-300" style="color:var(--text-muted);">Cancel</button>
                    </div>
                </form>
            </div>

        </div>
        @empty
        <div class="py-12 px-6 text-center">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color: var(--brand-icon,#0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No contacts yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Add your first contact to start tracking relationships.</p>
            <button type="button" @click="showAdd = true" class="corex-btn-primary text-sm">Add Contact</button>
        </div>
        @endforelse

        {{-- Pagination --}}
        @if($contacts->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $contacts->links() }}
        </div>
        @endif
    </div>

</div>
@endsection