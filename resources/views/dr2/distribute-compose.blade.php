@extends('layouts.corex')
@section('title', 'Send documents')

@section('corex-content')
@php
    $money = fn ($v) => number_format((float) $v, 0);
    $defaultIds = $party['default_documents']->pluck('id')->all();
    $recipient  = $party['recipients'][0] ?? null;
    $limitBytes = $sizeLimitMb * 1024 * 1024;
@endphp
<div style="max-width: 860px; margin: 0 auto; padding: 1rem;"
     x-data="composeSend({ mode: '{{ $party['delivery_mode'] }}', channel: '{{ $party['channel'] }}', limit: {{ $limitBytes }} })">

    @if(session('error'))<div class="corex-alert corex-alert-danger" style="margin:1rem 0;">{{ session('error') }}</div>@endif

    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:800;color:var(--brand-default,#0b2a4a);">Send documents — {{ $party['label'] }}</h1>
            <div style="color:var(--text-muted,#6b7280);font-size:.9rem;">Deal {{ $deal->deal_no ?? $deal->id }}@if($deal->property_address) · {{ $deal->property_address }}@endif</div>
        </div>
        <a href="{{ route('deals-dr2.pipeline', $deal) }}" class="corex-btn-secondary">← Deal</a>
    </div>

    {{-- Party switcher --}}
    <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;">
        @foreach($parties as $p)
            <a href="{{ route('deals-dr2.distribute.compose', ['deal'=>$deal,'party'=>$p['role']]) }}"
               class="{{ $p['role']===$party['role'] ? 'corex-btn-primary' : 'corex-btn-outline' }}"
               style="font-size:.8rem;padding:.3rem .7rem;">{{ $p['label'] }} @if(count($p['recipients']))· {{ count($p['default_documents']) }} doc{{ count($p['default_documents'])===1?'':'s' }}@endif</a>
        @endforeach
    </div>

    @if(!$recipient)
        <div class="corex-card" style="padding:1.5rem;text-align:center;color:#6b7280;">
            {{ $party['note'] ?? 'No recipient linked for this party on the deal.' }}
        </div>
    @else
    <form method="POST" action="{{ route('deals-dr2.distribute.send', $deal) }}">
        @csrf
        <input type="hidden" name="party_role" value="{{ $party['role'] }}">
        <input type="hidden" name="recipient_id" value="{{ $recipient['id'] ?? '' }}">

        {{-- Recipient --}}
        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;">
            <h3 style="font-size:.8rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem;">Recipient</h3>
            <div style="font-weight:600;">{{ $recipient['name'] }}</div>
            <div style="color:#6b7280;font-size:.85rem;">
                @if($recipient['email'])✉ {{ $recipient['email'] }}@endif
                @if($recipient['phone']) &nbsp; ☎ {{ $recipient['phone'] }}@endif
            </div>
            @if($party['note'])<div style="color:#b45309;font-size:.8rem;margin-top:.35rem;">{{ $party['note'] }}</div>@endif
        </div>

        {{-- Delivery method + channel --}}
        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;display:flex;gap:1rem;flex-wrap:wrap;">
            <label style="flex:1 1 220px;">Delivery method
                <select name="delivery_mode" x-model="mode" class="corex-input" style="width:100%;">
                    @foreach($modes as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label style="flex:1 1 220px;">Channel
                <select name="channel" x-model="channel" class="corex-input" style="width:100%;">
                    @foreach($channels as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <p x-show="channel==='whatsapp'" x-cloak style="flex:1 1 100%;color:#b45309;font-size:.8rem;margin:0;">WhatsApp sends secure links (not attachments).</p>
        </div>

        {{-- Documents: matrix defaults (checked) + add from the deal/property/contacts --}}
        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;">
            <h3 style="font-size:.8rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem;">Documents <span style="font-weight:400;text-transform:none;">— the matrix pre-selected these; untick or add any</span></h3>
            @if($corpus->isEmpty())
                <p style="color:#9ca3af;font-size:.85rem;">No documents filed on this deal, its property, or its contacts yet.</p>
            @else
                <input type="text" x-model="search" placeholder="Filter documents…" class="corex-input" style="width:100%;margin-bottom:.6rem;font-size:.85rem;">
                <div style="display:flex;flex-direction:column;gap:.3rem;max-height:320px;overflow:auto;">
                    @foreach($corpus as $doc)
                        @php $isDefault = in_array($doc->id, $defaultIds, true); @endphp
                        <label class="doc-row" data-name="{{ strtolower($doc->original_name.' '.($doc->documentType->label ?? '')) }}"
                               style="display:flex;align-items:center;gap:.6rem;padding:.35rem .5rem;border:1px solid var(--border,#eee);border-radius:6px;"
                               x-show="!search || '{{ strtolower(addslashes($doc->original_name)) }}'.includes(search.toLowerCase())">
                            <input type="checkbox" name="document_ids[]" value="{{ $doc->id }}" @checked($isDefault)
                                   data-size="{{ (int) ($doc->size ?? 0) }}" @change="recount()" x-ref="doc" style="width:1.05rem;height:1.05rem;">
                            <span style="flex:1;">
                                {{ $doc->original_name }}
                                <span style="color:#9ca3af;font-size:.75rem;">· {{ $doc->documentType->label ?? 'Unclassified' }}@if($isDefault) · <span style="color:#166534;">matrix default</span>@endif</span>
                            </span>
                            <span style="color:#9ca3af;font-size:.72rem;">{{ $doc->size ? number_format($doc->size/1024,0).' KB' : '' }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Editable message --}}
        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;">
            <h3 style="font-size:.8rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem;">Message</h3>
            <textarea name="message" rows="4" class="corex-input" style="width:100%;" placeholder="Optional note to the recipient…">{{ old('message') }}</textarea>
        </div>

        {{-- PREVIEW-before-send --}}
        <div class="corex-card" style="padding:1rem;margin-bottom:1rem;background:#f9fafb;">
            <h3 style="font-size:.8rem;font-weight:700;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem;">Before you send</h3>
            <div style="font-size:.9rem;">
                <strong x-text="count"></strong> document(s) to <strong>{{ $recipient['name'] }}</strong>
                via <strong x-text="channel==='whatsapp' ? 'WhatsApp' : 'email'"></strong>
                (<span x-text="mode==='secure_link' ? 'secure link + OTP' : 'attachment'"></span>).
                <span x-show="mode==='direct_attachment' && channel==='email' && parts>1" x-cloak>
                    Will be split into <strong x-text="parts"></strong> emails (Part N of M) to stay under {{ $sizeLimitMb }} MB.
                </span>
                <span x-show="count===0" x-cloak style="color:#dc2626;">Select at least one document.</span>
            </div>
        </div>

        <button type="submit" class="corex-btn-primary" x-bind:disabled="count===0">Send</button>
    </form>
    @endif
</div>

<script>
function composeSend(init) {
    return {
        mode: init.mode, channel: init.channel, limit: init.limit, search: '',
        count: 0, parts: 1,
        init() { this.recount(); },
        recount() {
            const boxes = [...this.$root.querySelectorAll('input[name="document_ids[]"]:checked')];
            this.count = boxes.length;
            // greedy part estimate for attachment/email
            const sizes = boxes.map(b => parseInt(b.dataset.size||'0',10)).sort((a,b)=>b-a);
            let parts = 0, cur = 0;
            for (const s of sizes) {
                if (s > this.limit) { if (cur>0){parts++;cur=0;} parts++; continue; }
                if (cur + s > this.limit && cur>0) { parts++; cur=0; }
                cur += s;
            }
            if (cur>0) parts++;
            this.parts = Math.max(1, parts);
        },
    }
}
</script>
@endsection
