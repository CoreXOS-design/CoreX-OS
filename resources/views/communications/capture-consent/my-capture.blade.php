{{-- AT-136 — agent's "My WhatsApp Capture" consent screen. Per-contact decision on
     whether their WhatsApp chat BODIES are archived. SEPARATE from the AT-125 contact
     marketing opt-out. DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (tokens, no emojis). --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" x-data="captureConsent()">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">My WhatsApp Capture</h1>
        <p class="text-sm text-white/60 max-w-2xl">Decide, per contact, whether your WhatsApp chats with them are archived to CoreX for compliance. Only contacts that match a CoreX record appear here — personal numbers are never captured. This controls archiving (FICA); it is separate from a contact's marketing opt-out.</p>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    @if($pendingCount)
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent); color: var(--text-primary);">
        <strong>{{ $pendingCount }}</strong> matched {{ \Illuminate\Support\Str::plural('contact', $pendingCount) }} awaiting your decision — choose whether to archive your WhatsApp with them. Until you decide, their message bodies are not captured.
    </div>
    @endif

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border); overflow:hidden;">
        <table class="w-full text-sm">
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
                        <span class="ml-2 text-[10px] font-semibold px-1.5 py-0.5 rounded" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 14%, transparent); color:var(--brand-icon,#0ea5e9);" title="{{ $row->admin_flag_note }}">Manager flagged as business</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $map = ['pending'=>['Awaiting decision','var(--ds-amber, #f59e0b)'],'opted_in'=>['Archiving on','var(--ds-green, #059669)'],'opted_out'=>['Not archiving','var(--text-muted)']];
                            [$lbl,$col] = $map[$row->status] ?? ['—','var(--text-muted)'];
                        @endphp
                        <span class="text-xs font-semibold" style="color: {{ $col }};">{{ $lbl }}</span>
                        @if($row->status==='opted_out' && $row->reason)<div class="text-[11px] mt-0.5" style="color:var(--text-muted);">“{{ $row->reason }}”</div>@endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="inline-flex gap-2">
                            <button type="button" @click="decide({{ $row->contact_id }}, 'opted_in')" :disabled="busy"
                                    class="text-[11px] font-semibold rounded px-3 py-1.5"
                                    style="background: {{ $row->status==='opted_in' ? 'var(--ds-green, #059669)' : 'var(--surface-2)' }}; color: {{ $row->status==='opted_in' ? '#fff' : 'var(--text-secondary)' }}; border:1px solid var(--border);">Archive</button>
                            <button type="button" @click="decide({{ $row->contact_id }}, 'opted_out')" :disabled="busy"
                                    class="text-[11px] font-semibold rounded px-3 py-1.5"
                                    style="background: {{ $row->status==='opted_out' ? 'var(--text-muted)' : 'var(--surface-2)' }}; color: {{ $row->status==='opted_out' ? '#fff' : 'var(--text-secondary)' }}; border:1px solid var(--border);">Don't archive</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-4 py-10 text-center" style="color:var(--text-secondary);">No matched WhatsApp contacts yet. Once your capture device sees a chat with a CoreX contact, it appears here for your decision.</td></tr>
            @endforelse
            </tbody>
        </table>
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
