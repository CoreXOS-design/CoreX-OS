{{-- Property identity S1: one line - how this agency knows the property (doors, portals, advert refs, first/last seen). Built by PropertySourceFacts. --}}
@if(!empty($facts) && ($facts['sources'] || $facts['refs']))
@php $shown = array_slice($facts['refs'], 0, 4); $more = count($facts['refs']) - count($shown); @endphp
<div data-qa="source-line" class="text-xs" style="margin-bottom: 14px; padding: 8px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; color: var(--text-secondary);">
    <strong style="color: var(--text-primary);">How we know this property</strong>
    · {{ implode(', ', $facts['sources']) ?: '-' }}
    · {{ implode(' + ', $facts['portals']) ?: '-' }}
    · {{ implode(', ', $shown) }}@if($more > 0) <span title="{{ implode(', ', $facts['refs']) }}">+{{ $more }} more</span>@endif
    @if($facts['first_seen']) · first seen {{ $facts['first_seen'] }}@endif
    @if($facts['last_seen']) · last seen {{ $facts['last_seen'] }}@endif
</div>
@endif
