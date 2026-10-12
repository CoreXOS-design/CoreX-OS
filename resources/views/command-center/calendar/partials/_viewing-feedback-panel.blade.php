{{-- Captured viewing feedback per property (R2). Agent-side: carries the internal comment.
     Editing / archiving is offered only when the server says can_edit (permission + scope). --}}
<template x-if="panelData.viewing_feedback && panelData.viewing_feedback.properties && panelData.viewing_feedback.properties.length > 0">
    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);" data-testid="viewing-feedback-panel">
        <div class="flex items-center justify-between mb-1.5">
            <div class="text-[10px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Feedback captured</div>
            <template x-if="panelData.viewing_feedback.can_edit && panelData.is_past">
                <button type="button" @click="openFeedbackModal(panelData.id)"
                        class="text-[11px] font-medium hover:underline" style="color: var(--brand-button);">Edit feedback &rarr;</button>
            </template>
        </div>
        <template x-if="!panelData.viewing_feedback.can_edit">
            <p class="text-[10px] mb-2" style="color: var(--text-muted);">Read only - only the agent who created this appointment, a branch manager or an admin can edit it.</p>
        </template>
        <template x-for="vp in panelData.viewing_feedback.properties" :key="vp.property_id">
            <div class="mb-3 rounded px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="flex items-start justify-between gap-2">
                    <div class="text-xs font-semibold" style="color: var(--text-primary);" x-text="vp.label"></div>
                    <template x-if="vp.viewing_status !== 'viewed'">
                        <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded flex-shrink-0" style="background:rgba(239,68,68,.12); color:#b91c1c;" x-text="vp.status_label"></span>
                    </template>
                </div>
                <template x-if="vp.captures.length === 0">
                    <p class="text-[11px] mt-1" style="color: var(--text-muted);">No feedback captured for this property.</p>
                </template>
                <template x-for="c in vp.captures" :key="c.id">
                    <div class="mt-2 text-[11px]" style="color: var(--text-secondary);">
                        <div class="flex flex-wrap items-center gap-1">
                            <template x-if="c.outcome_label"><span class="font-semibold uppercase px-1.5 py-0.5 rounded" style="background:rgba(16,185,129,.15); color:#059669;" x-text="c.outcome_label"></span></template>
                            <template x-for="cn in c.concerns" :key="cn"><span class="font-semibold px-1.5 py-0.5 rounded" style="background:rgba(245,158,11,.15); color:#b45309;" x-text="cn"></span></template>
                        </div>
                        <p class="mt-1" x-show="c.seller_notes"><span class="font-medium">Seller comment:</span> <span x-text="c.seller_notes"></span></p>
                        <p class="mt-1" x-show="c.internal_notes"><span class="font-medium">Internal comment:</span> <span x-text="c.internal_notes"></span></p>
                        <p class="mt-1" x-show="c.next_action"><span class="font-medium">Next action:</span> <span x-text="c.next_action"></span></p>
                        <p class="mt-1 text-[10px]" style="color: var(--text-muted);">
                            Captured by <span x-text="c.captured_by || 'unknown'"></span><span x-show="c.captured_at"> &middot; <span x-text="c.captured_at"></span></span>
                            <span x-show="c.last_edited_at"> &middot; last edited by <span x-text="c.last_edited_by || 'unknown'"></span> &middot; <span x-text="c.last_edited_at"></span></span>
                        </p>
                        <template x-if="panelData.viewing_feedback.can_edit">
                            <div class="mt-1">
                                <button type="button" x-show="vfConfirmArchive !== c.id" @click="vfConfirmArchive = c.id" class="text-[10px] hover:underline" style="color: var(--ds-crimson, #dc2626);">Archive</button>
                                <span x-show="vfConfirmArchive === c.id" class="text-[10px]">
                                    Archive this feedback? It can be restored.
                                    <button type="button" @click="vfArchive(c.id)" class="font-semibold hover:underline" style="color: var(--ds-crimson, #dc2626);">Yes, archive</button>
                                    <button type="button" @click="vfConfirmArchive = null" class="hover:underline" style="color: var(--text-muted);">Cancel</button>
                                </span>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="vp.archived && vp.archived.length > 0">
                    <div class="mt-2 pt-2 text-[10px]" style="border-top: 1px dashed var(--border); color: var(--text-muted);">
                        <div class="font-semibold uppercase tracking-wider mb-1">Archived</div>
                        <template x-for="a in vp.archived" :key="a.id">
                            <div class="flex items-center justify-between gap-2 mb-0.5">
                                <span><span x-text="a.outcome_label || a.status_label"></span> &middot; archived by <span x-text="a.archived_by || 'unknown'"></span></span>
                                <button type="button" @click="vfRestore(a.id)" class="font-semibold hover:underline" style="color: var(--brand-button);">Restore</button>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="vp.history && vp.history.length > 0">
                    <div class="mt-2" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="text-[10px] underline" style="color: var(--text-muted);" x-text="open ? 'Hide change log' : 'Show change log'"></button>
                        <ul x-show="open" x-cloak class="mt-1 space-y-0.5 text-[10px]" style="color: var(--text-muted);">
                            <template x-for="(h, hi) in vp.history" :key="hi">
                                <li>
                                    <span x-text="h.when"></span> &middot; <span x-text="h.by || 'system'"></span> &middot; <span x-text="h.action"></span>
                                    <template x-if="h.field"><span>: <span class="font-medium" x-text="h.field"></span> <span x-show="h.old">from "<span x-text="h.old"></span>"</span> to "<span x-text="h.new || '(empty)'"></span>"</span></template>
                                    <template x-if="h.note"><span> (<span x-text="h.note"></span>)</span></template>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>
