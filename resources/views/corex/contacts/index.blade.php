@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="{ showAdd: false, editId: null }">

    {{-- Page header --}}
    <div style="background:#0b2a4a; border-radius:16px; padding:20px 24px;" class="flex items-center justify-between">
        <div>
            <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Contacts</h2>
            <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Manage your contacts and leads.</div>
        </div>
        <button type="button" @click="showAdd = !showAdd"
                class="corex-btn-primary text-sm flex items-center gap-2">
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
    <form method="GET" action="{{ route('corex.contacts.index') }}" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Search name, phone, email…"
                   class="w-full rounded-lg px-3 py-2 text-sm"
                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <select name="type"
                    class="rounded-lg px-3 py-2 text-sm"
                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">All Types</option>
                @foreach($contactTypes as $type)
                    <option value="{{ $type->id }}" {{ request('type') == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-sm">Filter</button>
        @if(request()->hasAny(['search','type']))
        <a href="{{ route('corex.contacts.index') }}" class="text-sm" style="color:var(--text-muted);">Clear</a>
        @endif
    </form>

    {{-- Contacts table --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; overflow:hidden;">
        <div class="px-5 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <div class="text-sm font-bold" style="color:var(--text-primary);">Contacts</div>
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
