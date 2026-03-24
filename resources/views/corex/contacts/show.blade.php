@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="contactShowData('{{ route('corex.contacts.properties.search', $contact) }}', '{{ request('tab', 'info') }}')"
     x-init="activeTab = initTab">

    {{-- Back link --}}
    <a href="{{ route('corex.contacts.index') }}"
       class="inline-flex items-center gap-1.5 text-sm no-underline"
       style="color:var(--text-secondary);">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
        Back to Contacts
    </a>

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

    {{-- Contact header card --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:24px;">
        <div class="flex items-start gap-5 flex-wrap">
            {{-- Avatar --}}
            <div class="w-16 h-16 rounded-md flex items-center justify-center flex-shrink-0 text-xl font-bold text-white"
                 style="background: {{ $contact->type?->color ?? 'var(--brand-icon, #0ea5e9)' }};">
                {{ $contact->initials }}
            </div>

            {{-- Name + meta --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="text-xl font-extrabold text-white">{{ $contact->full_name }}</h1>
                    @if($contact->type)
                    <span class="text-xs px-2.5 py-1 rounded-md font-semibold"
                          style="background:rgba(255,255,255,0.12); color:{{ $contact->type->color }}; border:1px solid rgba(255,255,255,0.2);">
                        {{ $contact->type->name }}
                    </span>
                    @endif
                </div>

                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1.5">
                    <span class="flex items-center gap-1.5 text-sm" style="color:rgba(255,255,255,0.6);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                        {{ $contact->phone }}
                    </span>
                    @if($contact->email)
                    <span class="flex items-center gap-1.5 text-sm" style="color:rgba(255,255,255,0.6);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                        <a href="mailto:{{ $contact->email }}" class="no-underline hover:underline" style="color:inherit;">{{ $contact->email }}</a>
                    </span>
                    @endif
                </div>

                {{-- Linked agent + timestamps --}}
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1">
                    @if($contact->createdBy)
                    <span class="text-xs flex items-center gap-1.5" style="color:rgba(255,255,255,0.4);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        Agent: <strong class="text-white/60">{{ $contact->createdBy->name }}</strong>
                        @if($contact->createdBy->email)
                            <span style="color:rgba(255,255,255,0.3);">· {{ $contact->createdBy->email }}</span>
                        @endif
                    </span>
                    @endif
                    <span class="text-xs" style="color:rgba(255,255,255,0.3);">
                        Created {{ $contact->created_at->format('d M Y') }}
                    </span>
                    @if($contact->updated_at->ne($contact->created_at))
                    <span class="text-xs" style="color:rgba(255,255,255,0.3);">
                        · Updated {{ $contact->updated_at->diffForHumans() }}
                    </span>
                    @endif
                    <span class="text-xs" style="color:rgba(255,255,255,0.3);">
                        · {{ $contact->documents->count() }} file{{ $contact->documents->count() !== 1 ? 's' : '' }}
                        · {{ $contact->contactNotes->count() }} note{{ $contact->contactNotes->count() !== 1 ? 's' : '' }}
                    </span>
                </div>
            </div>

            {{-- Delete button --}}
            @if(auth()->user()->hasPermission('contacts.delete'))
            <form method="POST" action="{{ route('corex.contacts.destroy', $contact) }}"
                  onsubmit="return confirm('Permanently delete {{ addslashes($contact->full_name) }}?');"
                  class="flex-shrink-0">
                @csrf @method('DELETE')
                <button type="submit" class="text-xs font-semibold text-red-400 hover:text-red-300 px-3 py-1.5 rounded-md border border-red-400/20 hover:border-red-400/40 transition-all duration-300">
                    Delete Contact
                </button>
            </form>
            @endif
        </div>
    </div>

    {{-- Tab bar --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="flex" style="border-bottom:1px solid var(--border);" id="tab-bar">
            @php
                $ficaStatus = $contact->ficaStatus();
                $ficaIcon = match($ficaStatus) {
                    'complete' => '<span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:#dcfce7; color:#166534;">Complete</span>',
                    'expiring' => '<span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:#fef3c7; color:#92400e;">Expiring</span>',
                    default => '<span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:#fecaca; color:#991b1b;">Incomplete</span>',
                };
            @endphp
            @foreach([
                ['key'=>'info','label'=>'Info'],
                ['key'=>'properties','label'=>'Properties <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $contact->properties->count() .'</span>'],
                ['key'=>'notes','label'=>'Notes <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $contact->contactNotes->count() .'</span>'],
                ['key'=>'drive','label'=>'Drive <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $contact->documents->count() .'</span>'],
                ['key'=>'fica','label'=>'FICA Compliance ' . $ficaIcon],
                ['key'=>'matches','label'=>'Core Matches <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $contact->matches->count() .'</span>'],
            ] as $t)
            @if($t['key'] === 'matches' && (!\App\Models\PerformanceSetting::get('matches_enabled', 1) || !auth()->user()->hasPermission('access_core_matches')))
                @continue
            @endif
            <button type="button"
                    @click="activeTab = '{{ $t['key'] }}'"
                    :class="activeTab === '{{ $t['key'] }}' ? 'border-b-2' : 'border-b-2 border-transparent'"
                    :style="activeTab === '{{ $t['key'] }}' ? 'color:var(--brand-icon, #0ea5e9); border-color:var(--brand-icon, #0ea5e9); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);' : 'color:var(--text-secondary);'"
                    class="px-6 py-4 text-sm font-semibold whitespace-nowrap transition-all duration-300 outline-none hover:opacity-80"
                    >
                {!! $t['label'] !!}
            </button>
            @endforeach
        </div>

        {{-- ════════════════════════════
             INFO TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'info'" class="p-6 space-y-6">

            {{-- ── Action Boxes: Last Contacted | WhatsApp | Email ── --}}
            <div x-data="{
                    editing: false,
                    showWa: false,
                    showEmail: false,
                    waCount: {{ (int) $contact->whatsapp_count }},
                    emailCount: {{ (int) $contact->email_count }},
                    lastContactedLabel: '{{ $contact->last_contacted_at ? $contact->last_contacted_at->format('d M Y H:i') : 'Never' }}',
                    lastContactedRelative: '{{ $contact->last_contacted_at ? $contact->last_contacted_at->diffForHumans() : '' }}',
                    waMessage: 'Hi {{ addslashes($contact->first_name) }}',
                    emailSubject: 'Hi {{ addslashes($contact->first_name) }}',
                    emailBody: 'Hi {{ addslashes($contact->first_name) }}',
                    async increment(channel) {
                        const res = await fetch('{{ route('corex.contacts.increment', $contact) }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ channel })
                        });
                        const data = await res.json();
                        if (channel === 'whatsapp') this.waCount = data.count;
                        else this.emailCount = data.count;
                        this.lastContactedLabel = data.last_contacted;
                        this.lastContactedRelative = data.last_contacted_relative;
                    },
                    sendWa() {
                        let phone = '{{ preg_replace('/[^0-9]/', '', $contact->phone ?? '') }}';
                        if (phone.startsWith('0')) phone = '27' + phone.substring(1);
                        window.location.href = 'whatsapp://send?phone=' + phone + '&text=' + encodeURIComponent(this.waMessage);
                        this.increment('whatsapp');
                        this.showWa = false;
                    },
                    sendEmail() {
                        window.location.href = 'mailto:{{ $contact->email }}?subject=' + encodeURIComponent(this.emailSubject) + '&body=' + encodeURIComponent(this.emailBody);
                        this.increment('email');
                        this.showEmail = false;
                    }
                 }" class="space-y-3">

                {{-- 3 boxes in a row --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

                    {{-- Box 1: Last Contacted --}}
                    <div class="rounded-md px-5 py-4" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="flex items-center gap-2 mb-2">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <div class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Last Contacted</div>
                        </div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="lastContactedLabel"></div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="lastContactedRelative"></div>
                        <div class="mt-3 flex items-center gap-2">
                            <template x-if="!editing">
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('corex.contacts.touch', $contact) }}">
                                        @csrf
                                        <input type="hidden" name="last_contacted_at" value="{{ now()->format('Y-m-d\TH:i') }}">
                                        <button type="submit" class="text-[10px] font-semibold px-2.5 py-1 rounded-md transition-all duration-300"
                                                style="color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);">
                                            Mark as Now
                                        </button>
                                    </form>
                                    <button type="button" @click="editing = true"
                                            class="text-[10px] font-semibold px-2.5 py-1 rounded-md"
                                            style="color:var(--text-muted); border:1px solid var(--border);">
                                        Pick Date
                                    </button>
                                </div>
                            </template>
                            <template x-if="editing">
                                <form method="POST" action="{{ route('corex.contacts.touch', $contact) }}" class="flex flex-col gap-2 w-full">
                                    @csrf
                                    <input type="datetime-local" name="last_contacted_at"
                                           value="{{ $contact->last_contacted_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i') }}"
                                           class="rounded-md px-2.5 py-1 text-xs w-full"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="flex gap-2">
                                        <button type="submit" class="corex-btn-primary text-[10px] px-2.5 py-1">Save</button>
                                        <button type="button" @click="editing = false" class="text-[10px]" style="color:var(--text-muted);">Cancel</button>
                                    </div>
                                </form>
                            </template>
                        </div>
                    </div>

                    {{-- Box 2: WhatsApp --}}
                    @if(auth()->user()->hasPermission('contacts.whatsapp'))
                    <div class="rounded-md px-5 py-4 cursor-pointer transition-all duration-300 group"
                         style="background:var(--surface-2); border:2px solid rgba(37,211,102,0.25);"
                         @click="showWa = !showWa; showEmail = false"
                         onmouseover="this.style.borderColor='#25d366'; this.style.background='rgba(37,211,102,0.06)'" onmouseout="this.style.borderColor='rgba(37,211,102,0.25)'; this.style.background='var(--surface-2)'">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5" style="color:#25d366;" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                <div class="text-xs font-bold uppercase tracking-widest" style="color:#25d366;">WhatsApp</div>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md" style="background:rgba(37,211,102,0.12); color:#25d366;">Click to send</span>
                        </div>
                        <div class="text-2xl font-extrabold" style="color:var(--text-primary);" x-text="waCount"></div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">messages sent</div>
                    </div>
                    @endif

                    {{-- Box 3: Email --}}
                    @if(auth()->user()->hasPermission('contacts.email'))
                    <div class="rounded-md px-5 py-4 cursor-pointer transition-all duration-300 group"
                         style="background:var(--surface-2); border:2px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent);"
                         @click="showEmail = !showEmail; showWa = false"
                         onmouseover="this.style.borderColor='var(--brand-icon, #0ea5e9)'; this.style.background='color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent)'" onmouseout="this.style.borderColor='color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent)'; this.style.background='var(--surface-2)'">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                <div class="text-xs font-bold uppercase tracking-widest" style="color:var(--brand-icon, #0ea5e9);">Email</div>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9);">Click to send</span>
                        </div>
                        <div class="text-2xl font-extrabold" style="color:var(--text-primary);" x-text="emailCount"></div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">emails sent</div>
                    </div>
                    @endif
                </div>

                {{-- WhatsApp template popup --}}
                @if(auth()->user()->hasPermission('contacts.whatsapp'))
                <div x-show="showWa" x-cloak
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="rounded-md p-4" style="background:var(--surface); border:1px solid #25d366; border-left:3px solid #25d366;">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-4 h-4" style="color:#25d366;" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        <div class="text-xs font-bold" style="color:#25d366;">WhatsApp Message</div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Template</label>
                        <select @change="waMessage = $el.value"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="Hi {{ $contact->first_name }}">Hi {{ $contact->first_name }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Message</label>
                        <textarea x-model="waMessage" rows="3"
                                  class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="sendWa()"
                                class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-md text-white transition-all duration-300"
                                style="background:#25d366;"
                                onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            Send WhatsApp
                        </button>
                        <button type="button" @click="showWa = false" class="text-sm" style="color:var(--text-muted);">Cancel</button>
                    </div>
                </div>

                @endif

                {{-- Email template popup --}}
                @if(auth()->user()->hasPermission('contacts.email'))
                <div x-show="showEmail" x-cloak
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                     class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--brand-icon, #0ea5e9); border-left:3px solid var(--brand-icon, #0ea5e9);">
                    <div class="flex items-center gap-2 mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                        <div class="text-xs font-bold" style="color:var(--brand-icon, #0ea5e9);">Email Message</div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Template</label>
                        <select @change="emailSubject = 'Hi {{ addslashes($contact->first_name) }}'; emailBody = $el.value"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="Hi {{ $contact->first_name }}">Hi {{ $contact->first_name }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Subject</label>
                        <input type="text" x-model="emailSubject"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Body</label>
                        <textarea x-model="emailBody" rows="3"
                                  class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="sendEmail()"
                                class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-md text-white transition-all duration-300"
                                style="background:var(--brand-icon, #0ea5e9);"
                                onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                            Send Email
                        </button>
                        <button type="button" @click="showEmail = false" class="text-sm" style="color:var(--text-muted);">Cancel</button>
                    </div>
                </div>
                @endif
            </div>

            <form method="POST" action="{{ route('corex.contacts.update', $contact) }}" class="space-y-6">
                @csrf @method('PUT')
                <input type="hidden" name="_from_show" value="1">

                {{-- Basic Info --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-4" style="color:var(--text-muted);">Basic Information</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" value="{{ old('first_name', $contact->first_name) }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Surname <span class="text-red-500">*</span></label>
                            <input type="text" name="last_name" value="{{ old('last_name', $contact->last_name) }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contact Type</label>
                            <select name="contact_type_id"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— No type —</option>
                                @foreach($contactTypes as $type)
                                    <option value="{{ $type->id }}" {{ $contact->contact_type_id == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone <span class="text-red-500">*</span></label>
                            <input type="text" name="phone" value="{{ old('phone', $contact->phone) }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                            <input type="email" name="email" value="{{ old('email', $contact->email) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">ID Number <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                            <input type="text" name="id_number" value="{{ old('id_number', $contact->id_number) }}"
                                   placeholder="e.g. 9001010000000"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Date of Birth <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                            <input type="date" name="birthday" value="{{ old('birthday', $contact->birthday?->format('Y-m-d')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Address <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                            <input type="text" name="address" value="{{ old('address', $contact->address) }}"
                                   placeholder="e.g. 21 Dee Road, Uvongo"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>

                        {{-- Tags --}}
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Tags</label>
                            @php $contactTagIds = $contact->tags->pluck('id')->toArray(); @endphp
                            @if($contactTags->isNotEmpty())
                            <div class="flex flex-wrap gap-2">
                                @foreach($contactTags as $tag)
                                <label class="inline-flex items-center gap-1.5 cursor-pointer text-xs font-medium px-3 py-1.5 rounded-md transition-all duration-300"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);"
                                       x-data="{ checked: {{ in_array($tag->id, $contactTagIds) ? 'true' : 'false' }} }"
                                       :style="checked ? 'background:color-mix(in srgb, {{ $tag->color }} 12%, transparent); border-color:color-mix(in srgb, {{ $tag->color }} 40%, transparent); color:{{ $tag->color }};' : ''">
                                    <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}"
                                           {{ in_array($tag->id, $contactTagIds) ? 'checked' : '' }}
                                           @change="checked = $el.checked"
                                           class="sr-only">
                                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 transition-all duration-300"
                                          :style="checked ? 'background:{{ $tag->color }};' : 'background:var(--text-muted); opacity:0.3;'"></span>
                                    {{ $tag->name }}
                                </label>
                                @endforeach
                            </div>
                            @else
                            <p class="text-xs" style="color:var(--text-muted);">No tags configured — add them in
                                <a href="{{ route('corex.settings', ['tab'=>'feature','fsec'=>'contacts']) }}" class="underline" style="color:var(--brand-icon, #0ea5e9);">Settings → Contacts</a>.
                            </p>
                            @endif
                        </div>

                        {{-- Loaded / Modified dates --}}
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Loaded Date</label>
                            <input type="datetime-local" name="loaded_at" value="{{ old('loaded_at', $contact->loaded_at?->format('Y-m-d\TH:i')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Modified Date</label>
                            <input type="datetime-local" name="modified_at" value="{{ old('modified_at', $contact->modified_at?->format('Y-m-d\TH:i')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Banking Details (collapsible) --}}
                <div x-data="{ open: {{ ($contact->bank_name || $contact->bank_account_name || $contact->bank_account_number || $contact->bank_branch_name || $contact->bank_branch_code || $contact->bank_account_type) ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open" class="flex items-center gap-2 w-full text-left mb-4">
                        <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Banking Details</h3>
                        <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 transition-transform" style="color:var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="open" x-cloak>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Bank Name</label>
                                <input type="text" name="bank_name" value="{{ old('bank_name', $contact->bank_name) }}"
                                       placeholder="e.g. FNB"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Account Name</label>
                                <input type="text" name="bank_account_name" value="{{ old('bank_account_name', $contact->bank_account_name) }}"
                                       placeholder="Account holder name"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Account Number</label>
                                <input type="text" name="bank_account_number" value="{{ old('bank_account_number', $contact->bank_account_number) }}"
                                       placeholder="e.g. 62000000000"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Branch Name</label>
                                <input type="text" name="bank_branch_name" value="{{ old('bank_branch_name', $contact->bank_branch_name) }}"
                                       placeholder="e.g. Margate"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Branch Code</label>
                                <input type="text" name="bank_branch_code" value="{{ old('bank_branch_code', $contact->bank_branch_code) }}"
                                       placeholder="e.g. 210835"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Account Type</label>
                                <select name="bank_account_type"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="">— Select —</option>
                                    @foreach(['Savings', 'Cheque/Current', 'Transmission'] as $atype)
                                        <option value="{{ $atype }}" {{ old('bank_account_type', $contact->bank_account_type) === $atype ? 'selected' : '' }}>{{ $atype }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary text-sm">Save Changes</button>
                    <a href="{{ route('corex.contacts.index') }}" class="text-sm" style="color:var(--text-muted);">Cancel</a>
                </div>
            </form>
        </div>

        {{-- ════════════════════════════
             PROPERTIES TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'properties'" x-cloak class="p-6 space-y-6">

            {{-- Linked properties list --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">
                    Linked Properties ({{ $contact->properties->count() }})
                </h3>
                @forelse($contact->properties as $prop)
                @php
                $propThumb = $prop->gallery_images_json[0] ?? ($prop->dawn_images_json[0] ?? null);
                $propSc = ['active'=>'#22c55e','draft'=>'#94a3b8','sold'=>'#3b82f6','withdrawn'=>'#f59e0b'][$prop->status] ?? '#94a3b8';
                @endphp
                <div class="flex items-center gap-3 px-4 py-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);">
                    {{-- Thumb --}}
                    <div class="w-12 h-12 rounded-md overflow-hidden flex-shrink-0" style="background:var(--surface);">
                        @if($propThumb)
                        <img src="{{ $propThumb }}" alt="" class="w-full h-full object-cover">
                        @else
                        <div class="w-full h-full flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-6 h-6" style="color:var(--text-muted);opacity:.4;"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </div>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('corex.properties.show', $prop) }}"
                           class="text-sm font-semibold no-underline hover:underline"
                           style="color:var(--text-primary);">{{ $prop->title }}</a>
                        <div class="text-xs mt-0.5 flex flex-wrap gap-2" style="color:var(--text-muted);">
                            <span style="color:{{ $propSc }};">{{ ucfirst($prop->status) }}</span>
                            <span>{{ $prop->formattedPrice() }}</span>
                            @if($prop->address)<span>{{ $prop->address }}{{ $prop->suburb ? ', '.$prop->suburb : '' }}</span>@elseif($prop->suburb)<span>{{ $prop->suburb }}</span>@endif
                            @if($prop->pivot->role)<span class="font-semibold" style="color:var(--brand-icon, #0ea5e9);">{{ ucfirst($prop->pivot->role) }}</span>@endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('corex.contacts.properties.unlink', [$contact, $prop]) }}"
                          onsubmit="return confirm('Unlink this property from {{ addslashes($contact->full_name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs font-semibold text-red-500 hover:text-red-600 px-3 py-1.5 rounded-md hover:bg-red-500/10 transition-all duration-300 flex-shrink-0">Unlink</button>
                    </form>
                </div>
                @empty
                <div class="rounded-md p-6 text-center" style="background:var(--surface-2); border:1px dashed var(--border-hover);">
                    <div class="text-sm" style="color:var(--text-secondary);">No properties linked yet.</div>
                </div>
                @endforelse
            </div>

            {{-- Link property by address search --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:20px;">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-4" style="color:var(--text-muted);">Link a Property</h3>
                <p class="text-xs mb-4" style="color:var(--text-muted);">Search by address, suburb or title.</p>

                <div class="relative mb-3">
                    <input type="text" x-model="propSearch" @input.debounce.300ms="searchProps()"
                           placeholder="e.g. 21 Dee Road, Uvongo…"
                           class="w-full rounded-md px-3 py-2 text-sm pr-10"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <div x-show="propLoading" class="absolute right-3 top-2.5">
                        <svg class="animate-spin w-4 h-4" style="color:var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </div>
                </div>

                <div x-show="propResults.length > 0" class="rounded-md overflow-hidden mb-3" style="border:1px solid var(--border);">
                    <template x-for="r in propResults" :key="r.id">
                        <form method="POST" action="{{ route('corex.contacts.properties.link', $contact) }}">
                            @csrf
                            <input type="hidden" name="property_id" :value="r.id">
                            <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 text-left hover:opacity-80 transition-colors"
                                    style="border-bottom:1px solid var(--border); background:var(--surface);">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="r.title"></div>
                                    <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="(r.address || '') + ' · ' + r.price"></div>
                                </div>
                                <span class="text-xs font-semibold flex-shrink-0 px-2 py-1 rounded-md"
                                      :style="`background:${statusColor(r.status)}22; color:${statusColor(r.status)}; border:1px solid ${statusColor(r.status)}44;`"
                                      x-text="r.status.charAt(0).toUpperCase() + r.status.slice(1)"></span>
                                <span class="text-xs font-semibold flex-shrink-0" style="color:var(--brand-icon, #0ea5e9);">+ Link</span>
                            </button>
                        </form>
                    </template>
                </div>

                <div x-show="propSearched && propResults.length === 0" class="text-sm" style="color:var(--text-muted);">
                    No matching properties found.
                </div>
            </div>

        </div>

        {{-- ════════════════════════════
             NOTES TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'notes'" x-cloak class="p-6 space-y-5" id="tab-notes">

            {{-- Add note --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:16px;">
                <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Note</div>
                <form method="POST" action="{{ route('corex.contacts.notes.store', $contact) }}" class="space-y-3">
                    @csrf
                    <textarea name="body" rows="3" required
                              placeholder="Write a note…"
                              class="w-full rounded-md px-3 py-2 text-sm resize-none"
                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    <div class="flex justify-end">
                        <button type="submit" class="corex-btn-primary text-sm">Add Note</button>
                    </div>
                </form>
            </div>

            {{-- Notes list --}}
            @forelse($contact->contactNotes as $note)
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:16px;">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <div class="w-7 h-7 rounded-md flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                             style="background:var(--brand-default, #0b2a4a);">
                            {{ strtoupper(substr($note->user?->name ?? '?', 0, 1)) }}
                        </div>
                        <div>
                            <div class="text-xs font-semibold" style="color:var(--text-primary);">{{ $note->user?->name ?? 'Unknown' }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ $note->created_at->format('d M Y H:i') }} · {{ $note->created_at->diffForHumans() }}</div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('corex.contacts.notes.destroy', [$contact, $note]) }}"
                          onsubmit="return confirm('Delete this note?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-600 hover:text-red-700 flex-shrink-0">Delete</button>
                    </form>
                </div>
                <div class="mt-3 text-sm whitespace-pre-line" style="color:var(--text-primary);">{{ $note->body }}</div>
            </div>
            @empty
            <div class="py-12 text-center" style="color:var(--text-muted);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10 mx-auto mb-3 opacity-30"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                <div class="text-sm">No notes yet.</div>
            </div>
            @endforelse
        </div>

        {{-- ════════════════════════════
             DRIVE TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'drive'" x-cloak class="p-6 space-y-5" id="tab-drive"
             x-data="{ dragging: false }">

            {{-- Upload area --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:16px;">
                <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Upload File</div>
                <form method="POST" action="{{ route('corex.contacts.documents.store', $contact) }}"
                      enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <div @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false"
                         @drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files"
                         :style="dragging ? 'border-color:var(--brand-icon, #0ea5e9); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);' : ''"
                         class="border-2 border-dashed rounded-md p-8 text-center transition-all duration-300 cursor-pointer"
                         style="border-color:var(--border);"
                         @click="$refs.fileInput.click()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 mx-auto mb-2 opacity-30"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                        <div class="text-sm" style="color:var(--text-secondary);">Drag & drop or click to upload</div>
                        <div class="text-xs mt-1" style="color:var(--text-muted);">Max 20 MB — images, PDFs, documents</div>
                        <input x-ref="fileInput" type="file" name="file" class="hidden"
                               @change="$el.closest('form').querySelector('.file-name').textContent = $el.files[0]?.name ?? ''">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <select name="document_type_id" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface-1); color:var(--text-primary);">
                            <option value="">Document Type (optional)</option>
                            @foreach($documentTypes as $dt)
                            <option value="{{ $dt->id }}">{{ $dt->label }}</option>
                            @endforeach
                        </select>
                        <select name="property_id" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface-1); color:var(--text-primary);">
                            <option value="">Link to Property (optional)</option>
                            @foreach($contact->properties as $prop)
                            <option value="{{ $prop->id }}">{{ trim(($prop->unit_number ? 'Unit '.$prop->unit_number.', ' : '').($prop->complex_name ? $prop->complex_name.', ' : '').($prop->address ? $prop->address.', ' : '').($prop->suburb ?? ''), ', ') ?: 'Property #'.$prop->id }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="file-name text-xs truncate" style="color:var(--text-muted);"></span>
                        <button type="submit" class="corex-btn-primary text-sm flex-shrink-0">Upload</button>
                    </div>
                </form>
            </div>

            {{-- Grouped file list --}}
            @if($contact->documents->isNotEmpty())
                <div class="text-xs" style="color:var(--text-muted);">{{ $contact->documents->count() }} file{{ $contact->documents->count() !== 1 ? 's' : '' }}</div>

                @foreach($driveLinkedGroups as $propId => $docs)
                @php $prop = $drivePropertyMap->get($propId); @endphp
                <div style="border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                    <div class="px-4 py-2.5 flex items-center gap-2" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 opacity-50"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        <span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $prop ? (trim(($prop->unit_number ? 'Unit '.$prop->unit_number.', ' : '').($prop->complex_name ? $prop->complex_name.', ' : '').($prop->address ? $prop->address.', ' : '').($prop->suburb ?? ''), ', ') ?: 'Property #'.$prop->id) : 'Unknown Property' }}</span>
                    </div>
                    @foreach($docs as $doc)
                    @include('corex.contacts._drive-row', ['doc' => $doc, 'contact' => $contact, 'documentTypes' => $documentTypes])
                    @endforeach
                </div>
                @endforeach

                @if($driveUnlinkedDocs->isNotEmpty())
                <div style="border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                    <div class="px-4 py-2.5" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <span class="text-xs font-semibold" style="color:var(--text-muted);">Not Property-Linked</span>
                    </div>
                    @foreach($driveUnlinkedDocs as $doc)
                    @include('corex.contacts._drive-row', ['doc' => $doc, 'contact' => $contact, 'documentTypes' => $documentTypes])
                    @endforeach
                </div>
                @endif
            @else
            <div class="py-10 text-center" style="color:var(--text-muted);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10 mx-auto mb-3 opacity-30"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                <div class="text-sm">No files uploaded yet.</div>
            </div>
            @endif
        </div>

        {{-- ════════════════════════════
             FICA COMPLIANCE TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'fica'" x-cloak class="p-6 space-y-6" id="tab-fica">

            {{-- FICA status indicator --}}
            @php
                $ficaDocs = $contact->signedDocuments()
                    ->wherePivot('document_type', 'fica')
                    ->wherePivot('is_signed', true)
                    ->orderByPivot('signed_at', 'desc')
                    ->get();
                $allSignedDocs = $contact->signedDocuments()
                    ->wherePivot('is_signed', true)
                    ->orderByPivot('signed_at', 'desc')
                    ->get();
            @endphp

            <div class="rounded-lg p-5" style="border:1px solid var(--border); background:var(--surface-2);">
                <div class="flex items-center gap-4">
                    @if($ficaStatus === 'complete')
                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg" style="background:#dcfce7; color:#166534;">
                            &#10003;
                        </div>
                        <div>
                            <h3 class="text-base font-bold" style="color:var(--text-primary);">FICA Complete</h3>
                            <p class="text-sm" style="color:var(--text-secondary);">
                                {{ $ficaDocs->count() }} FICA document{{ $ficaDocs->count() !== 1 ? 's' : '' }} on file.
                                @if($ficaDocs->first()?->pivot?->signed_at)
                                    Latest signed {{ \Carbon\Carbon::parse($ficaDocs->first()->pivot->signed_at)->format('d M Y') }}.
                                @endif
                            </p>
                        </div>
                    @elseif($ficaStatus === 'expiring')
                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg" style="background:#fef3c7; color:#92400e;">
                            &#9888;
                        </div>
                        <div>
                            <h3 class="text-base font-bold" style="color:var(--text-primary);">FICA Expiring Soon</h3>
                            <p class="text-sm" style="color:var(--text-secondary);">FICA documents are nearing expiry. Consider requesting updated documentation.</p>
                        </div>
                    @else
                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg" style="background:#fecaca; color:#991b1b;">
                            &#10007;
                        </div>
                        <div>
                            <h3 class="text-base font-bold" style="color:var(--text-primary);">No FICA on File</h3>
                            <p class="text-sm" style="color:var(--text-secondary);">This contact has no signed FICA documents. FICA compliance is required before transacting.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- FICA documents --}}
            @if($ficaDocs->isNotEmpty())
            <div>
                <h4 class="text-sm font-bold uppercase tracking-wide mb-3" style="color:var(--text-muted);">FICA Documents</h4>
                <div class="space-y-2">
                    @foreach($ficaDocs as $doc)
                    <div class="flex items-center justify-between p-3 rounded-lg" style="background:var(--surface); border:1px solid var(--border);">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                            <div>
                                <p class="text-sm font-semibold" style="color:var(--text-primary);">{{ $doc->name }}</p>
                                <p class="text-xs" style="color:var(--text-muted);">
                                    {{ ucfirst(str_replace('_', ' ', $doc->pivot->party_role ?? '')) }}
                                    &middot; Signed {{ $doc->pivot->signed_at ? \Carbon\Carbon::parse($doc->pivot->signed_at)->format('d M Y') : 'N/A' }}
                                </p>
                            </div>
                        </div>
                        @if($doc->pivot->signed_pdf_path)
                        <a href="{{ route('docuperfect.signatures.download', $doc) }}"
                           class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
                           style="color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);">
                            Download
                        </a>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- All signed documents for this contact --}}
            @if($allSignedDocs->isNotEmpty())
            <div>
                <h4 class="text-sm font-bold uppercase tracking-wide mb-3" style="color:var(--text-muted);">All Signed Documents</h4>
                <div class="space-y-2">
                    @foreach($allSignedDocs as $doc)
                    <div class="flex items-center justify-between p-3 rounded-lg" style="background:var(--surface); border:1px solid var(--border);">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 flex-shrink-0" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                            <div>
                                <p class="text-sm font-semibold" style="color:var(--text-primary);">{{ $doc->name }}</p>
                                <p class="text-xs" style="color:var(--text-muted);">
                                    {{ ucfirst(str_replace('_', ' ', $doc->pivot->party_role ?? '')) }}
                                    &middot; {{ ucfirst($doc->pivot->document_type ?? 'document') }}
                                    &middot; {{ $doc->pivot->signed_at ? \Carbon\Carbon::parse($doc->pivot->signed_at)->format('d M Y') : '' }}
                                </p>
                            </div>
                        </div>
                        @if($doc->pivot->signed_pdf_path)
                        <a href="{{ route('docuperfect.signatures.download', $doc) }}"
                           class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
                           style="color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);">
                            Download
                        </a>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        {{-- ════════════════════════════
             CORE MATCHES TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'matches'" x-cloak class="p-6 space-y-6" id="tab-matches">

            {{-- Add new match form --}}
            <div class="rounded-md p-5 space-y-5" style="background:var(--surface-2); border:1px solid var(--border);">
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Add New Match Criteria</h3>

                <form method="POST" action="{{ route('corex.contacts.matches.store', $contact) }}"
                      x-data="{ listingType: 'sale' }"
                      class="space-y-5">
                    @csrf

                    {{-- Listing type toggle --}}
                    <div>
                        <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Listing Type</label>
                        <input type="hidden" name="listing_type" :value="listingType">
                        <div class="inline-flex rounded-md p-0.5 gap-0.5" style="background:var(--surface); border:1px solid var(--border);">
                            <button type="button"
                                    @click="listingType = 'sale'"
                                    :class="listingType === 'sale' ? 'text-white' : ''"
                                    :style="listingType === 'sale' ? 'background:var(--brand-button, #0ea5e9);' : 'color:var(--text-secondary);'"
                                    class="px-4 py-1.5 rounded-md text-xs font-semibold transition-all duration-150">
                                For Sale
                            </button>
                            <button type="button"
                                    @click="listingType = 'rental'"
                                    :class="listingType === 'rental' ? 'text-white' : ''"
                                    :style="listingType === 'rental' ? 'background:var(--brand-button, #0ea5e9);' : 'color:var(--text-secondary);'"
                                    class="px-4 py-1.5 rounded-md text-xs font-semibold transition-all duration-150">
                                Rental
                            </button>
                        </div>
                    </div>

                    {{-- Row 1: Category + Property Type + Suburb --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Category</label>
                            <select name="category" class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— Any —</option>
                                @foreach($matchCategories as $cat)
                                <option value="{{ $cat->name }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Property Type</label>
                            <select name="property_type" class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— Any —</option>
                                @foreach($matchTypes as $type)
                                <option value="{{ $type->name }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Suburb</label>
                            <input type="text" name="suburb" placeholder="e.g. Uvongo, Margate"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>

                    {{-- Row 2: Price range --}}
                    <div>
                        <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Price Range (R)</label>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <input type="number" name="price_min" placeholder="Min price" min="0" step="50000"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <input type="number" name="price_max" placeholder="Max price" min="0" step="50000"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>

                    {{-- Row 3: Beds / Baths / Garages / Parking --}}
                    <div>
                        <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Minimum Rooms</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            @foreach([['beds_min','Bedrooms'],['baths_min','Bathrooms'],['garages_min','Garages'],['parking_min','Parking']] as [$field,$label])
                            <div>
                                <label class="block text-[10px] mb-1" style="color:var(--text-muted);">{{ $label }}</label>
                                <input type="number" name="{{ $field }}" placeholder="Any" min="0" max="20"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Row 4: Floor size / Erf size --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Floor Size (m²)</label>
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" name="floor_size_min" placeholder="Min" min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <input type="number" name="floor_size_max" placeholder="Max" min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Erf Size (m²)</label>
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" name="erf_size_min" placeholder="Min" min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <input type="number" name="erf_size_max" placeholder="Max" min="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>

                    {{-- Notes --}}
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Notes (optional)</label>
                        <textarea name="notes" rows="2" placeholder="Any additional requirements..."
                                  class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit"
                                class="px-5 py-2 rounded-md text-sm font-semibold text-white shadow-lg transition-all duration-300"
                                style="background:var(--brand-button, #0ea5e9);"
                                onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                            Save Match
                        </button>
                    </div>
                </form>
            </div>

            {{-- Existing matches --}}
            @if($contact->matches->count())
            <div class="space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Saved Matches ({{ $contact->matches->count() }})</h3>
                @foreach($contact->matches as $match)
                <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0 space-y-3">

                            {{-- Header row: type badge + price --}}
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs font-bold px-2.5 py-1 rounded-md"
                                      style="{{ $match->listing_type === 'rental' ? 'background:rgba(168,85,247,0.12); color:#a855f7; border:1px solid rgba(168,85,247,0.25);' : 'background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent);' }}">
                                    {{ $match->listingTypeLabel() }}
                                </span>
                                @if($match->price_min || $match->price_max)
                                <span class="text-sm font-bold" style="color:var(--text-primary);">{{ $match->priceRangeLabel() }}</span>
                                @endif
                                @if($match->suburb)
                                <span class="text-xs px-2 py-0.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">
                                    📍 {{ $match->suburb }}
                                </span>
                                @endif
                            </div>

                            {{-- Detail grid --}}
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-1.5">
                                @if($match->category)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Category</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">{{ $match->category }}</div>
                                </div>
                                @endif
                                @if($match->property_type)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">{{ $match->property_type }}</div>
                                </div>
                                @endif
                                @foreach([[$match->beds_min,'Beds'],[$match->baths_min,'Baths'],[$match->garages_min,'Garages'],[$match->parking_min,'Parking']] as [$val,$lbl])
                                @if($val !== null)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $lbl }}</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">{{ $val }}+</div>
                                </div>
                                @endif
                                @endforeach
                                @if($match->floor_size_min || $match->floor_size_max)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Floor m²</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">
                                        {{ $match->floor_size_min ? number_format($match->floor_size_min) : '—' }} – {{ $match->floor_size_max ? number_format($match->floor_size_max) : '—' }}
                                    </div>
                                </div>
                                @endif
                                @if($match->erf_size_min || $match->erf_size_max)
                                <div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Erf m²</span>
                                    <div class="text-xs font-medium mt-0.5" style="color:var(--text-primary);">
                                        {{ $match->erf_size_min ? number_format($match->erf_size_min) : '—' }} – {{ $match->erf_size_max ? number_format($match->erf_size_max) : '—' }}
                                    </div>
                                </div>
                                @endif
                            </div>

                            @if($match->notes)
                            <p class="text-xs leading-relaxed" style="color:var(--text-muted);">{{ $match->notes }}</p>
                            @endif

                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <div class="text-[10px]" style="color:var(--text-muted);">
                                    Added {{ $match->created_at->diffForHumans() }}
                                    @if($match->createdBy) · by {{ $match->createdBy->name }} @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('corex.contacts.matches.results', [$contact, $match]) }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold no-underline transition-all duration-300"
                                       style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent); color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent);"
                                       onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon, #0ea5e9) 20%, transparent)'" onmouseout="this.style.background='color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent)'">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
                                        View Matches
                                    </a>
                                </div>
                            </div>
                        </div>

                        {{-- Delete --}}
                        <form method="POST" action="{{ route('corex.contacts.matches.destroy', [$contact, $match]) }}"
                              onsubmit="return confirm('Remove this match criteria?');"
                              class="flex-shrink-0">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="p-1.5 rounded-md transition-all duration-300"
                                    style="color:var(--text-muted);"
                                    onmouseover="this.style.color='#ef4444'; this.style.background='rgba(239,68,68,0.08)'"
                                    onmouseout="this.style.color='var(--text-muted)'; this.style.background='transparent'">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="py-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10 mx-auto mb-3 opacity-25" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
                <p class="text-sm font-semibold" style="color:var(--text-muted);">No match criteria saved yet.</p>
                <p class="text-xs mt-1" style="color:var(--text-muted); opacity:.7;">Use the form above to add what this contact is looking for.</p>
            </div>
            @endif

        </div>{{-- /matches tab --}}

    </div>{{-- /tab container --}}

</div>

<script>
function contactShowData(searchUrl, initTab) {
    return {
        activeTab: initTab || 'info',
        initTab: initTab || 'info',
        propSearch: '',
        propResults: [],
        propLoading: false,
        propSearched: false,
        async searchProps() {
            if (this.propSearch.length < 1) { this.propResults = []; this.propSearched = false; return; }
            this.propLoading = true;
            try {
                const r = await fetch(searchUrl + '?q=' + encodeURIComponent(this.propSearch), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                this.propResults = await r.json();
                this.propSearched = true;
            } finally { this.propLoading = false; }
        },
        statusColor(s) {
            return {active:'#22c55e', draft:'#94a3b8', sold:'#3b82f6', withdrawn:'#f59e0b'}[s] || '#94a3b8';
        }
    };
}
document.addEventListener('DOMContentLoaded', function () {
    const hash = window.location.hash;
    if (hash === '#tab-notes') {
        document.querySelector('[\\@click="activeTab = \'notes\'"]')?.click();
    } else if (hash === '#tab-drive') {
        document.querySelector('[\\@click="activeTab = \'drive\'"]')?.click();
    }
});
</script>
@endsection
