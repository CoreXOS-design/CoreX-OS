{{--
    AT-495 - a button that opens the lease picker (corex.notices._lease-picker, included once on the page). Shown only to someone who holds the
    purpose's permission AND leases.view (the screens it leads to are lease screens). Receives: $purpose (received | sent | breach), $label,
    $title (optional), $template (optional template id), $class (optional), $qa (optional data-qa).
--}}
@php
    $pbPermission = ['received' => 'rental_notices.review', 'sent' => 'rental_notices.create', 'breach' => 'rental_breaches.manage'][$purpose] ?? null;
    $pbUser = auth()->user();
    $pbOk = $pbPermission && $pbUser && $pbUser->hasPermission($pbPermission) && $pbUser->hasPermission('leases.view');
@endphp
@feature('rental-leases')
@if($pbOk)
    <button type="button" class="{{ $class ?? 'corex-btn-primary text-xs' }}" data-lease-picker="{{ json_encode(['purpose' => $purpose, 'title' => $title ?? $label, 'template' => $template ?? null]) }}" @isset($qa) data-qa="{{ $qa }}" @endisset>{{ $label }}</button>
@endif
@endfeature
