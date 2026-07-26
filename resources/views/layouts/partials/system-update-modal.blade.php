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
<div x-data="coreXSystemUpdates({{ \Illuminate\Support\Js::from($__suIds) }}, '{{ route('api.v1.system-updates.dismiss') }}')"
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
         style="max-width:520px; background:var(--surface, #fff); border:1px solid var(--border, rgba(0,0,0,0.08));"
         @click.stop>

        {{-- Header --}}
        <div class="flex items-start justify-between gap-3 px-5 py-4"
             style="border-bottom:1px solid var(--border, rgba(0,0,0,0.07));">
            <div>
                <div id="system-update-heading" class="text-sm font-bold" style="color:var(--text-primary, #111827);">
                    What's new in CoreX
                </div>
                @if(count($__suIds) > 1)
                <div class="text-xs mt-0.5" style="color:var(--text-secondary, #6b7280);">
                    <span x-text="index + 1"></span> of {{ count($__suIds) }}
                </div>
                @endif
            </div>
            <button type="button" @click="close()" aria-label="Close"
                    class="p-1 rounded-md" style="color:var(--text-secondary, #6b7280);">
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
             style="border-top:1px solid var(--border, rgba(0,0,0,0.07)); background:var(--surface-2, #f7f8fa);">

            <div class="text-xs" style="color:var(--text-secondary, #6b7280);">
                @if($__suOverflow > 0)
                    <a href="{{ route('corex.whats-new.index') }}" style="color:var(--brand-icon, #0ea5e9);">
                        + {{ $__suOverflow }} more {{ \Illuminate\Support\Str::plural('update', $__suOverflow) }} — see all
                    </a>
                @else
                    <a href="{{ route('corex.whats-new.index') }}" style="color:var(--text-secondary, #6b7280);">
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

            const post = window.CoreX?.api?.fetch
                ? window.CoreX.api.fetch(dismissUrl, {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ ids: this.ids }),
                  })
                : fetch(dismissUrl, {
                      method: 'POST',
                      credentials: 'same-origin',
                      headers: {
                          'Content-Type': 'application/json',
                          'Accept': 'application/json',
                          'X-Requested-With': 'XMLHttpRequest',
                          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                      },
                      body: JSON.stringify({ ids: this.ids }),
                  });

            Promise.resolve(post).catch(() => { /* see docblock — closing always wins */ });
        },
    };
}
</script>
@endpush
@endonce
@endif
@endauth
