{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- AT-393 — header, related links and the filter card are frozen (full-height flex
     column); only the scroll region (flash + queue table + pagination) scrolls.
     Spec: .ai/specs/claude_communication_archive_triage_addendum.md §10 --}}
<div class="w-full h-full flex flex-col" x-data="triage()">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0" data-tour="comms-triage-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Review Incoming Messages</h1>
                <p class="text-xs" style="color: var(--text-muted);">Unknown-contact messages awaiting your decision. Add the contact to archive the conversation, or mark it not real-estate related to remove it from your list.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            </div>
        </div>
    </div>

    <div class="flex-shrink-0 mt-3">
        @include('communications.partials._consent-crosslinks', ['current' => 'triage'])
    </div>

    @if(!($noContext ?? false))
    {{-- Filters — search (sender / subject / message) + channel; one GET form so they
         compose; pagination links carry the query string. Live count on the right. --}}
    @php $filtersActive = ($filters['q'] ?? '') !== '' || ($filters['channel'] ?? '') !== ''; @endphp
    <form method="GET" action="{{ route('communications.triage.index') }}"
          class="rounded-md px-4 py-3 mt-3 flex-shrink-0 flex flex-wrap items-center gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">
        <div class="relative flex-1 min-w-[180px] max-w-xs">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
            </svg>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Search sender, subject or message…"
                   class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                   style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
        </div>

        <select name="channel" onchange="this.form.submit()" class="list-header-filter">
            <option value="" {{ ($filters['channel'] ?? '') === '' ? 'selected' : '' }}>All channels</option>
            @foreach(($channels ?? []) as $ch)
                <option value="{{ $ch }}" {{ ($filters['channel'] ?? '') === $ch ? 'selected' : '' }}>{{ ucfirst($ch) }}</option>
            @endforeach
        </select>

        <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
        @if($filtersActive)
        <a href="{{ route('communications.triage.index') }}"
           class="text-xs underline transition-all duration-300" style="color:var(--text-muted);">Clear</a>
        @endif

        <span class="ml-auto text-xs" style="color:var(--text-muted);">{{ number_format($items->total()) }} message{{ $items->total() === 1 ? '' : 's' }}</span>
    </form>
    @endif

    {{-- Scroll region — everything from here down scrolls; header, links and filters stay put. --}}
    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-4 space-y-5">

    {{-- AT-274 guard-first / never-blank: an owner or null-agency actor reaches this
         per-agent queue with no resolved agency context. Explain it — never a blank
         screen or a bare 403. --}}
    @if($noContext ?? false)
    <div class="rounded-md px-6 py-10 text-center" style="background: var(--surface); border:1px solid var(--border);">
        <p class="text-sm max-w-xl mx-auto" style="color: var(--text-secondary);">
            You're viewing as <strong>System Owner</strong> with no single agency selected, so there is no personal message queue to show here. This screen is a <strong>per-agent</strong> queue of unknown-contact messages. Pick an agency from the switcher (or sign in as an agent) to review that agent's incoming messages.
        </p>
    </div>
    @else

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="comms-triage-table">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">When</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Channel</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">From</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Message</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $p)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $p->occurred_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3"><span class="ds-badge {{ $p->channel === 'email' ? 'ds-badge-default' : 'ds-badge-success' }}">{{ ucfirst($p->channel) }}</span></td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $p->from_identifier }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">
                            @if($p->subject)<div class="font-medium">{{ \Illuminate\Support\Str::limit($p->subject, 60) }}</div>@endif
                            <div class="text-xs" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($p->body_preview ?: $p->body_text, 100) }}</div>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-2">
                                <button type="button" class="corex-btn-primary corex-btn-xs"
                                        @if($loop->first) data-tour="comms-triage-add" @endif
                                        @click="openAdd(@js($p->from_identifier))">Add contact</button>
                                <form method="POST" action="{{ route('communications.triage.not-real-estate') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="identifier" value="{{ $p->from_identifier }}">
                                    <input type="hidden" name="message_external_id" value="{{ $p->external_id }}">
                                    <button type="submit" class="corex-btn-outline corex-btn-xs" @if($loop->first) data-tour="comms-triage-dismiss" @endif>Not real estate</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                        @if($filtersActive ?? false)
                            No messages match these filters.
                        @else
                            Nothing to triage. New unknown-contact messages will appear here.
                        @endif
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($items->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
            {{ $items->links() }}
        </div>
        @endif
    </div>

    @endif

    </div>{{-- /scroll region --}}

    {{-- Add-contact modal (reuses the standard contact-create fields, prefilled from the identifier) --}}
    <div x-show="showAdd" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="rounded-md w-full max-w-md" style="background: var(--surface); border:1px solid var(--border);" @click.outside="showAdd=false">
            <form method="POST" action="{{ route('communications.triage.add-contact') }}">
                @csrf
                <input type="hidden" name="identifier" :value="identifier">
                <div class="px-5 py-4" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Add contact</h3>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Adding archives this conversation and any other messages from <strong x-text="identifier"></strong>.</p>
                </div>
                <div class="px-5 py-4 space-y-3">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">First name <span class="text-red-500">*</span></label>
                        <input type="text" name="first_name" required class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Last name</label>
                        <input type="text" name="last_name" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone</label>
                        <input type="text" name="phone" x-model="phone" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                        <input type="email" name="email" x-model="email" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <p class="text-xs" style="color: var(--text-muted);">A phone or email is required.</p>
                </div>
                <div class="px-5 py-3 flex items-center justify-end gap-3" style="border-top:1px solid var(--border);">
                    <button type="button" @click="showAdd=false" class="corex-btn-outline">Cancel</button>
                    <button type="submit" class="corex-btn-primary">Add &amp; Archive</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function triage() {
    return {
        showAdd: false,
        identifier: '',
        phone: '',
        email: '',
        openAdd(identifier) {
            this.identifier = identifier;
            // Prefill phone or email from the identifier shape.
            if (String(identifier).includes('@') && !/@[sc]\./i.test(String(identifier))) {
                this.email = identifier; this.phone = '';
            } else {
                this.phone = identifier; this.email = '';
            }
            this.showAdd = true;
        },
    };
}
</script>
@endsection
