{{-- ════════════════════════════════════════════════════════════════════════
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     SYSTEM UPDATES — the "what's new in CoreX" pop-up.

     Self-contained: the ONLY central wiring is a single @include of this partial
     in the two app layouts (layouts/corex.blade.php and layouts/corex-app.blade.php),
     exactly like the tour engine. No per-page wiring anywhere.

     Server-rendered by design — the card content never round-trips as JSON, so the
     body is escaped once, server-side, and there is no fetch on page load. When
     nothing is pending this partial emits NOTHING and issues zero DB queries
     (spec §9.6).

     Spec: .ai/specs/system-updates.md §8, §12
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@php
    $__suPayload  = app(\App\Services\SystemUpdateService::class)->modalPayloadFor(auth()->user());
    $__suUpdates  = $__suPayload['updates'];
    $__suOverflow = $__suPayload['overflow'];
    $__suIds      = $__suUpdates->pluck('id')->values()->all();
@endphp

@if($__suUpdates->isNotEmpty())
{{-- The dismiss endpoint is addressed RELATIVELY (absolute:false), never as a full
     route() URL. route() builds from APP_URL, so on any install where APP_URL does not
     match the host the user is actually browsing (QA/staging clones share a config),
     the POST leaves the page's origin: the browser blocks it as cross-origin, the
     session cookie is not attached, the dismissal is never recorded — and because the
     write is fire-and-forget the failure is silent and the modal returns on EVERY page
     load. A same-origin path can never do that. The tour engine (the same self-scoped
     per-user dismissal pattern) has always addressed its endpoint this way. --}}
<div x-data="coreXSystemUpdates({{ \Illuminate\Support\Js::from($__suIds) }}, '{{ route('api.v1.system-updates.dismiss', [], false) }}')"
     x-show="open"
     x-cloak
     @keydown.escape.window="close()"
     class="fixed inset-0 z-[70] flex items-center justify-center p-4"
     role="dialog"
     aria-modal="true"
     aria-labelledby="system-update-heading">

    {{-- Scrim — clicking it closes AND dismisses, same as the Close button. --}}
    <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="close()"></div>

    {{-- Card --}}
    <div class="relative w-full rounded-md shadow-2xl overflow-hidden"
         style="max-width:520px; background:var(--surface); border:1px solid var(--border);"
         @click.stop>

        {{-- Header --}}
        <div class="flex items-start justify-between gap-3 px-5 py-4"
             style="border-bottom:1px solid var(--border);">
            <div>
                <div id="system-update-heading" class="text-sm font-bold" style="color:var(--text-primary);">
                    What's new in CoreX
                </div>
                @if(count($__suIds) > 1)
                <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                    <span x-text="index + 1"></span> of {{ count($__suIds) }}
                </div>
                @endif
            </div>
            <button type="button" @click="close()" aria-label="Close"
                    class="p-1 rounded-md" style="color:var(--text-secondary);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Cards — all rendered server-side, shown one at a time. --}}
        <div class="px-5 py-5 overflow-y-auto" style="max-height:65vh;">
            @foreach($__suUpdates as $__suIndex => $__suUpdate)
                <div x-show="index === {{ $__suIndex }}">
                    @include('layouts.partials._system-update-card', ['update' => $__suUpdate])
                </div>
            @endforeach
        </div>

        {{-- Footer --}}
        <div class="flex items-center justify-between gap-3 px-5 py-3"
             style="border-top:1px solid var(--border); background:var(--surface-2);">

            {{-- These carry data-system-update-link for the same reason "Take me there"
                 does: they navigate AWAY from the modal without ever calling close(), so
                 without the marker the dismissal is never recorded and the pop-up returns
                 on the page the user just asked to be taken to. --}}
            <div class="text-xs" style="color:var(--text-secondary);">
                @if($__suOverflow > 0)
                    <a href="{{ route('corex.whats-new.index') }}" data-system-update-link style="color:var(--brand-icon);">
                        + {{ $__suOverflow }} more {{ \Illuminate\Support\Str::plural('update', $__suOverflow) }} — see all
                    </a>
                @else
                    <a href="{{ route('corex.whats-new.index') }}" data-system-update-link style="color:var(--text-secondary);">
                        See all updates
                    </a>
                @endif
            </div>

            <div class="flex items-center gap-2">
                @if(count($__suIds) > 1)
                <button type="button" x-show="index > 0" @click="index--"
                        class="corex-btn-outline text-sm">Back</button>
                <button type="button" x-show="index < {{ count($__suIds) - 1 }}" @click="index++"
                        class="corex-btn-primary text-sm">Next</button>
                @endif
                <button type="button"
                        x-show="index === {{ count($__suIds) - 1 }}"
                        @click="close()"
                        class="corex-btn-primary text-sm">Got it</button>
            </div>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
/**
 * System Updates pop-up controller.
 *
 * Spec: .ai/specs/system-updates.md §9.5
 *
 * The dismissal POST is fire-and-forget on purpose. If it fails (offline, expired
 * CSRF, server error) the modal STILL closes and the update simply reappears on the
 * next page load. We never trade a user's ability to keep working for our own
 * bookkeeping — a user trapped behind a modal they cannot dismiss is a far worse
 * outcome than showing them the same note twice.
 */
function coreXSystemUpdates(ids, dismissUrl) {
    return {
        open: true,
        index: 0,
        ids: ids,
        sent: false,

        init() {
            // Clicking "Take me there" is a stronger acknowledgement than closing
            // the modal, so record the dismissal before the browser navigates away.
            this.$el.querySelectorAll('[data-system-update-link]').forEach((link) => {
                link.addEventListener('click', () => this.send());
            });
        },

        close() {
            this.open = false;
            this.send();
        },

        send() {
            if (this.sent || !this.ids.length) return;
            this.sent = true;   // idempotent client-side; the server is idempotent too

            // keepalive lets the request outlive the document. Every link inside the
            // modal dismisses and THEN navigates, and a plain fetch is cancelled the
            // moment the browser tears the page down — so the dismissal that fired on
            // "Take me there" or "See all updates" would be lost precisely when the
            // user acknowledged an update most emphatically, and the modal would greet
            // them again on arrival. The payload is a few bytes, far under the 64KB
            // keepalive ceiling.
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

            // Closing always wins (see docblock) — but it must never be SILENT. A dropped
            // CSRF header made every dismissal 419 for weeks and the only symptom users
            // could report was "the pop-up keeps coming back"; nothing was logged, client
            // or server, because the rejection was swallowed here. A failed dismissal now
            // says so in the console, where the next person looking at this can see it.
            Promise.resolve(post).catch((err) => {
                console.warn(
                    '[CoreX] System Update dismissal was not recorded — this pop-up will reappear.',
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
@endauth
