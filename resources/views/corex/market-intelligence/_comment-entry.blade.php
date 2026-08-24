{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    MIC property row comments — a single comment entry. Modeled directly on
    _slideover-activity-entry.blade.php (icon + text + actor/time meta line)
    so the comments modal doesn't look like a bolt-on.

    Input: $comment (TrackedPropertyComment, user eager-loaded), $viewerId,
    $canModerate, $trackedProperty.

    Spec: .ai/specs/mic-property-row-comments.md
--}}
@php
    $isMine = $viewerId && (int) $comment->user_id === (int) $viewerId;
    $canRemove = $isMine || $canModerate;
    $when = $comment->created_at;
    $tpId = (int) $comment->tracked_property_id;
@endphp
<div class="mi-comment-entry" data-comment-id="{{ $comment->id }}"
     style="display: grid; grid-template-columns: 28px 1fr; gap: 8px; padding: 8px 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 4px;">
    <div style="width: 24px; height: 24px; border-radius: 4px; background: var(--surface-2); display: flex; align-items: center; justify-content: center; color: var(--text-secondary);">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
        </svg>
    </div>
    <div style="min-width: 0;" x-data="{ editing: false }">
        <div x-show="!editing" style="font-size: 0.8125rem; color: var(--text-primary); line-height: 1.4; white-space: pre-line;">
            {{ $comment->body }}
        </div>
        <div x-show="!editing" style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span>{{ $comment->user->name ?? 'a user' }}</span>
            @if($when)
                <span>{{ $when->diffForHumans() }} · {{ $when->format('j M Y H:i') }}</span>
            @endif
            @if($comment->edited_at)
                <span title="Edited {{ $comment->edited_at->format('j M Y H:i') }}">(edited)</span>
            @endif
            @if($isMine)
                <button type="button" @click="editing = true"
                        class="corex-btn-outline corex-btn-xs" style="border: none; background: none; padding: 0; color: var(--brand-icon, #0ea5e9); font-weight: 600; box-shadow: none;">
                    Edit
                </button>
            @endif
            @if($canRemove)
                <button type="button"
                        @click="if (confirm('Remove this comment? This cannot be undone by other agents.')) removeComment({{ $tpId }}, {{ $comment->id }})"
                        style="border: none; background: none; padding: 0; color: var(--ds-crimson, #dc2626); font-weight: 600; cursor: pointer; font-size: inherit; box-shadow: none;">
                    Remove
                </button>
            @endif
        </div>

        @if($isMine)
        <form x-show="editing" x-cloak
              x-data="{ submitting: false, error: null, value: {{ \Illuminate\Support\Js::from($comment->body) }} }"
              @submit.prevent="
                  submitting = true; error = null;
                  try {
                      const res = await fetch('{{ route('corex.tracked-properties.comments.update', [$trackedProperty, $comment]) }}', {
                          method: 'PATCH',
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
                      updateCommentBadge({{ $tpId }}, data.count);
                  } catch (e) {
                      error = e.message;
                  } finally {
                      submitting = false;
                  }
              "
              style="margin-top: 6px;">
            <textarea x-model="value" required minlength="3" maxlength="1000" rows="2"
                      style="width: 100%; padding: 6px 8px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface-2); color: var(--text-primary); font-size: 0.8125rem; resize: vertical;"></textarea>
            <div x-show="error" x-text="error" style="color: var(--ds-crimson, #dc2626); font-size: 0.75rem; margin-top: 4px;"></div>
            <div style="display: flex; justify-content: flex-end; gap: 6px; margin-top: 6px;">
                <button type="button" @click="editing = false" class="corex-btn-outline corex-btn-xs">Cancel</button>
                <button type="submit" :disabled="submitting" class="corex-btn-primary corex-btn-xs">
                    <span x-show="!submitting">Save</span>
                    <span x-show="submitting">Saving…</span>
                </button>
            </div>
        </form>
        @endif
    </div>
</div>
