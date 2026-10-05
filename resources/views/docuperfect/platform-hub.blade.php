{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Platform E-Sign landing page (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $cards = [
        ['Send a contract', 'Pick a CoreX contract template, add the agency principal as the signer and send it for e-signature.', route('docuperfect.esign.create'), 'Send for signing', true, $sendable . ' ready to send'],
        ['Contract templates', 'The CoreX contract templates: open one to edit the wording, signature spots and fields, or archive it.', route('docuperfect.templates.index'), 'Manage templates', false, $templates . ' template' . ($templates === 1 ? '' : 's')],
        ['Create a template from a document', 'Upload your Word or PDF contract (Subscription Agreement, debit order form). It is turned into an e-sign template you can then edit.', route('docuperfect.import.index'), 'Import a document', false, null],
        ['Sent contracts', 'Track every contract that has been sent: who has signed, who is still to sign, and download the signed copy.', route('docuperfect.esign.myDocuments'), 'Open sent contracts', false, $sent . ' sent'],
        ['Recipient presets', 'Save the usual signers (for example the agency principal) so you do not retype them each time.', route('docuperfect.esign.recipient-presets.index'), 'Manage presets', false, null],
    ];
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Platform E-Sign</h1>
                <p class="text-xs" style="color: var(--text-muted);">CoreX's own contracts. This is the same e-sign agencies use — the same template creator, send wizard and signing — but everything here belongs to CoreX and is never visible to any agency.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.agency-timelines.index') }}" class="corex-btn-outline text-xs">Agency Timeline</a>
                <form method="POST" action="{{ route('admin.platform-esign.exit') }}">@csrf<button type="submit" class="corex-btn-outline text-xs">Exit Platform E-Sign</button></form>
            </div>
        </div>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach($cards as [$title, $text, $href, $cta, $primary, $meta])
            <div class="rounded-md p-5 flex flex-col" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex items-start justify-between gap-2">
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $title }}</div>
                    @if($meta)<span class="ds-badge ds-badge-default">{{ $meta }}</span>@endif
                </div>
                <p class="text-xs mt-2 flex-1" style="color: var(--text-muted);">{{ $text }}</p>
                <div class="mt-4"><a href="{{ $href }}" class="{{ $primary ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs">{{ $cta }}</a></div>
            </div>
        @endforeach
    </div>

    <p class="text-xs" style="color: var(--text-muted);">After a contract is sent, link it to the agency on that agency's Agency Timeline page — when it is fully signed, the "sign agreement" step ticks itself.</p>
</div>
@endsection
