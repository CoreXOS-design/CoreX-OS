@extends('layouts.corex')
@section('title', 'Document Distribution Matrix')

@section('corex-content')
<div style="max-width: 1000px; margin: 0 auto; padding: 1rem;">
    <h1 style="font-size:1.4rem;font-weight:800;color:var(--brand-default,#0b2a4a);margin-bottom:.25rem;">Document Distribution Matrix</h1>
    <p style="color:var(--text-muted,#6b7280);font-size:.9rem;margin-bottom:1rem;">
        Per document type: which deal parties may it be emailed to. This is the single source of truth the
        deal send-buttons and e-sign completion both use. Tick a party to make a type distributable to it.
        @if(\Illuminate\Support\Facades\Route::has('admin.settings.document-types.index'))
            <a href="{{ route('admin.settings.document-types.index') }}" style="font-size:.85rem;">← Document Types</a>
        @endif
    </p>

    @if(session('success'))<div class="corex-alert corex-alert-success" style="margin:1rem 0;">{{ session('success') }}</div>@endif

    <form method="POST" action="{{ route('admin.settings.document-distribution.save') }}">
        @csrf
        <div class="corex-card" style="padding:0;overflow-x:auto;">
            <table class="corex-table" style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <thead>
                    <tr style="background:var(--surface-2,#f9fafb);">
                        <th style="text-align:left;padding:.6rem .75rem;position:sticky;left:0;background:inherit;">Document type</th>
                        @foreach($partyRoles as $roleKey => $roleLabel)
                            <th style="text-align:center;padding:.6rem .5rem;white-space:nowrap;">{{ $roleLabel }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($types as $type)
                        @php $current = $matrix[$type->id] ?? []; @endphp
                        <tr style="border-top:1px solid var(--border,#e5e7eb);">
                            <td style="padding:.55rem .75rem;font-weight:600;position:sticky;left:0;background:var(--surface,#fff);">
                                {{ $type->label }}
                                @if(count($current))<span class="corex-badge" style="background:#dcfce7;color:#166534;font-size:.65rem;margin-left:.35rem;">distributable</span>@endif
                            </td>
                            @foreach($partyRoles as $roleKey => $roleLabel)
                                <td style="text-align:center;padding:.55rem .5rem;">
                                    <input type="checkbox" name="dist[{{ $type->id }}][]" value="{{ $roleKey }}"
                                           @checked(in_array($roleKey, $current, true))
                                           style="width:1.05rem;height:1.05rem;cursor:pointer;">
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <button class="corex-btn-primary" style="margin-top:1rem;">Save matrix</button>
    </form>
</div>
@endsection
