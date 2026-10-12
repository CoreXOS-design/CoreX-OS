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
        <label class="flex flex-wrap items-center gap-2 text-sm rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-contractor-row="{{ $c['id'] }}"
               data-search="{{ mb_strtolower($c['name'] . ' ' . $c['trades'] . ' ' . $c['phone']) }}"
               x-show="q === '' || $el.dataset.search.indexOf(q.toLowerCase()) !== -1">
            <input type="radio" name="contractor_pick" value="{{ $c['id'] }}" x-model="picked">
            <span class="font-medium">{{ $c['name'] }}</span>
            @if(($c['kind'] ?? 'contractor') !== 'contractor')<span class="ds-badge ds-badge-muted" data-contractor-kind="{{ $c['kind'] }}">{{ $c['kind_label'] }}</span>@endif
            @if($c['trades'])<span class="text-xs" style="color: var(--text-muted);">{{ $c['trades'] }}</span>@endif
            @if($c['phone'])<span class="text-xs" style="color: var(--text-muted);">{{ $c['phone'] }}</span>@endif
            @if(! ($c['has_contact'] ?? true))<span class="ds-badge ds-badge-danger" data-no-contact-details>no contact details</span>@endif
            @if(isset($c['callout']))<span class="text-xs" style="color: var(--text-muted);" data-callout-suffix="{{ $c['callout_status'] }}">- {{ $c['callout'] }}</span>@endif
        </label>
    @endforeach
@empty
    <p class="text-xs" style="color: var(--text-muted);">No contractors are set up yet. Add one under Suppliers, or use the internal crew.</p>
@endforelse
