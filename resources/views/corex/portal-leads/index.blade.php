@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-white leading-tight">Portal Leads</h1>
                <p class="text-sm" style="color: rgba(255,255,255,0.6);">Buyer enquiries received from Property24 and Private Property.</p>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-xs" style="color: rgba(255,255,255,0.7);">
                    Total: <span class="font-semibold text-white">{{ $leads->total() }}</span>
                </div>

                <div x-data="{
                        open: false,
                        agentId: '',
                        portal: 'p24',
                        sending: false,
                        msg: '',
                        ok: null,
                        diag: null,
                        async send() {
                            if (!this.agentId) { this.msg = 'Pick an agent first.'; this.ok = false; return; }
                            this.sending = true; this.msg = ''; this.ok = null;
                            try {
                                const res = await fetch(@js(route('corex.portal-leads.test')), {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                    },
                                    body: JSON.stringify({ agent_id: Number(this.agentId), portal: this.portal }),
                                });
                                const data = await res.json();
                                this.ok = res.ok && data.ok;
                                this.msg = data.message || (this.ok ? 'Test lead sent.' : 'Failed.');
                                this.diag = data.diagnostics || null;
                            } catch (e) {
                                this.ok = false; this.msg = 'Network error: ' + e.message;
                            } finally { this.sending = false; }
                        }
                    }" class="relative">
                    <button type="button" @click="open = !open"
                            class="rounded-md text-xs font-semibold px-3 py-1.5 transition-all duration-300"
                            style="background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(255,255,255,0.2);">
                        Send test lead
                    </button>
                    <div x-show="open" @click.outside="open = false" x-cloak
                         class="absolute right-0 mt-2 w-80 rounded-md p-4 z-30 space-y-3"
                         style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Send to agent</label>
                            <select x-model="agentId"
                                    class="w-full rounded-md text-sm"
                                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="">— pick an agent —</option>
                                @foreach($agents as $a)
                                    <option value="{{ $a->id }}">{{ $a->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Portal</label>
                            <select x-model="portal"
                                    class="w-full rounded-md text-sm"
                                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="p24">Property24</option>
                                <option value="pp">Private Property</option>
                            </select>
                        </div>
                        <button type="button" @click="send()" :disabled="sending"
                                class="w-full rounded-md text-sm font-semibold text-white px-3 py-2 transition-all duration-300 disabled:opacity-60"
                                style="background: var(--brand-button, #0ea5e9);">
                            <span x-show="!sending">Send test lead</span>
                            <span x-show="sending">Sending…</span>
                        </button>
                        <p x-show="msg" x-text="msg" :class="ok ? 'text-emerald-600' : 'text-red-600'" class="text-xs"></p>
                        <div x-show="diag" class="text-[10px] space-y-0.5 rounded p-2" style="background: var(--surface-2); color: var(--text-muted);">
                            <div>Agent device tokens: <span class="font-mono" x-text="diag?.agent_device_tokens"></span></div>
                            <div>Agency device tokens: <span class="font-mono" x-text="diag?.agency_device_tokens"></span></div>
                            <div>FCM class loaded: <span class="font-mono" x-text="diag?.fcm_class_exists ? 'yes' : 'NO'"></span></div>
                            <div>Firebase Messaging bound: <span class="font-mono" x-text="diag?.fcm_messaging_bound ? 'yes' : 'NO'"></span></div>
                        </div>
                        <p class="text-[10px]" style="color: var(--text-muted);">
                            Fires the same NewPortalLeadReceived event as a real lead — popup polls within ~10s and FCM push goes to the agent's registered devices.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('corex.portal-leads.index') }}"
          class="rounded-md p-4 grid grid-cols-1 md:grid-cols-6 gap-3 transition-all duration-300"
          style="background: var(--surface); border: 1px solid var(--border);">

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Portal</label>
            <select name="portal"
                    class="w-full rounded-md text-sm transition-all duration-300"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="p24" @selected(($filters['portal'] ?? '') === 'p24')>Property24</option>
                <option value="pp"  @selected(($filters['portal'] ?? '') === 'pp')>Private Property</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">From</label>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                   class="w-full rounded-md text-sm transition-all duration-300"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">To</label>
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                   class="w-full rounded-md text-sm transition-all duration-300"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Agent</label>
            <select name="agent_id"
                    class="w-full rounded-md text-sm transition-all duration-300"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All agents</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected((string)($filters['agent_id'] ?? '') === (string)$a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Status</label>
            <select name="status"
                    class="w-full rounded-md text-sm transition-all duration-300"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="new"      @selected(($filters['status'] ?? '') === 'new')>New Contact</option>
                <option value="existing" @selected(($filters['status'] ?? '') === 'existing')>Already Exists</option>
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit"
                    class="w-full rounded-md text-sm font-semibold text-white px-3 py-2 shadow-lg transition-all duration-300"
                    style="background: var(--brand-button, #0ea5e9);">Apply</button>
            <a href="{{ route('corex.portal-leads.index') }}"
               class="text-xs whitespace-nowrap transition-all duration-300"
               style="color: var(--text-muted);">Reset</a>
        </div>
    </form>

    {{-- Leads table --}}
    <div class="rounded-md overflow-hidden"
         style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-[10px] uppercase tracking-wider"
                    style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-3 py-2 font-semibold">Received</th>
                    <th class="text-left px-3 py-2 font-semibold">Portal</th>
                    <th class="text-left px-3 py-2 font-semibold">Type</th>
                    <th class="text-left px-3 py-2 font-semibold">Name</th>
                    <th class="text-left px-3 py-2 font-semibold">Contact</th>
                    <th class="text-left px-3 py-2 font-semibold">Property</th>
                    <th class="text-left px-3 py-2 font-semibold">Message</th>
                    <th class="text-left px-3 py-2 font-semibold">Status</th>
                    <th class="text-left px-3 py-2 font-semibold">Agent</th>
                </tr>
            </thead>
            <tbody>
                @forelse($leads as $lead)
                    @php
                        $agent = $lead->existingContactAgent
                              ?? ($lead->listing && $lead->listing->agent_id
                                  ? \App\Models\User::find($lead->listing->agent_id)
                                  : null);
                    @endphp
                    <tr class="transition-all duration-300"
                        style="border-top: 1px solid var(--border); color: var(--text-primary);">
                        <td class="px-3 py-2 whitespace-nowrap text-xs" style="color: var(--text-secondary);">
                            {{ optional($lead->received_at)->format('Y-m-d H:i') }}
                        </td>
                        <td class="px-3 py-2">
                            @if($lead->portal === 'p24')
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-bold text-white"
                                      style="background: var(--brand-default, #0b2a4a);">P24</span>
                            @else
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-bold text-white"
                                      style="background: var(--brand-button, #0ea5e9);">PP</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs" style="color: var(--text-secondary);">{{ $lead->lead_type }}{{ $lead->is_whatsapp ? ' / WhatsApp' : '' }}</td>
                        <td class="px-3 py-2 font-medium">{{ $lead->name }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if($lead->email)
                                <div style="color: var(--text-primary);">{{ $lead->email }}</div>
                            @endif
                            @if($lead->phone)
                                <div style="color: var(--text-muted);">{{ $lead->phone }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if($lead->listing)
                                <a href="{{ route('corex.properties.show', $lead->listing_id) }}"
                                   class="font-medium transition-all duration-300"
                                   style="color: var(--brand-icon, #0ea5e9);">
                                    {{ $lead->listing->title ?? ('#' . $lead->listing_id) }}
                                </a>
                            @elseif($lead->listing_portal_ref)
                                <span style="color: var(--text-muted);">ref {{ $lead->listing_portal_ref }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs max-w-xs whitespace-pre-wrap" style="color: var(--text-secondary);">{{ $lead->message }}</td>
                        <td class="px-3 py-2">
                            @if($lead->contact_exists)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold"
                                      style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                                    Already Exists{{ $lead->existingContactAgent ? ' — ' . $lead->existingContactAgent->name : '' }}
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold text-white"
                                      style="background: var(--brand-icon, #0ea5e9);">
                                    New Contact{{ $agent ? ' — ' . $agent->name : '' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs" style="color: var(--text-secondary);">{{ $agent->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-3 py-8 text-center text-sm"
                            style="color: var(--text-muted);">No portal leads yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $leads->links() }}</div>
</div>
@endsection
