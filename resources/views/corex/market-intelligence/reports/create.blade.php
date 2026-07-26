{{-- MIC Phase F — Upload form. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 720px; margin: 0 auto; padding: 0 20px;">
    @include('corex.market-intelligence.partials.tabs')

    {{-- Page header — flat neutral bar (AT-336). Back link sits in the right
         action cluster rather than stacked above the title. --}}
    <div style="margin-bottom: 16px; padding: 0 0 14px 0; border-bottom: 1px solid var(--border);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Upload a market report</h1>
                <p class="text-xs" style="margin: 2px 0 0 0; color: var(--text-muted);">
                    PDF only, up to 20MB. The format is auto-detected; pick a type manually only if auto-detect picks the wrong one.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('market-intelligence.reports.index') }}" class="corex-btn-outline text-xs">← All reports</a>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem;
                    background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent);
                    color: var(--ds-crimson, #dc2626);
                    border: 1px solid var(--ds-crimson, #dc2626); border-radius: 4px;">
            <ul style="margin: 0; padding-left: 18px;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('market-intelligence.reports.store') }}" enctype="multipart/form-data"
          style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 16px;">
        @csrf

        <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">PDF file *</label>
        <input type="file" name="file" accept="application/pdf" required
               style="width: 100%; padding: 6px; font-size: 0.8125rem; margin-bottom: 14px;
                      background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); border-radius: 4px;">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Suburb (optional)</label>
                <input type="text" name="source_suburb" maxlength="120" value="{{ old('source_suburb') }}"
                       style="width: 100%; padding: 6px 8px; font-size: 0.8125rem; background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Town (optional)</label>
                <input type="text" name="source_town" maxlength="120" value="{{ old('source_town') }}"
                       style="width: 100%; padding: 6px 8px; font-size: 0.8125rem; background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); border-radius: 4px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Report date (optional)</label>
                <input type="date" name="report_date" value="{{ old('report_date') }}"
                       style="width: 100%; padding: 6px 8px; font-size: 0.8125rem; background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); border-radius: 4px;">
            </div>
            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Type (optional — auto-detected if blank)</label>
                <select name="report_type_id"
                        style="width: 100%; padding: 6px 8px; font-size: 0.8125rem; background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); border-radius: 4px;">
                    <option value="">— auto-detect —</option>
                    @foreach($reportTypes as $type)
                        <option value="{{ $type->id }}" {{ old('report_type_id') == $type->id ? 'selected' : '' }}>{{ $type->display_name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display: flex; gap: 8px; justify-content: flex-end;">
            <a href="{{ route('market-intelligence.reports.index') }}"
               style="padding: 8px 14px; font-size: 0.8125rem; font-weight: 500; color: var(--text-secondary);
                      border: 1px solid var(--border); border-radius: 4px; text-decoration: none;">Cancel</a>
            <button type="submit"
                    style="padding: 8px 14px; font-size: 0.8125rem; font-weight: 500;
                           background: var(--brand-button); color: #fff;
                           border: none; border-radius: 4px; cursor: pointer;">
                Upload &amp; parse
            </button>
        </div>
    </form>
</div>
@endsection
