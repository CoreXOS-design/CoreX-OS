{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — preview a version exactly as the recipient and the PDF render it (spec §11.13). --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-4">
    @include('platform-esign._header', [
        'title' => 'Preview — ' . ($v->is_published ? $v->label() : 'Draft'), 'tab' => 'wording',
        'sub' => $viewMode === 'print' ? 'As the signed PDF prints, with a worst-case sample of entries so you can see how every page fills.' : 'As the recipient sees it: the paginated form, fields not yet filled in. Fields are switched off here.',
        'actions' => '<a href="' . route('platform-esign.wording.show', $v->id) . '" class="corex-btn-outline">← Back</a>'
            . '<a href="' . route('platform-esign.wording.preview', [$v->id, 'view' => $viewMode === 'print' ? 'form' : 'print']) . '" class="corex-btn-outline">' . ($viewMode === 'print' ? 'Show the recipient form' : 'Show how it prints') . '</a>'
            . '<a href="' . route('platform-esign.wording.preview.pdf', $v->id) . '" target="_blank" class="corex-btn-primary">Sample PDF</a>',
    ])
    @include('platform-esign.agreement._css')
    @include('platform-esign.agreement._sheets-css')
    @include('platform-esign.wording._css')
    <div class="rounded-md px-4 py-3 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">{{ $total }} pages. The agency initials every one of them, and the sealed PDF has exactly these pages.@unless($v->is_published) The page layout is calculated from this draft's wording and saved with it.@endunless</div>
    <div class="agr-wrap" style="max-width:860px;">
        @include('platform-esign.agreement._sheets', ['pages' => $pages, 'total' => $total, 'versionLabel' => $v->is_published ? $v->label() : 'Draft — not yet published'])
    </div>
</div>
@endsection
