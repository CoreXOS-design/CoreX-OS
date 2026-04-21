@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5"
     x-data="{ showAdd: false, showImport: false, editId: null, importLoading: false }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 flex items-center justify-between" style="background:var(--brand-default,#0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Contacts</h2>
            <p class="text-sm mt-0.5" style="color:rgba(255,255,255,0.55);">Manage your contacts and leads.</p>
        </div>
        <div class="flex items-center gap-2">
            @if(auth()->user()->effectiveRole() === 'super_admin')
            <form method="POST" action="{{ route('corex.contacts.destroy-all') }}"
                  onsubmit="return confirm('DELETE ALL CONTACTS? This will permanently delete every contact in the system. Are you sure?');">
                @csrf @method('DELETE')
                <button type="submit" class="text-sm font-medium px-3 py-2 rounded-md transition-all duration-300"
                        style="color:#ef4444; border:1px solid rgba(239,68,68,0.3);"
                        onmouseover="this.style.background='rgba(239,68,68,0.08)'" onmouseout="this.style.background=''">
                    Delete All
                </button>
            </form>
            <button type="button" @click="showImport = !showImport" class="corex-btn-outline text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
                </svg>
                Import
            </button>
            @endif
            <button type="button" @click="showAdd = !showAdd" class="corex-btn-primary text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Add Contact
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md border px-4 py-3 text-sm" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add Contact Form (collapsible) --}}
    <div x-show="showAdd" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         x-data="{
            dupChecking: false,
            dupFound: false,
            dupData: {},
            async checkDuplicate() {
                const phone = this.$refs.phoneInput.value.trim();
                const email = this.$refs.emailInput.value.trim();
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
         class="rounded-md" style="background:var(--surface); border:1px solid var(--border); padding:24px;">
        <div class="text-sm font-bold mb-4" style="color:var(--text-primary);">New Contact</div>

        {{-- Duplicate found popup --}}
        <div x-show="dupFound" x-cloak
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             class="rounded-md mb-4 p-4" style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.3);">
            <div class="flex items-start gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0 mt-0.5" style="color:#eab308;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                </svg>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-bold" style="color:#eab308;">Possible Duplicate Found</div>
                    <p class="text-xs mt-1" style="color:var(--text-secondary);">A contact with this phone number or email already exists.</p>
                    <div class="mt-3 rounded-md p-3" style="background:var(--surface); border:1px solid var(--border);">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                                 style="background:var(--brand-icon,#0ea5e9);"
                                 x-text="dupData.name ? dupData.name.charAt(0).toUpperCase() : ''"></div>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="dupData.name"></div>
                                <div class="text-xs" style="color:var(--text-muted);" x-text="dupData.type"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs mt-2">
                            <div>
                                <span class="font-semibold" style="color:var(--text-muted);">Phone:</span>
                                <span style="color:var(--text-secondary);" x-text="dupData.phone"></span>
                            </div>
                            <div>
                                <span class="font-semibold" style="color:var(--text-muted);">Email:</span>
                                <span style="color:var(--text-secondary);" x-text="dupData.email"></span>
                            </div>
                            <div>
                                <span class="font-semibold" style="color:var(--text-muted);">Agent:</span>
                                <span style="color:var(--text-secondary);" x-text="dupData.agent"></span>
                            </div>
                        </div>
                        <div class="text-xs mt-2">
                            <span class="font-semibold" style="color:var(--text-muted);">Last Contacted:</span>
                            <span style="color:var(--text-secondary);" x-text="dupData.last_contacted"></span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a :href="dupData.url" class="text-xs font-semibold no-underline transition-all duration-300" style="color:var(--brand-icon,#0ea5e9);">View Existing Contact</a>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('corex.contacts.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required
                           placeholder="e.g. John"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required
                           placeholder="e.g. Smith"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone Number <span class="text-red-500">*</span></label>
                    <input type="text" name="phone" value="{{ old('phone') }}" required
                           placeholder="e.g. 082 123 4567"
                           x-ref="phoneInput"
                           @blur="checkDuplicate()"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                    <input type="email" name="email" value="{{ old('email') }}"
                           placeholder="e.g. john@example.com"
                           x-ref="emailInput"
                           @blur="checkDuplicate()"
                           class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contact Type</label>
                    <select name="contact_type_id"
                            class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        <option value="">— No type —</option>
                        @foreach($contactTypes as $type)
                            <option value="{{ $type->id }}" {{ old('contact_type_id') == $type->id ? 'selected' : '' }}>
                                {{ $type->name }}
                            </option>
                        @endforeach
                    </select>
                    @if($contactTypes->isEmpty())
                        <p class="text-xs mt-1" style="color:var(--text-muted);">No types yet — add them in <a href="{{ route('corex.settings', ['tab'=>'feature','fsec'=>'contacts']) }}" class="underline" style="color:var(--brand-icon,#0ea5e9);">Settings → Feature Settings → Contacts</a>.</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="corex-btn-primary text-sm" :disabled="dupFound"
                        :style="dupFound ? 'opacity:0.4; cursor:not-allowed;' : ''">Save Contact</button>
                <button type="button" @click="showAdd = false" class="text-sm transition-all duration-300" style="color:var(--text-muted);">Cancel</button>
            </div>
        </form>
    </div>

    {{-- Import Contacts Panel (collapsible) --}}
    <div x-show="showImport" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         class="rounded-md" style="background:var(--surface); border:1px solid var(--border); padding:24px;">
        <div class="flex items-center justify-between mb-4">
            <div>
                <div class="text-sm font-bold" style="color:var(--text-primary);">Import Contacts from Excel</div>
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
            agents: {{ $agentList->toJson() }},
            get filtered() {
                if (!this.agentSearch) return this.agents;
                const q = this.agentSearch.toLowerCase();
                return this.agents.filter(a => a.name.toLowerCase().includes(q) || a.email.toLowerCase().includes(q));
            }
         }"
         class="rounded-md px-4 py-3" style="background:var(--surface);border:1px solid var(--border);">

        <form method="GET" action="{{ route('corex.contacts.index') }}" class="flex flex-wrap items-center gap-3">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search name, phone, email…"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                       style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
            </div>

            {{-- Preserve agent_id in form submission --}}
            @if($filterAgentId !== '')
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
                <a href="{{ $vtMineUrl }}"
                   class="px-3 py-2 text-xs font-semibold no-underline transition-all duration-300"
                   style="{{ $vtIsMine ? 'background:var(--brand-icon,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-muted);' }}"
                   title="Show only my contacts">
                    My Contacts
                </a>
                <a href="{{ $vtAllUrl }}"
                   class="px-3 py-2 text-xs font-semibold no-underline transition-all duration-300"
                   style="border-left:1px solid var(--border); {{ $vtIsAll ? 'background:var(--brand-icon,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-muted);' }}"
                   title="Show all {{ $vtDScope === 'branch' ? 'branch' : 'agency' }} contacts">
                    All Contacts
                </a>
            </div>
            @endif

            {{-- Agent picker (admin/BM only) --}}
            @if($canPickAgent)
            <div class="relative" @click.outside="agentPicker = false">
                <button type="button" @click="agentPicker = !agentPicker"
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
                <a href="{{ route('corex.contacts.index', ['search' => request('search'), 'type' => request('type'), 'agent_id' => '']) }}"
                   class="ml-1 inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold transition-all duration-300"
                   style="color:var(--text-muted);" title="Clear agent filter">&times;</a>
                @endif

                {{-- Picker dropdown --}}
                <div x-show="agentPicker"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 translate-y-1"
                     class="absolute top-full mt-1.5 left-0 z-50 w-72 rounded-md overflow-hidden"
                     style="background:var(--surface);border:1px solid var(--border);box-shadow:0 8px 30px rgba(0,0,0,0.12);"
                     x-cloak>

                    <div class="p-3" style="border-bottom:1px solid var(--border);">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                            </svg>
                            <input type="text" x-model="agentSearch" placeholder="Search agents..."
                                   class="w-full pl-8 pr-3 py-1.5 text-xs rounded-md outline-none transition-all duration-300"
                                   style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);"
                                   @keydown.escape="agentPicker = false">
                        </div>
                    </div>

                    <div style="max-height:260px;overflow-y:auto;">
                        <a href="{{ route('corex.contacts.index', ['search' => request('search'), 'type' => request('type'), 'agent_id' => '']) }}"
                           class="flex items-center gap-2 px-4 py-2.5 text-xs font-semibold transition-all duration-300"
                           style="color:var(--text-secondary);border-bottom:1px solid var(--border);"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold" style="background:var(--surface-2);color:var(--text-secondary);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1h5M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                            </span>
                            All agents
                        </a>

                        <template x-for="agent in filtered" :key="agent.id">
                            <a :href="`{{ route('corex.contacts.index') }}?agent_id=${agent.id}&search={{ urlencode(request('search','')) }}&type={{ urlencode(request('type','')) }}`"
                               class="flex items-center gap-2.5 px-4 py-2.5 text-xs transition-all duration-300"
                               :style="({{ $filterAgentId ? $filterAgentId : 0 }} === agent.id ? 'background:var(--surface-2);' : '')"
                               onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
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
                            </a>
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
            <a href="{{ route('corex.contacts.index', $filterAgentId ? ['agent_id' => $filterAgentId] : []) }}"
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
            <div class="text-xs" style="color:var(--text-muted);">{{ $contacts->total() }} total</div>
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
                            <span class="text-xs px-2 py-0.5 rounded-md font-medium"
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
                        <button type="submit"
                                class="text-[10px] font-medium px-2 py-1 rounded-md transition-all duration-300"
                                style="color:var(--text-muted);"
                                onmouseover="this.style.color='#ef4444';this.style.background='rgba(239,68,68,0.08)'"
                                onmouseout="this.style.color='var(--text-muted)';this.style.background=''">Delete</button>
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
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Contact Type</label>
                            <select name="contact_type_id"
                                    class="w-full rounded-md px-3 py-1.5 text-sm"
                                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— No type —</option>
                                @foreach($contactTypes as $type)
                                    <option value="{{ $type->id }}" {{ $contact->contact_type_id == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                                @endforeach
                            </select>
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
        <div class="p-8 text-center" style="color:var(--text-muted);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10 mx-auto mb-3 opacity-30"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
            <div class="text-sm">No contacts yet.</div>
            <button type="button" @click="showAdd = true" class="mt-3 text-sm font-semibold" style="color:var(--brand-icon,#0ea5e9);">Add your first contact</button>
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