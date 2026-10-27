{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight truncate" style="color: var(--text-primary);">Sign the board approval of RMCP v{{ $version->version_number }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">You are signing as {{ $line->member_name }}, {{ $line->member_title }}</p>
            </div>
            <a href="{{ route('compliance.rmcp.show', $version) }}#board-approval" class="corex-btn-outline text-xs">&larr; Back to the RMCP</a>
        </div>
    </div>

    @if(session('error'))
        <div class="px-4 py-3 text-sm rounded-md" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="px-4 py-3 text-sm rounded-md" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <div class="max-w-3xl rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); line-height: 1.6;">
        {!! $letter->letter_html !!}
        @include('compliance.rmcp-board._signature-capture', [
            'action' => route('compliance.rmcp.board.sign.submit', $version),
            'agencyName' => \App\Models\Agency::displayNameFor($version->agency, 'the agency'),
        ])
    </div>
</div>
@endsection
