@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5"
     x-data="{ showAdd: false, editId: null }">

    {{-- Page header --}}
    <div class="rounded-2xl px-6 py-5 flex items-center justify-between" style="background:var(--brand-primary,#0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Contacts</h2>
            <p class="text-sm mt-0.5" style="color:rgba(255,255,255,0.55);">Manage your contacts and leads.</p>
        </div>
        <button type="button" @click="showAdd = !showAdd"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white transition-opacity"
                style="background:var(--brand-secondary,#00b4d8);"
                onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Add Contact
        </button>
    </div>

    @if(session('success'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border px-4 py-3 text-sm" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add Contact Form (collapsible) --}}
    <div x-show="showAdd" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:24px;">
        <div class="text-sm font-bold mb-4" style="color:var(--text-primary);">New Contact</div>
        <form method="POST" action="{{ route('corex.contacts.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required
                           placeholder="e.g. John"
                           class="w-full rounded-lg px-3 py-2 text-sm"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required
                           placeholder="e.g. Smith"
                           class="w-full rounded-lg px-3 py-2 text-sm"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone Number <span class="text-red-500">*</span></label>
                    <input type="text" name="phone" value="{{ old('phone') }}" required
                           placeholder="e.g. 082 123 4567"
                           class="w-full rounded-lg px-3 py-2 text-sm"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                    <input type="email" name="email" value="{{ old('email') }}"
                           placeholder="e.g. john@example.com"
                           class="w-full rounded-lg px-3 py-2 text-sm"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contact Type</label>
                    <select name="contact_type_id"
                            class="w-full rounded-lg px-3 py-2 text-sm"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        <option value="">— No type —</option>
                        @foreach($contactTypes as $type)
                            <option value="{{ $type->id }}" {{ old('contact_type_id') == $type->id ? 'selected' : '' }}>
                                {{ $type->name }}
                            </option>
                        @endforeach
                    </select>
                    @if($contactTypes->isEmpty())
                        <p class="text-xs mt-1" style="color:var(--text-muted);">No types yet — add them in <a href="{{ route('corex.settings', ['tab'=>'feature','fsec'=>'contacts']) }}" class="underline text-[#00b4d8]">Settings → Feature Settings → Contacts</a>.</p>
                    @endif
                </div>
                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Notes <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                    <textarea name="notes" rows="2" placeholder="Any additional notes..."
                              class="w-full rounded-lg px-3 py-2 text-sm resize-none"
                              style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="corex-btn-primary text-sm">Save Contact</button>
                <button type="button" @click="showAdd = false" class="text-sm" style="color:var(--text-muted);">Cancel</button>
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
         class="flex flex-wrap items-center gap-3">

        {{-- Agent picker (admin/BM only) --}}
        @if($canPickAgent)
        <div class="relative">
            <button type="button" @click="agentPicker = !agentPicker"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors"
                    style="{{ $selectedAgent ? 'background:var(--brand-primary,#0b2a4a);color:#fff;border-color:var(--brand-primary,#0b2a4a);' : 'background:var(--surface);color:var(--text-secondary);border-color:var(--border);' }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-1a6 6 0 016-6h0M16 19l2 2 4-4"/>
                </svg>
                @if($selectedAgent)
                    {{ $selectedAgent->name }}
                @else
                    View Agent
                @endif
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 ml-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            @if($selectedAgent)
            <a href="{{ route('corex.contacts.index', ['search' => request('search'), 'type' => request('type'), 'agent_id' => '']) }}"
               class="ml-1 inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold"
               style="background:#fee2e2;color:#991b1b;" title="Show all contacts">&times;</a>
            @endif

            {{-- Picker dropdown --}}
            <div x-show="agentPicker"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 @click.outside="agentPicker = false"
                 style="position:absolute;top:calc(100% + 6px);left:0;z-index:50;width:300px;background:var(--surface);border-radius:14px;border:1px solid var(--border);box-shadow:0 10px 40px var(--shadow);overflow:hidden;"
                 x-cloak>

                <div style="padding:12px 14px 8px;border-bottom:1px solid var(--border);">
                    <p class="text-xs font-semibold" style="color:var(--text-primary);margin-bottom:8px;">Filter by agent</p>
                    <div class="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                        </svg>
                        <input type="text" x-model="agentSearch" placeholder="Search agents…"
                               class="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg outline-none"
                               style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);"
                               @keydown.escape="agentPicker = false">
                    </div>
                </div>

                <div style="max-height:260px;overflow-y:auto;">
                    <a href="{{ route('corex.contacts.index', ['search' => request('search'), 'type' => request('type'), 'agent_id' => '']) }}"
                       class="flex items-center gap-2 px-4 py-2.5 text-xs font-semibold"
                       style="color:var(--text-secondary);border-bottom:1px solid var(--border);"
                       onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold" style="background:var(--surface-2);color:var(--text-secondary);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1h5M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                        </span>
                        All agents
                    </a>

                    <template x-for="agent in filtered" :key="agent.id">
                        <a :href="`{{ route('corex.contacts.index') }}?agent_id=${agent.id}&search={{ urlencode(request('search','')) }}&type={{ urlencode(request('type','')) }}`"
                           class="flex items-center gap-2.5 px-4 py-2.5 text-xs"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold flex-shrink-0"
                                  style="background:var(--brand-primary,#0b2a4a);color:#fff;"
                                  x-text="agent.name.charAt(0).toUpperCase()">
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold truncate" style="color:var(--text-primary);" x-text="agent.name"></div>
                                <div class="truncate" style="color:var(--text-muted);" x-text="agent.email"></div>
                            </div>
                            <template x-if="{{ $filterAgentId ? $filterAgentId : 0 }} === agent.id">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 ml-auto flex-shrink-0" style="color:#00b4d8;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
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

        {{-- Type filter --}}
        <form method="GET" action="{{ route('corex.contacts.index') }}" class="flex flex-wrap items-center gap-2">
            @if($filterAgentId !== '')
            <input type="hidden" name="agent_id" value="{{ $filterAgentId }}">
            @endif
            <select name="type"
                    class="rounded-lg px-3 py-1.5 text-xs"
                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                    onchange="this.form.submit()">
                <option value="">All Types</option>
                @foreach($contactTypes as $type)
                    <option value="{{ $type->id }}" {{ request('type') == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                @endforeach
            </select>

            {{-- Search --}}
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:#94a3b8;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search name, phone, email…"
                       class="pl-8 pr-3 py-1.5 rounded-lg text-xs outline-none"
                       style="border:1px solid var(--border);width:230px;background:var(--surface);color:var(--text-primary);"
                       onfocus="this.style.borderColor='#00b4d8';this.style.boxShadow='0 0 0 2px rgba(0,180,216,0.15)'"
                       onblur="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
            </div>
            <button type="submit"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white"
                    style="background:var(--brand-secondary,#00b4d8);">Search</button>
            @if(request()->hasAny(['search','type']))
            <a href="{{ route('corex.contacts.index', $filterAgentId ? ['agent_id' => $filterAgentId] : []) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold"
               style="color:var(--text-secondary);border:1px solid var(--border);background:var(--surface);">Clear</a>
            @endif
        </form>

    </div>

    {{-- Contacts table --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; overflow:hidden;">
        <div class="px-5 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <div class="text-sm font-bold" style="color:var(--text-primary);">
                Contacts
                @if($selectedAgent)
                <span class="ml-2 text-xs font-normal" style="color:var(--text-muted);">— {{ $selectedAgent->name }}</span>
                @endif
            </div>
            <div class="text-xs" style="color:var(--text-muted);">{{ $contacts->total() }} total</div>
        </div>

        @forelse($contacts as $contact)
        <div class="px-5 py-4" style="border-bottom:1px solid var(--border);">

            {{-- View row --}}
            <div x-show="editId !== {{ $contact->id }}" class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 flex-1 min-w-0">
                    {{-- Avatar --}}
                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold text-white"
                         style="background: {{ $contact->type?->color ?? '#334155' }};">
                        {{ strtoupper(substr($contact->first_name, 0, 1) . substr($contact->last_name, 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ route('corex.contacts.show', $contact) }}"
                               class="text-sm font-semibold no-underline hover:text-[#00b4d8] transition-colors"
                               style="color:var(--text-primary);">{{ $contact->full_name }}</a>
                            @if($contact->type)
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                  style="background:{{ $contact->type->color }}22; color:{{ $contact->type->color }}; border:1px solid {{ $contact->type->color }}44;">
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
                <div class="flex items-center gap-3 flex-shrink-0">
                    <a href="{{ route('corex.contacts.show', $contact) }}"
                       class="text-xs font-semibold no-underline" style="color:#00b4d8;">View</a>
                    <form method="POST" action="{{ route('corex.contacts.destroy', $contact) }}"
                          onsubmit="return confirm('Delete {{ addslashes($contact->full_name) }}?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-700">Delete</button>
                    </form>
                </div>
            </div>

            {{-- Edit row (inline) --}}
            <div x-show="editId === {{ $contact->id }}" x-cloak
                 class="rounded-xl p-4 mt-1"
                 style="background:rgba(0,180,216,0.05); border:1px solid rgba(0,180,216,0.2);">
                <form method="POST" action="{{ route('corex.contacts.update', $contact) }}" class="space-y-3">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">First Name</label>
                            <input type="text" name="first_name" value="{{ $contact->first_name }}" required
                                   class="w-full rounded-lg px-3 py-1.5 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Surname</label>
                            <input type="text" name="last_name" value="{{ $contact->last_name }}" required
                                   class="w-full rounded-lg px-3 py-1.5 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Phone</label>
                            <input type="text" name="phone" value="{{ $contact->phone }}" required
                                   class="w-full rounded-lg px-3 py-1.5 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Email (optional)</label>
                            <input type="email" name="email" value="{{ $contact->email }}"
                                   class="w-full rounded-lg px-3 py-1.5 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Contact Type</label>
                            <select name="contact_type_id"
                                    class="w-full rounded-lg px-3 py-1.5 text-sm"
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
                                      class="w-full rounded-lg px-3 py-1.5 text-sm resize-none"
                                      style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ $contact->notes }}</textarea>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="px-4 py-1.5 bg-[#00b4d8] text-white text-sm font-semibold rounded-lg hover:bg-[#0096b7]">Save</button>
                        <button type="button" @click="editId = null" class="text-sm" style="color:var(--text-muted);">Cancel</button>
                    </div>
                </form>
            </div>

        </div>
        @empty
        <div class="p-8 text-center" style="color:var(--text-muted);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10 mx-auto mb-3 opacity-30"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
            <div class="text-sm">No contacts yet.</div>
            <button type="button" @click="showAdd = true" class="mt-3 text-sm font-semibold" style="color:#00b4d8;">Add your first contact</button>
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
