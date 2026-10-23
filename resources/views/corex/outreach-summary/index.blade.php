@extends('layouts.corex')

{{--
    AT-91 — WhatsApp Outreach Summary board (agents × outreach states).
    Body extracted to corex.outreach-summary._board so it can be reused as Tab 2
    of the unified Outreach & Canvassing board (Part 4) without duplicating markup.
    Spec: .ai/specs/whatsapp-outreach-summary.md
--}}

@section('corex-content')
{{-- .ai/specs/real-estate-menu-tabs.md - this board is the Consent Funnel tab of Real Estate > Outreach --}}
@include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\OutreachTabs::class])
@include('corex.outreach-summary._board', ['rows' => $rows, 'totals' => $totals, 'hasAwaiting' => $hasAwaiting])
@endsection
