{{--
    Public-link resilience audit item #5 (2026-08-24, Johan): the contact
    record behind this opt-out/opt-in link was archived after the message
    went out. Previously indistinguishable from an unknown/invalid token —
    both bare 404'd. Per the 3-branch policy, a valid-but-dead link may be
    specific; an unknown token stays a plain generic 404 (unchanged, still
    handled by resolveSend()'s abort(404)). Shared by both
    PublicOptOutController and PublicOptInController — same message either
    way, since there is nothing left to opt in or out of.

    Props: $agencyName, $agencyLogoUrl (nullable), $brand (nullable array)
--}}
@extends('layouts.public')

@section('title', 'Communication preferences — ' . $agencyName)

@section('public-content')

@php
    $brand = $brand ?? [];
    $agencyLogoUrl = $agencyLogoUrl ?? null;
@endphp

@if(!empty($brand))
@push('head')
<style>
    :root {
        --brand-sidebar: {{ $brand['sidebar'] ?? '#0b2a4a' }};
        --brand-icon:    {{ $brand['icon'] ?? '#33c4e0' }};
        --brand-default: {{ $brand['default'] ?? '#0b2a4a' }};
        --brand-button:  {{ $brand['button'] ?? '#00b4d8' }};
    }
</style>
@endpush
@endif

<div class="text-center mb-5">
    @if($agencyLogoUrl)
        <img src="{{ $agencyLogoUrl }}" alt="{{ $agencyName }}" style="max-height:56px;width:auto;margin:0 auto 10px;display:block;">
    @endif
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary, #111827);">
        {{ $agencyName }}
    </h1>
</div>

<div class="p-5 rounded-md text-center"
     style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e5e7eb);">
    <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary, #111827);">
        There's nothing to manage here
    </h2>
    <p class="text-sm" style="color: var(--text-secondary, #4b5563);">
        You're not currently receiving messages from {{ $agencyName }}, so there are no
        communication preferences to update on this link.
    </p>
</div>
