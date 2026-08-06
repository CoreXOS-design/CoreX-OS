{{-- Communications tab body (AT-43/AT-132) — extracted as its own partial
     (pre-existing bug fix, found while verifying Phase 4 in a real browser —
     NOT a Phase 4 change): same class of Blade-compiler defect as the other
     _*.blade.php partials split out of show.blade.php today. The
     contactThreads forelse loop was the one losing its opening tag once the
     other fixes freed up enough room to reach it. No logic changed here. --}}
<div class="flex items-center justify-between">
    <div>
        <h3 class="text-sm font-bold" style="color:var(--text-primary);">Communications</h3>
        <p class="text-xs mt-0.5" style="color:var(--text-muted);">Email &amp; WhatsApp threads linked to this contact. Message contents are private to the owning agent — request access to a thread to read it.</p>
    </div>
    @if($canViewComms ?? false)
    <a href="{{ route('compliance.comm-archive.index', ['contact' => $contact->id]) }}" class="text-xs font-semibold underline" style="color:var(--brand-icon, #0ea5e9);">Open full archive</a>
    @endif
</div>

{{-- AT-136 — per-agent WhatsApp capture toggle for THIS contact (controls
     whether MY WhatsApp chats with them are archived; SEPARATE from the
     contact's marketing opt-out). --}}
<div class="rounded px-4 py-3 flex items-center justify-between gap-3"
     style="background:var(--surface-2); border:1px solid var(--border);"
     x-data="{ status: @js($myCaptureStatus), busy:false,
        async set(s){ if(s===this.status) return; let reason='';
            if(s==='opted_out'){ reason = prompt('Optional: why not capture your WhatsApp with this contact? (recorded for compliance)') || ''; }
            this.busy=true;
            try{ const r=await fetch('{{ route('communications.capture.decide') }}',{method:'POST',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
                body:JSON.stringify({contact_id:{{ $contact->id }},status:s,reason})});
                if(r.status===419){ alert('Your session refreshed — reloading the page; please choose again.'); window.location.reload(); return; }
                const d=await r.json(); if(r.ok&&d.ok){ this.status=d.status; } else { alert(d.error||'Could not save.'); }
            }catch(e){ alert('Network error — try again.'); } finally{ this.busy=false; } } }">
    <div class="min-w-0">
        <div class="text-xs font-semibold" style="color:var(--text-primary);">Capture my WhatsApp chats with this contact</div>
        <p class="text-[11px] mt-0.5" style="color:var(--text-muted);">
            <span x-show="status==='opted_in'" style="color:var(--ds-green,#059669);">On — bodies captured for compliance.</span>
            <span x-show="status==='opted_out'">Off — only that a message occurred is kept; bodies are not captured.</span>
            <span x-show="status==='pending'" style="color:var(--ds-amber,#f59e0b);">Awaiting your decision — bodies not captured until you choose.</span>
            <span x-show="!status" style="color:var(--text-muted);">No WhatsApp match with this contact yet — choose to pre-set your preference.</span>
        </p>
    </div>
    <div class="inline-flex gap-2 shrink-0">
        <button type="button" @click="set('opted_in')" :disabled="busy" class="text-[11px] font-semibold rounded px-3 py-1.5"
                :style="status==='opted_in' ? 'background:var(--ds-green,#059669);color:#fff;border:1px solid var(--border);' : 'background:var(--surface);color:var(--text-secondary);border:1px solid var(--border);'">Capture</button>
        <button type="button" @click="set('opted_out')" :disabled="busy" class="text-[11px] font-semibold rounded px-3 py-1.5"
                :style="status==='opted_out' ? 'background:var(--text-muted);color:#fff;border:1px solid var(--border);' : 'background:var(--surface);color:var(--text-secondary);border:1px solid var(--border);'">Don't capture</button>
    </div>
</div>

@forelse(($contactThreads ?? collect()) as $thread)
    @php
        $isWa     = $thread->channel === \App\Models\Communications\Communication::CHANNEL_WHATSAPP;
        $accent   = $isWa ? '#25d366' : 'var(--brand-icon, #0ea5e9)';
        // AT-137 — pass origin context so the thread/message Back returns
        // HERE (the contact), not the compliance archive.
        $openHref = $thread->is_visible
            ? ($thread->thread_key !== null
                ? route('compliance.comm-archive.thread', ['threadKey' => $thread->thread_key, 'from' => 'contact', 'contact' => $contact->id])
                : route('compliance.comm-archive.show', ['communication' => $thread->communication_id, 'from' => 'contact', 'contact' => $contact->id]))
            : null;
    @endphp

    @if($thread->is_visible)
    {{-- VISIBLE thread — opens to the body; owner may toggle hide-subject --}}
    <div class="rounded px-4 py-3"
         style="background:var(--surface-2); border:1px solid var(--border); border-left:3px solid {{ $accent }};">
        <a href="{{ $openHref }}" class="block transition-all hover:opacity-90">
            @include('corex.contacts._comm-thread-meta', ['thread' => $thread, 'isWa' => $isWa, 'accent' => $accent])
        </a>
        <div class="flex items-center gap-3 mt-1.5">
            @if($thread->can_manage_subject)
            <div x-data="{
                    hidden: {{ $thread->subject_hidden_setting ? 'true' : 'false' }},
                    busy: false,
                    async toggle(){
                        this.busy = true;
                        try {
                            const r = await fetch('{{ route('api.v1.comms-access.thread-settings') }}', {
                                method: 'POST',
                                headers: { 'Content-Type':'application/json', 'Accept':'application/json',
                                           'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ contact_id: {{ $contact->id }}, thread_key: {{ json_encode($thread->thread_key) }}, hide_subject: !this.hidden })
                            });
                            const d = await r.json();
                            if (r.ok && d.ok) { this.hidden = d.hide_subject; }
                            else { alert(d.error || 'Could not update.'); }
                        } catch(e) { alert('Network error — please try again.'); }
                        finally { this.busy = false; }
                    }
                 }">
                <button type="button" @click="toggle()" :disabled="busy"
                        class="text-[11px] font-semibold rounded px-2.5 py-1"
                        style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">
                    <span x-show="!hidden">Hide subject from others</span>
                    <span x-show="hidden" x-cloak>Subject hidden from others — show</span>
                </button>
            </div>
            @endif
            @if($thread->viewer_grant_id)
            {{-- AT-132 — viewer holds a per-thread grant → show its mode + a Revoke control (No Silent Locks). --}}
            <div x-data="{
                    revoked: false, busy: false,
                    async revoke(){
                        if (!confirm('Revoke your access to this thread?')) return;
                        this.busy = true;
                        try {
                            const r = await fetch('{{ route('api.v1.comms-access.revoke', ['commsAccessRequest' => $thread->viewer_grant_id]) }}', {
                                method: 'POST',
                                headers: { 'Content-Type':'application/json', 'Accept':'application/json',
                                           'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ reason: 'self_revoke' })
                            });
                            const d = await r.json();
                            if (r.ok && d.ok) { this.revoked = true; setTimeout(() => window.location.reload(), 600); }
                            else { alert(d.error || 'Could not revoke.'); }
                        } catch(e) { alert('Network error — please try again.'); }
                        finally { this.busy = false; }
                    }
                 }" class="inline-flex items-center gap-2">
                <span class="text-[11px] font-semibold rounded px-2 py-0.5"
                      style="background:color-mix(in srgb, var(--ds-teal, #00d4aa) 16%, transparent); color:var(--ds-green, #059669);">
                    Access granted · {{ $thread->viewer_grant_mode === 'always' ? 'always' : 'this session' }}
                </span>
                <button type="button" @click="revoke()" :disabled="busy" x-show="!revoked"
                        class="text-[11px] font-semibold rounded px-2.5 py-1"
                        style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">Revoke access</button>
                <span x-show="revoked" x-cloak class="text-[11px]" style="color:var(--text-muted);">Revoked</span>
            </div>
            @endif
            <a href="{{ $openHref }}" class="text-[11px] font-semibold ml-auto" style="color:var(--brand-icon, #0ea5e9);">Open thread</a>
        </div>
    </div>
    @else
    {{-- GATED thread — safe metadata + per-thread Request access (No Silent Locks) --}}
    <div class="rounded px-4 py-3"
         style="background:var(--surface-2); border:1px solid var(--border); border-left:3px solid var(--text-muted);"
         x-data="{
            requested: {{ $thread->pending ? 'true' : 'false' }},
            loading: false, error: '',
            async request(){
                this.loading = true; this.error = '';
                try {
                    const r = await fetch('{{ route('api.v1.comms-access.store') }}', {
                        method: 'POST',
                        headers: { 'Content-Type':'application/json', 'Accept':'application/json',
                                   'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: JSON.stringify({
                            contact_id: {{ $contact->id }},
                            thread_key: {{ $thread->thread_key !== null ? json_encode($thread->thread_key) : 'null' }},
                            communication_id: {{ $thread->communication_id !== null ? $thread->communication_id : 'null' }}
                        })
                    });
                    const d = await r.json();
                    if (r.ok && d.ok) { this.requested = true; }
                    else { this.error = d.error || 'Could not send the request.'; }
                } catch (e) { this.error = 'Network error — please try again.'; }
                finally { this.loading = false; }
            }
         }">
        @include('corex.contacts._comm-thread-meta', ['thread' => $thread, 'isWa' => $isWa, 'accent' => 'var(--text-muted)'])
        <div class="flex items-center gap-3 mt-2">
            {{-- AT-153 — name the owning agent so the requester knows whom to ask
                 (bodies stay gated); fallback message avoids a dead-end when no
                 owning agent is on record. --}}
            <span class="text-[11px]" style="color:var(--text-muted);">
                @if($thread->owner_name)
                    Private to {{ $thread->owner_name }} — request access to read it.
                @else
                    Private — no owning agent on record; your request routes to a communications manager.
                @endif
            </span>
            <div class="ml-auto">
                <template x-if="!requested">
                    <button type="button" @click="request()" :disabled="loading"
                            class="text-[11px] font-semibold rounded px-3 py-1.5"
                            style="background:var(--brand-button, #0ea5e9); color:#fff;"
                            :style="loading ? 'opacity:.6;cursor:wait' : ''">
                        <span x-show="!loading">Request access</span>
                        <span x-show="loading">Sending</span>
                    </button>
                </template>
                <template x-if="requested">
                    <span class="inline-flex items-center text-[11px] font-semibold rounded px-2.5 py-1"
                          style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, transparent); color:var(--ds-amber, #f59e0b);">Requested — awaiting approval</span>
                </template>
            </div>
        </div>
        <p x-show="error" x-text="error" class="text-[11px] mt-1.5" style="color:var(--ds-crimson, #c41e3a);"></p>
    </div>
    @endif
@empty
    <div class="rounded px-4 py-8 text-center" style="background:var(--surface-2); border:1px dashed var(--border);">
        <p class="text-sm" style="color:var(--text-secondary);">No communications linked to this contact yet.</p>
        <p class="text-xs mt-1" style="color:var(--text-muted);">Captured email/WhatsApp with this contact's address or number will appear here automatically.</p>
    </div>
@endforelse
