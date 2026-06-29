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

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="outreachQueuePage({ csrf: @js(csrf_token()), sendAllowed: {{ $sendAllowed ? 'true' : 'false' }} })">

    {{-- Header --}}
    <div>
        <h1 class="text-xl font-semibold" style="color: var(--text-primary);">Outreach Queue</h1>
        <p class="text-xs mt-1" style="color: var(--text-muted);">
            Messages you prepared earlier, surfaced at their due time. Open each one — it pre-fills WhatsApp;
            you tap Send in WhatsApp to deliver it. "Sent" records the dispatch (CoreX opens the chat; you send it).
        </p>
    </div>

    {{-- Send-window closed banner --}}
    @unless($sendAllowed)
        <div class="rounded-sm p-3 text-xs"
             style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 6%, var(--surface));
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, var(--border));
                    color: var(--text-primary);">
            {{ $windowMessage }}
        </div>
    @endunless

    {{-- SURFACED — the work-list --}}
    <section>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-3" style="color: var(--text-muted);">
            Ready to send ({{ $ready->count() }})
        </h2>

        @forelse($ready as $row)
            <div id="oq-row-{{ $row->id }}" class="rounded-sm p-4 mb-2"
                 style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-sm font-semibold truncate" style="color: var(--text-primary);">
                                {{ trim(($row->contact->first_name ?? '') . ' ' . ($row->contact->last_name ?? '')) ?: ('Contact #' . $row->contact_id) }}
                            </span>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-sm"
                                  style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                                {{ $sourceLabels[$row->source] ?? ucfirst($row->source) }}
                            </span>
                        </div>
                        @if($propLabel($row->property))
                            <div class="text-[11px] mb-1.5" style="color: var(--text-muted);">{{ $propLabel($row->property) }}</div>
                        @endif
                        <p class="text-xs leading-relaxed" style="color: var(--text-secondary);">{{ Str::limit($row->body_snapshot, 220) }}</p>
                        <div class="text-[10px] mt-1.5" style="color: var(--text-muted);">
                            Prepared {{ optional($row->created_at)->format('D j M, H:i') ?? '—' }}
                        </div>
                    </div>
                    <div class="flex-shrink-0 flex flex-col items-end gap-1.5">
                        @if($sendAllowed)
                            <button type="button" @click="open({{ $row->id }}, '{{ route('corex.outreach-queue.open', $row) }}')"
                                    :disabled="busy === {{ $row->id }}"
                                    class="px-4 py-2 text-sm font-semibold rounded-sm"
                                    style="background: #00d4aa; color: #003a2f;">
                                <span x-show="busy !== {{ $row->id }}">Open WhatsApp</span>
                                <span x-show="busy === {{ $row->id }}" x-cloak>Recording…</span>
                            </button>
                        @else
                            <button type="button" disabled
                                    class="px-4 py-2 text-sm font-semibold rounded-sm opacity-60 cursor-not-allowed"
                                    style="background: var(--surface-2); color: var(--text-muted);"
                                    title="{{ $windowMessage }}">Sending closed</button>
                        @endif
                        <button type="button" @click="cancel({{ $row->id }}, '{{ route('corex.outreach-queue.cancel', $row) }}')"
                                :disabled="busy === {{ $row->id }}"
                                class="text-[11px] font-medium" style="color: var(--text-muted);">Remove</button>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-xs py-3" style="color: var(--text-muted);">Your queue is empty. Prepare a message from a contact or the Core-Matches share and tap "Add to queue".</p>
        @endforelse
    </section>

    {{-- INACTIVE — recently dropped/expired, for transparency (not actionable) --}}
    @if($inactive->isNotEmpty())
    <section>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-3" style="color: var(--text-muted);">Recently removed</h2>
        @foreach($inactive as $row)
            <div class="rounded-sm px-3 py-2 mb-1.5 flex items-center justify-between gap-4"
                 style="background: var(--surface-2); border: 1px solid var(--border);">
                <span class="text-xs truncate" style="color: var(--text-secondary);">
                    {{ trim(($row->contact->first_name ?? '') . ' ' . ($row->contact->last_name ?? '')) ?: ('Contact #' . $row->contact_id) }}
                </span>
                <span class="text-[10px] flex-shrink-0" style="color: var(--text-muted);">
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
