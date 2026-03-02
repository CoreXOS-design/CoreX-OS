@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ route('docuperfect.rental') }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to Dashboard
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">Signature Review</h2>
        </x-slot>
    </x-sticky-action-bar>

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Signature Review</h2>
                <div class="text-sm text-white/60">Review and approve before advancing.</div>
            </div>
            <a href="{{ route('docuperfect.rental') }}" class="text-sm text-white/60 hover:text-white">&larr; Back to Dashboard</a>
        </div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    {{-- Document info --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <h3 class="font-semibold text-slate-800 mb-3">{{ $document->name }}</h3>

        @if($completedRequest)
            <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 mb-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                    </div>
                    <div>
                        <div class="font-semibold text-amber-800">Awaiting Your Approval</div>
                        <div class="text-sm text-amber-700 mt-1">
                            <strong>{{ $completedRequest->signer_name }}</strong>
                            ({{ ucfirst($completedRequest->party_role) }})
                            signed on {{ $completedRequest->completed_at?->format('d M Y \a\t H:i') }}
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Signing progress for all parties --}}
        <div class="space-y-2 mb-4">
            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Signing Progress</div>
            @foreach(['agent', 'tenant', 'landlord'] as $role)
                @php $p = $progress[$role] ?? null; @endphp
                @if($p)
                <div class="flex items-center gap-3 text-sm py-1.5">
                    @if($p['is_complete'])
                        <span class="text-emerald-500 text-lg">&#10003;</span>
                        <span class="text-slate-600 capitalize w-20">{{ $role }}</span>
                        <span class="text-emerald-600 font-medium">{{ $p['name'] }}</span>
                        <span class="text-slate-400 text-xs ml-auto">
                            {{ $p['signed_markers'] }}/{{ $p['total_markers'] }} markers
                            @if($p['completed_at'])
                                &mdash; {{ $p['completed_at']->format('d M H:i') }}
                            @endif
                        </span>
                    @else
                        <span class="text-slate-300 text-lg">&#128274;</span>
                        <span class="text-slate-400 capitalize w-20">{{ $role }}</span>
                        <span class="text-slate-400">{{ $p['name'] }} &mdash; waiting</span>
                    @endif
                </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Document preview --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <h4 class="font-semibold text-slate-800 mb-3">Document Preview</h4>
        <div class="space-y-4">
            @for($pageNum = 0; $pageNum < $pageCount; $pageNum++)
                <div class="relative border border-slate-200 rounded-lg overflow-hidden">
                    <img src="{{ $pageImages[$pageNum] ?? '' }}" alt="Page {{ $pageNum + 1 }}" class="w-full h-auto">

                    @if(empty($hasFlattened))
                        {{-- FALLBACK: Overlay field values + signatures when not flattened --}}
                        @php
                            $docFields = $document->fields_json ?? [];
                            $pageMarkers = $allMarkers->where('page_number', $pageNum + 1);
                            $pageFields = collect($docFields)->where('pageIndex', $pageNum);
                        @endphp

                        @foreach($pageFields as $field)
                            @php
                                $type = $field['type'] ?? 'placeholder';
                                $pos = $field['position'] ?? [];
                                $size = $field['size'] ?? [];
                                $style = $field['style'] ?? [];
                                $x = $pos['x'] ?? 0;
                                $y = $pos['y'] ?? 0;
                                $w = $size['width'] ?? 0;
                                $h = $size['height'] ?? 0;
                                $fontSize = $style['fontSize'] ?? 12;
                                $fontFamily = $style['fontFamily'] ?? 'Helvetica';
                                $bold = !empty($style['bold']) ? 'font-weight:bold;' : '';
                                $underline = !empty($style['underline']) ? 'text-decoration:underline;' : '';
                                $solidBg = !empty($style['solidBackground']) ? 'background:white;' : '';
                                $fieldCss = "font-size:{$fontSize}px;font-family:{$fontFamily};color:#000;{$bold}{$underline}{$solidBg}";
                            @endphp

                            @if($type === 'placeholder' && !empty(trim((string)($field['value'] ?? ''))))
                                <div class="absolute pointer-events-none overflow-hidden"
                                     style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                    <div class="w-full h-full flex items-start px-0.5 overflow-hidden"
                                         style="{{ $fieldCss }}">{{ $field['value'] }}</div>
                                </div>
                            @elseif($type === 'date' && !empty(trim((string)($field['value'] ?? ''))))
                                <div class="absolute pointer-events-none overflow-hidden"
                                     style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                    <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                         style="{{ $fieldCss }}">{{ $field['value'] }}</div>
                                </div>
                            @elseif($type === 'selection' && !empty($field['selectedValue']))
                                <div class="absolute pointer-events-none overflow-hidden"
                                     style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                    <div class="w-full h-full flex items-center px-0.5 overflow-hidden" style="{{ $fieldCss }}">
                                        <span class="bg-cyan-100 text-cyan-800 px-1.5 py-0.5 rounded text-xs">{{ $field['selectedValue'] }}</span>
                                    </div>
                                </div>
                            @elseif($type === 'condition' && !empty(trim((string)($field['text'] ?? ''))))
                                <div class="absolute pointer-events-none overflow-hidden"
                                     style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                    <div class="w-full h-full overflow-hidden px-0.5 bg-white/85"
                                         style="{{ $fieldCss }}">{{ $field['text'] }}</div>
                                </div>
                            @elseif($type === 'strikethrough' && !empty($field['active']))
                                <div class="absolute pointer-events-none overflow-hidden"
                                     style="left:{{ $x }}%;top:{{ $y }}%;width:{{ $w }}%;height:{{ $h }}%;z-index:5;">
                                    @if(($field['strikethroughType'] ?? 'horizontal') === 'horizontal')
                                        <div class="absolute top-1/2 left-0 w-full h-0.5 bg-red-500 -translate-y-1/2"></div>
                                    @else
                                        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                                            <line x1="0" y1="0" x2="100" y2="100" stroke="#ef4444" stroke-width="3" />
                                        </svg>
                                    @endif
                                </div>
                            @endif
                        @endforeach

                        @foreach($pageMarkers as $marker)
                            @php $sig = $marker->signatures->first(); @endphp
                            <div class="absolute border-2 rounded"
                                 style="left: {{ $marker->x_position }}%; top: {{ $marker->y_position }}%; width: {{ $marker->width }}%; height: {{ $marker->height }}%; z-index:10; {{ $sig ? 'border-color: #10b981;' : 'border-color: #d1d5db; border-style: dashed;' }}">
                                @if($sig && $sig->signature_data)
                                    <img src="{{ $sig->signature_data }}" class="w-full h-full object-contain" alt="Signature">
                                @endif
                            </div>
                        @endforeach
                    @endif
                    {{-- When flattened: fields + signatures are already baked into the image --}}

                    <div class="absolute bottom-2 right-2 bg-white/80 text-xs text-slate-500 px-2 py-0.5 rounded">
                        Page {{ $pageNum + 1 }}
                    </div>
                </div>
            @endfor
        </div>
    </div>

    {{-- Marker checklist --}}
    @if($completedRequest)
        @php
            $completedRole = $completedRequest->party_role;
            $roleMarkers = $allMarkers->where('assigned_party', $completedRole);
            $signedCount = $roleMarkers->filter(fn($m) => $m->signatures->isNotEmpty())->count();
            $totalCount = $roleMarkers->where('required', true)->count();
        @endphp
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="font-semibold text-emerald-800">All signature zones signed</span>
            </div>
            <div class="text-sm text-emerald-700">
                {{ $signedCount }} of {{ $totalCount }} required markers completed by {{ $completedRequest->signer_name }}
            </div>
        </div>
    @endif

    {{-- Action buttons --}}
    <div class="flex items-center justify-between gap-4">
        <a href="{{ route('docuperfect.rental') }}"
           class="px-4 py-2 text-sm text-slate-600 border border-slate-300 rounded-lg hover:bg-slate-50">
            Cancel
        </a>

        <form method="POST" action="{{ route('docuperfect.signatures.approveAndAdvance', $document) }}">
            @csrf
            <button type="submit"
                    class="px-6 py-2.5 text-sm font-medium rounded-lg text-white transition-colors
                           {{ $nextParty
                               ? 'bg-blue-600 hover:bg-blue-700'
                               : 'bg-emerald-600 hover:bg-emerald-700' }}"
                    onclick="return confirm('{{ $nextParty
                        ? 'Approve and send to ' . ucfirst($nextParty) . '?'
                        : 'Approve and complete the document?' }}')">
                @if($nextParty)
                    Approve &amp; Send to {{ ucfirst($nextParty) }} &rarr;
                @else
                    Approve &amp; Complete Document
                @endif
            </button>
        </form>
    </div>

</div>
@endsection
