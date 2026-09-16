{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- Today board — DAY TIMELINE layout (2026-09-13, Johan picked option 3 of 5).
     Spec: .ai/specs/spec-command-center.md → "Today page — Day timeline layout".

     The page never scrolls on desktop: the board fills the content area (root is
     h-full / min-h-0 at lg+) and every region scrolls INSIDE itself —
       • LEFT  (7/12): today's appointments on an hour-by-hour grid with a live
                       "now" line; all-day items as chips above; tomorrow below.
       • RIGHT (5/12): every other card as a compact <x-tile> queue, most urgent
                       first, in a rail that scrolls on its own.
       • BOTTOM strip: the number cards (website, compliance, snapshot) as plain
                       figures so they never compete with the queues for height.
     Below lg the three regions stack and the page scrolls normally (mobile). --}}
<div x-data="commandCentre()" x-init="startAutoRefresh()" data-tour="cc-today-board"
     class="w-full flex flex-col lg:h-full lg:min-h-0">
    {{-- Page header — flat neutral bar (AT-336). Type scale matches /worksheet:
         16px bold title + 12px muted subtitle, actions in the right cluster at 12px. --}}
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0" data-tour="cc-today-header">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0" data-tour="cc-today-greeting">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Welcome back, {{ explode(' ', $user->name)[0] }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">{{ now()->format('l, d F Y') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 flex-shrink-0">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <span class="text-xs hidden md:inline" style="color: var(--text-muted);" x-text="lastRefresh"></span>
                <button type="button" @click="refresh()" :disabled="refreshing"
                        data-tour="cc-today-refresh"
                        class="corex-btn-outline text-xs disabled:opacity-40 disabled:cursor-not-allowed inline-flex items-center gap-2"
                        title="Refresh">
                    <svg class="w-4 h-4" :class="refreshing && 'animate-spin'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                    </svg>
                    <span>Refresh</span>
                </button>
                @permission('access_settings')
                <a href="{{ url('/corex/settings?s=command-center') }}"
                   title="Command Center Settings"
                   aria-label="Command Center Settings"
                   class="inline-flex items-center justify-center rounded-md transition-colors"
                   style="width:32px; height:32px; background: transparent; border: 1px solid var(--border); color: var(--text-secondary);"
                   onmouseover="this.style.background='var(--surface-2)'; this.style.color='var(--text-primary)';"
                   onmouseout="this.style.background='transparent'; this.style.color='var(--text-secondary)';">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- Empty state --}}
    <template x-if="cards.length === 0">
        <div class="rounded-md py-12 px-6 text-center mt-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Nothing pressing</h3>
            <p class="text-sm" style="color: var(--text-muted);">Enjoy your day. All caught up.</p>
        </div>
    </template>

    {{-- ═══════════ DAY BOARD ═══════════ --}}
    <div x-show="cards.length > 0" class="flex-1 min-h-0 flex flex-col gap-3 pt-4 lg:pt-5">
        <div class="flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-12 gap-4">

            {{-- ── LEFT: TODAY TIMELINE ── --}}
            <section class="lg:col-span-7 flex flex-col min-h-0 rounded-md overflow-hidden h-[28rem] lg:h-auto"
                     data-tour="cc-today-timeline"
                     style="background: var(--surface); border: 1px solid var(--border); border-top: 3px solid var(--brand-icon); box-shadow: 0 4px 12px rgba(0,0,0,0.30);">
                {{-- Header — same anatomy as the full tile header --}}
                <div class="flex items-center justify-between gap-3 px-4 pt-3.5 pb-2.5 flex-shrink-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0"
                             style="background: color-mix(in srgb, var(--brand-icon) 15%, transparent); color: var(--brand-icon);">
                            <svg class="w-[1.125rem] h-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold leading-tight" style="color: var(--text-primary);">Today</h2>
                            <p class="text-[0.6875rem] truncate" style="color: var(--text-muted);" x-text="scheduleSubline"></p>
                        </div>
                    </div>
                    <template x-if="scheduleCard && scheduleCard.view_all_url">
                        <a :href="scheduleCard.view_all_url" class="corex-btn-outline text-xs inline-flex items-center gap-1.5 flex-shrink-0 no-underline">
                            <span>Open calendar</span>
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                    </template>
                </div>

                {{-- All-day items — chips, never blocks on the grid --}}
                <div x-show="todayAllDay.length > 0" class="flex flex-wrap items-center gap-1.5 px-4 pb-2 flex-shrink-0">
                    <span class="text-[0.625rem] font-semibold uppercase tracking-wider mr-1" style="color: var(--text-faint, var(--text-muted));">All day</span>
                    <template x-for="item in todayAllDay" :key="'ad' + item.id">
                        <a :href="dayUrl(item)" class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium no-underline max-w-full"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                            <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="'background:' + item.colour"></span>
                            <span class="truncate" x-text="item.title"></span>
                        </a>
                    </template>
                </div>

                {{-- Hour grid — the ONLY scrolling region of the timeline card --}}
                <div x-ref="grid" class="flex-1 min-h-0 overflow-y-auto corex-tile-scroll relative mx-4 mb-2 pt-2.5 pb-1">
                    <div class="relative" :style="'height: max(calc(100% - 0.875rem), ' + (dayBounds.hours * 52) + 'px);'">
                        {{-- Hour rows --}}
                        <template x-for="(h, i) in hourLabels" :key="'h' + h">
                            <div class="absolute left-0 right-0 flex items-start gap-3" :style="'top:' + (i / dayBounds.hours * 100) + '%; height:' + (100 / dayBounds.hours) + '%;'">
                                <span class="w-10 flex-shrink-0 text-[0.6875rem] font-mono tabular-nums -mt-[7px]" style="color: var(--text-faint, var(--text-muted));" x-text="h"></span>
                                <span class="flex-1 h-px" style="background: var(--border);"></span>
                            </div>
                        </template>

                        {{-- Appointment blocks (lane-packed so overlaps sit side by side) --}}
                        <template x-for="b in blocks" :key="'b' + b.id">
                            <a :href="dayUrl(b)"
                               class="absolute rounded-md px-2.5 py-1.5 flex flex-col gap-0.5 overflow-hidden no-underline transition-colors"
                               :style="'top:' + b.top + '%; height:' + b.height + '%; left: calc(3.25rem + ' + b.left + '% - ' + (b.left / 100 * 3.25) + 'rem); width: calc(' + b.width + '% - ' + (b.width / 100 * 3.25) + 'rem - 4px); border: 1px solid var(--border); border-left: 3px solid ' + b.colour + '; background: color-mix(in srgb, ' + b.colour + ' 10%, var(--surface-2));'"
                               :title="b.title">
                                <span class="text-xs font-semibold truncate" style="color: var(--text-primary);" x-text="b.title"></span>
                                <span class="text-[0.6875rem] truncate" style="color: var(--text-muted);">
                                    <span class="font-mono tabular-nums" x-text="b.range"></span><span x-show="b.category" x-text="' · ' + b.category"></span>
                                </span>
                            </a>
                        </template>

                        {{-- Nothing scheduled — the grid stays so the day still has shape --}}
                        <div x-show="blocks.length === 0" class="absolute inset-0 flex items-center justify-center pointer-events-none">
                            <span class="text-xs rounded-md px-3 py-1.5" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">Nothing scheduled today</span>
                        </div>

                        {{-- Now line --}}
                        <template x-if="nowPct !== null">
                            <div class="absolute left-0 right-0 flex items-center pointer-events-none" :style="'top:' + nowPct + '%;'">
                                <span class="w-10 flex-shrink-0 text-[0.6875rem] font-mono font-semibold tabular-nums -mt-px" style="color: var(--brand-icon);" x-text="nowLabel"></span>
                                <span class="w-2 h-2 rounded-full flex-shrink-0 -ml-1" style="background: var(--brand-icon);"></span>
                                <span class="flex-1 h-px" style="background: var(--brand-icon);"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Tomorrow — the card already carries tomorrow's first appointments; keep them visible --}}
                <div x-show="tomorrowItems.length > 0" class="flex-shrink-0 px-4 pt-2 pb-3" style="border-top: 1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="text-[0.625rem] font-semibold uppercase tracking-wider" style="color: var(--text-faint, var(--text-muted));">Tomorrow</span>
                        <span class="text-[0.625rem] tabular-nums" style="color: var(--text-muted);" x-text="'· ' + tomorrowItems.length"></span>
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        <template x-for="item in tomorrowItems" :key="'tm' + item.id">
                            <a :href="dayUrl(item)" class="inline-flex items-center gap-2 text-xs no-underline min-w-0" style="color: var(--text-secondary);">
                                <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="'background:' + item.colour"></span>
                                <span class="font-mono tabular-nums" style="color: var(--text-muted);" x-text="item.all_day ? 'All day' : item.time"></span>
                                <span class="truncate" x-text="item.title"></span>
                            </a>
                        </template>
                    </div>
                </div>
            </section>

            {{-- ── RIGHT: QUEUES RAIL ── every non-schedule card, most urgent first --}}
            <aside class="lg:col-span-5 flex flex-col gap-3 min-h-0 lg:overflow-y-auto corex-tile-scroll lg:pr-1" data-tour="cc-today-rail">
                <template x-for="card in railCards" :key="card.card_id">
                    <div class="flex-shrink-0">
                        {{-- AT-164 Gate 3 — unified <x-tile> shell (shared with the Calendar Deck),
                             compact variant: one-line header so the CONTENT LIST gets the height. --}}
                        <x-tile :var="'card'" :compact="true" />
                    </div>
                </template>
                <div x-show="railCards.length === 0" class="rounded-md py-8 px-4 text-center flex-shrink-0" style="background: var(--surface); border: 1px solid var(--border);">
                    <svg class="w-5 h-5 mx-auto mb-1.5" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    <p class="text-sm font-medium" style="color: var(--text-primary);">Nothing waiting on you</p>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);">Your queues are clear.</p>
                </div>
            </aside>
        </div>

        {{-- ── BOTTOM: NUMBERS STRIP ── the snapshot cards as plain figures --}}
        <div x-show="stripCards.length > 0" data-tour="cc-today-strip"
             class="flex-shrink-0 rounded-md px-4 py-2.5 flex flex-wrap items-center gap-x-7 gap-y-2"
             style="background: var(--surface); border: 1px solid var(--border);">
            <template x-for="card in stripCards" :key="'s' + card.card_id">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1 min-w-0">
                    <a :href="card.view_all_url || '#'" class="text-[0.625rem] font-semibold uppercase tracking-wider whitespace-nowrap no-underline"
                       style="color: var(--text-faint, var(--text-muted));" x-text="card.title"></a>
                    <template x-for="(s, i) in stripStats(card)" :key="'ss' + card.card_id + i">
                        <span class="inline-flex items-baseline gap-1.5 whitespace-nowrap">
                            <span class="text-sm font-bold tabular-nums" :style="'color:' + (s.critical ? 'var(--ds-crimson)' : 'var(--text-primary)')" x-text="s.value"></span>
                            <span class="text-[0.6875rem]" style="color: var(--text-muted);" x-text="s.label"></span>
                        </span>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function commandCentre() {
    const URGENCY_RANK = { critical: 0, high: 1, medium: 2, low: 3 };
    // Cards that are FIGURES, not queues — they live in the bottom strip unless
    // one of them turns critical (an expired FFC is a queue item, not a stat).
    const STRIP_IDS = ['website_performance', 'my_compliance', 'agency_health', 'branch_lost_value', 'branch_compliance'];
    const toMin = (hhmm) => { const [h, m] = String(hhmm || '00:00').split(':').map(Number); return (h * 60) + (m || 0); };
    const pad = (n) => String(n).padStart(2, '0');

    return {
        cards: @json($cards),
        refreshing: false,
        lastRefresh: 'Just now',
        _refreshTimer: null,
        _clockTimer: null,
        // Server clock at render (app timezone) + client elapsed → the now line never
        // trusts a mis-set laptop clock for the DAY, only for the elapsed seconds.
        _serverNowMin: {{ (int) now()->format('G') * 60 + (int) now()->format('i') }},
        _loadedAt: Date.now(),
        _tick: 0,

        init() {
            this.$nextTick(() => this.scrollToNow());
        },

        // ── Schedule ──────────────────────────────────────────────────────
        get scheduleCard() { return this.cards.find(c => c.card_id === 'today_appointments') || null; },
        get scheduleItems() { return (this.scheduleCard && Array.isArray(this.scheduleCard.items)) ? this.scheduleCard.items : []; },
        get todayTimed()   { return this.scheduleItems.filter(i => i.date_label === 'Today' && !i.all_day); },
        get todayAllDay()  { return this.scheduleItems.filter(i => i.date_label === 'Today' && i.all_day); },
        get tomorrowItems(){ return this.scheduleItems.filter(i => i.date_label === 'Tomorrow'); },

        /* Working-day window 08:00–18:00, widened to hold any appointment outside it. */
        get dayBounds() {
            let start = 8 * 60, end = 18 * 60;
            for (const i of this.todayTimed) {
                const s = toMin(i.time);
                let e = i.end_time ? toMin(i.end_time) : s + 60;
                if (e <= s) e = s + 60;                 // end before start / crosses midnight → one hour
                start = Math.min(start, Math.floor(s / 60) * 60);
                end   = Math.max(end, Math.min(24 * 60, Math.ceil(e / 60) * 60));
            }
            return { start, end, hours: Math.max(1, (end - start) / 60) };
        },
        get hourLabels() {
            const out = [];
            for (let m = this.dayBounds.start; m < this.dayBounds.end; m += 60) out.push(pad(m / 60) + ':00');
            return out;
        },
        /* Blocks in % of the grid; overlapping appointments are packed into lanes. */
        get blocks() {
            const { start, end } = this.dayBounds;
            const total = end - start;
            const items = this.todayTimed.map(i => {
                const s = toMin(i.time);
                let e = i.end_time ? toMin(i.end_time) : s + 60;
                if (e <= s) e = s + 60;
                return { ...i, s, e: Math.min(e, end) };
            }).sort((a, b) => a.s - b.s || a.e - b.e);

            // Lane packing PER OVERLAP GROUP: an appointment that overlaps nothing
            // takes the full width; only the ones that clash share it.
            let lanes = [];                            // lanes[k] = end minute of the last block in lane k
            let group = [];                            // indexes of the current overlap group
            let groupEnd = -1;
            const laneOf = [], lanesOf = [];
            const closeGroup = () => { group.forEach(i => { lanesOf[i] = Math.max(1, lanes.length); }); lanes = []; group = []; };
            items.forEach((it, idx) => {
                if (group.length && it.s >= groupEnd) closeGroup();
                let k = lanes.findIndex(lastEnd => lastEnd <= it.s);
                if (k === -1) { k = lanes.length; lanes.push(0); }
                lanes[k] = it.e; laneOf[idx] = k;
                group.push(idx); groupEnd = Math.max(groupEnd, it.e);
            });
            closeGroup();
            return items.map((it, idx) => ({
                ...it,
                top:    ((it.s - start) / total) * 100,
                height: (Math.max(30, it.e - it.s) / total) * 100,
                left:   (laneOf[idx] / lanesOf[idx]) * 100,
                width:  (1 / lanesOf[idx]) * 100,
                range:  it.time + (it.end_time ? ' – ' + it.end_time : ''),
                colour: it.colour || 'var(--brand-icon)',
            }));
        },
        get nowMin() {
            void this._tick;                           // re-evaluate every clock tick
            return this._serverNowMin + Math.floor((Date.now() - this._loadedAt) / 60000);
        },
        get nowPct() {
            const { start, end } = this.dayBounds;
            const n = this.nowMin;
            if (n < start || n > end) return null;
            return ((n - start) / (end - start)) * 100;
        },
        get nowLabel() { const n = this.nowMin; return pad(Math.floor(n / 60) % 24) + ':' + pad(n % 60); },
        get scheduleSubline() {
            const n = this.todayTimed.length + this.todayAllDay.length;
            const next = this.todayTimed.find(i => toMin(i.time) > this.nowMin);
            const head = n === 0 ? 'No appointments today' : (n + (n === 1 ? ' appointment' : ' appointments') + ' today');
            return next ? head + ' · next at ' + next.time : head;
        },
        dayUrl(item) {
            const base = (this.scheduleCard && this.scheduleCard.view_all_url) ? this.scheduleCard.view_all_url : '{{ route('command-center.calendar') }}';
            return base + (base.includes('?') ? '&' : '?') + 'view=day&date=' + encodeURIComponent(item.date || '');
        },
        scrollToNow() {
            const el = this.$refs.grid;
            if (!el || this.nowPct === null) return;
            const inner = el.firstElementChild;
            const px = (this.nowPct / 100) * (inner ? inner.offsetHeight : el.scrollHeight);
            el.scrollTop = Math.max(0, px - el.clientHeight * 0.3);
        },

        // ── Rail + strip ──────────────────────────────────────────────────
        get stripCards() {
            return this.cards.filter(c => STRIP_IDS.includes(c.card_id) && c.urgency !== 'critical' && this.stripStats(c).length > 0);
        },
        get railCards() {
            const strip = new Set(this.stripCards.map(c => c.card_id));
            const hasItems = (c) => Array.isArray(c.items) && c.items.length > 0;
            return this.cards
                .filter(c => c.card_id !== 'today_appointments' && !strip.has(c.card_id))
                // Andre, 2026-09-13: Recent Activity is not shown on Today; Strategic
                // Insights only when it actually has something to say. Page-level only —
                // the mobile API and the Calendar deck keep receiving both cards.
                .filter(c => c.card_id !== 'recent_activity')
                .filter(c => c.card_id !== 'strategic_insights' || hasItems(c))
                .slice()
                .sort((a, b) => (URGENCY_RANK[a.urgency] ?? 9) - (URGENCY_RANK[b.urgency] ?? 9));
        },
        /* Plain figures for a strip card. Shapes mirror the tile's bespoke bodies. */
        stripStats(card) {
            const items = Array.isArray(card.items) ? card.items : [];
            if (card.card_id === 'agency_health') {
                const i = items[0] || {};
                return [
                    { label: 'agents',   value: i.agents },
                    { label: 'listings', value: i.listings },
                    { label: 'buyers',   value: i.active_buyers },
                    { label: 'lost 30d', value: i.lost_value_30d },
                ].filter(s => s.value !== undefined && s.value !== null);
            }
            if (card.card_id === 'branch_lost_value') {
                const i = items[0] || {};
                return i.value_display ? [{ label: 'lost value 30d', value: i.value_display }] : [];
            }
            return items
                .filter(i => i.label !== undefined && i.value !== undefined && i.url === undefined)
                .slice(0, 4)
                .map(i => ({ label: i.label, value: i.value, critical: !!i.critical }));
        },

        // ── Refresh ───────────────────────────────────────────────────────
        startAutoRefresh() {
            this._refreshTimer = setInterval(() => this.refresh(), 60000);
            this._clockTimer   = setInterval(() => { this._tick++; }, 30000);
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
    };
}
</script>
@endsection
