{{-- E-Sign V3 Phase 1B.5 — focused initialing view.
     Shown to the signing party only when amendment_status = 'amendment_initialing'.
     Displays ONLY the changed regions across all approved amendments with an
     initial slot per item — not the entire document.
     Spec: .ai/specs/esign-v3-complete-spec.md §7.5.7, §8 --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Initial the amendments — {{ $document?->name ?? 'Document' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="font-family: 'Figtree', Arial, sans-serif; background: #f4f6fb; margin: 0; padding: 2rem 1rem; color: #1f2937;">

<div style="max-width: 880px; margin: 0 auto;">

    {{-- Banner --}}
    <div style="background: #92400e; color: #fff; padding: 1.25rem 1.5rem; border-radius: 8px 8px 0 0;">
        <h1 style="margin: 0; font-size: 1.25rem; font-weight: 700;">
            Document amended — your initials are needed
        </h1>
        <p style="margin: 0.4rem 0 0; font-size: 0.9rem; opacity: 0.9;">
            This document was changed after you previously signed. Your original signature
            stays in place. Please initial the changed regions below to confirm them.
        </p>
    </div>

    <div style="background: #fff; padding: 1.5rem; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;">

        @if($noItems)
            <div style="padding: 2rem; text-align: center; color: #6b7280;">
                <p>No outstanding amendments require your initials right now.</p>
                <p style="margin-top: 0.6rem;">
                    <a href="{{ route('signatures.external', ['token' => $token]) }}"
                       style="color: #0ea5e9; text-decoration: underline;">Return to the document</a>
                </p>
            </div>
        @else
            <form method="POST" action="{{ route('signatures.external.initialAmendments', ['token' => $token]) }}"
                  x-data="initialingForm()" x-init="init()" id="initialingForm">
                @csrf

                @foreach($pendingItems as $idx => $item)
                    @php
                        $amendment = $item['amendment'];
                        $conditions = $item['conditions'];
                        $strikes = $item['strikethroughs'];
                    @endphp
                    <div style="margin-bottom: 1.5rem; padding: 1rem; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px;">
                        <div style="font-size: 0.7rem; font-weight: 700; color: #92400e; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.6rem;">
                            Amendment #{{ $amendment->id }}
                            &middot; {{ $amendment->amendment_type }}
                        </div>

                        @foreach($strikes as $strike)
                            <div style="margin-top: 0.6rem; padding: 0.75rem; background: #fff; border-left: 3px solid #dc2626; border-radius: 4px;">
                                <div style="font-size: 0.7rem; color: #dc2626; text-transform: uppercase; letter-spacing: 0.05em;">
                                    Strikethrough — clause {{ $strike->clause_ref }}
                                </div>
                                <p style="text-decoration: line-through; color: #6b7280; margin: 0.3rem 0;">
                                    {{ $strike->clause_original_text }}
                                </p>
                                @if($strike->replacementCondition)
                                    <div style="font-size: 0.7rem; color: #047857; margin-top: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em;">
                                        Replacement (in Other Conditions #{{ $strike->replacementCondition->condition_number }})
                                    </div>
                                    <p style="margin: 0.2rem 0; color: #1f2937;">{{ $strike->replacementCondition->content }}</p>
                                @endif
                                <label style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.6rem; font-size: 0.85rem;">
                                    <input type="checkbox" :checked="isItemChecked({{ $amendment->id }}, 'strikethrough', {{ $strike->id }})"
                                           @change="toggleItem({{ $amendment->id }}, 'strikethrough', {{ $strike->id }}, $event.target.checked)">
                                    I initial this change as {{ $request->signer_name }}
                                </label>
                            </div>
                        @endforeach

                        @foreach($conditions as $cond)
                            @if($cond->is_override)
                                {{-- Override rows are already shown under their parent strikethrough --}}
                                @continue
                            @endif
                            <div style="margin-top: 0.6rem; padding: 0.75rem; background: #fff; border-left: 3px solid #047857; border-radius: 4px;">
                                <div style="font-size: 0.7rem; color: #047857; text-transform: uppercase; letter-spacing: 0.05em;">
                                    New condition #{{ $cond->condition_number }}
                                    &middot; {{ ucwords(str_replace('_', ' ', $cond->block_purpose)) }}
                                </div>
                                <p style="margin: 0.3rem 0; color: #1f2937;">{{ $cond->content }}</p>
                                <label style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.6rem; font-size: 0.85rem;">
                                    <input type="checkbox" :checked="isItemChecked({{ $amendment->id }}, 'condition', {{ $cond->id }})"
                                           @change="toggleItem({{ $amendment->id }}, 'condition', {{ $cond->id }}, $event.target.checked)">
                                    I initial this change as {{ $request->signer_name }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endforeach

                <details style="margin-top: 1rem;">
                    <summary style="cursor: pointer; color: #6b7280; font-size: 0.85rem;">
                        View full document for context
                    </summary>
                    <p style="margin-top: 0.5rem; font-size: 0.85rem; color: #6b7280;">
                        <a href="{{ route('signatures.external', ['token' => $token]) }}?force_full_view=1"
                           style="color: #0ea5e9; text-decoration: underline;">
                            Open the full document &rarr;
                        </a>
                    </p>
                </details>

                <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" @click="submitInitials()"
                            :disabled="submitting || !hasAnyChecked"
                            style="background: #047857; color: #fff; padding: 0.7rem 1.5rem; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;"
                            :style="(submitting || !hasAnyChecked) ? 'opacity: 0.4; cursor: not-allowed;' : ''">
                        <span x-text="submitting ? 'Submitting…' : 'Submit initials'"></span>
                    </button>
                </div>

                <div x-show="error" x-cloak style="margin-top: 1rem; padding: 0.75rem; background: #fee2e2; color: #991b1b; border-radius: 4px;"
                     x-text="error"></div>
            </form>
        @endif
    </div>
</div>

<script>
function initialingForm() {
    return {
        items: {}, // amendmentId → { type-id → bool }
        submitting: false,
        error: '',
        get hasAnyChecked() {
            for (const aId in this.items) {
                for (const key in this.items[aId]) {
                    if (this.items[aId][key]) return true;
                }
            }
            return false;
        },
        init() {},
        isItemChecked(amendmentId, type, id) {
            return !!((this.items[amendmentId] || {})[type + '-' + id]);
        },
        toggleItem(amendmentId, type, id, checked) {
            if (!this.items[amendmentId]) this.items[amendmentId] = {};
            this.items[amendmentId][type + '-' + id] = checked;
        },
        async submitInitials() {
            this.error = '';
            this.submitting = true;
            const amendments = [];
            for (const aId in this.items) {
                const initials = [];
                for (const key in this.items[aId]) {
                    if (!this.items[aId][key]) continue;
                    const [type, id] = key.split('-');
                    initials.push({
                        initialable_type: type,
                        initialable_id: parseInt(id, 10),
                        initial_image_path: null,
                    });
                }
                if (initials.length) {
                    amendments.push({ amendment_id: parseInt(aId, 10), initials });
                }
            }
            if (amendments.length === 0) {
                this.error = 'Tick at least one change to initial.';
                this.submitting = false;
                return;
            }
            try {
                const csrf = document.querySelector('meta[name=csrf-token]').content;
                const r = await fetch('{{ route('signatures.external.initialAmendments', ['token' => $token]) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ amendments }),
                });
                if (!r.ok) {
                    const j = await r.json().catch(() => ({}));
                    this.error = j.error || ('Submit failed (' + r.status + ')');
                    this.submitting = false;
                    return;
                }
                const j = await r.json();
                window.location.href = j.next_url || '{{ route('signatures.external', ['token' => $token]) }}';
            } catch (e) {
                this.error = 'Network error: ' + e.message;
                this.submitting = false;
            }
        },
    };
}
</script>
</body>
</html>
