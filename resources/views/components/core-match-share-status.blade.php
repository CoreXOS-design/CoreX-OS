{{--
    AT-503 — the ONE "Shared … / Send N new" status for a wishlist. Used on the Core Matches
    board and on the senders (match results, buyer detail), so a confirmed send updates it
    in place — no reload.

    Updates arrive on the 'core-match-sent' window event (same page) and on a BroadcastChannel
    of the same name (the board open in ANOTHER tab). Both carry the status the confirm
    endpoint returned: { match_id, never_sent_count, last_shared_at, last_shared_human }.

    Props:
      matchId        int
      status         ?array  initial status (statusFor()) — null = nothing known yet (chip stays
                             hidden until the first send event arrives)
      quickViewUrl   ?string "Send N new" opens the not-yet-sent list (board only); without it the
                             unsent count is plain text
      neverStyle / ringStyle  the board's amber "Never shared" look
--}}
@props(['matchId', 'status' => null, 'quickViewUrl' => null, 'neverStyle' => 'color:var(--ds-amber, #f59e0b);', 'ringStyle' => 'display:inline-block; width:7px; height:7px; border-radius:50%; border:1.5px solid var(--ds-amber, #f59e0b); margin-right:5px; vertical-align:middle;'])
<div x-data="{
        matchId: {{ (int) $matchId }},
        known: {{ $status ? 'true' : 'false' }},
        unsent: {{ (int) ($status['never_sent_count'] ?? 0) }},
        lastIso: @js($status['last_shared_at'] ?? null),
        lastHuman: @js($status['last_shared_human'] ?? null),
        apply(s) {
            if (!s || Number(s.match_id) !== this.matchId) return;
            this.known = true;
            this.unsent = Number(s.never_sent_count) || 0;
            this.lastIso = s.last_shared_at;
            this.lastHuman = s.last_shared_human;
        },
        init() {
            try {
                if ('BroadcastChannel' in window) {
                    const ch = new BroadcastChannel('core-match-sent');
                    ch.onmessage = (e) => this.apply(e.data);
                }
            } catch (e) {}
        },
    }"
    @core-match-sent.window="apply($event.detail)"
    x-show="known" @if(!$status) x-cloak @endif
    {{ $attributes->merge(['class' => 'flex items-center gap-2 text-xs']) }} style="color:var(--text-secondary);" data-testid="core-match-share-status">
    {{-- Always-present elements, server-rendered for the first paint; Alpine only toggles/updates them. --}}
    <span x-show="!lastIso" class="font-medium" style="{{ $neverStyle }}@if($status && ($status['last_shared_at'] ?? null)) display:none; @endif"><span style="{{ $ringStyle }}"></span>Never shared</span>
    <span x-show="lastIso" :title="'Last shared ' + lastIso" x-text="'Shared ' + lastHuman" @if(!($status['last_shared_at'] ?? null)) style="display:none;" @endif>@if($status['last_shared_at'] ?? null)Shared {{ $status['last_shared_human'] }}@endif</span>
    @if($quickViewUrl)
    <button type="button" x-show="lastIso && unsent > 0" @click="$dispatch('open-new-since-share', { url: @js($quickViewUrl) })"
            class="text-xs font-semibold border-0 bg-transparent cursor-pointer p-0" style="color:var(--brand-icon,#0ea5e9);@if(!(($status['last_shared_at'] ?? null) && ($status['never_sent_count'] ?? 0) > 0)) display:none;@endif"
            x-text="'Send ' + unsent + ' new'" data-testid="core-match-send-new">@if(($status['last_shared_at'] ?? null) && ($status['never_sent_count'] ?? 0) > 0)Send {{ (int) $status['never_sent_count'] }} new @endif</button>
    @else
    <span x-show="lastIso && unsent > 0" class="font-semibold" style="color:var(--brand-icon,#0ea5e9);@if(!(($status['last_shared_at'] ?? null) && ($status['never_sent_count'] ?? 0) > 0)) display:none;@endif"
          x-text="unsent + ' new, not yet sent'" data-testid="core-match-send-new">@if(($status['last_shared_at'] ?? null) && ($status['never_sent_count'] ?? 0) > 0){{ (int) $status['never_sent_count'] }} new, not yet sent @endif</span>
    @endif
</div>
