{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<style>
    /* Contact show page — CoreX UI Design System token-based hover */
    .contact-show-row { transition: background 150ms ease; }
    .contact-show-row:hover { background: var(--surface-2); }
    .contact-show-wa-card { transition: background 150ms ease, border-color 150ms ease; }
    .contact-show-wa-card:hover { border-color: #25d366; background: color-mix(in srgb, #25d366 6%, transparent); }
    .contact-show-email-card { transition: background 150ms ease, border-color 150ms ease; }
    .contact-show-email-card:hover { border-color: var(--brand-icon, #0ea5e9); background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent); }
    .contact-show-btn-hover { transition: opacity 150ms ease; }
    .contact-show-btn-hover:hover { opacity: 0.85; }
</style>
<div class="w-full space-y-4"
     x-data="contactShowData('{{ route('corex.contacts.properties.search', $contact) }}', '{{ request('tab', 'info') }}')"
     x-init="activeTab = initTab">

    {{-- Back link --}}
    <a href="{{ route('corex.contacts.index') }}"
       class="inline-flex items-center gap-1.5 text-sm no-underline"
       style="color:var(--text-secondary);">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
        Back to Contacts
    </a>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            <div class="flex-1"><strong>Please fix the following:</strong> {{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Contact header card --}}
    <div class="rounded-md p-6" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex items-start justify-between gap-5 flex-wrap">
            {{-- Left group: avatar + name + meta. Kept as one flex-1 unit so the
                 action buttons (right group) wrap as a block and never squeeze
                 the name into a clipped, narrow column. --}}
            <div class="flex items-start gap-5 flex-1 min-w-0">
            {{-- Avatar --}}
            <div class="w-16 h-16 rounded-full flex items-center justify-center flex-shrink-0 text-xl font-bold text-white"
                 style="background: var(--brand-icon, #0ea5e9);">
                {{ $contact->initials }}
            </div>

            {{-- Name + meta --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="text-xl font-bold text-white leading-tight">{{ $contact->full_name }}</h1>
                    @if($contact->type)
                    <span class="text-xs px-2.5 py-1 rounded-md font-semibold text-white"
                          style="background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.25);">
                        {{ $contact->type->name }}
                    </span>
                    @endif
                    {{-- AT-50/AT-81 — derived communication status. All five outreach-consent
                         states are visibly distinct; tint is keyed off $commMeta['key']. --}}
                    @php
                        $commMeta = $contact->communicationStatusMeta();
                        $commTint = match ($commMeta['key']) {
                            \App\Models\Contact::COMM_TRANSACTION_ONLY     => 'rgba(217,119,6,0.85)',
                            \App\Models\Contact::COMM_ALL_BLOCKED          => 'rgba(220,38,38,0.85)',
                            \App\Models\Contact::COMM_MARKETING_OPTED_OUT  => 'var(--ds-orange, #ea580c)', // declined
                            \App\Models\Contact::OUTREACH_NO_RESPONSE      => 'var(--ds-amber, #f59e0b)',
                            \App\Models\Contact::OUTREACH_PENDING          => 'var(--ds-orange, #ea580c)',
                            \App\Models\Contact::OUTREACH_CONFIRMED        => 'var(--ds-green, #059669)',
                            \App\Models\Contact::OUTREACH_INITIAL          => 'rgba(22,163,74,0.85)',
                            default                                        => 'rgba(22,163,74,0.85)',
                        };
                    @endphp
                    <span class="text-xs px-2.5 py-1 rounded-md font-semibold text-white"
                          title="{{ $commMeta['title'] ?? '' }}"
                          style="background:{{ $commTint }}; border:1px solid rgba(255,255,255,0.35);">
                        {{ $commMeta['label'] }}
                    </span>
                </div>

                {{-- AT-125 — list ALL identifiers (primary first, marked); falls back
                     to the mirror for any contact without child rows. --}}
                @php
                    $allPhones = $contact->relationLoaded('phones')
                        ? $contact->phones->sortByDesc('is_primary')->values() : collect();
                    $allEmails = $contact->relationLoaded('emails')
                        ? $contact->emails->sortByDesc('is_primary')->values() : collect();
                @endphp
                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1.5">
                    @forelse($allPhones as $ph)
                        <span class="flex items-center gap-1.5 text-sm text-white/60">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                            <a href="tel:{{ preg_replace('/\s+/', '', $ph->phone) }}" class="no-underline hover:underline" style="color:inherit;">{{ $ph->phone }}</a>
                            @if($ph->label)<span class="text-[11px] text-white/35">{{ $ph->label }}</span>@endif
                            @if($ph->is_primary)<span class="text-[10px] uppercase tracking-wide font-semibold" style="color:var(--ds-teal, #00d4aa);">primary</span>@endif
                        </span>
                    @empty
                        @if($contact->phone)
                        <span class="flex items-center gap-1.5 text-sm text-white/60">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                            {{ $contact->phone }}
                        </span>
                        @endif
                    @endforelse

                    @forelse($allEmails as $em)
                        <span class="flex items-center gap-1.5 text-sm text-white/60">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                            <a href="mailto:{{ $em->email }}" class="no-underline hover:underline" style="color:inherit;">{{ $em->email }}</a>
                            @if($em->label)<span class="text-[11px] text-white/35">{{ $em->label }}</span>@endif
                            @if($em->is_primary)<span class="text-[10px] uppercase tracking-wide font-semibold" style="color:var(--ds-teal, #00d4aa);">primary</span>@endif
                        </span>
                    @empty
                        @if($contact->email)
                        <span class="flex items-center gap-1.5 text-sm text-white/60">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                            <a href="mailto:{{ $contact->email }}" class="no-underline hover:underline" style="color:inherit;">{{ $contact->email }}</a>
                        </span>
                        @endif
                    @endforelse
                </div>

                {{-- Linked agent + timestamps. The "Agent:" label is the ASSIGNED
                     agent (contacts.agent_id) ONLY — never the creator. A contact
                     with no assigned agent reads "Unassigned" (AT-118: do not pass
                     created_by off as the agent; the creator is shown separately as
                     "Captured by" on the assignment panel). --}}
                @php $primaryAgent = $contact->agent; @endphp
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1">
                    <span class="text-xs flex items-center gap-1.5" style="color:rgba(255,255,255,0.4);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        @if($primaryAgent)
                            Agent: <strong class="text-white/60">{{ $primaryAgent->name }}</strong>
                            @if($primaryAgent->email)
                                <span style="color:rgba(255,255,255,0.3);">· {{ $primaryAgent->email }}</span>
                            @endif
                        @else
                            Agent: <strong class="text-white/60">Unassigned</strong>
                        @endif
                    </span>
                    @if($contact->secondAgent)
                    <span class="text-xs flex items-center gap-1.5" style="color:rgba(255,255,255,0.4);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        Co-Agent: <strong class="text-white/60">{{ $contact->secondAgent->name }}</strong>
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
            </div>{{-- /left group --}}

            {{-- Right group: action buttons — wrap together, never overlap the name --}}
            <div class="flex items-center gap-2 flex-wrap flex-shrink-0">
            @include('layouts.partials.tour-header-launcher')
            {{-- Schedule Event from Contact --}}
            <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $contact->id, 'prefill_class' => $contact->is_buyer ? 'viewing' : 'meeting']) }}"
               target="_blank" rel="noopener"
               class="corex-btn-primary flex-shrink-0 no-underline"
               title="Opens the calendar in a new tab so you stay on this contact">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                Schedule Event
            </a>

            {{-- Birthday reminder (opt-in, only when a DOB is on file) --}}
            @if($contact->birthday)
            <form method="POST" action="{{ route('corex.contacts.birthday-reminder.toggle', $contact) }}" class="flex-shrink-0">
                @csrf
                @if($contact->birthday_reminder)
                <button type="submit" class="corex-btn-outline corex-btn-on-brand no-underline" title="Stop reminding me about this birthday">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.983 1.907a.75.75 0 0 0-1.966 0l-.16.661a8.25 8.25 0 0 0-6.357 8.027v3.243a3 3 0 0 1-.879 2.121l-.886.886A.75.75 0 0 0 2.5 18.75h19a.75.75 0 0 0 .53-1.28l-.886-.886a3 3 0 0 1-.879-2.122v-3.242a8.25 8.25 0 0 0-6.357-8.027l-.16-.661ZM12 22.5a3 3 0 0 1-2.83-2h5.66A3 3 0 0 1 12 22.5Z"/></svg>
                    Birthday reminder on
                </button>
                @else
                <button type="submit" class="corex-btn-outline corex-btn-on-brand no-underline" title="Remind me about this birthday">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
                    Remind me of birthday
                </button>
                @endif
            </form>
            @endif

            {{-- View as Buyer (if buyer) --}}
            @if($contact->is_buyer)
            <a href="{{ route('command-center.buyers.show', $contact) }}"
               class="corex-btn-outline corex-btn-on-brand flex-shrink-0 no-underline">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                Buyer Hub
            </a>
            @endif

            {{-- Create Listing from Contact (only if no linked properties).
                 Agent chooses the Classic form (single-page) or the guided
                 Upload Wizard — both pre-fill the contact's address and link
                 the contact as the seller/landlord on save. --}}
            @if(auth()->user()->hasPermission('access_properties') && $contact->properties()->count() === 0)
            <div class="relative flex-shrink-0" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open"
                        class="corex-btn-outline corex-btn-on-brand no-underline"
                        title="Create a new property linked to this contact">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Create Listing
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 ml-0.5" :class="open ? 'rotate-180' : ''" style="transition:transform .2s;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                </button>
                <div x-show="open" x-transition @click.outside="open = false"
                     class="absolute right-0 mt-1 w-60 rounded-md overflow-hidden z-30 shadow-lg"
                     style="background:var(--surface); border:1px solid var(--border);" x-cloak>
                    <a href="{{ route('corex.properties.wizard') }}?contact_id={{ $contact->id }}"
                       target="_blank" rel="noopener"
                       class="flex items-start gap-2.5 px-3 py-2.5 no-underline transition-colors"
                       style="color:var(--text-primary);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        <span>
                            <span class="block text-sm font-semibold">Upload Wizard</span>
                            <span class="block text-xs" style="color:var(--text-muted);">Guided, 4 quick steps</span>
                        </span>
                    </a>
                    <a href="{{ route('corex.properties.create') }}?contact_id={{ $contact->id }}"
                       target="_blank" rel="noopener"
                       class="flex items-start gap-2.5 px-3 py-2.5 no-underline transition-colors"
                       style="color:var(--text-primary); border-top:1px solid var(--border);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 flex-shrink-0" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m11.25 5.5H18a2.25 2.25 0 0 1-2.25-2.25v-2.25"/></svg>
                        <span>
                            <span class="block text-sm font-semibold">Classic Form</span>
                            <span class="block text-xs" style="color:var(--text-muted);">Everything on one page</span>
                        </span>
                    </a>
                </div>
            </div>
            @endif

            {{-- Delete button --}}
            @if(auth()->user()->hasPermission('contacts.delete'))
            <form method="POST" action="{{ route('corex.contacts.destroy', $contact) }}"
                  onsubmit="return confirm('Permanently delete {{ addslashes($contact->full_name) }}?');"
                  class="flex-shrink-0">
                @csrf @method('DELETE')
                <button type="submit" class="corex-btn-outline"
                        style="color: var(--ds-crimson); border-color: color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
                    Delete Contact
                </button>
            </form>
            @endif
            </div>{{-- /right group (actions) --}}
        </div>
    </div>

    {{-- Tab bar --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex overflow-x-auto" style="border-bottom: 1px solid var(--border);" id="tab-bar">
            @php
                $ficaStatus = $contact->ficaStatus();
                $ficaIcon = match($ficaStatus) {
                    'complete' => '<span class="ds-badge ds-badge-success ml-1">Complete</span>',
                    'expiring' => '<span class="ds-badge ds-badge-warning ml-1">Expiring</span>',
                    default => '<span class="ds-badge ds-badge-danger ml-1">Incomplete</span>',
                };
            @endphp
            @php
                $outreachCount = $outreachSends?->count() ?? 0;
                $outreachOptOutBadge = $contact->messaging_opt_out_at
                    ? ' <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase" style="background:var(--ds-crimson); color:#fff;">opt-out</span>'
                    : '';
            @endphp
            @foreach([
                ['key'=>'info','label'=>'Info'],
                ['key'=>'properties','label'=>'Properties &amp; Core Matches <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $contact->properties->count() .'</span>'],
                ['key'=>'viewings','label'=>'Viewings &amp; Feedback <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. ($viewingsCount ?? 0) .'</span>'],
                ['key'=>'notes','label'=>'Notes &amp; Testimonials <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. ($contact->contactNotes->count() + $contact->testimonials->count()) .'</span>'],
                ['key'=>'drive','label'=>'Drive <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $contact->documents->count() .'</span>'],
                ['key'=>'fica','label'=>'FICA Compliance ' . $ficaIcon],
                ['key'=>'consent','label'=>'Consent'],
                ['key'=>'communications','label'=>'Communications <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. ($contactThreads ?? collect())->count() .'</span>'],
                ['key'=>'outreach','label'=>'Outreach <span class="ml-1 text-xs px-1.5 py-0.5 rounded-md" style="background:var(--surface-2);">'. $outreachCount .'</span>' . $outreachOptOutBadge],
            ] as $t)
            @if($t['key'] === 'outreach' && !auth()->user()->hasPermission('outreach.compose'))
                @continue
            @endif
            @if($t['key'] === 'communications' && !(($canViewComms ?? false) || ($canRequestComms ?? false)))
                @continue
            @endif
            <button type="button"
                    @click="activeTab = '{{ $t['key'] }}'"
                    @if($t['key'] === 'outreach') data-tour="outreach-tab" @endif
                    :class="activeTab === '{{ $t['key'] }}' ? 'border-b-2' : 'border-b-2 border-transparent'"
                    :style="activeTab === '{{ $t['key'] }}' ? 'color:var(--brand-icon, #0ea5e9); border-color:var(--brand-icon, #0ea5e9); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);' : 'color:var(--text-secondary);'"
                    class="px-4 py-4 text-sm font-semibold whitespace-nowrap transition-all duration-300 outline-none hover:opacity-80"
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
                    waCount: {{ (int) ($waSent ?? 0) }},
                    emailCount: {{ (int) ($emailSent ?? 0) }},
                    lastContactedLabel: '{{ $contact->last_contacted_at ? $contact->last_contacted_at->format('d M Y H:i') : 'Never' }}',
                    lastContactedRelative: '{{ $contact->last_contacted_at ? $contact->last_contacted_at->diffForHumans() : '' }}',
                    waMessage: 'Hi {{ addslashes($contact->first_name) }}',
                    emailSubject: 'Hi {{ addslashes($contact->first_name) }}',
                    emailBody: 'Hi {{ addslashes($contact->first_name) }}',
                    async increment(channel, payload = {}) {
                        // Optimistic bump for instant feedback; reconciled by the
                        // server's derived count below (AT-59).
                        if (channel === 'whatsapp') this.waCount++;
                        else this.emailCount++;
                        try {
                            const res = await fetch('{{ route('corex.contacts.increment', $contact) }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                                body: JSON.stringify({ channel, subject: payload.subject ?? null, body: payload.body ?? null })
                            });
                            const data = await res.json();
                            if (channel === 'whatsapp') this.waCount = data.count;
                            else this.emailCount = data.count;
                            this.lastContactedLabel = data.last_contacted;
                            this.lastContactedRelative = data.last_contacted_relative;
                        } catch (e) {
                            // Network blip: keep the optimistic bump; the archive
                            // remains the source of truth on next page load.
                        }
                    },
                    sendWa() {
                        let phone = '{{ preg_replace('/[^0-9]/', '', $contact->phone ?? '') }}';
                        if (phone.startsWith('0')) phone = '27' + phone.substring(1);
                        window.location.href = 'whatsapp://send?phone=' + phone + '&text=' + encodeURIComponent(this.waMessage);
                        this.increment('whatsapp', { body: this.waMessage });
                        this.showWa = false;
                    },
                    sendEmail() {
                        window.location.href = 'mailto:' + encodeURIComponent({{ Illuminate\Support\Js::from($contact->email) }}) + '?subject=' + encodeURIComponent(this.emailSubject) + '&body=' + encodeURIComponent(this.emailBody);
                        this.increment('email', { subject: this.emailSubject, body: this.emailBody });
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
                    <div class="rounded-md px-5 py-4 cursor-pointer group contact-show-wa-card"
                         style="background:var(--surface-2); border:2px solid rgba(37,211,102,0.25);"
                         @click="showWa = !showWa; showEmail = false">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5" style="color:#25d366;" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                <div class="text-xs font-bold uppercase tracking-widest" style="color:#25d366;">WhatsApp</div>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md" style="background:rgba(37,211,102,0.12); color:#25d366;">Click to send</span>
                        </div>
                        <div class="text-2xl font-bold" style="color:var(--text-primary);" x-text="waCount"></div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">messages sent</div>
                    </div>
                    @endif

                    {{-- Box 3: Email --}}
                    @if(auth()->user()->hasPermission('contacts.email'))
                    <div class="rounded-md px-5 py-4 cursor-pointer group contact-show-email-card"
                         style="background:var(--surface-2); border:2px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent);"
                         @click="showEmail = !showEmail; showWa = false">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                <div class="text-xs font-bold uppercase tracking-widest" style="color:var(--brand-icon, #0ea5e9);">Email</div>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9);">Click to send</span>
                        </div>
                        <div class="text-2xl font-bold" style="color:var(--text-primary);" x-text="emailCount"></div>
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">sent from CoreX</div>
                        @if(($canViewComms ?? false) && $contactComms->count())
                        <button type="button" @click.stop="activeTab = 'communications'" class="text-[11px] font-semibold mt-1 underline" style="color:var(--brand-icon, #0ea5e9);">
                            {{ $contactComms->count() }} in archive →
                        </button>
                        @endif
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
                        @if($outreachWindow['allowed'] ?? true)
                        <button type="button" @click="sendWa()"
                                class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-md text-white contact-show-btn-hover"
                                style="background:#25d366;">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            Send WhatsApp
                        </button>
                        @else
                        {{-- AT-117 §4a — outside the send-window: disabled + reason. --}}
                        <button type="button" disabled
                                class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-md text-white opacity-60 cursor-not-allowed"
                                style="background:#9ca3af;" title="{{ $outreachWindow['message'] ?? '' }}">
                            Sending closed
                        </button>
                        @endif
                        <button type="button" @click="showWa = false" class="text-sm" style="color:var(--text-muted);">Cancel</button>
                    </div>
                    @unless($outreachWindow['allowed'] ?? true)
                    <p class="text-xs mt-2" style="color:var(--ds-crimson,#dc2626);">{{ $outreachWindow['message'] ?? '' }}</p>
                    @endunless
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
                                class="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2 rounded-md text-white contact-show-btn-hover"
                                style="background:var(--brand-icon, #0ea5e9);">
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
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contact Type <span class="text-red-500">*</span></label>
                            @include('corex.contacts._type_picker', ['contactTypes' => $contactTypes, 'contact' => $contact])
                            @error('parent_type_ids')<p class="mt-1 text-[11px]" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            @include('corex.contacts._identifier-repeater', ['kind' => 'phones', 'type' => 'text', 'title' => 'Phone Numbers', 'addLabel' => 'phone', 'placeholder' => 'e.g. 082 123 4567', 'existing' => $contact->phones()->orderByDesc('is_primary')->orderBy('id')->get()])
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            @include('corex.contacts._identifier-repeater', ['kind' => 'emails', 'type' => 'email', 'title' => 'Emails (optional — but a contact needs at least one phone or email)', 'addLabel' => 'email', 'placeholder' => 'e.g. john@example.com', 'existing' => $contact->emails()->orderByDesc('is_primary')->orderBy('id')->get()])
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
                        {{-- Residential address — where the CONTACT lives. Free text,
                             set ONLY by the agent here. Distinct from the structured
                             property-address capture on the Properties & Core Matches tab. --}}
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Address <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                            <input type="text" name="address" value="{{ old('address', $contact->address) }}"
                                   placeholder="e.g. 21 Dee Road, Uvongo"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
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

                {{-- Financial Position — buyer pre-approval (spec D3) --}}
                <div x-data="{ open: {{ ($contact->preapproval_amount || $contact->preapproval_expires_at || $contact->preapproval_institution) ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open" class="flex items-center gap-2 w-full text-left mb-4">
                        <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Financial Position</h3>
                        @if($contact->hasValidPreapproval())
                            <span class="ds-badge ds-badge-success">Pre-approved</span>
                        @elseif($contact->preapproval_amount)
                            <span class="ds-badge ds-badge-warning">Expired</span>
                        @endif
                        <svg :class="open ? 'rotate-180' : ''" class="w-4 h-4 transition-transform" style="color:var(--text-muted);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="open" x-cloak>
                        <p class="text-[11px] mb-3" style="color:var(--text-muted);">Buyer's verified financial pre-approval. Used for demand intelligence — pre-approved buyers count separately in the prospecting summary.</p>
                        @if($contact->preapproval_amount)
                            <div class="text-[11px] mb-3 rounded-md p-2" style="background:var(--surface-2); color:var(--text-secondary);">
                                Currently: <strong>R {{ number_format((float) $contact->preapproval_amount, 0, '.', ',') }}</strong>
                                @if($contact->preapproval_institution) via {{ $contact->preapproval_institution }} @endif
                                @if($contact->preapproval_expires_at) , expires {{ $contact->preapproval_expires_at->format('d M Y') }} @endif
                            </div>
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pre-approval Amount (R)</label>
                                <input type="number" name="preapproval_amount" value="{{ old('preapproval_amount', $contact->preapproval_amount) }}"
                                       placeholder="e.g. 2500000" min="0" step="1000"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pre-approval Expires</label>
                                <input type="date" name="preapproval_expires_at" value="{{ old('preapproval_expires_at', $contact->preapproval_expires_at?->format('Y-m-d')) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pre-approval Institution</label>
                                <input type="text" name="preapproval_institution" value="{{ old('preapproval_institution', $contact->preapproval_institution) }}"
                                       placeholder="e.g. FNB Home Loans" maxlength="100"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Assigned Agents — the operational Primary/Co-Agent (agent_id /
                     second_agent_id). created_by stays the immutable capture audit
                     (shown as "Captured by"), never as the assigned agent. AT-118:
                     changing the assignment requires contacts.reassign_agent. --}}
                @php $canReassign = auth()->user()?->hasPermission('contacts.reassign_agent'); @endphp
                <div class="pt-2 border-t" style="border-color:var(--border);">
                    <h3 class="text-xs font-bold uppercase tracking-widest pt-4 mb-1" style="color:var(--text-muted);">Assigned Agents</h3>
                    <p class="text-[11px] mb-3" style="color:var(--text-muted);">The agent(s) assigned to this contact. Captured by {{ $contact->createdBy?->name ?? 'Unknown' }}.</p>
                    @if($canReassign)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Primary Agent</label>
                            <select name="agent_id"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— Unassigned —</option>
                                @foreach($agencyAgents as $agent)
                                    <option value="{{ $agent->id }}" @selected((int) old('agent_id', $contact->agent_id) === $agent->id)>{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Co-Agent <span class="font-normal normal-case">(optional)</span></label>
                            <select name="second_agent_id"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— None —</option>
                                @foreach($agencyAgents as $agent)
                                    <option value="{{ $agent->id }}" @selected((int) old('second_agent_id', $contact->second_agent_id) === $agent->id)>{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @error('second_agent_id')
                        <p class="text-[11px] mt-1" style="color:var(--ds-crimson);">{{ $message }}</p>
                    @enderror
                    @else
                    {{-- No Silent Locks: show the current assignment read-only + why it's locked. --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Primary Agent</label>
                            <p class="text-sm" style="color:var(--text-primary);">{{ $contact->agent?->name ?? 'Unassigned' }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Co-Agent</label>
                            <p class="text-sm" style="color:var(--text-primary);">{{ $contact->secondAgent?->name ?? 'None' }}</p>
                        </div>
                    </div>
                    <p class="text-[11px] mt-2" style="color:var(--text-muted);">Only a manager can change the agent assigned to a contact. Ask an admin or branch manager to reassign it.</p>
                    @endif
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary text-sm">Save Changes</button>
                    <a href="{{ route('corex.contacts.index') }}" class="text-sm" style="color:var(--text-muted);">Cancel</a>
                </div>
            </form>

            @include('corex.contacts.partials.client-app-access', ['contact' => $contact])
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
                $propThumb = $prop->thumbFor($prop->gallery_images_json[0] ?? ($prop->dawn_images_json[0] ?? null));
                $propSc = [
                    'active' => 'var(--ds-green)',
                    'draft' => 'var(--text-muted)',
                    'sold' => 'var(--brand-icon)',
                    'withdrawn' => 'var(--ds-amber)',
                ][$prop->status] ?? 'var(--text-muted)';
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
                        {{-- AT-243 — same derived truth, read from the other side: this contact is the
                             one who actually bought this property (buyer on its granted/registered deal). --}}
                        @if(in_array((int) $contact->id, $prop->purchaserContactIds(), true))
                            <span class="ds-badge ds-badge-success" style="margin-left:.4rem;"
                                  title="This contact bought this property — they are the buyer on its granted deal.">Purchaser</span>
                        @endif
                        <div class="text-xs mt-0.5 flex flex-wrap gap-2" style="color:var(--text-muted);">
                            <span style="color:{{ $propSc }};">{{ ucfirst($prop->status) }}</span>
                            <span>{{ $prop->formattedPrice() }}</span>
                            <span>{{ $prop->buildDisplayAddress() }}</span>
                            @if($prop->pivot->role)<span class="font-semibold" style="color:var(--brand-icon, #0ea5e9);">{{ ucfirst($prop->pivot->role) }}</span>@endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('corex.contacts.properties.unlink', [$contact, $prop]) }}"
                          onsubmit="return confirm('Unlink this property from {{ addslashes($contact->full_name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all duration-300 flex-shrink-0"
                                style="color: var(--ds-crimson); border: 1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);">Unlink</button>
                    </form>
                </div>
                @if(in_array($prop->pivot->role, ['owner', 'seller', 'landlord', 'lessor']))
                    @php
                        $sellerLink = \App\Models\PropertySellerLink::ensureExists($prop->id, $contact->id);
                        $sellerLinkUrl = url('/property/live/' . $sellerLink->token);
                    @endphp
                    <div class="flex items-center gap-2 px-4 pb-2 -mt-1 text-[10px]" style="color:var(--text-muted);">
                        <span style="color:var(--brand-icon);">Seller Live Link</span>
                        <span class="truncate max-w-[200px]" title="{{ $sellerLinkUrl }}">{{ $sellerLinkUrl }}</span>
                        <button type="button" onclick="navigator.clipboard.writeText('{{ $sellerLinkUrl }}'); this.textContent='Copied!';"
                                class="font-medium px-1.5 py-0.5 rounded-md flex-shrink-0" style="color: var(--ds-green, #059669); background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);">Copy</button>
                    </div>
                @endif
                @empty
                <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No properties linked</h3>
                    <p class="text-sm mb-4" style="color: var(--text-muted);">Use the search below to link an existing property to this contact.</p>
                </div>
                @endforelse
            </div>

            {{-- Link property by address search --}}
            <div class="rounded-md p-5" style="background: var(--surface-2); border: 1px solid var(--border);">
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
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="r.label || r.address || r.title"></div>
                                    <div class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="(r.address || '') + ' · ' + r.price + (r.agent ? ' · ' + r.agent : '')"></div>
                                </div>
                                <span class="text-xs font-semibold flex-shrink-0 px-2 py-1 rounded-md"
                                      :style="`background:${statusColor(r.status || '')}22; color:${statusColor(r.status || '')}; border:1px solid ${statusColor(r.status || '')}44;`"
                                      x-text="(r.status || '').charAt(0).toUpperCase() + (r.status || '').slice(1)"></span>
                                <span class="text-xs font-semibold flex-shrink-0" style="color:var(--brand-icon, #0ea5e9);">+ Link</span>
                            </button>
                        </form>
                    </template>
                </div>

                <div x-show="propSearched && propResults.length === 0" class="text-sm" style="color:var(--text-muted);">
                    No matching properties found.
                </div>
            </div>

            {{-- AT-60 — Capture an address to START A NEW PROPERTY. This is a
                 property-creation aid: it persists to the contact's structured
                 property-address columns and transfers onto a new Property via
                 "Use for property". It is INDEPENDENT of the contact's residential
                 address (the free-text field on the Info tab) and never writes to it. --}}
            @if(session('held_address_warning'))
                @php $heldWarn = session('held_address_warning'); @endphp
                <div class="rounded-md p-4 mb-4" role="alert"
                     style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, transparent);">
                    <div class="flex items-start gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--ds-amber, #f59e0b);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        <div class="text-sm" style="color:var(--text-primary);">
                            <strong>HFC already has this property on its books</strong> — {{ $heldWarn['label'] ?? '' }}.
                            @if(!empty($heldWarn['address'])) <span style="color:var(--text-secondary);">({{ $heldWarn['address'] }})</span>@endif
                            <div class="mt-1 text-xs" style="color:var(--text-secondary);">
                                Check the existing record before canvassing the owner —
                                @if(!empty($heldWarn['property_url']))<a href="{{ $heldWarn['property_url'] }}" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open the property record</a>@elseif(!empty($heldWarn['tracked_url']))<a href="{{ $heldWarn['tracked_url'] }}" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open property intel</a>@endif.
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="rounded-md p-5" style="background: var(--surface-2); border: 1px solid var(--border);"
                 x-data="contactAddress({{ Js::from([
                    'unitNumber'       => old('unit_number',        $contact->unit_number ?? ''),
                    'floorNumber'      => old('floor_number',       $contact->floor_number ?? ''),
                    'unitSectionBlock' => old('unit_section_block', $contact->unit_section_block ?? ''),
                    'complexName'      => old('complex_name',       $contact->complex_name ?? ''),
                    'streetNumber'     => old('street_number',      $contact->street_number ?? ''),
                    'streetName'       => old('street_name',        $contact->street_name ?? ''),
                    'suburb'           => old('suburb',             $contact->suburb ?? ''),
                    'city'             => old('city',               $contact->city ?? ''),
                    'province'         => old('province',           $contact->province ?? ''),
                 ]) }})">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-1" style="color:var(--text-muted);">Start a Property from an Address</h3>
                <p class="text-xs mb-4" style="color:var(--text-muted);">Capture an address here to create a new property pre-filled with it. This does <strong>not</strong> change the contact's residential address.</p>

                <form method="POST" action="{{ route('corex.contacts.property-address.update', $contact) }}">
                    @csrf @method('PUT')

                    {{-- Read-only composed summary — a real, clearly-editable control (No Invisible Edits, STANDARDS.md) --}}
                    <button type="button" @click="openAddrModal = true"
                            class="w-full flex items-center justify-between gap-3 rounded-md px-3 py-2 text-left transition-all duration-300"
                            style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <span class="text-sm truncate" x-text="hasAddress ? summary : 'Click to set a property address'"
                              :style="hasAddress ? '' : 'color:var(--text-muted);'"></span>
                        <span class="inline-flex items-center gap-1 flex-shrink-0 text-[11px] font-semibold" style="color:var(--brand-icon, #2563eb);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            <span x-text="hasAddress ? 'Edit' : 'Set'"></span>
                        </span>
                    </button>

                    {{-- Part 3 — live "already on our books" warning. Fires as the agent
                         types the street/suburb; warns BEFORE they save & prospect so they
                         don't canvass an owner HFC already represents. Read-only check —
                         never mints a property. Honours the agency warn toggle server-side. --}}
                    <div x-show="held" x-cloak class="mt-3 rounded-md p-3"
                         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, transparent);">
                        <div class="flex items-start gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--ds-amber, #f59e0b);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                            <div class="text-xs leading-relaxed" style="color:var(--text-primary);">
                                <strong>HFC already has this property on its books</strong> — <span x-text="held && held.label"></span>.
                                <template x-if="held && held.address"><span> (<span x-text="held.address"></span>)</span></template>
                                <div class="mt-1" style="color:var(--text-secondary);">
                                    Check the existing record before canvassing the owner —
                                    <template x-if="held && held.property_url"><a :href="held.property_url" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open the property record</a></template>
                                    <template x-if="held && !held.property_url && held.tracked_url"><a :href="held.tracked_url" target="_blank" rel="noopener" class="font-semibold" style="color:var(--brand-icon, #2563eb);">open property intel</a></template>.
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Hidden inputs holding the parent-managed components so they submit even while the modal is closed. --}}
                    <input type="hidden" name="unit_number"        :value="unitNumber">
                    <input type="hidden" name="floor_number"       :value="floorNumber">
                    <input type="hidden" name="unit_section_block" :value="unitSectionBlock">
                    <input type="hidden" name="complex_name"       :value="complexName">
                    <input type="hidden" name="street_number"      :value="streetNumber">
                    <input type="hidden" name="street_name"        :value="streetName">

                    <div class="flex items-center gap-2 mt-3 flex-wrap">
                        <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-icon, #2563eb);">Save address</button>
                        @if($contact->hasStructuredAddress())
                            <a href="{{ route('corex.properties.create', ['contact_id' => $contact->id]) }}"
                               target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-md transition-all duration-300"
                               style="background:color-mix(in srgb, var(--brand-icon, #2563eb) 12%, transparent); color:var(--brand-icon, #2563eb);"
                               title="Create a property record pre-filled with this address and link this contact to it (opens in a new tab)">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                Use for property
                            </a>
                            {{-- AT-61 follow-up — REMOVE the captured property-address (completes
                                 CRUD). Submits the sibling DELETE form below via the HTML5 `form=`
                                 attribute (forms cannot legally nest). Only shown when an address is
                                 actually present. Clearing turns the address-only outreach bypass OFF
                                 and does NOT touch the residential address or any linked property. --}}
                            <button type="submit"
                                    form="clear-property-address-{{ $contact->id }}"
                                    onclick="return confirm('Remove this captured property address?\n\nThis clears the address from {{ addslashes($contact->first_name ?: 'this contact') }} and turns OFF the address-only pitch. The contact\'s residential address and any property you already created from it are NOT affected.');"
                                    class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-md transition-all duration-300"
                                    style="background:color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color:var(--ds-crimson, #dc2626);"
                                    title="Remove the captured property address from this contact. Does not affect the residential address or any property already created from it.">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Remove address
                            </button>
                        @endif
                    </div>

                    {{-- ===== PROPERTY-ADDRESS MODAL ===== --}}
                    <div x-show="openAddrModal" x-cloak
                         class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                         @keydown.escape.window="openAddrModal = false">
                        <div class="absolute inset-0 bg-black/60" @click="openAddrModal = false"></div>
                        <div class="relative w-full max-w-[46rem] max-h-[85vh] overflow-y-auto rounded-lg shadow-2xl"
                             style="background:var(--surface); border:1px solid var(--border);" @click.stop>

                            <div class="sticky top-0 z-10 flex items-center justify-between px-5 py-3 rounded-t-lg"
                                 style="background:var(--brand-default, #0b2a4a); color:#fff;">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>
                                    <span class="text-sm font-bold">Property Address</span>
                                </div>
                                <button type="button" @click="openAddrModal = false" class="p-1 rounded hover:bg-white/10">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <div class="p-5 space-y-5">
                                {{-- Complex or Estate --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default, #0b2a4a); color:#fff;">Complex or Estate</div>
                                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Unit Number</label>
                                                <input type="text" x-model="unitNumber" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Floor Number</label>
                                                <input type="text" x-model="floorNumber" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Unit, Section or Block</label>
                                            <input type="text" x-model="unitSectionBlock" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Name of Complex or Estate</label>
                                            <input type="text" x-model="complexName" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                    </div>
                                </div>

                                {{-- Street --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default, #0b2a4a); color:#fff;">Street</div>
                                    <div class="p-4 rounded-b-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Number</label>
                                            <input type="text" x-model="streetNumber" placeholder="e.g. 21" autocomplete="off" class="w-40 rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Street Name</label>
                                            <input type="text" x-model="streetName" placeholder="e.g. Dee Road" autocomplete="off" class="w-full rounded-md px-3 py-1.5 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                    </div>
                                </div>

                                {{-- Province / City / Suburb — Property24-backed typeahead (shared partial).
                                     fieldPrefix 'contact_addr' so it never cross-fires a property picker. --}}
                                <div>
                                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider text-center py-1.5 rounded-t-md" style="background:var(--brand-default, #0b2a4a); color:#fff;">Province / City / Suburb</div>
                                    <div class="p-4 rounded-b-md" style="background:var(--surface-2); border:1px solid var(--border); border-top:0;">
                                        @include('corex._partials.p24-location-picker', [
                                            'fieldPrefix'         => 'contact_addr',
                                            'initialProvinceId'   => old('contact_addr_province_id', $contact->p24_province_id ?? 0),
                                            'initialCityId'       => old('contact_addr_city_id',     $contact->p24_city_id ?? 0),
                                            'initialSuburbId'     => old('contact_addr_suburb_id',   $contact->p24_suburb_id ?? 0),
                                            'initialProvinceName' => old('province', $contact->province ?? ''),
                                            'initialCityName'     => old('city',     $contact->city ?? ''),
                                            'initialSuburbName'   => old('suburb',   $contact->suburb ?? ''),
                                            'denormaliseNames'    => true,
                                        ])
                                        <p class="text-[11px] mt-2" style="color:var(--text-muted);">Suburb is optional, but if you type one it must be picked from the Property24 list so it links cleanly to a property later.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="sticky bottom-0 px-5 py-3 rounded-b-lg flex items-center justify-between" style="background:var(--surface); border-top:1px solid var(--border);">
                                <button type="button" @click="clearAddress()" x-show="hasAddress"
                                        class="px-3 py-2 rounded-md text-xs font-semibold transition-all duration-300"
                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--ds-crimson, #dc2626);">Clear address</button>
                                <span x-show="!hasAddress"></span>
                                <button type="button" @click="openAddrModal = false" class="px-4 py-2 rounded-md text-xs font-semibold text-white" style="background:var(--ds-green, #16a34a);">Done</button>
                            </div>
                        </div>
                    </div>
                </form>

                {{-- AT-61 follow-up — sibling DELETE form for "Remove address" (kept
                     OUTSIDE the update form above so the markup never nests forms).
                     Triggered by the Remove button via its `form=` attribute. --}}
                @if($contact->hasStructuredAddress())
                    <form id="clear-property-address-{{ $contact->id }}" method="POST"
                          action="{{ route('corex.contacts.property-address.clear', $contact) }}" class="hidden">
                        @csrf @method('DELETE')
                    </form>
                @endif
            </div>

        </div>

        {{-- ════════════════════════════
             NOTES TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'notes'" x-cloak class="p-6 space-y-5" id="tab-notes">

            {{-- ════════════════════════════ TESTIMONIALS ════════════════════════════ --}}
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Testimonials</h3>
                    <span class="text-xs" style="color:var(--text-muted);">Captured here · publish to the website in <span class="font-semibold">Company Settings → Website</span></span>
                </div>

                {{-- Add testimonial --}}
                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);" x-data="{ rating: 0 }">
                    <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Testimonial</div>
                    <form method="POST" action="{{ route('corex.contacts.testimonials.store', $contact) }}" class="space-y-3">
                        @csrf
                        <textarea name="body" rows="3" required placeholder="What did the client say?"
                                  class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Public display name</label>
                                <input type="text" name="display_name" maxlength="150"
                                       value="{{ trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) }}"
                                       placeholder="Name shown on the website"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Agent it's about</label>
                                <select name="agent_id"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @foreach(($agencyAgents ?? collect()) as $ag)
                                        <option value="{{ $ag->id }}" {{ (int) $ag->id === (int) auth()->id() ? 'selected' : '' }}>{{ $ag->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Rating (optional)</label>
                                <input type="hidden" name="rating" :value="rating || ''">
                                <div class="flex items-center gap-1">
                                    <template x-for="star in 5" :key="star">
                                        <button type="button" @click="rating = (rating === star ? 0 : star)"
                                                class="text-xl leading-none"
                                                :style="star <= rating ? 'color:var(--ds-amber, #f5b301);' : 'color:var(--text-muted); opacity:.5;'">★</button>
                                    </template>
                                    <button type="button" x-show="rating > 0" @click="rating = 0" class="ml-2 text-xs" style="color:var(--text-muted);">clear</button>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="corex-btn-primary text-sm">Add Testimonial</button>
                        </div>
                    </form>
                </div>

                {{-- Testimonials list --}}
                @forelse($contact->testimonials as $testimonial)
                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);" x-data="{ editing: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-semibold" style="color:var(--text-primary);">{{ $testimonial->display_name }}</span>
                                @if($testimonial->rating)
                                    <span class="text-sm" style="color:var(--ds-amber, #f5b301);">{{ str_repeat('★', (int) $testimonial->rating) }}<span style="color:var(--text-muted); opacity:.4;">{{ str_repeat('★', 5 - (int) $testimonial->rating) }}</span></span>
                                @endif
                            </div>
                            <div class="text-xs" style="color:var(--text-muted);">
                                {{ $testimonial->user?->name ?? 'Unknown' }} · {{ $testimonial->created_at->format('d M Y') }}
                                @if($testimonial->agent)
                                    · <span style="color:var(--text-secondary);">About {{ $testimonial->agent->name }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            @if($testimonial->published)
                                <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color:var(--brand-icon, #0ea5e9);">On website</span>
                            @else
                                <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded" style="background:var(--surface); color:var(--text-muted); border:1px solid var(--border);">Not published</span>
                            @endif
                            <button type="button" @click="editing = !editing" class="text-xs font-semibold" style="color:var(--brand-icon, #0ea5e9);">Edit</button>
                            <form method="POST" action="{{ route('corex.contacts.testimonials.destroy', [$contact, $testimonial]) }}"
                                  onsubmit="return confirm('Delete this testimonial?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                            </form>
                        </div>
                    </div>

                    {{-- Read view --}}
                    <div class="mt-3 text-sm whitespace-pre-line" style="color:var(--text-primary);" x-show="!editing">{{ $testimonial->body }}</div>

                    {{-- Edit view --}}
                    <form x-show="editing" x-cloak method="POST" action="{{ route('corex.contacts.testimonials.update', [$contact, $testimonial]) }}"
                          class="mt-3 space-y-3" x-data="{ rating: {{ (int) $testimonial->rating }} }">
                        @csrf @method('PUT')
                        <textarea name="body" rows="3" required class="w-full rounded-md px-3 py-2 text-sm resize-none"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ $testimonial->body }}</textarea>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Public display name</label>
                                <input type="text" name="display_name" maxlength="150" value="{{ $testimonial->display_name }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Agent it's about</label>
                                <select name="agent_id"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @foreach(($agencyAgents ?? collect()) as $ag)
                                        <option value="{{ $ag->id }}" {{ (int) $ag->id === (int) $testimonial->agent_id ? 'selected' : '' }}>{{ $ag->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Rating (optional)</label>
                                <input type="hidden" name="rating" :value="rating || ''">
                                <div class="flex items-center gap-1">
                                    <template x-for="star in 5" :key="star">
                                        <button type="button" @click="rating = (rating === star ? 0 : star)"
                                                class="text-xl leading-none"
                                                :style="star <= rating ? 'color:var(--ds-amber, #f5b301);' : 'color:var(--text-muted); opacity:.5;'">★</button>
                                    </template>
                                    <button type="button" x-show="rating > 0" @click="rating = 0" class="ml-2 text-xs" style="color:var(--text-muted);">clear</button>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="editing = false" class="text-sm px-3 py-1.5 rounded-md" style="border:1px solid var(--border); color:var(--text-secondary);">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-sm">Save</button>
                        </div>
                    </form>
                </div>
                @empty
                <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface); border: 1px dashed var(--border);">
                    <p class="text-sm" style="color: var(--text-muted);">No testimonials captured yet. Add one above when a client gives you positive feedback.</p>
                </div>
                @endforelse
            </div>

            <div style="border-top:1px solid var(--border);"></div>

            {{-- Add note --}}
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
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
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
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
                        <button type="submit" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-crimson);">Delete</button>
                    </form>
                </div>
                <div class="mt-3 text-sm whitespace-pre-line" style="color:var(--text-primary);">{{ $note->body }}</div>
            </div>
            @empty
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No notes yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">Use the form above to record your first note for this contact.</p>
            </div>
            @endforelse
        </div>

        {{-- ════════════════════════════
             DRIVE TAB
             ════════════════════════════ --}}
        <div x-show="activeTab === 'drive'" x-cloak class="p-6 space-y-5" id="tab-drive"
             x-data="{ dragging: false }">

            {{-- Upload area --}}
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
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
                        <select name="document_type_id" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            <option value="">Document Type (optional)</option>
                            @foreach($documentTypes as $dt)
                            <option value="{{ $dt->id }}">{{ $dt->label }}</option>
                            @endforeach
                        </select>
                        <select name="property_id" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
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
                <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
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
                <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <div class="px-4 py-2.5" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <span class="text-xs font-semibold" style="color:var(--text-muted);">Not Property-Linked</span>
                    </div>
                    @foreach($driveUnlinkedDocs as $doc)
                    @include('corex.contacts._drive-row', ['doc' => $doc, 'contact' => $contact, 'documentTypes' => $documentTypes])
                    @endforeach
                </div>
                @endif
            @else
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No files uploaded</h3>
                <p class="text-sm" style="color: var(--text-muted);">Drop a file in the upload area above to attach it to this contact.</p>
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
                $ficaSubmissions = $contact->ficaSubmissions()
                    ->whereIn('status', ['approved', 'submitted', 'under_review'])
                    ->with('verifiedBy')
                    ->get();
                $approvedFicaSubs = $ficaSubmissions->where('status', 'approved');
                $allSignedDocs = $contact->signedDocuments()
                    ->wherePivot('is_signed', true)
                    ->orderByPivot('signed_at', 'desc')
                    ->get();
            @endphp

            <div class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface-2);">
                <div class="flex items-center gap-4">
                    @if($ficaStatus === 'complete')
                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg"
                             style="background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green);">
                            &#10003;
                        </div>
                        <div>
                            <h3 class="text-base font-bold" style="color:var(--text-primary);">FICA Complete</h3>
                            <p class="text-sm" style="color:var(--text-secondary);">
                                @if($approvedFicaSubs->isNotEmpty())
                                    {{ $approvedFicaSubs->count() }} approved FICA submission{{ $approvedFicaSubs->count() !== 1 ? 's' : '' }}.
                                    Latest approved {{ $approvedFicaSubs->first()->verified_at?->format('d M Y') }}.
                                @elseif($ficaDocs->isNotEmpty())
                                    {{ $ficaDocs->count() }} FICA document{{ $ficaDocs->count() !== 1 ? 's' : '' }} on file.
                                    @if($ficaDocs->first()?->pivot?->signed_at)
                                        Latest signed {{ \Carbon\Carbon::parse($ficaDocs->first()->pivot->signed_at)->format('d M Y') }}.
                                    @endif
                                @endif
                            </p>
                        </div>
                    @elseif($ficaStatus === 'expiring')
                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg"
                             style="background: color-mix(in srgb, var(--ds-amber) 15%, transparent); color: var(--ds-amber);">
                            &#9888;
                        </div>
                        <div>
                            <h3 class="text-base font-bold" style="color:var(--text-primary);">FICA Expiring Soon</h3>
                            <p class="text-sm" style="color:var(--text-secondary);">FICA documents are nearing expiry. Consider requesting updated documentation.</p>
                        </div>
                    @else
                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg"
                             style="background: color-mix(in srgb, var(--ds-crimson) 15%, transparent); color: var(--ds-crimson);">
                            &#10007;
                        </div>
                        <div>
                            <h3 class="text-base font-bold" style="color:var(--text-primary);">No FICA on File</h3>
                            <p class="text-sm" style="color:var(--text-secondary);">This contact has no signed FICA documents. FICA compliance is required before transacting.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- FICA submissions (new system) --}}
            @if($ficaSubmissions->isNotEmpty())
            <div>
                <h4 class="text-sm font-bold uppercase tracking-wide mb-3" style="color:var(--text-muted);">FICA Submissions</h4>
                <div class="space-y-2">
                    @foreach($ficaSubmissions as $sub)
                    @php
                        $subBadge = match($sub->status) {
                            'approved' => 'ds-badge-success',
                            'submitted' => 'ds-badge-info',
                            'under_review' => 'ds-badge-warning',
                            default => 'ds-badge-default',
                        };
                    @endphp
                    <div class="flex items-center justify-between p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                            </svg>
                            <div>
                                <p class="text-sm font-semibold" style="color:var(--text-primary);">
                                    FICA Form — {{ ucfirst($sub->entity_type) }}
                                    <span class="ds-badge {{ $subBadge }} ml-1">{{ $sub->status_label }}</span>
                                </p>
                                <p class="text-xs" style="color:var(--text-muted);">
                                    Submitted {{ $sub->signed_at?->format('d M Y') }}
                                    @if($sub->status === 'approved' && $sub->verifiedBy)
                                        &middot; Approved by {{ $sub->verifiedBy->name }} on {{ $sub->verified_at?->format('d M Y') }}
                                        @if($sub->risk_rating)
                                            &middot; Risk: {{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$sub->risk_rating] ?? '' }}
                                        @endif
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if($sub->status === 'approved')
                            <a href="{{ route('compliance.fica.pdf', $sub) }}" target="_blank"
                               class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
                               style="color:var(--text-muted); border:1px solid var(--border);" title="Download PDF">
                                PDF
                            </a>
                            @endif
                            <a href="{{ route('compliance.fica.show', $sub) }}"
                               class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
                               style="color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);">
                                View
                            </a>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Legacy FICA documents (e-sign system) --}}
            @if($ficaDocs->isNotEmpty())
            <div>
                <h4 class="text-sm font-bold uppercase tracking-wide mb-3" style="color:var(--text-muted);">FICA Documents (E-Sign)</h4>
                <div class="space-y-2">
                    @foreach($ficaDocs as $doc)
                    <div class="flex items-center justify-between p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
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
                    <div class="flex items-center justify-between p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
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
             CONSENT & COMPLIANCE TAB (M3.4)
             ════════════════════════════ --}}
        <div x-show="activeTab === 'consent'" x-cloak class="p-6 space-y-4" id="tab-consent">
            @php
                $consentTypes  = \App\Models\Contact::CONSENT_TYPES;
                $consentStates = collect($contact->consentStates())->keyBy('type');
            @endphp

            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Consent Records</h3>
                <span class="text-xs" style="color: var(--text-muted);">POPIA + CPA compliant</span>
            </div>

            <p class="text-[11px]" style="color: var(--text-muted);">
                <span class="font-medium" style="color: var(--ds-crimson);">No</span>
                means the client has refused this — do not contact them this way.
            </p>

            <div class="space-y-2">
                @foreach($consentTypes as $typeKey => $typeLabel)
                    @php
                        $state    = $consentStates->get($typeKey);
                        $decision = $state['decision'] ?? null;        // given | declined | null
                        $recordedAt = $state['recorded_at'] ?? null;
                        $isDeclined = $decision === 'declined';
                    @endphp
                    <div class="flex items-center justify-between px-3 py-2 rounded-md"
                         style="background: var(--surface-2); border: 1px solid {{ $isDeclined ? 'var(--ds-crimson)' : 'var(--border)' }};">
                        <div>
                            <span class="text-xs font-medium" style="color: var(--text-primary);">{{ $typeLabel }}</span>
                            @if($decision === 'given')
                                <span class="ds-badge ds-badge-success ml-2">Given</span>
                                @if($recordedAt)
                                    <span class="ml-1 text-[10px]" style="color: var(--text-muted);">since {{ $recordedAt->format('d M Y') }}</span>
                                @endif
                            @elseif($isDeclined)
                                <span class="ds-badge ds-badge-danger ml-2">No</span>
                                @if($recordedAt)
                                    <span class="ml-1 text-[10px]" style="color: var(--text-muted);">since {{ $recordedAt->format('d M Y') }}</span>
                                @endif
                            @else
                                <span class="ds-badge ds-badge-default ml-2">Not recorded</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-1">
                            {{-- Given — shown unless the client has declined. Once "Given"
                                 is chosen the "No" option is hidden; Clear reveals both again. --}}
                            @if(!$isDeclined)
                                <form method="POST" action="{{ route('corex.contacts.consent.record', $contact) }}">
                                    @csrf
                                    <input type="hidden" name="consent_type" value="{{ $typeKey }}">
                                    <input type="hidden" name="decision" value="given">
                                    <input type="hidden" name="method" value="electronic">
                                    <button type="submit"
                                            class="text-[10px] px-2 py-1 rounded-md {{ $decision === 'given' ? 'corex-btn-primary' : 'corex-btn-outline' }}">
                                        Given
                                    </button>
                                </form>
                            @endif
                            {{-- No — shown unless the client has given. Hidden once "Given"
                                 is chosen (per the toggle rule); Clear reveals it again. --}}
                            @if($decision !== 'given')
                                <form method="POST" action="{{ route('corex.contacts.consent.record', $contact) }}">
                                    @csrf
                                    <input type="hidden" name="consent_type" value="{{ $typeKey }}">
                                    <input type="hidden" name="decision" value="declined">
                                    <input type="hidden" name="method" value="electronic">
                                    <button type="submit"
                                            class="text-[10px] px-2 py-1 rounded-md"
                                            style="{{ $isDeclined
                                                ? 'background: var(--ds-crimson); color: #fff; border: 1px solid var(--ds-crimson);'
                                                : 'background: transparent; color: var(--ds-crimson); border: 1px solid var(--ds-crimson);' }}">
                                        No
                                    </button>
                                </form>
                            @endif
                            @if($decision !== null)
                                <form method="POST" action="{{ route('corex.contacts.consent.revoke', $contact) }}">
                                    @csrf
                                    <input type="hidden" name="consent_type" value="{{ $typeKey }}">
                                    <input type="hidden" name="reason" value="Cleared by agent">
                                    <button type="submit" class="text-[10px] px-2 py-1" style="color: var(--text-muted);" title="Clear — back to not recorded">Clear</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ════════════════════════════
             CORE MATCHES (merged into Properties tab)
             ════════════════════════════ --}}
        @if(\App\Models\PerformanceSetting::get('matches_enabled', 1) && auth()->user()->hasPermission('access_core_matches'))
        <div x-show="activeTab === 'properties'" x-cloak class="p-6 pt-0 space-y-6" id="tab-matches">

            {{-- Core Matches section header --}}
            <div class="pt-2 border-t" style="border-color:var(--border);">
                <h3 class="text-sm font-bold uppercase tracking-wide pt-4" style="color:var(--text-primary);">Core Matches</h3>
                <p class="text-xs mt-1" style="color:var(--text-muted);">Buyer/tenant requirements matched against tracked property intelligence.</p>
            </div>

            {{-- Add new match form --}}
            <div class="rounded-md p-5 space-y-5" style="background:var(--surface-2); border:1px solid var(--border);">
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Add New Match Criteria</h3>

                @include('corex.contacts._match-form', ['contact' => $contact, 'match' => null])
            </div>

            {{-- Existing matches --}}
            @if($contact->matches->count())
            <div class="space-y-3">
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Saved Matches ({{ $contact->matches->count() }})</h3>
                @foreach($contact->matches as $match)
                <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0 space-y-3">

                            {{-- Header row: type badge + price + primary flag --}}
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="ds-badge {{ $match->listing_type === 'rental' ? 'ds-badge-info' : 'ds-badge-default' }}"
                                      style="{{ $match->listing_type === 'rental' ? '' : 'background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); border: 1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);' }}">
                                    {{ $match->listingTypeLabel() }}
                                </span>
                                @if($match->is_primary)
                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md whitespace-nowrap"
                                      style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent); color:var(--ds-amber, #f59e0b); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent);"
                                      title="This is the contact's primary wishlist — used for demand intelligence">
                                    ⭐ Primary
                                </span>
                                @endif
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
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-1.5 min-w-0 break-words">
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
                                    @if(!$match->is_primary)
                                    <form method="POST" action="{{ route('corex.contacts.matches.update', [$contact, $match]) }}" class="inline">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="is_primary" value="1">
                                        <button type="submit"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                                                style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); color:var(--ds-amber, #f59e0b); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent);"
                                                title="Mark this wishlist as the contact's primary">
                                            ⭐ Make Primary
                                        </button>
                                    </form>
                                    @endif
                                    <a href="{{ route('corex.contacts.matches.results', [$contact, $match]) }}"
                                       class="corex-btn-outline text-xs no-underline">
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
                                    style="color: var(--ds-crimson);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No match criteria saved</h3>
                <p class="text-sm" style="color: var(--text-muted);">Use the form above to add what this contact is looking for.</p>
            </div>
            @endif

        </div>{{-- /matches (under Properties) --}}
        @endif

        {{-- ══════════════════════════════════════════
             VIEWINGS & FEEDBACK TAB
             ════════════════════════════════════════ --}}
        <div x-show="activeTab === 'viewings'" x-cloak class="p-6 space-y-6" id="tab-viewings">

            {{-- Viewing Packs (AT-110 discoverability) — find/open/edit packs built for this contact. --}}
            @include('command-center.viewing-packs._packs-section', ['contact' => $contact])

            {{-- Buyer perspective — ALL linked appointments (property optional) + provide-feedback-from-here (AT-114). --}}
            @include('command-center.calendar._linked-events', ['contact' => $contact])

            {{-- Seller perspective --}}
            @if(($sellerUpcoming ?? collect())->isNotEmpty() || ($sellerPast ?? collect())->isNotEmpty())
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Seller — Feedback on Your Listings</h3>
                    @foreach($sellerPast ?? [] as $sv)
                        <div class="rounded-md p-4 mb-2" style="background:var(--surface); border:1px solid var(--border);">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('corex.properties.show', $sv['property_id']) }}" target="_blank"
                                       class="text-sm font-semibold no-underline hover:underline" style="color:var(--text-primary);">{{ $sv['address'] }}</a>
                                    <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">Viewed by: {{ $sv['buyer_label'] }}</div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <div class="text-[10px]" style="color:var(--text-muted);">{{ \Carbon\Carbon::parse($sv['event_date'])->format('D, j M Y') }}</div>
                                    <div class="text-[10px]" style="color:var(--text-muted);">Agent: {{ $sv['agent_name'] }}</div>
                                </div>
                            </div>
                            @if($sv['feedback'] ?? null)
                                <div class="mt-2 rounded px-3 py-2" style="background:var(--surface-2); border:1px solid var(--border);">
                                    @if($sv['feedback']['outcome_label'] ?? null)
                                        <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded-md" style="background:color-mix(in srgb, var(--ds-green, #059669) 15%, transparent); color:var(--ds-green, #059669);">{{ $sv['feedback']['outcome_label'] }}</span>
                                    @endif
                                    @if($sv['feedback']['seller_notes'] ?? null)
                                        <p class="text-xs mt-1" style="color:var(--text-secondary);">{{ $sv['feedback']['seller_notes'] }}</p>
                                    @endif
                                    <div class="text-[10px] mt-1" style="color:var(--text-muted);">Captured {{ \Carbon\Carbon::parse($sv['feedback']['captured_at'])->diffForHumans() }}</div>
                                </div>
                            @else
                                <span class="ds-badge ds-badge-default mt-1">No feedback</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if(($buyerViewings ?? collect())->isEmpty() && ($sellerViewings ?? collect())->isEmpty())
                <div class="py-12 text-center">
                    <p class="text-sm" style="color:var(--text-muted);">No viewings or feedback recorded for this contact.</p>
                </div>
            @endif

        </div>{{-- /viewings tab --}}

        {{-- ════════════════════════════
             OUTREACH TAB (Prompt 07)
             ════════════════════════════ --}}
        {{-- ════════════════════════════
             COMMUNICATIONS TAB (AT-43) — linked archive comms (email + WhatsApp)
             ════════════════════════════ --}}
        {{-- AT-132 Wave 1 — per-thread list. Safe metadata for every thread (channel,
             date, message count, owning agent, attachment flag, subject unless the
             owner hid it); BODIES stay gated per row. Visible threads open to the
             archive; gated threads show a per-thread "Request access". Never renders
             body / body_preview / message content. DESIGN SYSTEM COMPLIANCE:
             UI_DESIGN_SYSTEM.md (tokens via var(), no emojis, sharp corners). --}}
        @if(($canViewComms ?? false) || ($canRequestComms ?? false))
        <div x-show="activeTab === 'communications'" x-cloak class="p-6 space-y-4" id="tab-communications">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Communications</h3>
                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Email &amp; WhatsApp threads linked to this contact. Message contents are private to the owning agent — request access to a thread to read it.</p>
                </div>
                @if($canViewComms ?? false)
                <a href="{{ route('compliance.comm-archive.index', ['contact' => $contact->id]) }}" class="text-xs font-semibold underline" style="color:var(--brand-icon, #0ea5e9);">Open full archive</a>
                @endif
            </div>

            {{-- AT-136 — per-agent WhatsApp capture toggle for THIS contact (controls
                 whether MY WhatsApp chats with them are archived; SEPARATE from the
                 contact's marketing opt-out). --}}
            <div class="rounded px-4 py-3 flex items-center justify-between gap-3"
                 style="background:var(--surface-2); border:1px solid var(--border);"
                 x-data="{ status: @js($myCaptureStatus), busy:false,
                    async set(s){ if(s===this.status) return; let reason='';
                        if(s==='opted_out'){ reason = prompt('Optional: why not capture your WhatsApp with this contact? (recorded for compliance)') || ''; }
                        this.busy=true;
                        try{ const r=await fetch('{{ route('communications.capture.decide') }}',{method:'POST',
                            headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
                            body:JSON.stringify({contact_id:{{ $contact->id }},status:s,reason})});
                            if(r.status===419){ alert('Your session refreshed — reloading the page; please choose again.'); window.location.reload(); return; }
                            const d=await r.json(); if(r.ok&&d.ok){ this.status=d.status; } else { alert(d.error||'Could not save.'); }
                        }catch(e){ alert('Network error — try again.'); } finally{ this.busy=false; } } }">
                <div class="min-w-0">
                    <div class="text-xs font-semibold" style="color:var(--text-primary);">Capture my WhatsApp chats with this contact</div>
                    <p class="text-[11px] mt-0.5" style="color:var(--text-muted);">
                        <span x-show="status==='opted_in'" style="color:var(--ds-green,#059669);">On — bodies captured for compliance.</span>
                        <span x-show="status==='opted_out'">Off — only that a message occurred is kept; bodies are not captured.</span>
                        <span x-show="status==='pending'" style="color:var(--ds-amber,#f59e0b);">Awaiting your decision — bodies not captured until you choose.</span>
                        <span x-show="!status" style="color:var(--text-muted);">No WhatsApp match with this contact yet — choose to pre-set your preference.</span>
                    </p>
                </div>
                <div class="inline-flex gap-2 shrink-0">
                    <button type="button" @click="set('opted_in')" :disabled="busy" class="text-[11px] font-semibold rounded px-3 py-1.5"
                            :style="status==='opted_in' ? 'background:var(--ds-green,#059669);color:#fff;border:1px solid var(--border);' : 'background:var(--surface);color:var(--text-secondary);border:1px solid var(--border);'">Capture</button>
                    <button type="button" @click="set('opted_out')" :disabled="busy" class="text-[11px] font-semibold rounded px-3 py-1.5"
                            :style="status==='opted_out' ? 'background:var(--text-muted);color:#fff;border:1px solid var(--border);' : 'background:var(--surface);color:var(--text-secondary);border:1px solid var(--border);'">Don't capture</button>
                </div>
            </div>

            @forelse(($contactThreads ?? collect()) as $thread)
                @php
                    $isWa     = $thread->channel === \App\Models\Communications\Communication::CHANNEL_WHATSAPP;
                    $accent   = $isWa ? '#25d366' : 'var(--brand-icon, #0ea5e9)';
                    // AT-137 — pass origin context so the thread/message Back returns
                    // HERE (the contact), not the compliance archive.
                    $openHref = $thread->is_visible
                        ? ($thread->thread_key !== null
                            ? route('compliance.comm-archive.thread', ['threadKey' => $thread->thread_key, 'from' => 'contact', 'contact' => $contact->id])
                            : route('compliance.comm-archive.show', ['communication' => $thread->communication_id, 'from' => 'contact', 'contact' => $contact->id]))
                        : null;
                @endphp

                @if($thread->is_visible)
                {{-- VISIBLE thread — opens to the body; owner may toggle hide-subject --}}
                <div class="rounded px-4 py-3"
                     style="background:var(--surface-2); border:1px solid var(--border); border-left:3px solid {{ $accent }};">
                    <a href="{{ $openHref }}" class="block transition-all hover:opacity-90">
                        @include('corex.contacts._comm-thread-meta', ['thread' => $thread, 'isWa' => $isWa, 'accent' => $accent])
                    </a>
                    <div class="flex items-center gap-3 mt-1.5">
                        @if($thread->can_manage_subject)
                        <div x-data="{
                                hidden: {{ $thread->subject_hidden_setting ? 'true' : 'false' }},
                                busy: false,
                                async toggle(){
                                    this.busy = true;
                                    try {
                                        const r = await fetch('{{ route('api.v1.comms-access.thread-settings') }}', {
                                            method: 'POST',
                                            headers: { 'Content-Type':'application/json', 'Accept':'application/json',
                                                       'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                            body: JSON.stringify({ contact_id: {{ $contact->id }}, thread_key: {{ json_encode($thread->thread_key) }}, hide_subject: !this.hidden })
                                        });
                                        const d = await r.json();
                                        if (r.ok && d.ok) { this.hidden = d.hide_subject; }
                                        else { alert(d.error || 'Could not update.'); }
                                    } catch(e) { alert('Network error — please try again.'); }
                                    finally { this.busy = false; }
                                }
                             }">
                            <button type="button" @click="toggle()" :disabled="busy"
                                    class="text-[11px] font-semibold rounded px-2.5 py-1"
                                    style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">
                                <span x-show="!hidden">Hide subject from others</span>
                                <span x-show="hidden" x-cloak>Subject hidden from others — show</span>
                            </button>
                        </div>
                        @endif
                        @if($thread->viewer_grant_id)
                        {{-- AT-132 — viewer holds a per-thread grant → show its mode + a Revoke control (No Silent Locks). --}}
                        <div x-data="{
                                revoked: false, busy: false,
                                async revoke(){
                                    if (!confirm('Revoke your access to this thread?')) return;
                                    this.busy = true;
                                    try {
                                        const r = await fetch('{{ route('api.v1.comms-access.revoke', ['commsAccessRequest' => $thread->viewer_grant_id]) }}', {
                                            method: 'POST',
                                            headers: { 'Content-Type':'application/json', 'Accept':'application/json',
                                                       'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                            body: JSON.stringify({ reason: 'self_revoke' })
                                        });
                                        const d = await r.json();
                                        if (r.ok && d.ok) { this.revoked = true; setTimeout(() => window.location.reload(), 600); }
                                        else { alert(d.error || 'Could not revoke.'); }
                                    } catch(e) { alert('Network error — please try again.'); }
                                    finally { this.busy = false; }
                                }
                             }" class="inline-flex items-center gap-2">
                            <span class="text-[11px] font-semibold rounded px-2 py-0.5"
                                  style="background:color-mix(in srgb, var(--ds-teal, #00d4aa) 16%, transparent); color:var(--ds-green, #059669);">
                                Access granted · {{ $thread->viewer_grant_mode === 'always' ? 'always' : 'this session' }}
                            </span>
                            <button type="button" @click="revoke()" :disabled="busy" x-show="!revoked"
                                    class="text-[11px] font-semibold rounded px-2.5 py-1"
                                    style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">Revoke access</button>
                            <span x-show="revoked" x-cloak class="text-[11px]" style="color:var(--text-muted);">Revoked</span>
                        </div>
                        @endif
                        <a href="{{ $openHref }}" class="text-[11px] font-semibold ml-auto" style="color:var(--brand-icon, #0ea5e9);">Open thread</a>
                    </div>
                </div>
                @else
                {{-- GATED thread — safe metadata + per-thread Request access (No Silent Locks) --}}
                <div class="rounded px-4 py-3"
                     style="background:var(--surface-2); border:1px solid var(--border); border-left:3px solid var(--text-muted);"
                     x-data="{
                        requested: {{ $thread->pending ? 'true' : 'false' }},
                        loading: false, error: '',
                        async request(){
                            this.loading = true; this.error = '';
                            try {
                                const r = await fetch('{{ route('api.v1.comms-access.store') }}', {
                                    method: 'POST',
                                    headers: { 'Content-Type':'application/json', 'Accept':'application/json',
                                               'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                    body: JSON.stringify({
                                        contact_id: {{ $contact->id }},
                                        thread_key: {{ $thread->thread_key !== null ? json_encode($thread->thread_key) : 'null' }},
                                        communication_id: {{ $thread->communication_id !== null ? $thread->communication_id : 'null' }}
                                    })
                                });
                                const d = await r.json();
                                if (r.ok && d.ok) { this.requested = true; }
                                else { this.error = d.error || 'Could not send the request.'; }
                            } catch (e) { this.error = 'Network error — please try again.'; }
                            finally { this.loading = false; }
                        }
                     }">
                    @include('corex.contacts._comm-thread-meta', ['thread' => $thread, 'isWa' => $isWa, 'accent' => 'var(--text-muted)'])
                    <div class="flex items-center gap-3 mt-2">
                        {{-- AT-153 — name the owning agent so the requester knows whom to ask
                             (bodies stay gated); fallback message avoids a dead-end when no
                             owning agent is on record. --}}
                        <span class="text-[11px]" style="color:var(--text-muted);">
                            @if($thread->owner_name)
                                Private to {{ $thread->owner_name }} — request access to read it.
                            @else
                                Private — no owning agent on record; your request routes to a communications manager.
                            @endif
                        </span>
                        <div class="ml-auto">
                            <template x-if="!requested">
                                <button type="button" @click="request()" :disabled="loading"
                                        class="text-[11px] font-semibold rounded px-3 py-1.5"
                                        style="background:var(--brand-button, #0ea5e9); color:#fff;"
                                        :style="loading ? 'opacity:.6;cursor:wait' : ''">
                                    <span x-show="!loading">Request access</span>
                                    <span x-show="loading">Sending</span>
                                </button>
                            </template>
                            <template x-if="requested">
                                <span class="inline-flex items-center text-[11px] font-semibold rounded px-2.5 py-1"
                                      style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, transparent); color:var(--ds-amber, #f59e0b);">Requested — awaiting approval</span>
                            </template>
                        </div>
                    </div>
                    <p x-show="error" x-text="error" class="text-[11px] mt-1.5" style="color:var(--ds-crimson, #c41e3a);"></p>
                </div>
                @endif
            @empty
                <div class="rounded px-4 py-8 text-center" style="background:var(--surface-2); border:1px dashed var(--border);">
                    <p class="text-sm" style="color:var(--text-secondary);">No communications linked to this contact yet.</p>
                    <p class="text-xs mt-1" style="color:var(--text-muted);">Captured email/WhatsApp with this contact's address or number will appear here automatically.</p>
                </div>
            @endforelse
        </div>
        @endif

        @if(auth()->user()->hasPermission('outreach.compose') && isset($outreachSends))
        <div x-show="activeTab === 'outreach'" x-cloak class="p-6 space-y-6" id="tab-outreach">
            @include('seller-outreach.contact-timeline._panel', [
                'contact'        => $contact,
                'sends'          => $outreachSends,
                'clickCounts'    => $outreachClickCounts ?? collect(),
                'optedOut'       => $contact->messaging_opt_out_at !== null,
                'optedIn'        => $contact->messaging_opted_in_at !== null,
                'outcomeOptions' => $outreachOutcomeOptions ?? [],
            ])
        </div>
        @endif

    </div>{{-- /tab container --}}

</div>

<script>
// AT-60 — structured contact address modal + live summary.
function contactAddress(config) {
    return {
        openAddrModal: false,
        unitNumber:       config.unitNumber       || '',
        floorNumber:      config.floorNumber      || '',
        unitSectionBlock: config.unitSectionBlock || '',
        complexName:      config.complexName      || '',
        streetNumber:     config.streetNumber     || '',
        streetName:       config.streetName       || '',
        // Province/City/Suburb are owned by the P24 picker; mirrored here for the
        // summary via the namespaced "p24-location-changed:contact_addr" event so
        // the property pickers on other pages never cross-fire this one.
        suburb:   config.suburb   || '',
        city:     config.city     || '',
        province: config.province || '',

        // Part 3 — "already on our books" live check.
        heldChecking: false,
        held: null,

        init() {
            window.addEventListener('p24-location-changed:contact_addr', (e) => {
                if (!e.detail) return;
                this.suburb   = e.detail.suburbName   || '';
                this.city     = e.detail.cityName     || '';
                this.province = e.detail.provinceName || '';
                this.queueHeldCheck();
            });

            // Debounced held-address check as the agent types the street/suburb.
            let t;
            this._queueHeldCheck = () => { clearTimeout(t); t = setTimeout(() => this.checkHeld(), 450); };
            this.$watch('streetName',  () => this.queueHeldCheck());
            this.$watch('streetNumber', () => this.queueHeldCheck());
            this.$watch('complexName', () => this.queueHeldCheck());
            // Run once on open if an address is already present.
            if (this.streetName || this.streetNumber) this.queueHeldCheck();
        },

        queueHeldCheck() { if (this._queueHeldCheck) this._queueHeldCheck(); },

        async checkHeld() {
            // Need at least a street name or number — a suburb alone is too broad.
            if (!this.streetName && !this.streetNumber) { this.held = null; return; }
            this.heldChecking = true;
            try {
                const res = await fetch('{{ route('corex.contacts.check-held-address') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        street_number: this.streetNumber, street_name: this.streetName,
                        unit_number: this.unitNumber, complex_name: this.complexName,
                        suburb: this.suburb, city: this.city, province: this.province,
                    }),
                });
                if (!res.ok) { this.held = null; return; }
                const data = await res.json();
                this.held = data.held ? data : null;
            } catch (e) {
                this.held = null;
            } finally {
                this.heldChecking = false;
            }
        },

        get summary() {
            const parts = [];
            if (this.unitNumber)       parts.push('Unit ' + this.unitNumber.trim());
            if (this.unitSectionBlock) parts.push(this.unitSectionBlock.trim());
            if (this.complexName)      parts.push(this.complexName.trim());
            if (this.streetNumber && this.streetName) parts.push((this.streetNumber + ' ' + this.streetName).trim());
            else if (this.streetName)  parts.push(this.streetName.trim());
            if (this.suburb)           parts.push(this.suburb.trim());
            if (this.city && this.city.toLowerCase() !== (this.suburb || '').toLowerCase()) parts.push(this.city.trim());
            if (this.province)         parts.push(this.province.trim());
            return parts.filter(Boolean).join(', ');
        },

        get hasAddress() { return this.summary.length > 0; },

        clearAddress() {
            this.unitNumber = ''; this.floorNumber = ''; this.unitSectionBlock = '';
            this.complexName = ''; this.streetNumber = ''; this.streetName = '';
            this.suburb = ''; this.city = ''; this.province = '';
            // Reset the P24 picker (clears its hidden ids/names too).
            window.dispatchEvent(new CustomEvent('p24-location-reset:contact_addr'));
        },
    };
}

function contactShowData(searchUrl, initTab) {
    // Core Matches was merged into the Properties tab — keep legacy ?tab=matches links working
    if (initTab === 'matches') initTab = 'properties';
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
