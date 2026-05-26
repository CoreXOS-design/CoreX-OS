@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ route('corex.contacts.show', $contact) }}"
           class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">
            ← Back to {{ trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'contact' }}
        </a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Outreach Timeline</h1>
        <p class="text-sm text-white/60">
            Every WhatsApp / email pitch sent to this contact, plus clicks and outcomes.
        </p>
    </div>

    @include('seller-outreach.contact-timeline._panel')
</div>
@endsection
