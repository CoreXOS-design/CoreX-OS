{{-- .ai/specs/ppra-ffc-employment-letter.md --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $letter->user?->name ?? 'Letter' }} — PPRA Confirmation of Employment</h1>
                <p class="text-xs" style="color: var(--text-muted);">{{ \App\Models\Compliance\PpraEmploymentLetter::statusLabel($letter->status) }} &middot; Created {{ $letter->created_at->format('d M Y') }}</p>
            </div>
            <a href="{{ route('admin.ppra-employment-letters.index') }}" class="corex-btn-outline text-xs">Back to list</a>
        </div>
    </div>

    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div><dt class="text-xs font-semibold" style="color:var(--text-muted);">Agent</dt><dd style="color:var(--text-primary);">{{ $letter->user?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold" style="color:var(--text-muted);">Branch</dt><dd style="color:var(--text-primary);">{{ $letter->branch?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold" style="color:var(--text-muted);">Principal</dt><dd style="color:var(--text-primary);">{{ $letter->principal?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold" style="color:var(--text-muted);">Created by</dt><dd style="color:var(--text-primary);">{{ $letter->createdBy?->name ?? '—' }}</dd></div>
            {{-- LEGACY: only letters made under the retired PIN ceremony carry these timestamps (spec §20). --}}
            @if($letter->agent_signed_at)
            <div><dt class="text-xs font-semibold" style="color:var(--text-muted);">Signed electronically by agent (retired)</dt><dd style="color:var(--text-primary);">{{ $letter->agent_signed_at->format('d M Y H:i') }}</dd></div>
            @endif
            @if($letter->principal_signed_at)
            <div><dt class="text-xs font-semibold" style="color:var(--text-muted);">Signed electronically by principal (retired)</dt><dd style="color:var(--text-primary);">{{ $letter->principal_signed_at->format('d M Y H:i') }}</dd></div>
            @endif
        </dl>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, #15803d 10%, transparent); color:#15803d; border:1px solid color-mix(in srgb, #15803d 25%, transparent);">{{ session('success') }}</div>
    @endif
    @if(session('error') || $errors->has('signed_copy'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, #c41e3a 10%, transparent); color:#c41e3a; border:1px solid color-mix(in srgb, #c41e3a 25%, transparent);">{{ $errors->first('signed_copy') ?: session('error') }}</div>
    @endif

    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <iframe src="{{ route('admin.ppra-employment-letters.download', $letter->id) }}" style="width:100%; height:640px; border:none; border-radius:4px; background:#fff;"></iframe>
        <div class="mt-3">
            <a href="{{ route('admin.ppra-employment-letters.download', $letter->id) }}?inline=0" class="corex-btn-outline text-xs">Download</a>
        </div>
    </div>

    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <h2 class="text-sm font-bold mb-2" style="color:var(--text-primary);">Signed copy</h2>
        @include('compliance.ppra-employment-letters._signed-copies', [
            'letter'        => $letter,
            'uploadUrl'     => route('admin.ppra-employment-letters.upload', $letter->id),
            'scanRouteName' => 'admin.ppra-employment-letters.signed-copy',
        ])
    </div>

    @if($letter->trashed())
        <form method="POST" action="{{ route('admin.ppra-employment-letters.restore', $letter->id) }}">
            @csrf
            <button type="submit" class="corex-btn-primary text-xs">Restore letter</button>
        </form>
    @else
        <form method="POST" action="{{ route('admin.ppra-employment-letters.archive', $letter->id) }}" onsubmit="return confirm('Archive this letter?');">
            @csrf
            <button type="submit" class="text-xs font-semibold" style="color:var(--ds-crimson,#c41e3a);">Archive letter</button>
        </form>
    @endif

</div>
@endsection
