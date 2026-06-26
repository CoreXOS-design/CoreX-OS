@extends('layouts.corex')

{{--
    AT-91 — WhatsApp Outreach Summary board (agents × outreach states).
    Body extracted to corex.outreach-summary._board so it can be reused as Tab 2
    of the unified Outreach & Canvassing board (Part 4) without duplicating markup.
    Spec: .ai/specs/whatsapp-outreach-summary.md
--}}

@section('corex-content')
@include('corex.outreach-summary._board', ['rows' => $rows, 'totals' => $totals, 'hasAwaiting' => $hasAwaiting])
@endsection
