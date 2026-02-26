@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Rental Settings</h2>
            <div class="text-sm text-white/60">
                <a href="{{ route('rental.dashboard') }}" class="text-white/60 hover:text-white">&larr; Rentals</a>
            </div>
        </div>
    </div>

    <div class="max-w-2xl space-y-4">
        <a href="{{ route('rental.settings.properties.index') }}"
           class="block bg-white border rounded-lg p-5 hover:shadow-md transition">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-900">Properties</h3>
                    <p class="text-sm text-gray-500">Manage rental properties, addresses, and landlord details</p>
                </div>
            </div>
        </a>

        <a href="{{ route('rental.settings.document-types.index') }}"
           class="block bg-white border rounded-lg p-5 hover:shadow-md transition">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-900">Document Types</h3>
                    <p class="text-sm text-gray-500">Configure document types and lease tracking flags</p>
                </div>
            </div>
        </a>

        {{-- Future: Reminder Rules --}}
        <div class="bg-white border rounded-lg p-5 opacity-50 cursor-not-allowed">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-400">Lease Expiry Reminders</h3>
                    <p class="text-sm text-gray-400">Coming soon — configure when to send expiry notifications</p>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
