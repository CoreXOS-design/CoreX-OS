@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a; border-radius:16px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Rental Settings</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">
            <a href="{{ route('rental.dashboard') }}" style="color:rgba(255,255,255,0.55);" class="hover:text-white">&larr; Back to Rentals</a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('rental.settings.properties.index') }}"
           class="flex items-center gap-4 p-5 rounded-xl hover:shadow-md transition-shadow"
           style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold text-sm" style="color:var(--text-primary);">Properties</h3>
                <p class="text-xs mt-0.5" style="color:var(--text-secondary);">Manage rental properties, addresses, and landlord details</p>
            </div>
        </a>

        <a href="{{ route('rental.settings.document-types.index') }}"
           class="flex items-center gap-4 p-5 rounded-xl hover:shadow-md transition-shadow"
           style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold text-sm" style="color:var(--text-primary);">Document Types</h3>
                <p class="text-xs mt-0.5" style="color:var(--text-secondary);">Configure document types and lease tracking flags</p>
            </div>
        </a>

        {{-- Future: Reminder Rules --}}
        <div class="flex items-center gap-4 p-5 rounded-xl opacity-50 cursor-not-allowed"
             style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold text-sm" style="color:var(--text-muted);">Lease Expiry Reminders</h3>
                <p class="text-xs mt-0.5" style="color:var(--text-muted);">Coming soon — configure when to send expiry notifications</p>
            </div>
        </div>
    </div>

</div>
@endsection
