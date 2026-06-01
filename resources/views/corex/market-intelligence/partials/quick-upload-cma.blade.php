{{-- MIC Phase F — quick CMA upload widget for the Work tab top zone. --}}
@permission('mic.upload_reports')
<div style="margin-bottom: 16px; padding: 12px 14px;
            background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, var(--surface));
            border: 1px dashed color-mix(in srgb, var(--brand-icon, #0ea5e9) 35%, var(--border));
            border-radius: 6px;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 200px;">
            <div style="font-size: 0.8125rem; font-weight: 600; color: var(--text-primary); margin-bottom: 2px;">
                Got a CMA or market report?
            </div>
            <div style="font-size: 0.6875rem; color: var(--text-muted);">
                PDF, up to 20MB. Auto-detected and parsed in the background.
            </div>
        </div>
        <form method="POST" action="{{ route('market-intelligence.reports.store') }}"
              enctype="multipart/form-data"
              style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
            @csrf
            <input type="file" name="file" accept="application/pdf" required
                   style="font-size: 0.75rem; padding: 4px;
                          background: var(--surface); color: var(--text-primary);
                          border: 1px solid var(--border); border-radius: 6px;">
            <button type="submit" class="corex-btn-primary">
                Upload
            </button>
            <a href="{{ route('market-intelligence.reports.index') }}"
               style="padding: 6px 8px; font-size: 0.6875rem; color: var(--text-muted); text-decoration: none;">
                All reports →
            </a>
        </form>
    </div>
</div>
@endpermission
