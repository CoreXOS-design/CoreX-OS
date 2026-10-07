{{-- Compact Buyer Pipeline status chip — the pipeline's own labels and colours
     (same ds-badge classes as command-center/buyers/pipeline.blade.php). Renders
     nothing when the contact has no pipeline status, so there is never an
     always-lit badge. --}}
@props(['state' => null])
@php
    $stateClass = match ($state) {
        'new'  => 'ds-badge-info',
        'warm' => 'ds-badge-success',
        'cold' => 'ds-badge-warning',
        'lost' => 'ds-badge-danger',
        'won'  => 'ds-badge-success',
        default => null,
    };
@endphp
@if($stateClass)
<span class="ds-badge {{ $stateClass }} whitespace-nowrap flex-shrink-0" title="Buyer pipeline status" data-buyer-state="{{ $state }}">{{ ucfirst($state) }}</span>
@endif
