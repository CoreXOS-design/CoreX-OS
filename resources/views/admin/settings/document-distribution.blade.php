@extends('layouts.corex')
@section('title', 'Deal Document Distribution')

@section('corex-content')
<div style="max-width: 1000px; margin: 0 auto; padding: 1rem;">
    <h1 style="font-size:1.4rem;font-weight:800;color:var(--brand-default,#0b2a4a);margin-bottom:.25rem;">Deal Document Distribution</h1>
    <p style="color:var(--text-muted,#6b7280);font-size:.9rem;margin-bottom:1rem;">
        Party-first: for each deal party, tick the documents they may receive, choose how each is delivered,
        and (optionally) pin it to a stage. This is the single source of truth the deal send-buttons and
        e-sign completion both use.
    </p>

    @if(session('success'))<div class="corex-alert corex-alert-success" style="margin:1rem 0;">{{ session('success') }}</div>@endif

    <form method="POST" action="{{ route('admin.settings.document-distribution.save') }}">
        @csrf

        @foreach($partyRoles as $roleKey => $roleLabel)
            @php $roleCfg = $partyMatrix[$roleKey] ?? []; $count = count($roleCfg); @endphp
            <div class="corex-card" style="padding:0;margin-bottom:.85rem;" x-data="{ open: {{ $count ? 'true' : 'false' }} }">
                <div @click="open = !open" style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;cursor:pointer;background:var(--surface-2,#f9fafb);">
                    <div style="font-weight:700;color:var(--brand-default,#0b2a4a);">
                        {{ $roleLabel }}
                        <span class="corex-badge" style="background:{{ $count ? '#dcfce7' : '#f3f4f6' }};color:{{ $count ? '#166534' : '#6b7280' }};font-size:.7rem;margin-left:.4rem;">{{ $count }} doc{{ $count===1?'':'s' }}</span>
                    </div>
                    <span x-text="open ? '▲' : '▼'" style="color:#9ca3af;font-size:.8rem;"></span>
                </div>
                <div x-show="open" x-cloak style="padding:.25rem .5rem .5rem;">
                    <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                        <thead>
                            <tr style="color:var(--text-muted,#6b7280);">
                                <th style="text-align:left;padding:.4rem .6rem;width:40%;">Document type</th>
                                <th style="text-align:left;padding:.4rem .6rem;">Include</th>
                                <th style="text-align:left;padding:.4rem .6rem;">Delivery</th>
                                <th style="text-align:left;padding:.4rem .6rem;">Optional stage</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($types as $type)
                                @php $cfg = $roleCfg[$type->id] ?? null; @endphp
                                <tr style="border-top:1px solid var(--border,#eee);">
                                    <td style="padding:.4rem .6rem;">{{ $type->label }}</td>
                                    <td style="padding:.4rem .6rem;">
                                        <input type="checkbox" name="party[{{ $roleKey }}][{{ $type->id }}][include]" value="1" @checked($cfg) style="width:1.05rem;height:1.05rem;cursor:pointer;">
                                    </td>
                                    <td style="padding:.4rem .6rem;">
                                        <select name="party[{{ $roleKey }}][{{ $type->id }}][delivery_mode]" class="corex-input" style="font-size:.8rem;padding:.15rem .4rem;">
                                            <option value="secure_link" @selected(($cfg['delivery_mode'] ?? 'secure_link')==='secure_link')>Secure link (OTP)</option>
                                            <option value="direct_attachment" @selected(($cfg['delivery_mode'] ?? '')==='direct_attachment')>Attachment</option>
                                        </select>
                                    </td>
                                    <td style="padding:.4rem .6rem;">
                                        <select name="party[{{ $roleKey }}][{{ $type->id }}][stage]" class="corex-input" style="font-size:.8rem;padding:.15rem .4rem;">
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

        <button class="corex-btn-primary" style="margin-top:.5rem;">Save distribution</button>
    </form>
</div>
@endsection
