{{-- AT-136 — admin/CO review of agent WhatsApp-capture OPT-OUTS (FICA backstop).
     Declaration + reason ONLY — NEVER message content (not a backdoor to chats).
     Admin may FLAG a contact for opt-in (the business call) — sees, does NOT override.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="captureReview()">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">WhatsApp Consent — Review</h1>
                <p class="text-sm text-white/60 max-w-2xl">Agents who chose NOT to capture their WhatsApp with a matched contact, and why. This is a declaration for compliance — message content is never shown here. If you judge a chat to be business, flag it for the agent to reconsider; you cannot override their choice.</p>
            </div>
        </div>
    </div>

    @include('communications.partials._consent-crosslinks', ['current' => 'review'])

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    {{-- Opt-outs table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
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
                            <a href="{{ route('corex.contacts.show', $row->contact_id) }}" class="font-semibold" style="color: var(--brand-icon, #0ea5e9);">{{ $nm ?: '(no name)' }}</a>
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $row->reason ?: '— (none given)' }}</td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap" style="color: var(--text-muted);">{{ optional($row->decided_at)->format('d M Y, H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($row->admin_flagged)
                                <span class="ds-badge ds-badge-info" title="Flagged for the agent to reconsider capturing this chat.">Flagged</span>
                            @else
                                <button type="button" @click="flag({{ $row->id }})" :disabled="busy"
                                        class="corex-btn-outline corex-btn-xs disabled:opacity-40 disabled:cursor-not-allowed">Flag as business</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No capture opt-outs to review. When an agent opts out of capturing a matched contact's chat, it will appear here.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
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
