@extends('layouts.corex')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Wet-Ink Signing" :flush="true" back-route="{{ route('docuperfect.esign.create') }}" back-label="Back to E-Sign" />
    <div class="p-4 lg:p-6">
        <div class="max-w-lg mx-auto text-center py-12">
            <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-amber-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                </svg>
            </div>

            <h2 class="text-2xl font-bold text-gray-900 mb-2">Document Ready for Wet-Ink Signing</h2>

            <p class="text-gray-600 mb-6">
                <span class="font-semibold">{{ $template->name ?? 'Your document' }}</span>
                has been prepared. Print it out and have all parties sign with pen and ink.
            </p>

            <div class="bg-amber-50 border border-amber-200 rounded-sm p-4 mb-6 text-left">
                <div class="text-sm font-semibold text-amber-800 mb-2">Next Steps</div>
                <ol class="text-sm text-amber-700 space-y-1 list-decimal list-inside">
                    <li>Download or print the document from the link below</li>
                    <li>Have all parties sign in the designated signature areas</li>
                    <li>Scan the signed document and upload it back to CoreX</li>
                </ol>
            </div>

            @if($document)
                <div class="flex items-center justify-center gap-3">
                    <a href="{{ route('docuperfect.esign.downloadDocument', $document->id) }}"
                       class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-sm hover:bg-amber-700 transition inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        Print / Download Document
                    </a>
                    <a href="{{ route('docuperfect.documents.edit', $document->id) }}"
                       class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-sm hover:bg-gray-200 transition">
                        View in Documents
                    </a>
                    <a href="{{ route('docuperfect.esign.create') }}"
                       class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-sm hover:bg-blue-700 transition">
                        Create Another
                    </a>
                </div>
            @else
                <a href="{{ route('docuperfect.esign.create') }}"
                   class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-sm hover:bg-blue-700 transition">
                    Create Another
                </a>
            @endif
        </div>
    </div>
</div>
@endsection
