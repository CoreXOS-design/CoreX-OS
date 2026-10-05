{{-- AT-447 — public contract signing page. Shows the frozen document only while the link is signable. --}}
@extends('layouts.public')
@section('title', $env->title . ' — sign')
@push('head')
<style>
    .container-public { max-width: 800px; }
    .doc { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.5rem 1.75rem; color:#111827; line-height:1.6; font-size:.95rem; }
    .doc h3 { font-size:1.1rem; font-weight:700; margin:1.1rem 0 .3rem; } .doc h4 { font-weight:700; margin:.9rem 0 .25rem; }
    .doc p { margin:.5rem 0; } .doc ul { list-style:disc; margin:.5rem 0 .5rem 1.25rem; }
    #pad { background:#fff; border:1px dashed #9ca3af; border-radius:8px; width:100%; height:140px; touch-action:none; }
</style>
@endpush
@section('public-content')
<div class="space-y-4">
    <div class="text-center">
        <div class="text-xl font-bold" style="color:#0b2a4a;">CoreX <span style="color:#00b4d8;">Os</span></div>
        <h1 class="text-lg font-bold mt-2">{{ $env->title }}</h1>
        <p class="text-sm" style="color:#6b7280;">For {{ $env->agency?->name }} — to be signed by {{ $env->signatory_name }} ({{ $env->signatory_role }})</p>
    </div>

    <div class="doc">{!! $env->body_html_snapshot !!}</div>

    @if($env->attachments->isNotEmpty())
        <div class="doc" style="padding:1rem 1.25rem;">
            <div class="font-semibold text-sm mb-1">Attached documents</div>
            @foreach($env->attachments as $att)
                <div class="text-sm"><a href="{{ route('agency-contract.attachment', [$token, $att->id]) }}" class="underline" style="color:#0369a1;">{{ $att->original_name }}</a></div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('agency-contract.sign', $token) }}" id="signForm" class="doc space-y-4">
        @csrf
        <h2 class="font-bold">Sign this agreement</h2>
        @if($errors->any())<div class="text-sm" style="color:#dc2626;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
        <div>
            <label class="block text-sm font-semibold mb-1">Type your full name</label>
            <input name="typed_name" required maxlength="255" value="{{ old('typed_name') }}" autocomplete="name" class="w-full rounded-md px-3 py-2 text-sm" style="border:1px solid #d1d5db;">
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1">Draw your signature <span style="font-weight:400; color:#6b7280;">(optional)</span></label>
            <canvas id="pad"></canvas>
            <button type="button" id="clearPad" class="text-xs underline mt-1" style="color:#6b7280;">Clear</button>
            <input type="hidden" name="signature_data" id="signature_data">
        </div>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="consent" value="1" required class="mt-1"> <span>{{ $consent }}</span></label>
        <button type="submit" class="rounded-md px-6 py-3 text-sm font-semibold text-white" style="background:#00b4d8;">Sign agreement</button>
    </form>

    <details class="doc" style="padding:1rem 1.25rem;">
        <summary class="text-sm cursor-pointer" style="color:#6b7280;">I do not want to sign</summary>
        <form method="POST" action="{{ route('agency-contract.decline', $token) }}" class="mt-3 space-y-2">
            @csrf
            <label class="block text-sm font-semibold">Please tell us why</label>
            <textarea name="reason" required maxlength="1000" rows="3" class="w-full rounded-md px-3 py-2 text-sm" style="border:1px solid #d1d5db;"></textarea>
            <button type="submit" class="rounded-md px-4 py-2 text-sm font-semibold" style="border:1px solid #d1d5db;">Decline</button>
        </form>
    </details>
</div>

<script>
(function () {
    var c = document.getElementById('pad'), ctx, drawing = false, drawn = false;
    function size() { var r = c.getBoundingClientRect(), d = window.devicePixelRatio || 1; c.width = r.width * d; c.height = r.height * d; ctx = c.getContext('2d'); ctx.scale(d, d); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111827'; }
    function pos(e) { var r = c.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    size();
    c.addEventListener('pointerdown', function (e) { drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); c.setPointerCapture(e.pointerId); });
    c.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); drawn = true; });
    ['pointerup', 'pointercancel'].forEach(function (n) { c.addEventListener(n, function () { drawing = false; }); });
    document.getElementById('clearPad').addEventListener('click', function () { ctx.clearRect(0, 0, c.width, c.height); drawn = false; document.getElementById('signature_data').value = ''; });
    document.getElementById('signForm').addEventListener('submit', function () { if (drawn) document.getElementById('signature_data').value = c.toDataURL('image/png'); });
})();
</script>
@endsection
