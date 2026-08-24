{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    MIC property row comments — modal body (list + add box). Fetched as an
    HTML fragment by the row's comment chip and injected via x-html, mirroring
    _listings.blade.php's existing openBuyerPanel() fetch pattern. Also
    re-rendered server-side and returned inside the JSON envelope by the
    store/update/destroy endpoints so one round trip refreshes both the
    modal body and the row badge (see updateCommentBadge() in _listings.blade.php).

    Input: $trackedProperty, $comments (Collection<TrackedPropertyComment>,
    user eager-loaded), $viewerId, $canAdd, $canModerate.

    Spec: .ai/specs/mic-property-row-comments.md
--}}
<div class="mi-comments-list" style="display: flex; flex-direction: column; gap: 8px; max-height: 320px; overflow-y: auto; padding-right: 2px;">
    @forelse($comments as $comment)
        @include('corex.market-intelligence._comment-entry', [
            'comment'         => $comment,
            'viewerId'        => $viewerId,
            'canModerate'     => $canModerate,
            'trackedProperty' => $trackedProperty,
        ])
    @empty
        <div style="padding: 20px 8px; text-align: center; color: var(--text-muted); font-size: 0.8125rem;">
            No comments yet — be the first to leave one for the next agent who works this property.
        </div>
    @endforelse
</div>

@if($canAdd)
<form
    x-data="{ submitting: false, error: null, value: '' }"
    @submit.prevent="
        submitting = true; error = null;
        try {
            const res = await fetch('{{ route('corex.tracked-properties.comments.store', $trackedProperty) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                },
                credentials: 'same-origin',
                body: JSON.stringify({ body: value })
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
            commentsModalHtml = data.comments_html;
            updateCommentBadge({{ (int) $trackedProperty->id }}, data.count);
            value = '';
        } catch (e) {
            error = e.message;
        } finally {
            submitting = false;
        }
    "
    style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border);">
    <textarea x-model="value"
              required
              minlength="3"
              maxlength="1000"
              rows="3"
              placeholder="Add a comment for other agents working this property…"
              style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface-2); color: var(--text-primary); font-size: 0.8125rem; resize: vertical;"></textarea>
    <div x-show="error" x-text="error" style="color: var(--ds-crimson, #dc2626); font-size: 0.75rem; margin-top: 6px;"></div>
    <div style="display: flex; justify-content: flex-end; gap: 6px; margin-top: 8px;">
        <button type="submit" :disabled="submitting" class="corex-btn-primary corex-btn-xs">
            <span x-show="!submitting">Post comment</span>
            <span x-show="submitting">Posting…</span>
        </button>
    </div>
</form>
@endif
