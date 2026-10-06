{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — template preview (AT-447, spec §3A). --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    @include('platform-esign._header', ['title' => 'Preview — ' . $template->name, 'tab' => 'templates',
        'sub' => 'Merge fields are shown as [field_name]. Real values are filled in when you send.',
        'actions' => '<a href="' . route('platform-esign.templates.index') . '" class="corex-btn-outline">← Templates</a><a href="' . route('platform-esign.templates.edit', $template->id) . '" class="corex-btn-outline">Edit</a>'])
    <div class="rounded-md p-8 max-w-4xl leading-relaxed" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        {!! $html !!}
        @foreach($template->roles() as $r)
            <div class="mt-8 pt-3" style="border-top: 1px solid var(--border);"><strong>{{ $r['label'] }}</strong><div class="text-xs" style="color: var(--text-muted);">Signature block</div></div>
        @endforeach
    </div>
    @include('platform-esign._end')
</div>
@endsection
