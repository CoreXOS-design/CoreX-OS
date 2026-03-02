@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4"
     x-data="{ showRejectModal: false, rejectionNote: '', submitting: false }">

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">
                Review Returned Document &mdash; {{ $send->document_name }}
            </h2>
            <div class="text-sm text-white/60">
                Returned by {{ $recipient->recipient_name }}
                ({{ ucfirst($recipient->recipient_role) }})
                @if($recipient->returned_at)
                    on {{ $recipient->returned_at->format('d M Y') }}
                @endif
            </div>
        </div>
        <a href="{{ route('docuperfect.sales') }}"
           class="text-sm text-white/70 hover:text-white">&larr; Back to Sales</a>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- Main content: side by side --}}
    <div class="flex flex-col lg:flex-row gap-4">

        {{-- Left: Recipient info + actions (25%) --}}
        <div class="w-full lg:flex-shrink-0 space-y-3" style="max-width: 25%;">

            {{-- Recipient details --}}
            <div class="ds-status-card p-4 space-y-3">
                <h3 class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Recipient Details</h3>

                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-xs text-slate-400">Name</span>
                        <div class="font-medium text-slate-700">{{ $recipient->recipient_name }}</div>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400">Role</span>
                        <div class="font-medium text-slate-700">{{ ucfirst($recipient->recipient_role) }}</div>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400">Email</span>
                        <div class="font-medium text-slate-700 text-xs break-all">{{ $recipient->recipient_email }}</div>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400">Returned</span>
                        <div class="font-medium text-slate-700">{{ $recipient->returned_at?->format('d M Y H:i') ?? 'N/A' }}</div>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400">Method</span>
                        <div class="font-medium text-slate-700">
                            @switch($recipient->return_method)
                                @case('upload') Uploaded via link @break
                                @case('email') Emailed back @break
                                @case('whatsapp') WhatsApp @break
                                @case('in_person') In person @break
                                @default {{ ucfirst($recipient->return_method ?? 'Unknown') }}
                            @endswitch
                        </div>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="ds-status-card p-4 space-y-3">
                <h3 class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Decision</h3>

                <p class="text-[11px] text-slate-500 leading-tight">
                    Review the uploaded document on the right, then approve or reject.
                </p>

                {{-- Approve --}}
                <form action="{{ route('docuperfect.sales.approve', [$send, $recipient]) }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 transition-colors">
                        @if($nextRecipient)
                            Approve &amp; Send to {{ $nextRecipient->recipient_name }}
                        @else
                            Approve &amp; Complete
                        @endif
                    </button>
                </form>

                {{-- Reject --}}
                <button @click="showRejectModal = true" type="button"
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-medium bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-colors">
                    Reject &mdash; Request Re-upload
                </button>
            </div>
        </div>

        {{-- Right: Uploaded document viewer (75%) --}}
        <div class="w-full flex-1 ds-status-card p-3 space-y-2">
            <h3 class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Uploaded Document</h3>

            @if(count($uploadFiles) === 0)
                <div class="text-sm text-slate-500 text-center py-8">
                    No files uploaded. This document was returned via email &mdash; check your inbox.
                </div>
            @else
                <div class="space-y-3">
                    @foreach($uploadFiles as $file)
                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="flex items-center justify-between px-3 py-1 bg-slate-50 border-b border-slate-200">
                            <span class="text-xs font-medium text-slate-600">{{ $file['name'] }}</span>
                            @if($file['exists'])
                            <a href="{{ $file['url'] }}" target="_blank"
                               class="text-[10px] text-blue-600 hover:underline">
                                Open Full Size &#8599;
                            </a>
                            @endif
                        </div>

                        @if($file['exists'])
                            @if(in_array(strtolower($file['extension']), ['jpg', 'jpeg', 'png']))
                                <img src="{{ $file['url'] }}"
                                     class="w-full"
                                     alt="Uploaded scan">
                            @elseif(strtolower($file['extension']) === 'pdf')
                                <iframe src="{{ $file['url'] }}#toolbar=1&navpanes=0&scrollbar=1&view=FitH"
                                        style="width:100%; height:85vh; border:none;"></iframe>
                            @endif
                        @else
                            <div class="p-4 text-center text-sm text-red-500">File not found on server.</div>
                        @endif
                    </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Reject modal --}}
    <div x-show="showRejectModal" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center"
         style="background:rgba(0,0,0,0.6);" @click="showRejectModal = false">
        <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-4 p-6 space-y-4" @click.stop>
            <h3 class="text-lg font-semibold text-slate-800">Reject &amp; Request Re-upload</h3>
            <p class="text-sm text-slate-600">
                The recipient will be notified and asked to upload a corrected document.
            </p>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Reason (required)</label>
                <textarea x-model="rejectionNote" rows="3"
                          class="w-full rounded-lg border-slate-300 text-sm px-3 py-2"
                          placeholder="What needs to be corrected? E.g. missing signatures, wrong pages..."></textarea>
                <p x-show="rejectionNote.length > 0 && rejectionNote.length < 5" class="text-xs text-red-500 mt-1">
                    Reason must be at least 5 characters.
                </p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button @click="showRejectModal = false"
                        class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">Cancel</button>
                <form action="{{ route('docuperfect.sales.resend', $recipient) }}" method="POST" @submit="submitting = true">
                    @csrf
                    <button type="submit"
                            :disabled="rejectionNote.length < 5 || submitting"
                            class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span x-show="!submitting">Reject &amp; Resend</span>
                        <span x-show="submitting" x-cloak>Sending...</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection
