@extends('layouts.corex')

@section('corex-content')
<div x-data="commandCentre()" x-init="startAutoRefresh()">
    {{-- Header --}}
    <div class="flex items-end justify-between mb-5 gap-4 flex-wrap">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold leading-tight" style="color:var(--text-primary);">Welcome back, {{ explode(' ', $user->name)[0] }}</h1>
            <p class="text-sm mt-0.5" style="color:var(--text-muted);">{{ now()->format('l, d F Y') }}</p>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <span class="text-xs hidden md:inline" style="color:var(--text-muted);" x-text="lastRefresh"></span>

            <button type="button" @click="refresh()" :disabled="refreshing"
                    class="p-1.5 rounded-md transition-colors"
                    style="background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);" title="Refresh">
                <svg class="w-4 h-4" :class="refreshing && 'animate-spin'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Empty state --}}
    <template x-if="cards.length === 0">
        <div class="rounded-lg p-12 text-center" style="background:var(--surface);border:1px solid var(--border);">
            <div class="text-4xl mb-3" style="color:var(--text-muted);opacity:0.4;">&#10003;</div>
            <h2 class="text-lg font-semibold mb-1" style="color:var(--text-primary);">Nothing pressing</h2>
            <p class="text-sm" style="color:var(--text-muted);">Enjoy your day. All caught up.</p>
        </div>
    </template>

    {{-- ═══════════ GROUPED SECTIONS ═══════════ --}}
    <template x-for="group in groups" :key="group.key">
        <section x-show="group.cards.length > 0" class="mb-6">
            {{-- Section label --}}
            <div class="flex items-center gap-2 mb-3">
                <span class="w-2 h-2 rounded-full" :style="'background:' + group.colour"></span>
                <h2 class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-secondary);" x-text="group.label"></h2>
                <span class="text-xs tabular-nums" style="color:var(--text-muted);" x-text="'· ' + group.cards.length"></span>
                <div class="flex-1 h-px" style="background:var(--border);"></div>
            </div>

            {{-- Card grid: capped at 4 cols, responsive --}}
            <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <template x-for="card in group.cards" :key="card.card_id">
                    <a :href="card.view_all_url || '#'"
                       class="group flex flex-col rounded-lg overflow-hidden transition-all no-underline hover:-translate-y-0.5 hover:shadow-lg h-full"
                       :style="cardStyle(card)"
                       @mouseenter="hoveredCard = card.card_id" @mouseleave="hoveredCard = null">

                        {{-- ─── Compact (disabled — always detailed) ─── --}}
                        <div x-show="false" class="p-4 flex flex-col gap-3 min-h-[140px]">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0"
                                         :style="'background:' + urgencyColour(card.urgency) + '20; color:' + urgencyColour(card.urgency)">
                                        <span x-html="cardIcon(card.icon)" class="w-4 h-4"></span>
                                    </div>
                                    <span class="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded"
                                          :style="'background:' + urgencyColour(card.urgency) + '15; color:' + urgencyColour(card.urgency)"
                                          x-text="urgencyLabel(card.urgency)"></span>
                                </div>
                                <span class="font-bold tabular-nums leading-none text-3xl"
                                      :style="card.count > 0 ? 'color:' + urgencyColour(card.urgency) : 'color:var(--text-muted);opacity:0.35;'"
                                      x-text="card.count > 99 ? '99+' : (card.count || '0')"></span>
                            </div>
                            <div class="mt-auto">
                                <div class="text-sm font-semibold leading-tight truncate" style="color:var(--text-primary);" x-text="card.title"></div>
                                <div class="text-xs mt-1 truncate" style="color:var(--text-muted);" x-text="compactPreview(card)"></div>
                            </div>
                        </div>

                        {{-- ─── Detailed ─── --}}
                        <div class="p-4 flex flex-col flex-1 min-h-[180px]">
                            <div class="flex items-center justify-between mb-3 gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0"
                                         :style="'background:' + urgencyColour(card.urgency) + '20; color:' + urgencyColour(card.urgency)">
                                        <span x-html="cardIcon(card.icon)" class="w-4 h-4"></span>
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-semibold truncate leading-tight" style="color:var(--text-primary);" x-text="card.title"></h3>
                                        <span class="text-[10px] uppercase tracking-wider font-semibold"
                                              :style="'color:' + urgencyColour(card.urgency)"
                                              x-text="urgencyLabel(card.urgency)"></span>
                                    </div>
                                </div>
                                <span x-show="card.count > 0" class="text-2xl font-bold flex-shrink-0 tabular-nums leading-none"
                                      :style="'color:' + urgencyColour(card.urgency)" x-text="card.count > 99 ? '99+' : card.count"></span>
                            </div>

                            {{-- Inline invitation actions --}}
                            <template x-if="card.card_id === 'pending_invitations'">
                                <div class="flex-1 space-y-1.5 text-xs">
                                    <template x-for="item in card.items.slice(0,3)" :key="item.id">
                                        <div>
                                            <div class="truncate font-medium" style="color:var(--text-primary);" x-text="item.title"></div>
                                            <div class="flex items-center gap-1 mt-1">
                                                <button type="button" @click.prevent="respondInvitation(item, 'accepted')" class="text-[10px] px-2 py-0.5 rounded text-white font-medium" style="background:#10b981;">Accept</button>
                                                <button type="button" @click.prevent="respondInvitation(item, 'tentative')" class="text-[10px] px-2 py-0.5 rounded font-medium" style="color:#f59e0b;background:var(--surface);">Tentative</button>
                                                <button type="button" @click.prevent="respondInvitation(item, 'declined')" class="text-[10px] px-2 py-0.5 rounded font-medium" style="color:#ef4444;background:var(--surface);">Decline</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Pipeline / breakdown --}}
                            <template x-if="['active_buyer_pipeline','esign_activity','prospecting_activity','listings_pending_marketing'].includes(card.card_id)">
                                <div class="flex-1 space-y-1.5 text-xs min-w-0">
                                    <template x-for="item in card.items" :key="item.label">
                                        <div class="flex items-center justify-between gap-2 min-w-0">
                                            <div class="flex items-center gap-2 min-w-0 flex-1">
                                                <span class="w-2 h-2 rounded-full flex-shrink-0" :style="'background:' + item.colour"></span>
                                                <span class="truncate" style="color:var(--text-secondary);" x-text="item.label"></span>
                                            </div>
                                            <span class="font-bold tabular-nums flex-shrink-0" style="color:var(--text-primary);" x-text="item.value"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Agency snapshot --}}
                            <template x-if="card.card_id === 'agency_health'">
                                <div class="flex-1 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                    <template x-for="item in card.items" :key="'ah'"><template x-if="true"><div class="contents">
                                        <div><span class="font-bold" style="color:var(--text-primary);" x-text="item.agents"></span> <span style="color:var(--text-muted);">agents</span></div>
                                        <div><span class="font-bold" style="color:var(--text-primary);" x-text="item.listings"></span> <span style="color:var(--text-muted);">listings</span></div>
                                        <div><span class="font-bold" style="color:var(--text-primary);" x-text="item.active_buyers"></span> <span style="color:var(--text-muted);">buyers</span></div>
                                        <div><span class="font-bold" style="color:var(--text-primary);" x-text="item.lost_value_30d"></span> <span style="color:var(--text-muted);">lost</span></div>
                                    </div></template></template>
                                </div>
                            </template>

                            {{-- Generic list --}}
                            <template x-if="!['pending_invitations','active_buyer_pipeline','esign_activity','prospecting_activity','listings_pending_marketing','agency_health'].includes(card.card_id)">
                                <div class="flex-1 space-y-1 text-xs">
                                    <template x-for="(item, idx) in card.items.slice(0, 4)" :key="idx">
                                        <div class="truncate" style="color:var(--text-secondary);" x-text="detailedItemText(card, item)"></div>
                                    </template>
                                    <template x-if="card.items.length > 4"><div class="text-[11px]" style="color:var(--text-muted);" x-text="'+ ' + (card.items.length - 4) + ' more'"></div></template>
                                </div>
                            </template>

                            <div class="mt-auto pt-2 text-xs font-semibold flex items-center gap-1" style="color:var(--brand-button);border-top:1px solid var(--border);">
                                <span>View all</span><span class="group-hover:translate-x-0.5 transition-transform">&rarr;</span>
                            </div>
                        </div>
                    </a>
                </template>
            </div>
        </section>
    </template>

    {{-- Hover tooltip (compact mode) --}}
    <template x-if="hoveredCard && viewMode === 'compact'">
        <div class="fixed z-50 pointer-events-none" :style="tooltipPos()" x-cloak>
            <template x-for="card in cards.filter(c => c.card_id === hoveredCard)" :key="card.card_id">
                <div class="rounded-lg shadow-xl p-3 text-xs max-w-xs" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
                    <div class="font-semibold mb-1.5" x-text="card.title"></div>
                    <template x-for="(item, idx) in card.items.slice(0, 3)" :key="idx">
                        <div class="py-0.5 truncate" style="color:var(--text-secondary);" x-text="tooltipItemText(card, item)"></div>
                    </template>
                    <template x-if="card.items.length > 3"><div class="pt-0.5" style="color:var(--text-muted);" x-text="'+ ' + (card.items.length - 3) + ' more'"></div></template>
                </div>
            </template>
        </div>
    </template>
</div>

<script>
function commandCentre() {
    return {
        cards: @json($cards),
        viewMode: 'detailed',
        refreshing: false,
        lastRefresh: 'Just now',
        hoveredCard: null,
        _refreshTimer: null,
        _mouseX: 0, _mouseY: 0,

        init() {
            document.addEventListener('mousemove', (e) => { this._mouseX = e.clientX; this._mouseY = e.clientY; });
        },

        setView(mode) {
            this.viewMode = mode;
            localStorage.setItem('cc_view', mode);
        },

        // Group cards by urgency for the three-tier layout
        get groups() {
            const by = (urgencies) => this.cards.filter(c => urgencies.includes(c.urgency));
            return [
                { key: 'now',   label: 'Action Required', colour: '#ef4444', cards: by(['critical','high']) },
                { key: 'today', label: 'Today',           colour: '#0ea5e9', cards: by(['medium']) },
                { key: 'fyi',   label: 'Snapshot',        colour: '#64748b', cards: by(['low']) },
            ];
        },

        cardStyle(card) {
            return 'background:var(--surface-2);border:1px solid var(--border);border-top:3px solid ' + this.urgencyColour(card.urgency) + ';';
        },

        urgencyLabel(u) {
            return { critical: 'Now', high: 'Now', medium: 'Today', low: 'FYI' }[u] || 'FYI';
        },

        startAutoRefresh() {
            this._refreshTimer = setInterval(() => this.refresh(), 60000);
        },

        async refresh() {
            this.refreshing = true;
            try {
                const r = await fetch('{{ route("command-center.today.cards") }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    credentials: 'same-origin',
                });
                if (r.ok) {
                    const data = await r.json();
                    this.cards = data.cards;
                    this.lastRefresh = 'Updated ' + new Date().toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
                }
            } catch (e) { console.warn('Refresh failed:', e); }
            this.refreshing = false;
        },

        async respondInvitation(item, action) {
            try {
                const fd = new FormData();
                fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
                fd.append('action', action);
                await fetch(item.respond_url, { method: 'POST', body: fd, credentials: 'same-origin' });
                this.refresh();
            } catch (e) { console.warn('Respond failed:', e); }
        },

        urgencyColour(u) {
            return { critical: '#ef4444', high: '#f59e0b', medium: '#0ea5e9', low: '#64748b' }[u] || '#64748b';
        },

        tooltipPos() {
            const x = Math.min(this._mouseX + 12, window.innerWidth - 280);
            const y = Math.min(this._mouseY + 12, window.innerHeight - 150);
            return 'left:' + x + 'px;top:' + y + 'px;';
        },

        compactPreview(card) {
            if (!card.items || card.items.length === 0) return card.count > 0 ? card.count + ' items' : 'None';
            const first = card.items[0];
            if (card.card_id === 'today_appointments') return first.time + ' ' + first.title;
            if (card.card_id === 'active_buyer_pipeline') return card.items.map(i => i.value + ' ' + i.label.split(' ')[0].toLowerCase()).join(', ');
            if (card.card_id === 'esign_activity') return card.items.map(i => i.value + ' ' + i.label.toLowerCase()).join(' | ');
            if (card.card_id === 'prospecting_activity') return card.items.map(i => i.value + ' ' + i.label.toLowerCase()).join(' | ');
            if (card.card_id === 'listings_pending_marketing') return first.label + ': ' + (first.value || '');
            if (card.card_id === 'agency_health') return (first.agents ?? '') + ' agents, ' + (first.listings ?? '') + ' listings';
            if (card.card_id === 'branch_lost_value') return first.value_display || '';
            if (card.card_id === 'strategic_insights') return first.text ? first.text.slice(0, 50) + '...' : '';
            if (first.title) return first.title;
            if (first.name) return first.name;
            if (first.contact) return first.contact;
            if (first.message) return first.message;
            if (first.label) return first.label + ': ' + (first.value ?? '');
            if (first.text) return first.text.slice(0, 50);
            return card.count + ' items';
        },

        tooltipItemText(card, item) {
            if (item.title) return item.title + (item.status ? ' — ' + item.status : '');
            if (item.name) return item.name + (item.reason ? ' — ' + item.reason : item.issue ? ' — ' + item.issue : '');
            if (item.contact) return item.contact + (item.status ? ' — ' + item.status : '');
            if (item.message) return item.message;
            if (item.label) return item.label + (item.value !== undefined ? ': ' + item.value : '');
            if (item.text) return item.text.slice(0, 60);
            if (item.dates) return item.dates + (item.name ? ' — ' + item.name : '');
            return JSON.stringify(item).slice(0, 60);
        },

        detailedItemText(card, item) { return this.tooltipItemText(card, item); },

        cardIcon(icon) {
            const i = {
                'calendar': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>',
                'mail': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>',
                'alert-triangle': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>',
                'users': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>',
                'activity': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>',
                'home': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>',
                'shield': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>',
                'shield-check': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>',
                'clock': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>',
                'eye': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
                'building': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>',
                'clipboard-check': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75"/></svg>',
                'trending-down': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6 9 12.75l4.286-4.286a11.948 11.948 0 0 1 4.306 6.43l.776 2.898M18.75 19.5l3-3m0 0-3-3m3 3H15"/></svg>',
                'bar-chart': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>',
                'alert-circle': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>',
                'lightbulb': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/></svg>',
                'file-signature': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>',
            };
            return i[icon] || i['clock'];
        },
    };
}
</script>
@endsection
