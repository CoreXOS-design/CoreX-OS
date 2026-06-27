{{-- MIC Work / Analyse header actions — folded from the legacy _top-bar into
     the branded page header. White-on-navy styling. Manager-only.
     UI only; behaviour (in-stock toggle, Setup link) is identical to the
     former _top-bar controls. UI_DESIGN_SYSTEM.md §2.4. --}}
@php
    $isManager = auth()->user()?->hasPermission('prospecting_setup.manage') ?? false;
    $includeInStockToggle = (bool) request()->boolean('include_in_stock');
@endphp
{{-- Tour "?" launcher is now rendered by the x-mic-page-header component itself,
     so it appears on every MIC page (not just the ones passing these actions). --}}
@if($isManager)
    <label class="inline-flex items-center gap-2 text-xs cursor-pointer"
           style="color: rgba(255,255,255,0.8);"
           title="Audit-only: include listings already promoted to agency stock">
        <input type="checkbox"
               {{ $includeInStockToggle ? 'checked' : '' }}
               onchange="(function(cb){
                   const url = new URL(window.location.href);
                   if (cb.checked) { url.searchParams.set('include_in_stock','1'); }
                   else { url.searchParams.delete('include_in_stock'); }
                   window.location.href = url.toString();
               })(this)">
        Show in-stock too
    </label>
    <a href="{{ route('settings.prospecting.index') }}"
       class="corex-btn-outline text-sm"
       style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);"
       title="Configure prospecting segments and suggested-action thresholds">
        Setup
    </a>
@endif
