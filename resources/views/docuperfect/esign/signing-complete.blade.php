@extends('layouts.corex')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Document Signed" :flush="true" back-route="{{ route('docuperfect.esign.create') }}" back-label="Back to E-Sign" />
    <div class="p-4 lg:p-6">
        <div class="max-w-lg mx-auto text-center py-12">
            <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-emerald-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>

            <h2 class="text-2xl font-bold text-gray-900 mb-2">Document Signed!</h2>

            <p class="text-gray-600 mb-6">
                You have successfully signed
                <span class="font-semibold">{{ $template->name ?? 'the document' }}</span>.
            </p>

            @if($nextRecipient)
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 text-left">
                    <div class="text-sm font-semibold text-blue-800 mb-1">Next: Sent for signature</div>
                    <div class="text-sm text-blue-700">
                        Sent to <span class="font-medium">{{ $nextRecipient['name'] ?? 'Unknown' }}</span>
                        ({{ ucfirst($nextRecipient['role'] ?? 'signer') }})
                        @if(!empty($nextRecipient['email']))
                            at {{ $nextRecipient['email'] }}
                        @endif
                    </div>
                </div>
            @endif

            @if($document)
                <div class="flex items-center justify-center gap-3">
                    <a href="{{ route('docuperfect.signatures.audit', ['document' => $document->id]) }}"
                       class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                        View Audit Trail
                    </a>
                    <a href="{{ route('docuperfect.esign.create') }}"
                       class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition">
                        Create Another
                    </a>
                </div>
            @else
                <a href="{{ route('docuperfect.esign.create') }}"
                   class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition">
                    Create Another
                </a>
            @endif

            {{-- DEV TESTING: Signing links for external parties --}}
            @if(config('app.debug') && isset($signingRequests) && $signingRequests->count() > 0)
                <div class="mt-8 bg-amber-50 border-2 border-amber-300 rounded-lg p-4 text-left">
                    <div class="text-xs font-bold text-amber-800 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        DEV TESTING — Signing Links
                    </div>
                    <div class="space-y-2">
                        @foreach($signingRequests as $sr)
                            @if($sr->party_role === 'agent')
                                @continue
                            @endif
                            <div class="flex items-center gap-2 text-sm">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    {{ $sr->status === 'pending' ? 'bg-green-100 text-green-800' :
                                       ($sr->status === 'waiting' ? 'bg-gray-100 text-gray-600' :
                                       ($sr->status === 'completed' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-500')) }}">
                                    {{ strtoupper($sr->status) }}
                                </span>
                                <span class="font-medium text-amber-900">
                                    {{ $sr->signer_name ?? ucfirst($sr->party_role) }}
                                    ({{ ucfirst($sr->party_role) }})
                                </span>
                                @if($sr->token && in_array($sr->status, ['pending', 'waiting']))
                                    <a href="{{ url('/sign/' . $sr->token) }}"
                                       class="text-amber-700 hover:text-amber-900 underline font-mono text-xs ml-auto"
                                       target="_blank">
                                        /sign/{{ \Illuminate\Support\Str::limit($sr->token, 12) }}...
                                    </a>
                                @elseif($sr->status === 'completed')
                                    <span class="text-gray-400 text-xs ml-auto">Signed {{ $sr->completed_at?->diffForHumans() }}</span>
                                @elseif($sr->status === 'waiting')
                                    <span class="text-gray-400 text-xs ml-auto">Waiting for previous party</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
