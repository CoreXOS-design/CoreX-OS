{{-- AT-118 — Communications Access inbox (approvers: owning agents + grant_access holders). --}}
{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (tokens via var()). --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" x-data="commsAccessInbox()">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Communications Access Requests</h1>
                <p class="text-sm text-white/60">Colleagues asking to see a contact's email &amp; WhatsApp threads. Approve or decline — approval grants access for the requester's current session only.</p>
            </div>
        </div>
    </div>

    @forelse($requests as $req)
        <div class="rounded-md px-5 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3"
             style="background:var(--surface); border:1px solid var(--border);"
             id="car-{{ $req->id }}"
             x-show="!handled.includes({{ $req->id }})">
            <div class="min-w-0">
                <p class="text-sm font-semibold" style="color:var(--text-primary);">
                    {{ $req->requester?->name ?? 'A colleague' }}
                    <span class="font-normal" style="color:var(--text-muted);">requests access to</span>
                    {{ trim(($req->contact->first_name ?? '').' '.($req->contact->last_name ?? '')) ?: 'a contact' }}
                </p>
                @if($req->reason)
                    <p class="text-xs mt-0.5" style="color:var(--text-secondary);">“{{ $req->reason }}”</p>
                @endif
                <p class="text-[11px] mt-0.5" style="color:var(--text-muted);">Requested {{ $req->created_at->diffForHumans() }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button type="button" @click="act({{ $req->id }}, 'approve')" :disabled="busy"
                        class="text-xs font-semibold rounded-md px-4 py-2" style="background:#00d4aa; color:#06251f;">Approve</button>
                <button type="button" @click="act({{ $req->id }}, 'decline')" :disabled="busy"
                        class="text-xs font-semibold rounded-md px-4 py-2" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">Decline</button>
                <a href="{{ route('corex.contacts.show', $req->contact_id) }}" class="text-xs font-semibold underline" style="color:var(--brand-icon, #0ea5e9);">View contact</a>
            </div>
        </div>
    @empty
        <div class="rounded-md px-4 py-10 text-center" style="background:var(--surface-2); border:1px dashed var(--border);">
            <p class="text-sm" style="color:var(--text-secondary);">No pending communications access requests.</p>
            <p class="text-xs mt-1" style="color:var(--text-muted);">When a colleague requests access to a contact you own (or you can authorise), it appears here.</p>
        </div>
    @endforelse
</div>

@push('scripts')
<script>
function commsAccessInbox() {
    return {
        handled: [],
        busy: false,
        async act(id, decision) {
            if (decision === 'decline' && !confirm('Decline this access request?')) return;
            this.busy = true;
            try {
                const r = await fetch(`/api/v1/comms-access/${id}/authorize`, {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json', 'Accept':'application/json',
                               'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ decision })
                });
                const d = await r.json();
                if (r.ok && d.ok) { this.handled.push(id); }
                else { alert(d.error || 'Could not action the request.'); }
            } catch (e) { alert('Network error — please try again.'); }
            finally { this.busy = false; }
        }
    };
}
</script>
@endpush
@endsection
