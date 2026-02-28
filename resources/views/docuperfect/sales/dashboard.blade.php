@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Sales Documents</h2>
            <div class="text-sm text-white/60">Send, track and manage signed sales documents.</div>
        </div>
        <a href="{{ route('docuperfect.sales.send') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 text-white text-sm font-medium rounded-xl transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Upload &amp; Send New
        </a>
    </div>

    {{-- Flash messages handled by global toast system --}}

    {{-- Status summary cards --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="ds-status-card p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">{{ $inProgress->count() }}</div>
            <div class="text-xs text-slate-500 mt-1">In Progress</div>
        </div>
        <div class="ds-status-card p-4 text-center">
            <div class="text-2xl font-bold text-emerald-600">{{ $completed->count() }}</div>
            <div class="text-xs text-slate-500 mt-1">Completed</div>
        </div>
        <div class="ds-status-card p-4 text-center">
            <div class="text-2xl font-bold text-slate-400">{{ $expired->count() }}</div>
            <div class="text-xs text-slate-500 mt-1">Expired</div>
        </div>
    </div>

    {{-- ═══════════ IN PROGRESS ═══════════ --}}
    @if($inProgress->isNotEmpty())
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-blue-700 uppercase tracking-wider">In Progress ({{ $inProgress->count() }})</h3>

        <div class="space-y-4">
            @foreach($inProgress as $send)
                <div class="ds-status-card rounded-2xl border border-slate-200 p-5">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <div class="font-semibold text-slate-800">{{ $send->document_name }}</div>
                            <div class="text-xs text-slate-500">
                                Sent by: {{ $send->sender->name ?? 'Unknown' }} &mdash; {{ $send->created_at->format('d M Y') }}
                            </div>
                        </div>
                        @if($send->needsApproval())
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">
                                Needs Approval
                            </span>
                        @endif
                    </div>

                    {{-- Recipient chain --}}
                    <div class="space-y-2 mb-4">
                        @foreach($send->recipients as $r)
                            @php
                                $urgency = $r->urgencyColor();
                                $urgencyClasses = match($urgency) {
                                    'red'    => 'border-red-200 bg-red-50',
                                    'yellow' => 'border-amber-200 bg-amber-50',
                                    default  => 'border-slate-100 bg-slate-50',
                                };
                            @endphp
                            <div class="rounded-xl border {{ $r->status === 'sent' ? $urgencyClasses : 'border-slate-100 bg-slate-50' }} p-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-slate-400">{{ $r->signing_order }}.</span>
                                        <span class="text-xs font-medium uppercase text-slate-500">{{ $r->recipient_role }}:</span>

                                        @if($r->status === 'approved')
                                            <span class="text-sm text-emerald-700 font-medium">{{ $r->recipient_name }}</span>
                                            <span class="text-xs text-emerald-600">— approved {{ $r->returned_at?->format('d M') }}</span>
                                        @elseif($r->status === 'returned_pending_approval')
                                            <span class="text-sm text-amber-700 font-medium">{{ $r->recipient_name }}</span>
                                            <span class="text-xs text-amber-600">— returned {{ $r->returned_at?->format('d M') }}</span>
                                        @elseif($r->status === 'sent')
                                            <span class="text-sm text-slate-700 font-medium">{{ $r->recipient_name }}</span>
                                            <span class="text-xs text-slate-500">— sent {{ $r->sent_at?->format('d M') }}</span>
                                            @if($r->daysSinceSent() > 0)
                                                <span class="text-xs {{ $urgency === 'red' ? 'text-red-600 font-semibold' : ($urgency === 'yellow' ? 'text-amber-600' : 'text-slate-500') }}">
                                                    ({{ $r->daysSinceSent() }} {{ $r->daysSinceSent() === 1 ? 'day' : 'days' }} ago)
                                                </span>
                                            @endif
                                        @elseif($r->status === 'waiting')
                                            <span class="text-sm text-slate-400">{{ $r->recipient_name }}</span>
                                            <span class="text-xs text-slate-400">— waiting</span>
                                        @endif
                                    </div>

                                    {{-- Status icon --}}
                                    <div>
                                        @if($r->status === 'approved')
                                            <span class="text-emerald-500" title="Approved">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </span>
                                        @elseif($r->status === 'returned_pending_approval')
                                            <span class="text-amber-500" title="Needs approval">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                                            </span>
                                        @elseif($r->status === 'sent')
                                            <span class="text-blue-400" title="Sent, awaiting return">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </span>
                                        @elseif($r->status === 'waiting')
                                            <span class="text-slate-300" title="Waiting for previous">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Reminder info for sent recipients --}}
                                @if($r->status === 'sent' && $r->reminder_count > 0)
                                    <div class="text-xs text-slate-400 mt-1 ml-6">
                                        Reminders sent:
                                        @if($r->reminder_count >= 1) <span class="text-slate-500">gentle</span> @endif
                                        @if($r->reminder_count >= 2) <span class="text-slate-500">, firm</span> @endif
                                        @if($r->reminder_count >= 3) <span class="text-slate-500">, final</span> @endif
                                    </div>
                                @endif

                                {{-- Needs approval banner --}}
                                @if($r->status === 'returned_pending_approval')
                                    <div class="mt-2 ml-6 px-2 py-1 bg-amber-100 rounded-lg text-xs font-semibold text-amber-800">
                                        NEEDS YOUR APPROVAL
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Action buttons --}}
                    <div class="flex flex-wrap gap-2" x-data="{ showCancelModal: false, cancelReason: '', submitting: false, showUploadModal_{{ $send->id }}: false, uploadSubmitting_{{ $send->id }}: false }">
                        @foreach($send->recipients as $r)
                            @if($r->status === 'returned_pending_approval')
                                @if($r->return_method === 'upload' && $r->returned_file_path)
                                    <span class="text-xs text-slate-500 px-3 py-1.5 bg-slate-50 rounded-lg border border-slate-200">
                                        Uploaded via link
                                    </span>
                                @elseif($r->return_method && $r->return_method !== 'upload')
                                    <span class="text-xs text-slate-500 px-3 py-1.5 bg-slate-50 rounded-lg border border-slate-200">
                                        Received via {{ str_replace('_', ' ', $r->return_method) }}
                                    </span>
                                @endif
                                <a href="{{ route('docuperfect.sales.review', [$send, $r]) }}"
                                   class="px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 transition-colors inline-flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    Review &amp; Approve
                                </a>
                            @endif

                            @if($r->status === 'sent')
                                <form action="{{ route('docuperfect.sales.remind', $r) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 bg-blue-50 text-blue-700 text-xs font-medium rounded-lg border border-blue-200 hover:bg-blue-100 transition-colors">
                                        Send Reminder to {{ $r->recipient_name }}
                                    </button>
                                </form>
                                <form action="{{ route('docuperfect.sales.mark-returned', $r) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 bg-slate-50 text-slate-700 text-xs font-medium rounded-lg border border-slate-200 hover:bg-slate-100 transition-colors">
                                        Mark {{ $r->recipient_name }} as Returned
                                    </button>
                                </form>
                                <button @click="showUploadModal_{{ $send->id }} = true" type="button"
                                        class="px-3 py-1.5 bg-slate-50 text-slate-700 text-xs font-medium rounded-lg border border-slate-200 hover:bg-slate-100 transition-colors">
                                    Upload on Behalf of {{ $r->recipient_name }}
                                </button>
                                <form action="{{ route('docuperfect.sales.resend', $r) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 bg-slate-50 text-slate-500 text-xs font-medium rounded-lg border border-slate-200 hover:bg-slate-100 transition-colors">
                                        Resend to {{ $r->recipient_name }}
                                    </button>
                                </form>

                                {{-- Upload on behalf modal --}}
                                <div x-show="showUploadModal_{{ $send->id }}" x-cloak x-transition.opacity
                                     class="fixed inset-0 z-50 flex items-center justify-center"
                                     style="background:rgba(0,0,0,0.6);" @click="showUploadModal_{{ $send->id }} = false">
                                    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-4 p-6 space-y-4" @click.stop>
                                        <h3 class="text-lg font-semibold text-slate-800">Upload on Behalf of {{ $r->recipient_name }}</h3>
                                        <p class="text-sm text-slate-600">
                                            Upload the signed document received via WhatsApp, email, or in person.
                                        </p>
                                        <form action="{{ route('docuperfect.sales.uploadOnBehalf', [$send, $r]) }}"
                                              method="POST" enctype="multipart/form-data" @submit="uploadSubmitting_{{ $send->id }} = true">
                                            @csrf
                                            <div class="space-y-3">
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 mb-1">Signed Document(s)</label>
                                                    <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png"
                                                           class="w-full text-sm text-slate-600 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" required>
                                                    <p class="text-[10px] text-slate-400 mt-1">PDF, JPG, PNG (max 20MB each)</p>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 mb-1">How was it received?</label>
                                                    <select name="receive_method" required
                                                            class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-blue-500 focus:border-blue-500">
                                                        <option value="">Select...</option>
                                                        <option value="whatsapp">WhatsApp</option>
                                                        <option value="email">Email</option>
                                                        <option value="in_person">In Person</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-end gap-3 pt-4">
                                                <button @click="showUploadModal_{{ $send->id }} = false" type="button"
                                                        class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">Cancel</button>
                                                <button type="submit"
                                                        :disabled="uploadSubmitting_{{ $send->id }}"
                                                        class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                                    <span x-show="!uploadSubmitting_{{ $send->id }}">Upload &amp; Review</span>
                                                    <span x-show="uploadSubmitting_{{ $send->id }}" x-cloak>Uploading...</span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        {{-- Reject / Cancel --}}
                        <button @click="showCancelModal = true" type="button"
                                class="text-xs text-red-600 hover:text-red-800 font-medium px-3 py-1.5">
                            Reject / Cancel
                        </button>

                        {{-- Cancel modal --}}
                        <div x-show="showCancelModal" x-cloak x-transition.opacity
                             class="fixed inset-0 z-50 flex items-center justify-center"
                             style="background:rgba(0,0,0,0.6);" @click="showCancelModal = false">
                            <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-4 p-6 space-y-4" @click.stop>
                                <h3 class="text-lg font-semibold text-slate-800">Cancel Document</h3>
                                <p class="text-sm text-slate-600">
                                    This will cancel the document and expire all pending signing requests.
                                </p>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Reason (required)</label>
                                    <textarea x-model="cancelReason" rows="3"
                                              class="w-full rounded-lg border-slate-300 text-sm px-3 py-2"
                                              placeholder="Why is this document being cancelled?"></textarea>
                                    <p x-show="cancelReason.length > 0 && cancelReason.length < 5" class="text-xs text-red-500 mt-1">
                                        Reason must be at least 5 characters.
                                    </p>
                                </div>
                                <div class="flex items-center justify-end gap-3 pt-2">
                                    <button @click="showCancelModal = false"
                                            class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">Cancel</button>
                                    <form action="{{ route('docuperfect.sales.cancel', $send) }}" method="POST" @submit="submitting = true">
                                        @csrf
                                        <input type="hidden" name="rejection_reason" :value="cancelReason">
                                        <button type="submit"
                                                :disabled="cancelReason.length < 5 || submitting"
                                                class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                            <span x-show="!submitting">Cancel Document</span>
                                            <span x-show="submitting" x-cloak>Cancelling...</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ═══════════ COMPLETED ═══════════ --}}
    @if($completed->isNotEmpty())
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-emerald-700 uppercase tracking-wider">Completed ({{ $completed->count() }})</h3>

        <div class="space-y-3">
            @foreach($completed as $send)
                <div class="ds-status-card rounded-2xl border border-emerald-100 bg-emerald-50/30 p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                <span class="font-semibold text-slate-800">{{ $send->document_name }}</span>
                            </div>
                            <div class="text-xs text-slate-500 mt-1">
                                All {{ $send->recipients->count() }} {{ $send->recipients->count() === 1 ? 'recipient' : 'recipients' }} returned &mdash; completed {{ $send->completed_at?->format('d M Y') }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 space-y-1">
                        @foreach($send->recipients as $r)
                            <div class="text-xs text-slate-600">
                                {{ $r->signing_order }}. <span class="font-medium uppercase text-slate-500">{{ $r->recipient_role }}:</span>
                                {{ $r->recipient_name }} &mdash; returned &amp; approved
                            </div>
                        @endforeach
                    </div>

                    @if($send->original_file_path)
                        <div class="mt-3">
                            <a href="{{ route('docuperfect.sales.download', $send) }}" class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                Download Original
                            </a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ═══════════ EXPIRED ═══════════ --}}
    @if($expired->isNotEmpty())
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Expired ({{ $expired->count() }})</h3>

        <div class="space-y-3">
            @foreach($expired as $send)
                <div class="ds-status-card rounded-2xl border border-slate-200 bg-slate-50/50 p-4 opacity-60">
                    <div class="font-semibold text-slate-600">{{ $send->document_name }}</div>
                    <div class="text-xs text-slate-400">
                        Sent by: {{ $send->sender->name ?? 'Unknown' }} &mdash; {{ $send->created_at->format('d M Y') }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Empty state --}}
    @if($inProgress->isEmpty() && $completed->isEmpty() && $expired->isEmpty())
        <div class="ds-status-card rounded-2xl p-8 text-center">
            <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
            <p class="text-sm text-slate-500">No sales documents yet.</p>
            <a href="{{ route('docuperfect.sales.send') }}" class="inline-block mt-3 text-sm text-blue-600 hover:text-blue-800 font-medium">
                Upload &amp; Send your first document
            </a>
        </div>
    @endif

</div>
@endsection
