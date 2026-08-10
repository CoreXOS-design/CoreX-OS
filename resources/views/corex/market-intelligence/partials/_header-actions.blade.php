{{-- MIC Work / Analyse header actions — folded from the legacy _top-bar into
     the branded page header. White-on-navy styling. Manager-only.
     UI only; behaviour (in-stock toggle, Setup link) is identical to the
     former _top-bar controls. UI_DESIGN_SYSTEM.md §2.4. --}}
@php
    $isManager = auth()->user()?->hasPermission('prospecting_setup.manage') ?? false;
    $includeInStockToggle = (bool) request()->boolean('include_in_stock');
    $includeMandatedToggle = (bool) request()->boolean('include_mandated');
@endphp
{{-- Tour "?" launcher is now rendered by the x-mic-page-header component itself,
     so it appears on every MIC page (not just the ones passing these actions). --}}
{{-- BUG 3 — every agent (not manager-gated): sole/exclusive-mandated listings
     are excluded from the canvassing pool by default; this reveals them. --}}
<label class="inline-flex items-center gap-2 text-xs cursor-pointer"
       style="color: rgba(255,255,255,0.8);"
       title="Sole/exclusive-mandated listings are excluded by default — another agency already holds the mandate. Check to include them anyway.">
    <input type="checkbox"
           {{ $includeMandatedToggle ? 'checked' : '' }}
           onchange="(function(cb){
               const url = new URL(window.location.href);
               if (cb.checked) { url.searchParams.set('include_mandated','1'); }
               else { url.searchParams.delete('include_mandated'); }
               window.location.href = url.toString();
           })(this)">
    Show sole/exclusive mandates
</label>
{{-- Address presence toggle (pull-all). Peer tick styled like the others: some
     captured listings have no street address; this restricts to those that do. --}}
<label class="inline-flex items-center gap-2 text-xs cursor-pointer"
       style="color: rgba(255,255,255,0.8);"
       title="Some captured listings have no street address yet. Check to show only listings that have an address.">
    <input type="checkbox"
           {{ request('address_filter') === 'with_address' ? 'checked' : '' }}
           onchange="(function(cb){
               const url = new URL(window.location.href);
               if (cb.checked) { url.searchParams.set('address_filter','with_address'); }
               else { url.searchParams.delete('address_filter'); }
               window.location.href = url.toString();
           })(this)">
    With address only
</label>
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
