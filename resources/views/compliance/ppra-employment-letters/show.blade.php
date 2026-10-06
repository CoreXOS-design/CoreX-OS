@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-3xl mx-auto" style="padding:24px;">
    <div class="flex items-center justify-between" style="margin-bottom:16px;">
        <div>
            <h1 style="font-size:1.25rem; font-weight:700; color:var(--text-primary); margin:0;">PPRA Confirmation of Employment</h1>
            <p style="font-size:0.8125rem; color:var(--text-muted); margin:4px 0 0;">{{ $agent->name }} &middot; {{ \App\Models\Compliance\PpraEmploymentLetter::statusLabel($letter->status) }}</p>
        </div>
        <a href="{{ route('agent.portal') }}#documents" style="font-size:0.8125rem; color:var(--brand-icon); text-decoration:none;">&larr; Back to My Portal</a>
    </div>

    @if(session('success'))
    <div class="rounded-md" style="font-size:0.8125rem; color:var(--ds-green); background:color-mix(in srgb, var(--ds-green) 10%, transparent); padding:10px 14px; margin-bottom:16px;">{{ session('success') }}</div>
    @endif
    @if(session('error') || $errors->has('signed_copy'))
    <div class="rounded-md" style="font-size:0.8125rem; color:var(--ds-crimson); background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); padding:10px 14px; margin-bottom:16px;">{{ $errors->first('signed_copy') ?: session('error') }}</div>
    @endif

    <div style="border:1px solid var(--border); border-radius:8px; padding:16px; margin-bottom:20px;">
        <iframe src="{{ route('ppra-employment-letters.download', $letter) }}?inline=1" style="width:100%; height:640px; border:none; border-radius:4px; background:#fff;"></iframe>
        <div class="flex items-center gap-2" style="margin-top:12px;">
            <a href="{{ route('ppra-employment-letters.download', $letter) }}" class="corex-btn-outline" style="font-size:0.75rem;">Download</a>
            <a href="{{ route('ppra-employment-letters.download', $letter) }}?inline=1" target="_blank" class="corex-btn-outline" style="font-size:0.75rem;">Print</a>
        </div>
    </div>

    <div style="border:1px solid var(--border); border-radius:8px; padding:16px; margin-bottom:16px;">
        <h3 style="font-size:0.9375rem; font-weight:700; color:var(--text-primary); margin:0 0 8px;">Signed copy</h3>
        @include('compliance.ppra-employment-letters._signed-copies', [
            'letter'        => $letter,
            'uploadUrl'     => $canUpload ? route('ppra-employment-letters.upload', $letter) : null,
            'scanRouteName' => 'ppra-employment-letters.signed-copy',
        ])
    </div>

    @if($isAgent && $letter->isCancellableByAgent())
    <form method="POST" action="{{ route('ppra-employment-letters.cancel', $letter) }}" onsubmit="return confirm('Archive this letter? You can start a new one any time.');">
        @csrf
        <button type="submit" style="font-size:0.75rem; color:var(--ds-crimson); background:none; border:none; cursor:pointer; text-decoration:underline; padding:0;">Archive this letter</button>
    </form>
    @endif
</div>
@endsection
