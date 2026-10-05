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
            <div><dt class="text-xs font-semibold" style="color:var(--text-muted);">Agent signed</dt><dd style="color:var(--text-primary);">{{ $letter->agent_signed_at?->format('d M Y H:i') ?? 'Not yet' }} @if($letter->agent_signed_ip) <span class="text-xs" style="color:var(--text-muted);">({{ $letter->agent_signed_ip }})</span>@endif</dd></div>
            <div><dt class="text-xs font-semibold" style="color:var(--text-muted);">Principal signed</dt><dd style="color:var(--text-primary);">{{ $letter->principal_signed_at?->format('d M Y H:i') ?? 'Not yet' }} @if($letter->principal_signed_ip) <span class="text-xs" style="color:var(--text-muted);">({{ $letter->principal_signed_ip }})</span>@endif</dd></div>
        </dl>
    </div>

    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
        <iframe src="{{ route('admin.ppra-employment-letters.download', $letter->id) }}" style="width:100%; height:640px; border:none; border-radius:4px; background:#fff;"></iframe>
        <div class="mt-3">
            <a href="{{ route('admin.ppra-employment-letters.download', $letter->id) }}?inline=0" class="corex-btn-outline text-xs">Download</a>
        </div>
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
