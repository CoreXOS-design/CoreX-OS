{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — what changed between two versions, side by side (spec §11.14). --}}
@extends('layouts.corex')

@section('corex-content')
@php $name = fn ($x) => $x->is_published ? 'Version ' . $x->version . ' — ' . $x->version_date->format('j M Y') : 'Draft (not published)'; @endphp
<div class="w-full space-y-4">
    @include('platform-esign._header', [
        'title' => 'What changed', 'tab' => 'wording',
        'sub' => 'Two versions side by side. Red is wording that was removed, green is wording that was added; an edited clause shows exactly which words moved.',
        'actions' => '<a href="' . route('platform-esign.wording.index') . '" class="corex-btn-outline">← Versions</a>',
    ])
    @include('platform-esign.wording._css')
    <style>
        .cmp-row { display:grid; grid-template-columns: 1fr 1fr; gap: 0; border-top:1px solid var(--border); }
        .cmp-cell { padding:.55rem .8rem; font-size:.86rem; line-height:1.5; white-space:pre-wrap; word-break:break-word; overflow-wrap:anywhere; min-width:0; }
        .cmp-cell + .cmp-cell { border-left:1px solid var(--border); }
        .cmp-removed .cmp-cell:first-child { background:#fef2f2; color:#111827; }
        .cmp-added .cmp-cell:last-child { background:#f0fdf4; color:#111827; }
        .cmp-changed .cmp-cell { background:#fffdf5; color:#111827; }
        .cmp-empty { background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 6px, #f1f5f9 6px, #f1f5f9 12px); color:#111827; }
        .cmp-same { color: var(--text-muted); }
        @media (max-width: 760px) { .cmp-row { grid-template-columns: 1fr; } .cmp-cell + .cmp-cell { border-left:0; border-top:1px dashed var(--border); } }
    </style>

    <form method="GET" class="rounded-md p-3 flex flex-col flex-wrap lg:flex-row gap-2 lg:items-center" style="background: var(--surface); border: 1px solid var(--border);">
        <label class="flex items-center gap-2 text-xs" style="color: var(--text-muted);">Older <select name="a" class="list-header-filter">@foreach($all as $x)<option value="{{ $x->id }}" @selected($a && $a->id === $x->id)>{{ $name($x) }}</option>@endforeach</select></label>
        <label class="flex items-center gap-2 text-xs" style="color: var(--text-muted);">Newer <select name="b" class="list-header-filter">@foreach($all as $x)<option value="{{ $x->id }}" @selected($b && $b->id === $x->id)>{{ $name($x) }}</option>@endforeach</select></label>
        <label class="flex items-center gap-1 text-xs" style="color: var(--text-muted);"><input type="checkbox" name="only" value="changes" @checked($onlyChanges)> Only show what changed</label>
        <button class="corex-btn-primary">Compare</button>
    </form>

    @if($result)
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
            <div><strong>{{ $name($a) }}</strong> → <strong>{{ $name($b) }}</strong></div>
            @if($a->id === $b->id)<div class="mt-1" style="color: var(--text-muted);">Pick two different versions to see what changed.</div>
            @else
                <div class="mt-1" style="color: var(--text-secondary);">{{ $result['total']['changed'] }} {{ \Illuminate\Support\Str::plural('clause', $result['total']['changed']) }} edited · {{ $result['total']['added'] }} added · {{ $result['total']['removed'] }} removed · {{ count($result['rates']) }} {{ \Illuminate\Support\Str::plural('rate', count($result['rates'])) }} changed</div>
                @if($b->change_note)<div class="mt-1 text-xs" style="color: var(--text-muted);">Note on the newer version: {{ $b->change_note }}</div>@endif
            @endif
        </div>

        @if($result['rates'])
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);"><div class="ds-section-header">Pricing</div></div>
                @foreach($result['rates'] as $r)<div class="px-5 py-2 text-sm flex flex-wrap gap-x-4" style="border-top: 1px solid var(--border);"><span style="min-width: 22rem;">{{ $r['label'] }}</span><span><del>{{ $r['a'] }}</del> → <ins>{{ $r['b'] }}</ins></span></div>@endforeach
            </div>
        @endif

        @foreach($result['parts'] as $key => $p)
            @php $sum = $p['summary']; $n = $sum['added'] + $sum['removed'] + $sum['changed']; @endphp
            @if($n > 0 || !$onlyChanges)
                <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="px-5 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                        <div class="ds-section-header">{{ $p['label'] }}</div>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $n === 0 ? 'No changes' : $sum['changed'] . ' edited · ' . $sum['added'] . ' added · ' . $sum['removed'] . ' removed' }}</span>
                    </div>
                    @php $hidden = 0; @endphp
                    @foreach($p['rows'] as $row)
                        @if($row['type'] === 'same')
                            @if($onlyChanges) @php $hidden++; @endphp @continue @endif
                            <div class="cmp-row cmp-same"><div class="cmp-cell">{{ \Illuminate\Support\Str::limit($row['a'], 220) }}</div><div class="cmp-cell">{{ \Illuminate\Support\Str::limit($row['b'], 220) }}</div></div>
                        @else
                            <div class="cmp-row cmp-{{ $row['type'] }}">
                                <div class="cmp-cell {{ $row['a'] === null ? 'cmp-empty' : '' }}">@if($row['type'] === 'changed'){!! $row['aHtml'] !!}@else{{ $row['a'] }}@endif</div>
                                <div class="cmp-cell {{ $row['b'] === null ? 'cmp-empty' : '' }}">@if($row['type'] === 'changed'){!! $row['bHtml'] !!}@else{{ $row['b'] }}@endif</div>
                            </div>
                        @endif
                    @endforeach
                    @if($onlyChanges && $hidden > 0)<div class="px-5 py-2 text-xs" style="border-top: 1px solid var(--border); color: var(--text-muted);">{{ $hidden }} unchanged {{ \Illuminate\Support\Str::plural('clause', $hidden) }} not shown.</div>@endif
                </div>
            @endif
        @endforeach
    @endif
    @include('platform-esign._end')
</div>
@endsection
