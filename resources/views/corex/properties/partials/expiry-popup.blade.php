{{-- ════════════════════════════════════════════════════════════════════════
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     AT-448 — "Mandates expiring soon" pop-up on the Properties list.

     Server-rendered: PropertyController::index() hands over the listings this
     user has NOT yet been shown (MandateExpiryPolicy::unannouncedExpiringFor).
     When there are none this partial emits NOTHING. Same self-contained shape,
     dismissal contract and relative-URL rule as the System Updates modal
     (layouts/partials/system-update-modal.blade.php) — closing, Esc, the scrim,
     "Got it", and every link inside record the listings as seen, so each one
     is announced exactly once (Andre's ruling, spec §2.2).

     Spec: .ai/specs/at448-property-expiry.md §2.2, §7 flow A
     ════════════════════════════════════════════════════════════════════════ --}}
@if(isset($expiringProperties) && $expiringProperties->isNotEmpty())
@php
    $__exIds = $expiringProperties->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    $__exMore = (int) ($expiringMore ?? 0);
    $__exViewAll = $expiringViewAllUrl ?? '#';
@endphp
<div x-data="coreXExpiryPopup({{ \Illuminate\Support\Js::from($__exIds) }}, '{{ route('api.v1.properties.expiry-popup.dismiss', [], false) }}')"
     x-show="open"
     x-cloak
     @keydown.escape.window="close()"
     class="fixed inset-0 z-[70] flex items-center justify-center p-4"
     role="dialog"
     aria-modal="true"
     aria-labelledby="expiry-popup-heading"
     data-tour="properties-expiry-popup">

    <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="close()"></div>

    <div class="relative w-full max-w-xl rounded-md shadow-2xl overflow-hidden"
         style="background:var(--surface); border:1px solid var(--border);"
         @click.stop>

        <div class="flex items-start justify-between gap-3 px-5 py-4" style="border-bottom:1px solid var(--border);">
            <div>
                <div id="expiry-popup-heading" class="text-sm font-bold" style="color:var(--text-primary);">Mandates expiring soon</div>
                <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                    {{ $expiringProperties->count() + $__exMore === 1 ? 'One listing reaches its expiry date' : ($expiringProperties->count() + $__exMore) . ' listings reach their expiry date' }}
                    within the next {{ $expiringWarnDays ?? \App\Services\Properties\MandateExpiryPolicy::DEFAULT_WARN_DAYS }} days.
                </div>
            </div>
            <button type="button" @click="close()" class="p-1 rounded-md" style="color:var(--text-muted);" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="px-5 py-3" style="max-height:60vh; overflow-y:auto;">
            <ul class="divide-y" style="border-color:var(--border);">
                @foreach($expiringProperties as $ep)
                @php
                    $__days = (int) \Illuminate\Support\Carbon::today()->diffInDays($ep->expiry_date->copy()->startOfDay(), false);
                    $__when = $__days <= 0 ? 'Expires today' : ($__days === 1 ? 'Expires tomorrow' : "Expires in {$__days} days");
                @endphp
                <li class="py-2.5 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('corex.properties.show', $ep) }}" @click="send()"
                           class="text-sm font-medium truncate block hover:underline" style="color:var(--text-primary);">
                            {{ $ep->buildDisplayAddress() ?: ($ep->title ?: 'Property #' . $ep->id) }}
                        </a>
                        <div class="text-xs" style="color:var(--text-muted);">
                            {{ $ep->agent?->name ?? '—' }} · {{ $ep->expiry_date->format('d M Y') }}
                        </div>
                    </div>
                    <span class="flex-shrink-0 text-xs font-semibold px-2 py-0.5 rounded-md"
                          style="background:color-mix(in srgb, {{ $__days <= 3 ? 'var(--ds-crimson, #dc2626)' : 'var(--ds-amber, #f59e0b)' }} 12%, transparent); color:{{ $__days <= 3 ? 'var(--ds-crimson, #dc2626)' : 'var(--ds-amber, #f59e0b)' }};">
                        {{ $__when }}
                    </span>
                </li>
                @endforeach
            </ul>
            @if($__exMore > 0)
            <div class="text-xs pt-2" style="color:var(--text-secondary);">+{{ $__exMore }} more — use View all.</div>
            @endif
        </div>

        <div class="flex items-center justify-end gap-2 px-5 py-3" style="background:var(--surface-2); border-top:1px solid var(--border);">
            <a href="{{ $__exViewAll }}" @click="send()" class="corex-btn-outline text-sm">View all</a>
            <button type="button" @click="close()" class="corex-btn-primary text-sm">Got it</button>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
/**
 * AT-448 — expiring-soon popup controller. Mirrors coreXSystemUpdates(): the
 * dismissal POST is fire-and-forget (the user is never trapped behind a modal
 * because our bookkeeping failed), keepalive so a click-through still records,
 * and a failure is logged to the console instead of swallowed. Links inside the
 * modal call send() declaratively (@click) — no init() DOM walk, so the render
 * gate exercises the real registration path.
 */
function coreXExpiryPopup(ids, dismissUrl) {
    return {
        open: true,
        ids: ids,
        sent: false,

        close() {
            this.open = false;
            this.send();
        },

        send() {
            if (this.sent || !this.ids.length) return;
            this.sent = true;

            const body = JSON.stringify({ ids: this.ids });
            const post = window.CoreX?.api?.fetch
                ? window.CoreX.api.fetch(dismissUrl, {
                      method: 'POST',
                      keepalive: true,
                      headers: { 'Content-Type': 'application/json' },
                      body,
                  })
                : fetch(dismissUrl, {
                      method: 'POST',
                      credentials: 'same-origin',
                      keepalive: true,
                      headers: {
                          'Content-Type': 'application/json',
                          'Accept': 'application/json',
                          'X-Requested-With': 'XMLHttpRequest',
                          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                      },
                      body,
                  });

            Promise.resolve(post).catch((err) => {
                console.warn(
                    '[CoreX] Expiring-mandates pop-up dismissal was not recorded — it will show again.',
                    err?.status ? `HTTP ${err.status}` : err,
                );
            });
        },
    };
}
</script>
@endpush
@endonce
@endif
