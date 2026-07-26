@extends('layouts.corex')
@section('title', 'Deal Document Distribution')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header — §2.4 Pattern A --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Deal Document Distribution</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Party-first: for each deal party, tick the documents they may receive, choose how each is delivered,
                    and (optionally) pin it to a stage. This is the single source of truth the deal send-buttons and
                    e-sign completion both use.
                </p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.settings.document-distribution.save') }}" class="space-y-3">
        @csrf

        @foreach($partyRoles as $roleKey => $roleLabel)
            @php $roleCfg = $partyMatrix[$roleKey] ?? []; $count = count($roleCfg); @endphp
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ open: {{ $count ? 'true' : 'false' }} }">
                <div @click="open = !open"
                     class="flex items-center justify-between px-4 py-3 cursor-pointer"
                     style="background: var(--surface-2);">
                    <div class="text-sm font-semibold flex items-center gap-2" style="color: var(--text-primary);">
                        {{ $roleLabel }}
                        <span class="rounded px-2 py-0.5 text-[0.7rem] font-medium"
                              style="background: {{ $count ? 'color-mix(in srgb, var(--ds-green, #059669) 12%, transparent)' : 'var(--surface)' }};
                                     border: 1px solid {{ $count ? 'color-mix(in srgb, var(--ds-green, #059669) 30%, transparent)' : 'var(--border)' }};
                                     color: {{ $count ? 'var(--ds-green, #059669)' : 'var(--text-muted)' }};">{{ $count }} doc{{ $count===1?'':'s' }}</span>
                    </div>
                    <span x-text="open ? '▲' : '▼'" class="text-xs" style="color: var(--text-muted);"></span>
                </div>
                <div x-show="open" x-cloak class="px-2 pb-2 pt-1 overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="text-xs" style="color: var(--text-muted);">
                                <th class="text-left font-medium px-3 py-2 w-2/5">Document type</th>
                                <th class="text-left font-medium px-3 py-2">Include</th>
                                <th class="text-left font-medium px-3 py-2">Delivery</th>
                                <th class="text-left font-medium px-3 py-2">Optional stage</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($types as $type)
                                @php $cfg = $roleCfg[$type->id] ?? null; @endphp
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="px-3 py-2" style="color: var(--text-secondary);">{{ $type->label }}</td>
                                    <td class="px-3 py-2">
                                        <input type="checkbox" name="party[{{ $roleKey }}][{{ $type->id }}][include]" value="1" @checked($cfg) class="cursor-pointer" style="width:1.05rem;height:1.05rem;accent-color: var(--brand-icon, #0ea5e9);">
                                    </td>
                                    <td class="px-3 py-2">
                                        <select name="party[{{ $roleKey }}][{{ $type->id }}][delivery_mode]"
                                                class="rounded-md px-2 py-1 text-xs"
                                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="secure_link" @selected(($cfg['delivery_mode'] ?? 'secure_link')==='secure_link')>Secure link (OTP)</option>
                                            <option value="direct_attachment" @selected(($cfg['delivery_mode'] ?? '')==='direct_attachment')>Attachment</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <select name="party[{{ $roleKey }}][{{ $type->id }}][stage]"
                                                class="rounded-md px-2 py-1 text-xs"
                                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="">Any stage</option>
                                            @foreach($stageOptions as $stageName => $stepId)
                                                <option value="{{ $stepId }}" @selected(($cfg['pipeline_step_id'] ?? null) == $stepId)>{{ $stageName }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <div class="flex justify-end">
            <button class="corex-btn-primary">Save distribution</button>
        </div>
    </form>
</div>
@endsection
