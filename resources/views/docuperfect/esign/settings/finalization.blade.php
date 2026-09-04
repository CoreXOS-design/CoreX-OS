{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Finalisation Settings</h1>
                <p class="text-xs" style="color: var(--text-muted);">Controls what happens right after every party has signed a document — before it's filed and emailed out.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('docuperfect.esign.myDocuments') }}" class="corex-btn-outline text-xs">Back to My E-Sign Documents</a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--ds-green, #059669);">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);
                    color: var(--ds-crimson, #dc2626);">
            {{ session('error') }}
        </div>
    @endif

    <div class="rounded-md p-6 space-y-6" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('docuperfect.esign.settings.finalization.update') }}" class="space-y-6">
            @csrf

            <div class="flex items-start gap-3">
                <input type="checkbox" id="async_completion_enabled" name="async_completion_enabled" value="1"
                       {{ $settings->asyncCompletionEnabled() ? 'checked' : '' }}
                       class="mt-1 h-4 w-4 rounded" style="accent-color: var(--brand-icon);">
                <div>
                    <label for="async_completion_enabled" class="text-sm font-semibold" style="color: var(--text-primary);">
                        Finish documents in the background
                    </label>
                    <p class="text-xs mt-1" style="color: var(--text-secondary); max-width: 42rem;">
                        When a document is fully signed and approved, CoreX still has real work to do — generating the
                        signed PDF, filing it, and emailing everyone their copy. With this ON, the approving agent is
                        returned to My E-Sign Documents immediately while that work finishes in the background — much
                        faster for the agent. With it OFF, the agent's screen waits for all of that work to finish
                        before returning them, which is slower but does not depend on a background worker running.
                    </p>
                    <p class="text-[11px] mt-1" style="color: var(--text-muted);">Default: on.</p>
                </div>
            </div>

            <div>
                <label for="finalization_stuck_threshold_minutes" class="text-sm font-semibold block mb-1" style="color: var(--text-primary);">
                    Flag a document as stuck after
                </label>
                <div class="flex items-center gap-2">
                    <input type="number" min="1" max="1440" id="finalization_stuck_threshold_minutes" name="finalization_stuck_threshold_minutes"
                           value="{{ old('finalization_stuck_threshold_minutes', $settings->finalizationStuckThresholdMinutes()) }}"
                           class="w-24 text-sm rounded-lg border px-3 py-1.5" style="border-color: var(--border);" required>
                    <span class="text-sm" style="color: var(--text-secondary);">minutes</span>
                </div>
                <p class="text-xs mt-1" style="color: var(--text-secondary); max-width: 42rem;">
                    If the background work above (PDF, filing, emails) hasn't finished within this many minutes of a
                    document being approved — for example because the background worker isn't running — CoreX marks
                    it as a failed finalisation and tells the approving agent and your admin, instead of leaving it
                    looking "Completed" with nobody aware anything is missing.
                </p>
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">Default: 15 minutes.</p>
            </div>

            {{-- AT-385/AT-332 --}}
            <div class="flex items-start gap-3">
                <input type="checkbox" id="whatsapp_resend_enabled" name="whatsapp_resend_enabled" value="1"
                       {{ $settings->whatsappResendEnabled() ? 'checked' : '' }}
                       class="mt-1 h-4 w-4 rounded" style="accent-color: var(--brand-icon);">
                <div>
                    <label for="whatsapp_resend_enabled" class="text-sm font-semibold" style="color: var(--text-primary);">
                        Allow agents to send signing links via WhatsApp
                    </label>
                    <p class="text-xs mt-1" style="color: var(--text-secondary); max-width: 42rem;">
                        Email always goes out automatically to every recipient — this never changes. With this ON,
                        agents also get a "Send via WhatsApp" button so they can personally nudge a recipient who
                        hasn't signed yet. It opens WhatsApp with the signing link pre-filled — the agent sends it
                        themselves — CoreX cannot confirm whether it was actually delivered, so this is always a
                        manual convenience, never a replacement for the email invitation.
                    </p>
                    <p class="text-[11px] mt-1" style="color: var(--text-muted);">Default: on.</p>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="corex-btn-primary text-sm">Save</button>
            </div>
        </form>
    </div>
</div>
@endsection
