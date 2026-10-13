{{-- The contractor rows of a fault: search box, the "no contractor loaded" warning with its two add paths, then "Set for this property" / "Suits <type>" /
     "Other contractors" with each contractor's trades, phone, kind badge and cc4's call-out suffix. ONE copy, used by the appoint form and by the Decision 1 block.
     Needs the page's variables $faultReport, $contractorPicker, $contractorClassify, $canManageContractors, $tradeCode, and, in the enclosing Alpine scope, `q` (search)
     and `picked` (the chosen supplier id). --}}
<input type="search" x-model="q" placeholder="Search contractors by name, trade or phone" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
{{-- AT-452 round 2 (C) - ONE rule (RentalFaultContractorService::classify): the contractors that suit this type of work first, the rest below
     (still selectable - an agency may use a general handyman); when none suit, say so and offer the two add paths. --}}
@php($cls = $contractorClassify ?? null)
@if($cls && $cls['category'] && $cls['suits']->isEmpty())
    @php($typeName = $cls['type'] ?: $cls['category'])
    <div class="text-xs" data-no-contractor-suits>
        <p class="font-semibold">No contractor loaded for {{ $typeName }}.@if($cls['mapping_gap']) <span class="font-normal">Your trade list has no &ldquo;{{ $cls['category'] }}&rdquo; yet &mdash; add it under Settings &rarr; COC / Service Types, then tick it on the contractor who does this work.</span>@endif</p>
        @if(!empty($canManageContractors))
            <button type="button" class="underline" data-contractor-popup="add" data-fault-id="{{ $faultReport->id }}" data-type="{{ $typeName }}" data-trade-code="{{ $tradeCode ?? '' }}">Add a contractor</button> &middot;
            <button type="button" class="underline" data-contractor-popup="types" data-fault-id="{{ $faultReport->id }}" data-type="{{ $typeName }}">Add {{ $typeName }} to an existing contractor</button>
        @endif
    </div>
@endif
@forelse(collect($contractorPicker)->groupBy(fn ($c) => ($c['first_responder'] ?? false) ? 'set' : ($cls && $cls['category'] ? ($c['suits'] ? 'suits' : 'others') : 'all')) as $groupKey => $groupRows)
    @if($groupKey === 'set')
        {{-- section 21.3 - the people set up to look at this fault first, in their order --}}
        <div class="text-xs font-semibold pt-1" data-contractor-group="set">Set for this property</div>
    @elseif($cls && $cls['category'])
        <div class="text-xs font-semibold pt-1" data-contractor-group="{{ $groupKey }}">{{ $groupKey === 'suits' ? 'Suits ' . ($cls['type'] ?: $cls['category']) : 'Other contractors' }}</div>
    @endif
    @foreach($groupRows as $c)
        {{-- one aligned line each: name | kind or trade | phone | call-out; a contractor with no way to reach them gets a plain amber line under it --}}
        <label class="cx-crow rounded-md px-2 py-1 text-sm" style="border: 1px solid var(--border);" data-contractor-row="{{ $c['id'] }}"
               data-search="{{ $c['search'] ?? mb_strtolower($c['name'] . ' ' . $c['trades'] . ' ' . $c['phone']) }}"
               x-show="q === '' || $el.dataset.search.indexOf(q.toLowerCase()) !== -1">
            <input type="radio" name="contractor_pick" value="{{ $c['id'] }}" x-model="picked">
            <span class="font-medium cx-crow-name">{{ $c['name'] }}</span>
            <span class="text-xs cx-crow-trade" style="color: var(--text-muted);">@if(($c['kind'] ?? 'contractor') !== 'contractor')<span data-contractor-kind="{{ $c['kind'] }}">{{ $c['kind_label'] }}</span>@if($c['trades']) &middot; @endif @endif{{ $c['trades'] }}</span>
            <span class="text-xs cx-crow-phone" style="color: var(--text-muted);">{{ $c['phone'] }}</span>
            <span class="text-xs cx-crow-callout" style="color: var(--text-muted);">@if(isset($c['callout']))<span data-callout-suffix="{{ $c['callout_status'] }}">{{ $c['callout'] }}</span>@endif</span>
            @if(! ($c['has_contact'] ?? true))<span class="cx-crow-warn" data-no-contact-details>No email or mobile on file - they cannot be sent the job</span>@endif
        </label>
    @endforeach
@empty
    <p class="text-xs" style="color: var(--text-muted);">No contractors are set up yet. Add one under Suppliers, or use the internal crew.</p>
@endforelse
@once
<style>
    .cx-crow { display: grid; grid-template-columns: 1.25rem minmax(0, 1fr); column-gap: .5rem; row-gap: .1rem; align-items: center; cursor: pointer; }
    .cx-crow-name { grid-column: 2; }
    .cx-crow-trade, .cx-crow-phone, .cx-crow-callout { grid-column: 2; }
    .cx-crow-trade:empty, .cx-crow-phone:empty, .cx-crow-callout:empty { display: none; }
    .cx-crow-warn { grid-column: 2; font-size: 12px; padding: 2px 8px; border-radius: 6px; background: color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, var(--surface)); border: 1px solid var(--ds-amber, #f59e0b); }
    @media (min-width: 640px) {
        .cx-crow { grid-template-columns: 1.25rem minmax(9rem, 1.4fr) minmax(7rem, 1.2fr) minmax(7rem, 1fr) minmax(7rem, 1fr); }
        .cx-crow-name { grid-column: 2; } .cx-crow-trade { grid-column: 3; } .cx-crow-phone { grid-column: 4; } .cx-crow-callout { grid-column: 5; }
        .cx-crow-trade:empty, .cx-crow-phone:empty, .cx-crow-callout:empty { display: block; }
        .cx-crow-warn { grid-column: 2 / -1; }
    }
</style>
@endonce
