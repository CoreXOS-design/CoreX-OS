{{-- ════════════════════════════════════════════════════════════════════════
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     GRADUAL FEATURE ROLLOUT POP-UPS (AT-478 Part 3). Spec: .ai/specs/agency-feature-rollout.md
     §2.5–2.6, §3.5a, §5.3–5.4. Same shape as the System Updates pop-up: one include in each app
     layout, server-rendered, endpoints addressed RELATIVELY (see system-update-modal for why).

     At most ONE of these per page (FeatureRolloutAccess::popupFor), in this order:
       1. review_released — the admin checks who has access to groups that went live without a review
       2. review_upcoming — the admin chooses who gets the group switching on next release day
       3. review_admin    — an "Admin decides" group switched on for the admins only: who else should see it (§18)
       4. release         — "New in CoreX", once per user per group, only features they can open
     It renders ABOVE the System Updates pop-up (z-index) so the admin's prompt is seen first.
     No plan for the agency = one indexed query and nothing rendered.
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@php
    $__fr = app(\App\Services\FeatureRollout\FeatureRolloutAccess::class)->popupFor(
        auth()->user(),
        array_map('intval', (array) session(\App\Http\Controllers\Api\V1\FeatureRolloutPopupController::LATER_SESSION_KEY, []))
    );
@endphp

@if($__fr)
@php
    $__frCatalogue = app(\App\Services\Features\AgencyFeatureService::class)->catalogue();
    $__frWaveIds   = $__fr['waves']->pluck('id')->values()->all();
    $__frTz        = app(\App\Services\FeatureRollout\RolloutCalendar::class)->timezone();
@endphp

@if(str_starts_with($__fr['kind'], 'review_'))
@php
    $__frAdmin    = $__fr['kind'] === 'review_admin';
    $__frReleased = in_array($__fr['kind'], ['review_released', 'review_admin'], true);
    $__frAwait    = (array) config('feature-rollout.admin_switch_on', []);
    $__frRoles    = $__fr['roles'];
    $__frWhen     = $__frReleased
        ? $__fr['waves']->min('released_at')?->copy()->setTimezone($__frTz)
        : $__fr['waves']->min('scheduled_for');
@endphp
<div x-data="coreXRolloutReview({{ \Illuminate\Support\Js::from($__frWaveIds) }}, {{ \Illuminate\Support\Js::from($__fr['grid']) }}, '{{ route('api.v1.feature-rollout.access-review', [], false) }}')"
     x-show="open" x-cloak
     class="fixed inset-0 z-[75] flex items-center justify-center p-4"
     role="dialog" aria-modal="true" aria-labelledby="rollout-review-heading">
    <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);"></div>

    <div class="relative w-full max-w-3xl rounded-md shadow-2xl overflow-hidden"
         style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom:1px solid var(--border);">
            <div id="rollout-review-heading" class="text-sm font-bold" style="color:var(--text-primary);">
                @if($__frAdmin)
                    Ready for you — who else should see these?
                @elseif($__frReleased)
                    These went live on {{ $__frWhen?->format('l j F') }} — check who has access
                @else
                    Switching on {{ $__frWhen?->format('l j F') }} — choose who gets access
                @endif
            </div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">
                @if($__frAdmin)
                    These features are switched on for you and your fellow admins only. Tick the roles that should also see them — nobody else gets them until you do. You can change this any time in Settings → Feature rollout.
                @elseif($__frReleased)
                    These features switched on while you were away, with your roles' existing access. Untick a role to take it away, or tick one to give it.
                @else
                    Tick the roles that should see each feature when it switches on. Untick every role and nobody sees it until you change it in the Role Manager.
                @endif
            </div>
        </div>

        <div class="px-5 py-4 overflow-y-auto space-y-5" style="max-height:62vh;">
            @php $__frLow = $__frReleased ? null : app(\App\Services\FeatureRollout\FeatureRolloutUsage::class)->lowUse($__fr['plan']); @endphp
            @if($__frLow)
                <div class="rounded-md px-4 py-3 text-sm" data-low-use style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
                    Only {{ $__frLow['opened'] }} of {{ $__frLow['people'] }} people with access have opened what switched on in "{{ $__frLow['group'] }}". If your team needs more time, <strong>Hold this group</strong> pauses the introduction until you resume it.
                </div>
            @endif
            @foreach($__fr['waves'] as $__frWave)
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $__frWave->title }}</div>
                    @if($__frWave->intro_text)
                        <p class="text-sm mt-1" style="color:var(--text-secondary);">{{ $__frWave->intro_text }}</p>
                    @endif
                    <div class="mt-2 space-y-3">
                        @foreach(app(\App\Services\FeatureRollout\FeatureRolloutAccess::class)->liveFeatures($__frWave) as $__frKey)
                            <div class="rounded-md px-4 py-3" style="border:1px solid var(--border); background:var(--surface-2);">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $__frCatalogue[$__frKey]['label'] ?? $__frKey }}</div>
                                @if(!empty($__frCatalogue[$__frKey]['explain']))
                                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $__frCatalogue[$__frKey]['explain'] }}</div>
                                @endif
                                @php $__frMissing = app(\App\Services\FeatureRollout\FeatureReadiness::class)->missing(\App\Models\Agency::withoutGlobalScopes()->find($__fr['plan']->agency_id), $__frKey); @endphp
                                @if($__frMissing !== [])
                                    <div class="text-xs mt-1" style="color:var(--ds-amber, #b45309);" data-readiness="{{ $__frKey }}">
                                        Set this up first: add
                                        @foreach($__frMissing as $__frI => $__frM){{ $__frI > 0 ? ($loop->last ? ' and ' : ', ') : '' }}<a href="{{ $__frM['url'] }}" style="color:var(--brand-icon); text-decoration:underline;">{{ $__frM['label'] }}</a>@endforeach.
                                        It waits until then and switches on by itself once it is done.
                                    </div>
                                @endif
                                @if(in_array($__frKey, $__frAwait, true))
                                    <div class="text-xs mt-1" style="color:var(--ds-amber, #b45309);">This one reaches people outside your agency, so it waits for you to switch it on in Settings → Feature rollout.</div>
                                @endif
                                @if(($__fr['grid'][$__frKey] ?? null) === null)
                                    <div class="text-xs mt-2" style="color:var(--text-muted);">Everyone who can open this part of CoreX gets it — there is no separate access to set.</div>
                                @elseif($__frRoles->isEmpty())
                                    <div class="text-xs mt-2" style="color:var(--text-muted);">Your agency has no roles of its own to set yet.</div>
                                @else
                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
                                        @foreach($__frRoles as $__frRole)
                                            <label class="inline-flex items-center gap-1.5 text-sm" style="color:var(--text-primary);">
                                                <input type="checkbox" x-model="grid['{{ $__frKey }}']['{{ $__frRole->name }}']"
                                                       data-rollout-access="{{ $__frKey }}|{{ $__frRole->name }}">
                                                {{ $__frRole->label ?: $__frRole->name }}
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3"
             style="border-top:1px solid var(--border); background:var(--surface-2);">
            <div class="flex items-center gap-3">
                <button type="button" @click="send('later')" :disabled="busy" class="text-sm" style="color:var(--text-secondary);">Ask me next time</button>
                <span x-show="error" x-text="error" class="text-xs" style="color:var(--ds-crimson, #c41e3a);"></span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @unless($__frReleased)
                    <button type="button" @click="send('hold')" :disabled="busy" class="corex-btn-outline text-sm">Hold this group</button>
                @endunless
                <button type="button" @click="send('keep')" :disabled="busy" class="corex-btn-outline text-sm">{{ $__frAdmin ? 'Keep for admins only' : 'Keep as is' }}</button>
                <button type="button" @click="send('save')" :disabled="busy" class="corex-btn-primary text-sm">Save access</button>
            </div>
        </div>
    </div>
</div>
@else
<div x-data="coreXRolloutRelease({{ \Illuminate\Support\Js::from($__frWaveIds) }}, '{{ route('api.v1.feature-rollout.announcements.seen', [], false) }}')"
     x-show="open" x-cloak
     @keydown.escape.window="close()"
     class="fixed inset-0 z-[75] flex items-center justify-center p-4"
     role="dialog" aria-modal="true" aria-labelledby="rollout-release-heading">
    <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="close()"></div>

    <div class="relative w-full max-w-xl rounded-md shadow-2xl overflow-hidden"
         style="background:var(--surface); border:1px solid var(--border);" @click.stop>
        <div class="flex items-start justify-between gap-3 px-5 py-4" style="border-bottom:1px solid var(--border);">
            <div id="rollout-release-heading" class="text-sm font-bold" style="color:var(--text-primary);">New in CoreX for you</div>
            <button type="button" @click="close()" aria-label="Close" class="p-1 rounded-md" style="color:var(--text-secondary);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="px-5 py-4 overflow-y-auto space-y-5" style="max-height:65vh;">
            @foreach($__fr['announcements'] as $__frA)
                <div>
                    <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $__frA['wave']->title }}</div>
                    @if($__frA['wave']->intro_text)
                        <p class="text-sm mt-1" style="color:var(--text-secondary);">{{ $__frA['wave']->intro_text }}</p>
                    @endif
                    <ul class="mt-2 space-y-2">
                        @foreach($__frA['features'] as $__frF)
                            <li class="rounded-md px-4 py-3 flex items-start justify-between gap-3" style="border:1px solid var(--border); background:var(--surface-2);">
                                <div>
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $__frF['label'] }}</div>
                                    @if($__frF['explain'] !== '')
                                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $__frF['explain'] }}</div>
                                    @endif
                                </div>
                                @if($__frF['tour'])
                                    <a href="{{ $__frF['tour']['url'] }}" data-rollout-link
                                       class="corex-btn-primary text-xs whitespace-nowrap" title="{{ $__frF['tour']['title'] }}">Take the tour</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <div class="flex items-center justify-between gap-3 px-5 py-3" style="border-top:1px solid var(--border); background:var(--surface-2);">
            <a href="{{ route('corex.new-and-coming.index') }}" data-rollout-link class="text-xs" style="color:var(--brand-icon);">See everything new and coming</a>
            <button type="button" @click="close()" class="corex-btn-primary text-sm">Got it</button>
        </div>
    </div>
</div>
@endif

@once
@push('scripts')
<script>
/* AT-478 — gradual rollout pop-ups. Posts mirror the System Updates pop-up (CSRF header, same-origin). */
function coreXRolloutPost(url, payload, keepalive) {
    const body = JSON.stringify(payload);
    return window.CoreX?.api?.fetch
        ? window.CoreX.api.fetch(url, { method: 'POST', keepalive: !!keepalive, headers: { 'Content-Type': 'application/json' }, body })
        : fetch(url, {
              method: 'POST', credentials: 'same-origin', keepalive: !!keepalive,
              headers: {
                  'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
              },
              body,
          }).then((r) => { if (!r.ok) { throw { status: r.status }; } return r; });
}

/* The admin access review: the decision must be recorded, so unlike the release pop-up it stays
   open and says so when a save fails. "Ask me next time" is the way out without deciding. */
function coreXRolloutReview(waveIds, grid, url) {
    const g = {};
    Object.keys(grid || {}).forEach((f) => { if (grid[f]) { g[f] = { ...grid[f] }; } });
    return {
        open: true, busy: false, error: '', grid: g,
        send(action) {
            this.busy = true; this.error = '';
            Promise.resolve(coreXRolloutPost(url, { action, wave_ids: waveIds, access: action === 'save' ? this.grid : {} }))
                .then(() => {
                    this.open = false;
                    // Keep / save / hold can change what the next page shows (the next pop-up, the menu).
                    if (action !== 'later') { window.location.reload(); }
                })
                .catch((err) => {
                    this.busy = false;
                    this.error = 'That was not saved' + (err?.status ? ` (error ${err.status})` : '') + '. Please try again.';
                });
        },
    };
}

/* The release pop-up: closing always wins, exactly like System Updates. */
function coreXRolloutRelease(waveIds, url) {
    return {
        open: true, sent: false,
        init() {
            this.$el.querySelectorAll('[data-rollout-link]').forEach((a) => a.addEventListener('click', () => this.send()));
        },
        close() { this.open = false; this.send(); },
        send() {
            if (this.sent) return;
            this.sent = true;
            Promise.resolve(coreXRolloutPost(url, { wave_ids: waveIds }, true)).catch((err) => {
                console.warn('[CoreX] Feature rollout pop-up was not recorded as seen — it will reappear.', err?.status ? `HTTP ${err.status}` : err);
            });
        },
    };
}
</script>
@endpush
@endonce
@endif
@endauth
