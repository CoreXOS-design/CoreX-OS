{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Platform E-Sign public signing page (AT-447, spec §3A). --}}
@extends('layouts.public')
@section('title', $doc->title . ' — sign')
@push('head')
<style>
    .container-public { max-width: 860px; }
    .doc { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.5rem 1.75rem; color:#111827; line-height:1.6; font-size:.95rem; }
    .doc h3 { font-size:1.1rem; font-weight:700; margin:1.1rem 0 .3rem; } .doc h4 { font-weight:700; margin:.9rem 0 .25rem; }
    .doc p { margin:.5rem 0; } .doc ul { list-style:disc; margin:.5rem 0 .5rem 1.25rem; }
    .pgwrap { position:relative; line-height:0; border:1px solid #e5e7eb; background:#fff; margin-bottom:12px; }
    .pgwrap img { width:100%; display:block; }
    .fbox { position:absolute; box-sizing:border-box; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; line-height:1.1; overflow:hidden; }
    .fbox.mine { border:2px solid #00b4d8; background:rgba(0,180,216,.12); color:#0369a1; }
    .fbox.other { border:1px dashed #9ca3af; background:rgba(156,163,175,.12); color:#6b7280; }
    .fbox input { width:100%; height:100%; border:0; background:rgba(255,255,255,.9); font-size:12px; padding:0 4px; }
    #pad { background:#fff; border:1px dashed #9ca3af; border-radius:8px; width:100%; height:140px; touch-action:none; }
</style>
@endpush
@section('public-content')
<div class="space-y-4">
    <div class="text-center">
        <div class="text-xl font-bold" style="color:#0b2a4a;">CoreX <span style="color:#00b4d8;">Os</span></div>
        <h1 class="text-lg font-bold mt-2">{{ $doc->title }}</h1>
        <p class="text-sm" style="color:#6b7280;">For {{ $signer->name }} — signing as {{ $signer->role_label }}</p>
    </div>

    @if(session('signed') || $signer->status === 'signed')
        <div class="doc text-center space-y-2">
            <div class="text-3xl">✓</div>
            <h2 class="font-bold text-lg">{{ $doc->status === 'completed' ? 'Signed by everyone' : 'Thank you — you have signed' }}</h2>
            <p class="text-sm" style="color:#6b7280;">
                @if($doc->status === 'completed') Everyone has signed. A sealed PDF copy has been emailed to all signers.
                @else You will receive the signed copy by email once everyone has signed. @endif
            </p>
            @if($doc->status === 'completed' && $doc->sealed_pdf_path)<a href="{{ route('platform-esign.sign.download', $token) }}" class="inline-block mt-2 px-5 py-2 rounded-md text-white text-sm font-semibold" style="background:#00b4d8;">Download the signed PDF</a>@endif
        </div>
    @elseif($blocked)
        <div class="doc text-center"><p class="font-semibold">{{ $blocked }}</p></div>
    @endif

    @if($doc->status === 'declined' && $signer->status === 'declined')
        <div class="doc text-center"><p class="font-semibold">You declined this document.</p></div>
    @endif

    {{-- The document itself --}}
    @if($doc->isPdf())
        @for($i = 0; $i < $doc->page_count; $i++)
            <div class="pgwrap">
                <img src="{{ route('platform-esign.sign.page', [$token, $i]) }}" alt="Page {{ $i + 1 }}" loading="{{ $i > 1 ? 'lazy' : 'eager' }}">
                @foreach($doc->fields_json as $f)
                    @continue($f['page_index'] !== $i)
                    @php $mine = $f['role_key'] === $signer->role_key; $canEdit = !$blocked && $mine; @endphp
                    <div class="fbox {{ $mine ? 'mine' : 'other' }}" style="left:{{ $f['x'] }}%; top:{{ $f['y'] }}%; width:{{ $f['w'] }}%; height:{{ $f['h'] }}%;">
                        @if($canEdit && in_array($f['type'], ['text', 'date'], true))
                            <input form="signForm" name="fields[{{ $f['id'] }}]" value="{{ old('fields.' . $f['id'], $f['type'] === 'date' ? now()->format('j F Y') : '') }}" placeholder="{{ $f['label'] ?: ucfirst($f['type']) }}" maxlength="500">
                        @else
                            {{ $mine ? ($f['label'] ?: ['signature' => 'Sign here', 'initial' => 'Initials', 'date' => 'Date', 'text' => 'Text'][$f['type']]) : ($doc->signers->firstWhere('role_key', $f['role_key'])?->role_label) }}
                        @endif
                    </div>
                @endforeach
            </div>
        @endfor
    @else
        <div class="doc">{!! $doc->body_html_snapshot !!}</div>
    @endif

    @if($doc->attachments->isNotEmpty())
        <div class="doc" style="padding:1rem 1.25rem;">
            <div class="font-semibold text-sm mb-1">Attached documents</div>
            @foreach($doc->attachments as $att)<div class="text-sm"><a href="{{ route('platform-esign.sign.attachment', [$token, $att->id]) }}" class="underline" style="color:#0369a1;">{{ $att->original_name }}</a></div>@endforeach
        </div>
    @endif

    @if(!$blocked && $signer->status !== 'signed')
        <form method="POST" action="{{ route('platform-esign.sign.submit', $token) }}" id="signForm" class="doc space-y-4">
            @csrf
            <h2 class="font-bold">Sign this document</h2>
            @if($errors->any())<div class="text-sm" style="color:#dc2626;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
            <div class="grid sm:grid-cols-2 gap-3">
                <div><label class="block text-sm font-semibold mb-1">Type your full name</label>
                    <input name="typed_name" required maxlength="255" value="{{ old('typed_name', $signer->name) }}" autocomplete="name" class="w-full rounded-md px-3 py-2 text-sm" style="border:1px solid #d1d5db;"></div>
                <div><label class="block text-sm font-semibold mb-1">ID / passport number</label>
                    <input name="id_number" maxlength="40" value="{{ old('id_number', $signer->id_number) }}" required class="w-full rounded-md px-3 py-2 text-sm" style="border:1px solid #d1d5db;"></div>
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1">Draw your signature <span style="color:#6b7280;font-weight:400;">(optional — your typed name also signs)</span></label>
                <canvas id="pad"></canvas>
                <input type="hidden" name="signature" id="signature">
                <button type="button" id="clearPad" class="text-xs underline mt-1" style="color:#6b7280;">Clear</button>
            </div>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="consent" value="1" class="mt-1" required> <span>{{ $consent }}</span></label>
            <button type="submit" class="px-6 py-2.5 rounded-md text-white text-sm font-semibold" style="background:#00b4d8;">Sign</button>
        </form>

        <details class="doc">
            <summary class="text-sm font-semibold cursor-pointer" style="color:#6b7280;">I do not want to sign</summary>
            <form method="POST" action="{{ route('platform-esign.sign.decline', $token) }}" class="space-y-2 mt-3">@csrf
                <textarea name="reason" required maxlength="490" rows="2" placeholder="Tell the sender why" class="w-full rounded-md px-3 py-2 text-sm" style="border:1px solid #d1d5db;"></textarea>
                <button class="px-4 py-2 rounded-md text-sm font-semibold" style="border:1px solid #dc2626; color:#dc2626;">Decline to sign</button>
            </form>
        </details>

        <script>
        (function () {
            var c = document.getElementById('pad'); if (!c) return;
            var ctx, drawing = false, dirty = false, hidden = document.getElementById('signature');
            function size() { var r = c.getBoundingClientRect(); c.width = r.width * 2; c.height = r.height * 2; ctx = c.getContext('2d'); ctx.scale(2, 2); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111827'; }
            function pos(e) { var r = c.getBoundingClientRect(), t = e.touches ? e.touches[0] : e; return {x: t.clientX - r.left, y: t.clientY - r.top}; }
            function start(e) { drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
            function move(e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); dirty = true; e.preventDefault(); }
            function end() { if (drawing && dirty) hidden.value = c.toDataURL('image/png'); drawing = false; }
            size();
            ['mousedown', 'touchstart'].forEach(function (n) { c.addEventListener(n, start, {passive: false}); });
            ['mousemove', 'touchmove'].forEach(function (n) { c.addEventListener(n, move, {passive: false}); });
            ['mouseup', 'mouseleave', 'touchend'].forEach(function (n) { c.addEventListener(n, end); });
            document.getElementById('clearPad').addEventListener('click', function () { ctx.clearRect(0, 0, c.width, c.height); hidden.value = ''; dirty = false; });
        })();
        </script>
    @endif
</div>
@endsection
