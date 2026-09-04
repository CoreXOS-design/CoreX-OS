{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    AT-385 / AT-332 — "Send via WhatsApp" resend button. ONE canonical
    partial, included everywhere the button appears (recipient-1 confirmation
    screens + My E-Sign Documents), matching the pattern
    _recipient-resend.blade.php already established for the email resend.

    Johan's decision: email stays the automatic primary; this is a MANUAL,
    agent-clicked convenience only. It opens wa.me pre-filled — the agent
    sends it themselves — then logs ONLY that the link was opened (never
    "sent"), via a fire-and-forget POST after the tab opens (AT-323 lesson:
    open WhatsApp FIRST, inside the click gesture, or popup-blockers eat it).

    Required param:
      $signatureRequest — App\Models\Docuperfect\SignatureRequest
      $document         — App\Models\Docuperfect\Document (for the route binding)

    Availability (including the disabled-with-reason state) is decided
    server-side by SigningWhatsAppLinkService::resolveAvailability() —
    never re-derived in this blade.
--}}
@php
    $waState = app(\App\Services\Docuperfect\SigningWhatsAppLinkService::class)
        ->resolveAvailability($signatureRequest, (int) ($document->agency_id ?? auth()->user()?->effectiveAgencyId() ?? 0));
@endphp
@if($waState['available'])
    <button type="button"
            x-data
            @click="
                window.open(@js($waState['link']), '_blank', 'noopener');
                fetch(@js(route('docuperfect.signatures.whatsappOpened', ['document' => $document->id, 'signatureRequest' => $signatureRequest->id])), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ normalized_phone: @js($waState['normalizedPhone']) })
                }).catch(() => {});
            "
            class="text-xs font-semibold hover:underline transition-colors duration-150"
            style="color: var(--ds-green, #059669);"
            title="Opens WhatsApp with the signing link pre-filled — you send it yourself. CoreX cannot confirm delivery.">
        WhatsApp &#8594; {{ \Illuminate\Support\Str::limit($signatureRequest->signer_name, 14) }}
    </button>
@elseif($waState['reason'])
    <span class="text-xs" style="color: var(--text-muted);" title="{{ $waState['reason'] }}">
        WhatsApp unavailable &mdash; {{ $waState['reason'] }}
    </span>
@endif
