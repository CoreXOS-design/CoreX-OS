{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="comp-comm-archive-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Communication Archive</h1>
                <p class="text-sm text-white/60">Immutable record of business email &amp; WhatsApp — retained for compliance.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('compliance.comm-mailboxes.index') }}" data-tour="comp-comm-archive-mailboxes"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                   style="background:rgba(255,255,255,0.08); color:#fff; border:1px solid rgba(255,255,255,0.18);">Mailboxes</a>
                @include('layouts.partials.tour-header-launcher')
            </div>
        </div>
    </div>

    @if($contact)
    <div class="rounded-md px-4 py-2 text-sm" style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent); color: var(--text-primary);">
        Filtered to contact: <strong>{{ $contact->first_name }} {{ $contact->last_name }}</strong>
        <a href="{{ route('compliance.comm-archive.index') }}" class="ml-2" style="color: var(--brand-icon);">Clear</a>
    </div>
    @endif

    {{-- Filters --}}
    <div class="rounded-md p-4" data-tour="comp-comm-archive-filters" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            @if($contact)<input type="hidden" name="contact" value="{{ $contact->id }}">@endif
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                <div class="relative">
                    <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style="color: var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.3-4.3M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z"/>
                    </svg>
                    <input type="text" name="search" value="{{ $search }}" placeholder="Subject, sender or preview…"
                           class="w-full rounded-md pl-9 pr-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Channel</label>
                <select name="channel" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All</option>
                    <option value="email" {{ $channel === 'email' ? 'selected' : '' }}>Email</option>
                    <option value="whatsapp" {{ $channel === 'whatsapp' ? 'selected' : '' }}>WhatsApp</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Direction</label>
                <select name="direction" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All</option>
                    <option value="inbound" {{ $direction === 'inbound' ? 'selected' : '' }}>Inbound</option>
                    <option value="outbound" {{ $direction === 'outbound' ? 'selected' : '' }}>Outbound</option>
                </select>
            </div>
            <button type="submit" class="corex-btn-primary">Apply</button>
            @if($search || $channel || $direction)
            <a href="{{ route('compliance.comm-archive.index', $contact ? ['contact' => $contact->id] : []) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</a>
            @endif
        </form>
    </div>

    {{-- List --}}
    <div class="rounded-md overflow-hidden" data-tour="comp-comm-archive-list" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">When</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Channel</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Direction</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">From</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Subject / preview</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($communications as $c)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $c->occurred_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $c->channel === 'email' ? 'ds-badge-default' : 'ds-badge-success' }}">{{ ucfirst($c->channel) }}</span>
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $c->direction === 'inbound' ? '↓ In' : '↑ Out' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $c->from_display }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">
                            <div class="font-medium">{{ \Illuminate\Support\Str::limit($c->subject ?: '(no subject)', 70) }}</div>
                            <div class="text-xs" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($c->body_preview, 90) }}</div>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($c->thread_key)
                            <a href="{{ route('compliance.comm-archive.thread', $c->thread_key) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Thread</a>
                            @endif
                            <a href="{{ route('compliance.comm-archive.show', $c) }}" class="text-xs font-semibold ml-2" style="color: var(--brand-icon);">Open</a>
                            {{-- AT-182 — jump straight to the matched contact's communications tab (new tab). --}}
                            @php $cid = optional($c->links->firstWhere('linkable_type', \App\Models\Contact::class))->linkable_id; @endphp
                            @if($cid)
                            <a href="{{ route('corex.contacts.show', $cid) }}?tab=communications" target="_blank" rel="noopener" class="text-xs font-semibold ml-2" style="color: var(--brand-icon);">Contact</a>
                            @else
                            <span class="text-xs ml-2" style="color: var(--text-muted);" title="No matched contact">Contact</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No communications archived yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($communications->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $communications->links() }}</div>
        @endif
    </div>
</div>
@endsection
