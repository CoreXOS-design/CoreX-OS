{{-- AT-136 — admin/CO review of agent WhatsApp-capture OPT-OUTS (FICA backstop).
     Declaration + reason ONLY — NEVER message content (not a backdoor to chats).
     Admin may FLAG a contact for opt-in (the business call) — sees, does NOT override.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (tokens, no emojis). --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" x-data="captureReview()">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">WhatsApp Capture Opt-outs — Review</h1>
        <p class="text-sm text-white/60 max-w-2xl">Agents who chose NOT to capture their WhatsApp with a matched contact, and why. This is a declaration for compliance — message content is never shown here. If you judge a chat to be business, flag it for the agent to reconsider; you cannot override their choice.</p>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border); overflow:hidden;">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Contact</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Reason (declaration)</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">When</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Business call</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                @php $ct = $row->contact; $nm = $ct ? trim(($ct->first_name ?? '').' '.($ct->last_name ?? '')) : ('Contact #'.$row->contact_id); @endphp
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-3" style="color: var(--text-primary);">{{ $row->agent?->name ?? ('User #'.$row->agent_user_id) }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('corex.contacts.show', $row->contact_id) }}" class="font-semibold" style="color: var(--text-primary);">{{ $nm ?: '(no name)' }}</a>
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $row->reason ?: '— (none given)' }}</td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ optional($row->decided_at)->format('d M Y, H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        @if($row->admin_flagged)
                            <span class="text-[11px] font-semibold" style="color: var(--brand-icon, #0ea5e9);">Flagged for agent</span>
                        @else
                            <button type="button" @click="flag({{ $row->id }})" :disabled="busy"
                                    class="text-[11px] font-semibold rounded px-3 py-1.5" style="background: var(--surface-2); color: var(--text-secondary); border:1px solid var(--border);">Flag as business</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center" style="color:var(--text-secondary);">No capture opt-outs to review.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@push('scripts')
<script>
function captureReview() {
    return {
        busy: false,
        async flag(id) {
            const note = prompt('Why is this a business chat the agent should capture? (sent to the agent)') || '';
            this.busy = true;
            try {
                const r = await fetch('/communications/capture/' + id + '/flag', {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ note })
                });
                const d = await r.json();
                if (r.ok && d.ok) { window.location.reload(); }
                else { alert(d.error || 'Could not flag.'); }
            } catch (e) { alert('Network error — please try again.'); }
            finally { this.busy = false; }
        }
    };
}
</script>
@endpush
@endsection
