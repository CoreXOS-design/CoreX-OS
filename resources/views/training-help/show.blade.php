@extends('layouts.corex')

@section('corex-content')
<div class="flex gap-0 min-h-[calc(100vh-4rem)]" x-data="trainingViewer()" x-init="initScrollSpy()">

    {{-- LEFT SIDEBAR --}}
    <aside class="w-72 flex-shrink-0 sticky top-0 h-[calc(100vh-4rem)] overflow-y-auto px-4 py-5 hidden lg:block"
           style="background:var(--surface); border-right:1px solid var(--border);">
        <a href="{{ route('training-help.index') }}" class="flex items-center gap-2 text-xs font-medium mb-4 hover:underline" style="color:var(--brand-icon);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            Back to Training
        </a>

        <h2 class="text-sm font-bold leading-tight mb-1" style="color:var(--text-primary);">{{ $doc->title }}</h2>
        <div class="text-xs mb-2" style="color:var(--text-muted);">For: {{ ucwords(str_replace('_', ' ', $doc->role_audience)) }}</div>
        <div class="text-xs mb-3" style="color:var(--text-muted);">{{ $doc->reading_time }} min read &middot; v{{ $doc->version }}</div>

        {{-- Progress --}}
        @php $progress = $doc->getProgressForUser(auth()->id()); @endphp
        <div class="mb-4">
            <div class="w-full h-1.5 rounded-full overflow-hidden" style="background:var(--surface-2);">
                <div class="h-full rounded-full transition-all" id="sidebar-progress-bar"
                     style="width:{{ $progress }}%; background:{{ $read->completed_at && !$read->is_outdated_since ? 'var(--ds-emerald)' : 'var(--brand-icon)' }};"></div>
            </div>
            <div class="text-[11px] mt-1" style="color:var(--text-muted);"><span id="sidebar-progress-text">{{ $progress }}%</span> complete</div>
        </div>

        {{-- Bookmarks --}}
        @if($bookmarks->isNotEmpty())
        <div class="mb-4">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color:var(--text-muted);">Your Bookmarks</div>
            @foreach($bookmarks as $anchor => $bm)
            <a href="#{{ $anchor }}" class="block text-xs py-1 px-2 rounded hover:opacity-80 truncate" style="color:var(--brand-icon);">
                {{ $anchor }}
            </a>
            @endforeach
        </div>
        @endif

        {{-- TOC --}}
        <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color:var(--text-muted);">Contents</div>
        <nav class="space-y-0.5">
            @foreach($toc as $tocItem)
            @php
                $isRead = in_array($tocItem->section_anchor, $sectionsCompleted);
                $depth = substr_count($tocItem->heading_path ?? '', ' > ');
            @endphp
            <a href="#{{ $tocItem->section_anchor }}"
               class="toc-link block py-1 rounded text-xs transition-colors truncate"
               style="padding-left:{{ 8 + ($depth * 12) }}px; color:var(--text-secondary);"
               :class="{ 'font-semibold': activeSection === '{{ $tocItem->section_anchor }}' }"
               :style="activeSection === '{{ $tocItem->section_anchor }}' ? 'color:var(--brand-icon); background:color-mix(in srgb, var(--brand-icon) 10%, transparent);' : ''"
               data-anchor="{{ $tocItem->section_anchor }}">
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 inline-flex items-center justify-center flex-shrink-0 rounded-full text-[8px]"
                          :style="sectionsDone.includes('{{ $tocItem->section_anchor }}') ? 'background:var(--ds-emerald); color:#fff;' : 'border:1px solid var(--border);'"
                          id="toc-tick-{{ $tocItem->section_anchor }}">
                        <template x-if="sectionsDone.includes('{{ $tocItem->section_anchor }}')">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-2 h-2"><path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd" /></svg>
                        </template>
                    </span>
                    <span class="truncate">{{ $tocItem->heading_path ? last(explode(' > ', $tocItem->heading_path)) : $tocItem->section_anchor }}</span>
                </span>
            </a>
            @endforeach
        </nav>

        {{-- PDF download --}}
        <div class="mt-6">
            <a href="{{ route('training-help.pdf', $doc->slug) }}"
               class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium transition-colors w-full justify-center"
               style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Download PDF
            </a>
        </div>
    </aside>

    {{-- MAIN CONTENT --}}
    <main class="flex-1 min-w-0 px-4 sm:px-8 py-6 max-w-4xl">
        {{-- Outdated banner --}}
        @if($read->is_outdated_since)
        <div class="mb-6 px-4 py-3 rounded-lg flex items-center justify-between gap-3"
             style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
            <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" style="color:var(--ds-amber);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                <span class="text-sm" style="color:var(--text-primary);">This document was updated on {{ $read->is_outdated_since->format('j M Y') }}. Sections you previously marked as read may need re-reviewing.</span>
            </div>
            <button @click="markRereviewed()" class="flex-shrink-0 px-3 py-1.5 rounded text-xs font-medium"
                    style="background:var(--brand-icon); color:#fff;">Mark all re-reviewed</button>
        </div>
        @endif

        {{-- Rendered Markdown Content --}}
        <article class="training-doc-content prose prose-sm dark:prose-invert max-w-none"
                 style="--tw-prose-body:var(--text-primary); --tw-prose-headings:var(--text-primary); --tw-prose-links:var(--brand-icon); --tw-prose-bold:var(--text-primary); --tw-prose-code:var(--text-primary); --tw-prose-th-borders:var(--border); --tw-prose-td-borders:var(--border);">
            {!! $renderedContent !!}
        </article>
    </main>
</div>

<style>
    /* Section action buttons injected via JS */
    .section-actions { display: inline-flex; gap: 0.5rem; margin-left: 0.75rem; vertical-align: middle; }
    .section-actions button { padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; cursor: pointer; transition: all 0.15s; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary); }
    .section-actions button:hover { background: var(--brand-icon); color: #fff; border-color: var(--brand-icon); }
    .section-actions button.is-done { background: var(--ds-emerald, #10b981); color: #fff; border-color: var(--ds-emerald, #10b981); }
    .section-actions button.is-bookmarked { color: var(--brand-icon); border-color: var(--brand-icon); }
    /* Screenshot placeholder styling */
    .training-doc-content p:has(> strong:first-child:contains("[SCREENSHOT")),
    .screenshot-placeholder { padding: 1rem 1.25rem; border-radius: 0.5rem; margin: 1rem 0; font-size: 0.8125rem; font-style: italic; background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, transparent); border: 1px dashed color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, transparent); color: var(--text-muted); }
</style>

<script>
function trainingViewer() {
    return {
        activeSection: '',
        sectionsDone: @json($sectionsCompleted),
        bookmarked: @json($bookmarks->keys()->toArray()),

        initScrollSpy() {
            // Add section IDs and action buttons to H2 elements
            this.$nextTick(() => {
                const headings = document.querySelectorAll('.training-doc-content h2');
                headings.forEach(h2 => {
                    const text = h2.textContent.trim();
                    const slug = text.toLowerCase().replace(/[^\w\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-');
                    h2.id = slug;

                    // Add action buttons
                    const actions = document.createElement('span');
                    actions.className = 'section-actions';

                    const readBtn = document.createElement('button');
                    readBtn.textContent = this.sectionsDone.includes(slug) ? 'Read' : 'Mark read';
                    if (this.sectionsDone.includes(slug)) readBtn.classList.add('is-done');
                    readBtn.onclick = () => this.markSectionRead(slug, readBtn);
                    actions.appendChild(readBtn);

                    const bmBtn = document.createElement('button');
                    bmBtn.innerHTML = this.bookmarked.includes(slug) ? '&#9733;' : '&#9734;';
                    if (this.bookmarked.includes(slug)) bmBtn.classList.add('is-bookmarked');
                    bmBtn.onclick = () => this.toggleBookmark(slug, bmBtn);
                    actions.appendChild(bmBtn);

                    h2.appendChild(actions);
                });

                // Style [SCREENSHOT: ...] placeholders
                document.querySelectorAll('.training-doc-content p').forEach(p => {
                    if (p.textContent.includes('[SCREENSHOT:')) {
                        p.classList.add('screenshot-placeholder');
                    }
                });

                // IntersectionObserver for TOC highlighting
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            this.activeSection = entry.target.id;
                        }
                    });
                }, { rootMargin: '-10% 0px -80% 0px' });

                headings.forEach(h2 => observer.observe(h2));
            });
        },

        async markSectionRead(anchor, btn) {
            // Optimistic update
            if (!this.sectionsDone.includes(anchor)) {
                this.sectionsDone.push(anchor);
            }
            btn.textContent = 'Read';
            btn.classList.add('is-done');

            try {
                const res = await fetch(`{{ route('training-help.read', $doc->slug) }}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ section: anchor })
                });
                const data = await res.json();
                if (data.progress !== undefined) {
                    document.getElementById('sidebar-progress-bar').style.width = data.progress + '%';
                    document.getElementById('sidebar-progress-text').textContent = data.progress + '%';
                }
            } catch (e) { console.error(e); }
        },

        async toggleBookmark(anchor, btn) {
            if (this.bookmarked.includes(anchor)) {
                // Remove — we'd need the bookmark ID; for simplicity, just toggle visually
                this.bookmarked = this.bookmarked.filter(a => a !== anchor);
                btn.innerHTML = '&#9734;';
                btn.classList.remove('is-bookmarked');
            } else {
                this.bookmarked.push(anchor);
                btn.innerHTML = '&#9733;';
                btn.classList.add('is-bookmarked');

                await fetch(`{{ route('training-help.bookmark', $doc->slug) }}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ section: anchor })
                });
            }
        },

        async markRereviewed() {
            await fetch(`{{ route('training-help.rereviewed', $doc->slug) }}`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            location.reload();
        }
    };
}
</script>
@endsection
