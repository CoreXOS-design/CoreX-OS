{{-- .ai/specs/rentals-faults-work-orders.md section 24 - one collapsible working panel on the staff work order screen: a title and ONE summary line in the header, so a closed panel still reads. --}}
@props(['key', 'title', 'summary' => '', 'open' => false])
<details class="wo-panel" id="wo-panel-{{ $key }}" data-wo-panel="{{ $key }}" @if($open) open @endif>
    <summary class="wo-panel-head">
        <span class="wo-chev" aria-hidden="true"></span>
        <span class="wo-panel-title">{{ $title }}</span>
        <span class="wo-panel-sum" data-panel-summary>{{ $summary }}</span>
    </summary>
    <div class="wo-panel-body">{{ $slot }}</div>
</details>
