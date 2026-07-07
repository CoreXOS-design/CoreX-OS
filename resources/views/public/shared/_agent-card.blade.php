{{--
    SHARED PUBLIC-PAGE COMPONENT — Agent card  (AT-204)
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (tokens via var(--token,#fallback))

    CONTRACT (proposed by cc2 / buyer-portal; cc1 / seller page to converge — see
    .ai/tickets/AT-204-buyer-portal-redesign.md). Consume this partial on ANY
    tokenised public page that needs to tell the visitor WHO to call. Build nothing
    duplicate.

    Expects:
      $agent   App\Models\User|null  — the point-of-contact agent (nullable → card hidden)
      $agency  App\Models\Agency|null (optional) — PPRA/FFC fallback + company name
      $heading string (optional, default 'Your agent')

    Relies on the host page's :root design tokens:
      --surface, --border, --text-primary, --text-secondary, --text-muted,
      --brand-default, --brand-button   (all with in-partial fallbacks)

    Renders: photo (or initials), name, tap-to-call, WhatsApp, mailto, PPRA/FFC line.
    Fully null-safe: any missing field is simply omitted; never 500s.
--}}
@php
    /** @var \App\Models\User|null $agent */
    $heading = $heading ?? 'Your agent';
    if (!empty($agent)) {
        $agentPhone = $agent->cell ?? $agent->phone ?? null;
        $agentPhotoUrl = method_exists($agent, 'profilePhotoUrl') ? $agent->profilePhotoUrl() : null;

        // wa.me digits: local "0.." → "27..".
        $waDigits = preg_replace('/\D/', '', (string) $agentPhone);
        if ($waDigits && str_starts_with($waDigits, '0')) {
            $waDigits = '27' . substr($waDigits, 1);
        }

        // Initials fallback for the avatar.
        $agentInitials = collect(explode(' ', trim((string) $agent->name)))
            ->filter()->take(2)->map(fn ($p) => strtoupper(substr($p, 0, 1)))->implode('');

        // PPRA / FFC compliance line — agent's own, else the agency's.
        $ppraLine = $agent->ffc_number
            ?: (!empty($agency) ? ($agency->ppra_number ?? $agency->ffc_no ?? null) : null);
    }
@endphp

@if(!empty($agent))
<div class="agent-card" style="background: var(--surface,#fff); border: 1px solid var(--border,rgba(0,0,0,.08)); border-radius: 12px; padding: 1rem 1.125rem;">
    <div style="font-size:.6875rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color: var(--text-muted,#9ca3af); margin-bottom:.75rem;">
        {{ $heading }}
    </div>
    <div style="display:flex; align-items:center; gap:.875rem;">
        {{-- Avatar --}}
        @if($agentPhotoUrl)
            <img src="{{ $agentPhotoUrl }}" alt="{{ $agent->name }}"
                 style="width:56px; height:56px; border-radius:9999px; object-fit:cover; flex-shrink:0; border:2px solid var(--brand-button,#00b4d8);">
        @else
            <div style="width:56px; height:56px; border-radius:9999px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.05rem; color:#fff; background: var(--brand-button,#00b4d8);">
                {{ $agentInitials ?: '·' }}
            </div>
        @endif

        {{-- Name + PPRA --}}
        <div style="min-width:0; flex:1;">
            <div style="font-size:1.0625rem; font-weight:700; color: var(--text-primary,#111827); line-height:1.2;">
                {{ $agent->name }}
            </div>
            @if(!empty($agency) && ($agency->name ?? null))
                <div style="font-size:.8125rem; color: var(--text-secondary,#4b5563); margin-top:.125rem;">
                    {{ $agency->trading_name ?: $agency->name }}
                </div>
            @endif
            @if(!empty($ppraLine))
                <div style="font-size:.6875rem; color: var(--text-muted,#9ca3af); margin-top:.25rem;">
                    PPRA / FFC: {{ $ppraLine }}
                </div>
            @endif
        </div>
    </div>

    {{-- Contact actions — big tap targets, stack on narrow screens --}}
    <div class="agent-card-actions" style="display:grid; grid-template-columns:1fr 1fr; gap:.5rem; margin-top:1rem;">
        @if($agentPhone)
            <a href="tel:{{ $agentPhone }}"
               style="display:flex; align-items:center; justify-content:center; gap:.4rem; min-height:44px; padding:.625rem; border-radius:9px; font-size:.875rem; font-weight:600; color:#fff; background: var(--brand-button,#00b4d8);">
                <svg xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                Call
            </a>
        @endif
        @if($waDigits)
            <a href="https://wa.me/{{ $waDigits }}" target="_blank" rel="noopener"
               style="display:flex; align-items:center; justify-content:center; gap:.4rem; min-height:44px; padding:.625rem; border-radius:9px; font-size:.875rem; font-weight:600; color:#fff; background:#25D366;">
                <svg xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 0 0 1.51 5.26l-.999 3.648 3.978-1.719zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.612-.916-2.207-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                WhatsApp
            </a>
        @endif
        @if($agent->email)
            <a href="mailto:{{ $agent->email }}"
               style="grid-column:1 / -1; display:flex; align-items:center; justify-content:center; gap:.4rem; min-height:44px; padding:.625rem; border-radius:9px; font-size:.875rem; font-weight:600; color: var(--text-secondary,#4b5563); background: var(--surface,#fff); border:1px solid var(--border,rgba(0,0,0,.1));">
                <svg xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                Email
            </a>
        @endif
    </div>
</div>
@endif
