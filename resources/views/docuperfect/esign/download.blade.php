@extends('layouts.corex')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Download Document" :flush="true" back-route="{{ route('docuperfect.esign.create') }}" back-label="Back to E-Sign" />
    <div class="p-4 lg:p-6">
        {{-- Action buttons (hidden when printing) --}}
        <div class="max-w-4xl mx-auto mb-4 flex items-center justify-between print:hidden">
            <div class="text-sm text-gray-600">
                <span class="font-semibold">{{ $document->name }}</span>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('docuperfect.documents.edit', $document->id) }}"
                   class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-sm hover:bg-gray-200 transition">
                    View in Documents
                </a>
                <button onclick="window.print()"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-sm hover:bg-blue-700 transition inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    Print / Save as PDF
                </button>
            </div>
        </div>

        {{-- Document content --}}
        <div class="max-w-4xl mx-auto bg-white border border-gray-200 rounded-sm shadow-sm print:border-0 print:shadow-none print:max-w-none">
            <div class="p-8 print:p-0" id="document-content">
                @if($mergedHtml)
                    {!! $mergedHtml !!}
                @else
                    <div class="text-center py-12 text-gray-500">
                        <p class="text-lg font-semibold mb-2">Document preview not available</p>
                        <p class="text-sm">This document does not have a web-rendered preview. Please use the Documents page to access it.</p>
                        <a href="{{ route('docuperfect.documents.edit', $document->id) }}"
                           class="mt-4 inline-block px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800">
                            Go to Documents &rarr;
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    @media print {
        /* Hide everything except the document */
        .corex-sidebar,
        .corex-topbar,
        nav,
        header,
        .print\\:hidden {
            display: none !important;
        }

        /* Reset page layout for printing */
        body {
            margin: 0;
            padding: 0;
            background: white !important;
        }

        .corex-main-content,
        .corex-content-area {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        #document-content {
            padding: 0 !important;
        }

        @page {
            margin: 15mm;
            size: A4;
        }
    }
</style>
@endpush
@endsection
