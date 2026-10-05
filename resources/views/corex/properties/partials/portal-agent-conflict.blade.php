{{-- Portal Agent Mismatch Guard — .ai/specs/portal-agent-mismatch-guard.md §4.
     Shown inside the P24 / PP syndication panel whenever a send was stopped on an
     agent problem (or would be). The two buttons ARE the "ask first" Johan chose:
     nothing switches the portal's agent until someone presses Yes. Rendered
     inside an Alpine component that spreads corexAgentConflictMixin(). --}}
<div x-show="enabled && agentConflict" x-cloak
     class="rounded-md px-3 py-2.5 space-y-2"
     style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent);">
    <p class="text-xs font-semibold m-0" style="color:var(--ds-amber);" x-text="agentConflict ? agentConflict.message : ''"></p>
    @unless($synReadOnly)
    <div x-show="agentConflict && agentConflict.can_switch" class="flex flex-wrap gap-2">
        <button type="button" @click.stop="confirmAgentSwitch()" :disabled="loading"
                class="flex-1 px-2.5 py-1.5 rounded-md text-[11px] font-semibold transition-opacity hover:opacity-85"
                style="background:var(--brand-button); color:#fff;"
                x-text="'Yes, send under ' + (agentConflict && agentConflict.listing_agents ? agentConflict.listing_agents.join(' and ') : 'the listing agent')"></button>
        <button type="button" @click.stop="agentConflict = null" :disabled="loading"
                class="px-2.5 py-1.5 rounded-md text-[11px] font-semibold"
                style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
            Cancel
        </button>
    </div>
    @endunless
</div>
