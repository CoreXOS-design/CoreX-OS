{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    use Illuminate\Support\Str;
    $sourceLabels = ['contact' => 'Contact', 'map' => 'Map', 'mic' => 'MIC'];
    $propLabel = function ($p) {
        if (!$p) return null;
        return method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->title ?? ('Property #' . $p->id));
    };
@endphp

<div class="w-full space-y-5"
     x-data="outreachQueuePage({ csrf: @js(csrf_token()), sendAllowed: {{ $sendAllowed ? 'true' : 'false' }} })">

    {{-- Page header --}}
    <div data-tour="oq-intro" class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-white leading-tight">Outreach Queue</h1>
                <p class="text-sm text-white/60 mt-1 max-w-3xl">
                    Messages you prepared earlier — ready to send now. Open each one: it pre-fills WhatsApp and
                    you tap Send in WhatsApp to deliver it. "Sent" records the dispatch (CoreX opens the chat; you send it).
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap flex-shrink-0">
                @include('layouts.partials.tour-header-launcher')
            </div>
        </div>
    </div>

    {{-- Send-window closed banner --}}
    @unless($sendAllowed)
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1 min-w-0">{{ $windowMessage }}</div>
        </div>
    @endunless

    {{-- The work-list --}}
    <section data-tour="oq-ready">
        <h2 class="text-xs font-bold uppercase tracking-widest mb-3" style="color: var(--text-muted);">
            Ready to send ({{ number_format($ready->count()) }})
        </h2>

        @forelse($ready as $row)
            <div id="oq-row-{{ $row->id }}" class="rounded-md p-4 mb-2"
                 style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <span class="text-sm font-semibold truncate" style="color: var(--text-primary);">
                                {{ trim(($row->contact->first_name ?? '') . ' ' . ($row->contact->last_name ?? '')) ?: ('Contact #' . $row->contact_id) }}
                            </span>
                            <span class="text-xs font-medium px-2 py-0.5 rounded-md whitespace-nowrap"
                                  style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                                {{ $sourceLabels[$row->source] ?? ucfirst($row->source) }}
                            </span>
                            {{-- AT-120 — whose message it is, shown when viewing beyond own (manager/admin). --}}
                            @if(($showAgent ?? false) && $row->agent)
                                <span class="text-xs font-medium px-2 py-0.5 rounded-md whitespace-nowrap"
                                      style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent); color: var(--brand-icon, #0ea5e9);">
                                    {{ $row->agent_id === ($currentUserId ?? null) ? 'You' : ($row->agent->name ?? ('Agent #' . $row->agent_id)) }}
                                </span>
                            @endif
                        </div>
                        @if($propLabel($row->property))
                            <div class="text-xs mb-1.5" style="color: var(--text-muted);">{{ $propLabel($row->property) }}</div>
                        @endif
                        <p class="text-xs leading-relaxed" style="color: var(--text-secondary);">{{ Str::limit($row->body_snapshot, 220) }}</p>
                        <div class="text-xs mt-1.5" style="color: var(--text-muted);">
                            Prepared {{ optional($row->created_at)->format('D j M, H:i') ?? '—' }}
                        </div>
                    </div>
                    <div class="flex-shrink-0 flex flex-col items-end gap-1.5">
                        {{-- AT-120 — act-own: send/remove only on YOUR rows (sending opens YOUR
                             WhatsApp). A manager/admin viewing a team member's row sees it read-only;
                             the server enforces this too. --}}
                        @if($row->agent_id === ($currentUserId ?? null))
                            @if($sendAllowed)
                                <button type="button" data-tour="oq-open" @click="open({{ $row->id }}, '{{ route('corex.outreach-queue.open', $row) }}')"
                                        :disabled="busy === {{ $row->id }}"
                                        class="corex-btn-primary text-sm whitespace-nowrap disabled:opacity-40 disabled:cursor-not-allowed">
                                    <span x-show="busy !== {{ $row->id }}">Open WhatsApp</span>
                                    <span x-show="busy === {{ $row->id }}" x-cloak>Recording…</span>
                                </button>
                            @else
                                <button type="button" disabled
                                        class="text-sm font-semibold rounded-md px-3.5 py-1.5 opacity-60 cursor-not-allowed whitespace-nowrap"
                                        style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);"
                                        title="{{ $windowMessage }}">Sending closed</button>
                            @endif
                            <button type="button" @click="cancel({{ $row->id }}, '{{ route('corex.outreach-queue.cancel', $row) }}')"
                                    :disabled="busy === {{ $row->id }}"
                                    class="text-xs font-medium" style="color: var(--text-muted);">Remove</button>
                        @else
                            <span class="text-xs" style="color: var(--text-muted);">Team member's queue — view only</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            {{-- Empty state --}}
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Your queue is empty</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Prepare a message from a contact or the Core-Matches share and tap "Add to queue" — it will appear here ready to send.</p>
                <a href="{{ route('corex.contacts.index') }}" class="corex-btn-primary text-sm">Go to Contacts</a>
            </div>
        @endforelse
    </section>

    {{-- INACTIVE — recently dropped/expired, for transparency (not actionable) --}}
    @if($inactive->isNotEmpty())
    <section>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-3" style="color: var(--text-muted);">Recently removed</h2>
        @foreach($inactive as $row)
            <div class="rounded-md px-3 py-2 mb-1.5 flex items-center justify-between gap-4"
                 style="background: var(--surface-2); border: 1px solid var(--border);">
                <span class="text-xs truncate" style="color: var(--text-secondary);">
                    {{ trim(($row->contact->first_name ?? '') . ' ' . ($row->contact->last_name ?? '')) ?: ('Contact #' . $row->contact_id) }}
                </span>
                <span class="text-xs flex-shrink-0" style="color: var(--text-muted);">
                    {{ $row->status === 'expired' ? 'Expired' : 'Removed' }}@if($row->dropped_reason) · {{ str_replace('_', ' ', $row->dropped_reason) }}@endif
                </span>
            </div>
        @endforeach
    </section>
    @endif
</div>

<script>
    function outreachQueuePage(cfg) {
        return {
            csrf: cfg.csrf,
            sendAllowed: cfg.sendAllowed,
            busy: null,

            async open(id, url) {
                if (!this.sendAllowed || this.busy) return;
                this.busy = id;
                try {
                    const res = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (!res.ok) {
                        alert(data.message || 'Could not dispatch.');
                        if (data.dropped) this.removeRow(id); // consent revoked / contact gone → it left the queue
                        return;
                    }
                    if (data.client_url) window.open(data.client_url, 'corex_whatsapp_web');
                    this.removeRow(id); // sent → leaves the active work-list
                } catch (e) {
                    alert('Network error — try again.');
                } finally {
                    this.busy = null;
                }
            },

            async cancel(id, url) {
                if (this.busy) return;
                this.busy = id;
                try {
                    const res = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (!res.ok) { alert(data.message || 'Could not cancel.'); return; }
                    this.removeRow(id);
                } catch (e) {
                    alert('Network error — try again.');
                } finally {
                    this.busy = null;
                }
            },

            removeRow(id) {
                const el = document.getElementById('oq-row-' + id);
                if (el) el.remove();
            },
        };
    }
</script>
@endsection
