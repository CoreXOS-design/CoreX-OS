{{-- AT-136 — agent's "My WhatsApp Capture" consent screen. Per-contact decision on
     whether their WhatsApp chat BODIES are archived. SEPARATE from the AT-125 contact
     marketing opt-out. DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="captureConsent()">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">My WhatsApp Capture</h1>
                <p class="text-sm text-white/60 max-w-2xl">Decide, per contact, whether your WhatsApp chats with them are archived to CoreX for compliance. Only contacts that match a CoreX record appear here — personal numbers are never captured. This controls archiving (FICA); it is separate from a contact's marketing opt-out.</p>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    @if($pendingCount)
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent); color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber, #f59e0b);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
        </svg>
        <div class="flex-1">
            <strong>{{ number_format($pendingCount) }}</strong> matched {{ \Illuminate\Support\Str::plural('contact', $pendingCount) }} awaiting your decision — choose whether to archive your WhatsApp with them. Until you decide, their message bodies are not captured.
        </div>
    </div>
    @endif

    {{-- Consent table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Contact</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Archive my chats?</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    @php $ct = $row->contact; $nm = $ct ? trim(($ct->first_name ?? '').' '.($ct->last_name ?? '')) : ('Contact #'.$row->contact_id); @endphp
                    <tr style="border-top:1px solid var(--border); {{ $row->status==='pending' ? 'background:color-mix(in srgb, var(--ds-amber,#f59e0b) 7%, transparent);' : '' }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('corex.contacts.show', $row->contact_id) }}" class="font-semibold" style="color: var(--text-primary);">{{ $nm ?: '(no name)' }}</a>
                            @if($row->admin_flagged)
                            <span class="ml-2 inline-block text-[11px] font-semibold px-2 py-0.5 rounded-md whitespace-nowrap" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);" title="{{ $row->admin_flag_note }}">Manager flagged</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $map = [
                                    'pending'   => ['Awaiting decision', 'ds-badge-warning'],
                                    'opted_in'  => ['Archiving on',      'ds-badge-success'],
                                    'opted_out' => ['Not archiving',     'ds-badge-default'],
                                ];
                                [$lbl, $variant] = $map[$row->status] ?? ['—', 'ds-badge-default'];
                            @endphp
                            <span class="ds-badge {{ $variant }}">{{ $lbl }}</span>
                            @if($row->status==='opted_out' && $row->reason)<div class="text-[11px] mt-1" style="color:var(--text-muted);">“{{ $row->reason }}”</div>@endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex gap-2">
                                <button type="button" @click="decide({{ $row->contact_id }}, 'opted_in')" :disabled="busy"
                                        class="text-[11px] font-semibold rounded-md px-3 py-1.5"
                                        style="background: {{ $row->status==='opted_in' ? 'var(--ds-green, #059669)' : 'var(--surface-2)' }}; color: {{ $row->status==='opted_in' ? '#fff' : 'var(--text-secondary)' }}; border:1px solid var(--border);">Archive</button>
                                <button type="button" @click="decide({{ $row->contact_id }}, 'opted_out')" :disabled="busy"
                                        class="text-[11px] font-semibold rounded-md px-3 py-1.5"
                                        style="background: {{ $row->status==='opted_out' ? 'var(--text-muted)' : 'var(--surface-2)' }}; color: {{ $row->status==='opted_out' ? '#fff' : 'var(--text-secondary)' }}; border:1px solid var(--border);">Don't archive</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No matched WhatsApp contacts yet. Once your capture device sees a chat with a CoreX contact, it appears here for your decision.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
function captureConsent() {
    return {
        busy: false,
        async decide(contactId, status) {
            let reason = '';
            if (status === 'opted_out') { reason = prompt('Optional: why are you not archiving this contact? (recorded for compliance)') || ''; }
            this.busy = true;
            try {
                const r = await fetch('{{ route('communications.capture.decide') }}', {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ contact_id: contactId, status, reason })
                });
                // 419 = the page's CSRF token went stale (session refreshed / device
                // re-link). Reload to mint a fresh token rather than dead-end on
                // "Could not save" — the decision did not persist, so retry after.
                if (r.status === 419) { alert('Your session refreshed — reloading the page; please choose again.'); window.location.reload(); return; }
                const d = await r.json();
                if (r.ok && d.ok) { window.location.reload(); }
                else { alert(d.error || 'Could not save.'); }
            } catch (e) { alert('Network error — please try again.'); }
            finally { this.busy = false; }
        }
    };
}
</script>
@endpush
@endsection
