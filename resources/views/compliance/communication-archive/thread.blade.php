@extends('layouts.corex-app')

@section('corex-content')
@php
    // AT-137 — context-aware Back.
    $backRoute = isset($backContact) && $backContact
        ? route('corex.contacts.show', $backContact->id)
        : route('compliance.comm-archive.index');
    $backLabel = isset($backContact) && $backContact
        ? (trim(($backContact->first_name ?? '').' '.($backContact->last_name ?? '')) ?: 'Contact')
        : 'Communication Archive';
    $olderUrl  = route('api.v1.communications.threads.older',  ['threadKey' => $threadKey]);
    $searchUrl = route('api.v1.communications.threads.search', ['threadKey' => $threadKey]);
@endphp
<div class="-m-4 lg:-m-6"
     x-data="waThread({
        olderUrl: @js($olderUrl),
        searchUrl: @js($searchUrl),
        hasMore: @js((bool) ($hasMore ?? false)),
        cursor: @js($olderCursor ?? null),
        total: @js((int) ($total ?? 0)),
     })"
     x-init="init()">
    {{-- AT-168 defect fix — the page header is NOT sticky on this page: it and the
         toolbar below both defaulted to `sticky top-0` inside the scrolling <main>,
         and the header (z-30) then rendered OVER the toolbar (z-10), clipping it.
         The toolbar is the only sticky control here, so the header scrolls away and
         the toolbar always stays fully visible. --}}
    <x-page-header title="Conversation Thread" :back-route="$backRoute" :back-label="$backLabel" :flush="true" :sticky="false" />

    {{-- AT-168 Part C — thread toolbar: in-thread search (jump + highlight),
         oldest/newest jump, date jump. The ONLY sticky element on the page (see
         above), so nothing overlaps it. Inline z-index (critical layering — a
         Tailwind arbitrary z class wouldn't compile on a blade-only deploy). --}}
    <div class="px-4 lg:px-6 py-2 sticky flex flex-wrap items-center gap-2"
         style="top:0; z-index:20; background:var(--surface-1,var(--surface,#fff)); border-bottom:1px solid var(--border,#e5e7eb);">
        <div class="flex items-center gap-1">
            <input type="search" placeholder="Search this conversation…" x-model.debounce.300ms="term" @keydown.enter.prevent="runSearch()" @input="if(term.length<2) clearSearch()"
                   class="text-sm rounded px-3 py-1.5" style="background:var(--surface-2,#f0f2f8); color:var(--text-primary,#111827); border:1px solid var(--border,#e5e7eb); min-width:200px;">
            <template x-if="matches.length">
                <div class="flex items-center gap-1 text-xs" style="color:var(--text-secondary,#4b5563);">
                    <button @click="prevMatch()" class="px-2 py-1 rounded" style="border:1px solid var(--border,#e5e7eb);">↑</button>
                    <span x-text="(matchIndex+1)+' / '+matches.length"></span>
                    <button @click="nextMatch()" class="px-2 py-1 rounded" style="border:1px solid var(--border,#e5e7eb);">↓</button>
                </div>
            </template>
            <template x-if="searched && !matches.length">
                <span class="text-xs" style="color:var(--text-muted,#9ca3af);">No matches</span>
            </template>
        </div>

        <div class="flex items-center gap-2 ml-auto text-xs">
            <span style="color:var(--text-muted,#9ca3af);" x-text="loadedCount()+' of '+total"></span>
            <input type="date" x-model="jumpDate" @change="jumpToDate()"
                   class="text-xs rounded px-2 py-1" style="background:var(--surface-2,#f0f2f8); color:var(--text-primary,#111827); border:1px solid var(--border,#e5e7eb);">
            <button @click="jumpOldest()" class="px-2 py-1 rounded font-semibold" style="border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#4b5563);" x-bind:disabled="loading">Oldest</button>
            <button @click="jumpNewest()" class="px-2 py-1 rounded font-semibold" style="border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#4b5563);">Newest</button>
            {{-- AT-182 — open the matched contact's communications tab (new tab). --}}
            @if(!empty($contactId))
            <a href="{{ route('corex.contacts.show', $contactId) }}?tab=communications" target="_blank" rel="noopener"
               class="px-2 py-1 rounded font-semibold" style="border:1px solid var(--border,#e5e7eb); color:var(--brand-icon,#0ea5e9);">Contact</a>
            @endif
        </div>
    </div>

    {{-- Scroll container. Opens scrolled to the NEWEST message; scrolling to the
         top lazy-loads older pages. --}}
    <div x-ref="scroller" class="overflow-y-auto" style="height:calc(100vh - 190px);">
        <div class="p-4 lg:p-6">
            <div class="max-w-3xl mx-auto">
                {{-- Top sentinel + loading state --}}
                <div x-ref="sentinel" class="h-6 flex items-center justify-center">
                    <template x-if="loading"><span class="text-xs" style="color:var(--text-muted,#9ca3af);">Loading earlier messages…</span></template>
                    <template x-if="!hasMore && total"><span class="text-xs" style="color:var(--text-muted,#9ca3af);">Start of conversation</span></template>
                </div>

                <div x-ref="messages" class="space-y-3">
                    @foreach($messages as $m)
                        @include('compliance.communication-archive._thread-bubble', ['m' => $m])
                    @endforeach
                </div>

                @if(($total ?? 0) === 0)
                    <div class="text-center text-sm py-10" style="color:var(--text-muted,#9ca3af);">No messages in this thread.</div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
function waThread(cfg) {
    return {
        olderUrl: cfg.olderUrl,
        searchUrl: cfg.searchUrl,
        hasMore: cfg.hasMore,
        cursor: cfg.cursor,
        total: cfg.total,
        loading: false,
        ready: false,
        term: '',
        matches: [],
        matchIndex: -1,
        searched: false,
        jumpDate: '',

        init() {
            // Open on the NEWEST message (WhatsApp-style), on EVERY entry path.
            this.openAtNewest();
            // Lazy-load older when the top sentinel scrolls into view — but ONLY
            // after the initial scroll-to-newest has settled. Otherwise the sentinel
            // is in view on first paint and auto-loads older pages all the way to the
            // top ("opened at Start of conversation" — the reported defect).
            const obs = new IntersectionObserver((entries) => {
                if (this.ready && entries.some(e => e.isIntersecting)) this.loadOlder();
            }, { root: this.$refs.scroller, threshold: 0.1 });
            obs.observe(this.$refs.sentinel);
        },

        openAtNewest() {
            // Double-rAF so layout (audio players, transcript rows) is settled before
            // we measure scrollHeight; re-assert once more, THEN arm the loader.
            const raf2 = (cb) => requestAnimationFrame(() => requestAnimationFrame(cb));
            raf2(() => {
                this.scrollToBottom();
                raf2(() => { this.scrollToBottom(); this.ready = true; });
            });
        },

        loadedCount() { return this.$refs.messages ? this.$refs.messages.querySelectorAll('.cx-msg').length : 0; },
        scrollToBottom() { const s = this.$refs.scroller; if (s) s.scrollTop = s.scrollHeight; },

        async loadOlder() {
            if (this.loading || !this.hasMore) return false;
            this.loading = true;
            const s = this.$refs.scroller, prevH = s.scrollHeight, prevTop = s.scrollTop;
            try {
                const url = new URL(this.olderUrl, window.location.origin);
                if (this.cursor) { url.searchParams.set('before_at', this.cursor.before_at); url.searchParams.set('before_id', this.cursor.before_id); }
                const r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                const j = await r.json();
                if (j.html) {
                    this.$refs.messages.insertAdjacentHTML('afterbegin', j.html);
                    // Preserve the viewport: keep the previously-top message in place.
                    this.$nextTick(() => { s.scrollTop = s.scrollHeight - prevH + prevTop; });
                }
                this.hasMore = !!j.has_more;
                this.cursor = j.cursor;
            } catch (e) { /* leave hasMore; user can retry by scrolling */ }
            this.loading = false;
            return true;
        },

        async loadAllOlder(guard) {
            let n = 0;
            while (this.hasMore && n < (guard || 500)) { await this.loadOlder(); n++; if (guard === undefined && !this.hasMore) break; }
        },

        async loadUntil(id) {
            let n = 0;
            while (!document.getElementById('msg-' + id) && this.hasMore && n < 500) { await this.loadOlder(); n++; }
            return document.getElementById('msg-' + id);
        },

        async runSearch() {
            this.searched = true; this.matches = []; this.matchIndex = -1;
            const q = this.term.trim();
            if (q.length < 2) return;
            try {
                const url = new URL(this.searchUrl, window.location.origin);
                url.searchParams.set('q', q);
                const r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                const j = await r.json();
                this.matches = j.matches || [];
                if (this.matches.length) this.gotoMatch(0);
            } catch (e) {}
        },
        clearSearch() { this.searched = false; this.matches = []; this.matchIndex = -1; this.clearHighlights(); },

        async gotoMatch(i) {
            if (!this.matches.length) return;
            this.matchIndex = (i + this.matches.length) % this.matches.length;
            const id = this.matches[this.matchIndex].id;
            const el = await this.loadUntil(id);
            this.clearHighlights();
            if (el) {
                el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                const b = el.querySelector('.cx-bubble');
                if (b) { b.style.boxShadow = '0 0 0 2px var(--brand-button, #0ea5e9)'; b.dataset.hit = '1'; }
            }
        },
        nextMatch() { this.gotoMatch(this.matchIndex + 1); },
        prevMatch() { this.gotoMatch(this.matchIndex - 1); },
        clearHighlights() { this.$refs.messages.querySelectorAll('.cx-bubble[data-hit]').forEach(b => { b.style.boxShadow = ''; delete b.dataset.hit; }); },

        async jumpNewest() { this.scrollToBottom(); },
        async jumpOldest() { await this.loadAllOlder(); this.$nextTick(() => { this.$refs.scroller.scrollTop = 0; }); },
        async jumpToDate() {
            if (!this.jumpDate) return;
            // Load older until the oldest loaded message is on/before the target date.
            let n = 0;
            const oldestAt = () => { const f = this.$refs.messages.querySelector('.cx-msg'); return f ? f.dataset.at : null; };
            while (this.hasMore && n < 500) { const oa = oldestAt(); if (oa && oa <= this.jumpDate) break; await this.loadOlder(); n++; }
            // Scroll to the first message on/after the target date.
            const rows = Array.from(this.$refs.messages.querySelectorAll('.cx-msg'));
            const target = rows.find(r => (r.dataset.at || '') >= this.jumpDate) || rows[0];
            if (target) target.scrollIntoView({ block: 'start', behavior: 'smooth' });
        },
    };
}
</script>
@endsection
