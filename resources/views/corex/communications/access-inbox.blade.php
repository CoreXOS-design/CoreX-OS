{{-- AT-118 — Communications Access inbox (approvers: owning agents + grant_access holders). --}}
{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5" x-data="commsAccessInbox()">
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
                {{-- AT-132 — name the SPECIFIC thread (subject unless the owner hid it, else channel + date). --}}
                <p class="text-xs mt-0.5" style="color:var(--text-secondary);">Thread: {{ $req->threadLabel() }}</p>
                @if($req->reason)
                    <p class="text-xs mt-0.5" style="color:var(--text-secondary);">“{{ $req->reason }}”</p>
                @endif
                <p class="text-[11px] mt-0.5" style="color:var(--text-muted);">Requested {{ $req->created_at->diffForHumans() }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                {{-- AT-132 — approve WITH MODE: this session vs always (this thread). --}}
                <button type="button" @click="act({{ $req->id }}, 'approve', 'session')" :disabled="busy"
                        class="corex-btn-primary text-sm disabled:opacity-40 disabled:cursor-not-allowed"
                        style="background: var(--ds-green, #059669); box-shadow: 0 4px 12px color-mix(in srgb, var(--ds-green, #059669) 25%, transparent);"
                        title="Grant access for this session only — ends at logout and at midnight">Approve · this session</button>
                <button type="button" @click="act({{ $req->id }}, 'approve', 'always')" :disabled="busy"
                        class="corex-btn-primary text-sm disabled:opacity-40 disabled:cursor-not-allowed"
                        title="Grant standing access to this thread — survives logout and midnight until revoked">Approve · always</button>
                <button type="button" @click="act({{ $req->id }}, 'decline')" :disabled="busy"
                        class="corex-btn-outline text-sm disabled:opacity-40 disabled:cursor-not-allowed">Decline</button>
                <a href="{{ route('corex.contacts.show', $req->contact_id) }}" class="corex-btn-outline text-sm">View contact</a>
            </div>
        </div>
    @empty
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No pending access requests</h3>
            <p class="text-sm" style="color: var(--text-muted);">When a colleague requests access to a contact you own (or you can authorise), it appears here.</p>
        </div>
    @endforelse
</div>

@push('scripts')
<script>
function commsAccessInbox() {
    return {
        handled: [],
        busy: false,
        async act(id, decision, grantMode = 'session') {
            if (decision === 'decline' && !confirm('Decline this access request?')) return;
            this.busy = true;
            try {
                const r = await fetch(`/api/v1/comms-access/${id}/authorize`, {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json', 'Accept':'application/json',
                               'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ decision, grant_mode: grantMode })
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
