@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" x-data="triage()">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);" data-tour="comms-triage-intro">
        <h1 class="text-xl font-bold text-white leading-tight">Message Triage</h1>
        <p class="text-sm text-white/60">Unknown-contact messages awaiting your decision. Add the contact to archive the conversation, or mark it not real-estate related to remove it from your list.</p>
    </div>

    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="comms-triage-table">
        <table class="min-w-full text-sm ds-table">
            <thead>
                <tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">When</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Channel</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">From</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Message</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Decision</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $p)
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $p->occurred_at?->format('d M Y H:i') }}</td>
                    <td class="px-4 py-3"><span class="ds-badge {{ $p->channel === 'email' ? 'ds-badge-default' : 'ds-badge-success' }}">{{ ucfirst($p->channel) }}</span></td>
                    <td class="px-4 py-3" style="color: var(--text-primary);">{{ $p->from_identifier }}</td>
                    <td class="px-4 py-3" style="color: var(--text-primary);">
                        @if($p->subject)<div class="font-medium">{{ \Illuminate\Support\Str::limit($p->subject, 60) }}</div>@endif
                        <div class="text-xs" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($p->body_preview ?: $p->body_text, 100) }}</div>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <button type="button" class="corex-btn-primary" style="font-size:0.7rem; padding:4px 10px;"
                                @if($loop->first) data-tour="comms-triage-add" @endif
                                @click="openAdd(@js($p->from_identifier))">Add contact</button>
                        <form method="POST" action="{{ route('communications.triage.not-real-estate') }}" class="inline ml-2">
                            @csrf
                            <input type="hidden" name="identifier" value="{{ $p->from_identifier }}">
                            <input type="hidden" name="message_external_id" value="{{ $p->external_id }}">
                            <button type="submit" class="text-xs font-semibold" style="color: var(--text-muted);" @if($loop->first) data-tour="comms-triage-dismiss" @endif>Not real estate</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">Nothing to triage. New unknown-contact messages will appear here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add-contact modal (reuses the standard contact-create fields, prefilled from the identifier) --}}
    <div x-show="showAdd" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.4);">
        <div class="rounded-md w-full max-w-md" style="background: var(--surface); border:1px solid var(--border);" @click.outside="showAdd=false">
            <form method="POST" action="{{ route('communications.triage.add-contact') }}">
                @csrf
                <input type="hidden" name="identifier" :value="identifier">
                <div class="px-5 py-4" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-base font-bold" style="color: var(--text-primary);">Add contact</h3>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Adding archives this conversation and any other messages from <strong x-text="identifier"></strong>.</p>
                </div>
                <div class="px-5 py-4 space-y-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:#64748b;">First name *</label>
                        <input type="text" name="first_name" required class="w-full px-3 py-2 text-sm border" style="border-color:var(--border); border-radius:6px;">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:#64748b;">Last name</label>
                        <input type="text" name="last_name" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border); border-radius:6px;">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:#64748b;">Phone</label>
                        <input type="text" name="phone" x-model="phone" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border); border-radius:6px;">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:#64748b;">Email</label>
                        <input type="email" name="email" x-model="email" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border); border-radius:6px;">
                    </div>
                    <p class="text-xs" style="color:#94a3b8;">A phone or email is required.</p>
                </div>
                <div class="px-5 py-3 flex items-center justify-end gap-3" style="border-top:1px solid var(--border);">
                    <button type="button" @click="showAdd=false" class="text-sm" style="color:#6b7280;">Cancel</button>
                    <button type="submit" class="corex-btn-primary">Add &amp; Archive</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function triage() {
    return {
        showAdd: false,
        identifier: '',
        phone: '',
        email: '',
        openAdd(identifier) {
            this.identifier = identifier;
            // Prefill phone or email from the identifier shape.
            if (String(identifier).includes('@') && !/@[sc]\./i.test(String(identifier))) {
                this.email = identifier; this.phone = '';
            } else {
                this.phone = identifier; this.email = '';
            }
            this.showAdd = true;
        },
    };
}
</script>
@endsection
