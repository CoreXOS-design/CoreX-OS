@extends('layouts.corex')

@section('title', 'Private Property — Agents on Branch')

@section('corex-content')
<div class="p-6 space-y-6"
     x-data="{
        busy: null, msg: '', ok: null,
        deactivateUrl: @json(route('admin.pp.agents.deactivate')),
        csrf: @json(csrf_token()),
        async deactivate(btn) {
            const a = {
                pp_encrypted_id: btn.dataset.encryptedId,
                agent_id:        btn.dataset.agentId,
                first_name:      btn.dataset.firstName,
                last_name:       btn.dataset.lastName,
                email:           btn.dataset.email,
                tel_cell:        btn.dataset.cell,
            };
            if (!confirm('Deactivate PP profile ' + a.agent_id + ' (' + a.first_name + ' ' + a.last_name + ')? PP will refuse if this profile has active listings.')) return;
            this.busy = a.pp_encrypted_id; this.msg = ''; this.ok = null;
            try {
                const res = await fetch(this.deactivateUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(a),
                });
                const data = await res.json().catch(() => ({ success: false, message: 'Bad JSON from server (HTTP ' + res.status + ')' }));
                this.ok = !!data.success;
                this.msg = data.message || (this.ok ? 'Deactivated' : 'Deactivate failed (HTTP ' + res.status + ')');
                if (this.ok) setTimeout(() => location.reload(), 1200);
            } catch (e) {
                this.ok = false;
                this.msg = 'Network error: ' + (e && e.message ? e.message : e);
            }
            this.busy = null;
        }
     }">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">Private Property — Agents on Branch</h1>
            <p class="text-sm mt-1" style="color:var(--text-muted);">
                Every agent profile PP currently has for this branch. Rows highlighted in red share an email with another row — those are duplicate profiles that need cleanup.
            </p>
        </div>
        <a href="{{ route('admin.pp.agents') }}"
           class="px-4 py-2 rounded-md text-sm font-medium"
           style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
            Refresh from PP
        </a>
    </div>

    @if($error)
        <div class="rounded-md p-4 text-xs whitespace-pre-wrap break-all" style="background:rgba(239,68,68,0.12); color:#ef4444; border:1px solid rgba(239,68,68,0.3);">
            {{ $error }}
        </div>
    @endif

    <p x-show="msg" x-cloak class="text-sm font-medium"
       :style="ok ? 'color:#22c55e' : 'color:#ef4444'" x-text="msg"></p>

    <div class="rounded-xl overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead style="background:var(--surface-2); color:var(--text-secondary);">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">External Ref</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Name</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Email</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Cell</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">CoreX User</th>
                    <th class="text-left px-4 py-3 font-semibold uppercase tracking-wider text-xs">Encrypted ID</th>
                    <th class="text-right px-4 py-3 font-semibold uppercase tracking-wider text-xs">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($agents as $a)
                    <tr style="border-top:1px solid var(--border); {{ $a['is_duplicate_external_ref'] ? 'background:rgba(239,68,68,0.06);' : '' }}">
                        <td class="px-4 py-3 font-mono" style="color:var(--text-primary);">{{ $a['agent_id'] }}</td>
                        <td class="px-4 py-3" style="color:var(--text-primary);">{{ trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $a['email'] }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $a['contact_number'] }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">
                            @if($a['corex_user_id'])
                                #{{ $a['corex_user_id'] }} {{ $a['corex_user_name'] }}
                            @else
                                <span style="color:var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs" style="color:var(--text-muted);">{{ $a['pp_encrypted_id'] }}</td>
                        <td class="px-4 py-3 text-right">
                            <button type="button"
                                    @click="deactivate($event.currentTarget)"
                                    data-encrypted-id="{{ $a['pp_encrypted_id'] }}"
                                    data-agent-id="{{ $a['agent_id'] }}"
                                    data-first-name="{{ $a['first_name'] }}"
                                    data-last-name="{{ $a['last_name'] }}"
                                    data-email="{{ $a['email'] }}"
                                    data-cell="{{ $a['contact_number'] }}"
                                    :disabled="busy === '{{ $a['pp_encrypted_id'] }}'"
                                    class="px-3 py-1.5 rounded-md text-xs font-medium"
                                    style="color:#ef4444; border:1px solid rgba(239,68,68,0.3); background:rgba(239,68,68,0.08); cursor:pointer;">
                                <span x-show="busy !== '{{ $a['pp_encrypted_id'] }}'">Deactivate</span>
                                <span x-show="busy === '{{ $a['pp_encrypted_id'] }}'" x-cloak>...</span>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center" style="color:var(--text-muted);">No agents returned by PP.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
