{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    MIC property row comments — centered modal chrome (backdrop + dialog),
    matching the existing "Add note" modal shell already used elsewhere in
    MIC (_slideover-header.blade.php). Fetches its body content via
    micRowComments().openCommentsModal() (see _comments-alpine.blade.php)
    and swaps it in via x-html.

    Shared between the Work tab (_listings.blade.php) and the Opportunities
    tab (opportunities.blade.php) — extracted here so the two tabs render
    identical modal chrome from one source, per Johan's "same look" /
    "share code properly" instruction. Must be included inside a
    `<div x-data="micRowComments()">` ancestor (it reads
    commentsModalOpen/commentsModalLoading/commentsModalHtml from that
    scope).

    Spec: .ai/specs/mic-property-row-comments.md
--}}
<div x-show="commentsModalOpen" x-cloak
     @keydown.escape.window="commentsModalOpen = false"
     @click.self="commentsModalOpen = false"
     style="position: fixed; inset: 0; z-index: 70; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center;">
    <div @click.stop style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 16px; width: 92%; max-width: 480px; max-height: 85vh; display: flex; flex-direction: column;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
            <h3 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">Property comments</h3>
            <button type="button" @click="commentsModalOpen = false"
                    style="background: none; border: none; cursor: pointer; font-size: 1.25rem; line-height: 1; color: var(--text-muted);">&times;</button>
        </div>
        <template x-if="commentsModalLoading">
            <div class="p-8 text-center text-sm" style="color: var(--text-muted);">Loading…</div>
        </template>
        <div x-show="!commentsModalLoading" x-html="commentsModalHtml" style="overflow-y: auto;"></div>
    </div>
</div>
