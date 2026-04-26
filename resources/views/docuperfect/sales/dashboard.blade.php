@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Sales Documents</h1>
                <p class="text-sm text-white/60">Send, track and manage signed sales documents.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('docuperfect.sales.send') }}" class="corex-btn-primary inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Upload &amp; Send New
                </a>
            </div>
        </div>
    </div>

    {{-- Status summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold" style="color: var(--brand-icon);">{{ number_format($inProgress->count()) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">In Progress</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold" style="color: var(--ds-green);">{{ number_format($completed->count()) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Completed</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold" style="color: var(--text-muted);">{{ number_format($expired->count()) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Expired</div>
        </div>
    </div>

    {{-- ═══════════ IN PROGRESS ═══════════ --}}
    @if($inProgress->isNotEmpty())
    <div class="space-y-3">
        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon);">In Progress ({{ number_format($inProgress->count()) }})</h3>

        <div class="space-y-4">
            @foreach($inProgress as $send)
                <div class="rounded-md p-5 transition-all duration-300" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <div class="font-semibold" style="color: var(--text-primary);">{{ $send->document_name }}</div>
                            <div class="text-xs" style="color: var(--text-muted);">
                                Sent by: {{ $send->sender->name ?? 'Unknown' }} &mdash; {{ $send->created_at->format('d M Y') }}
                            </div>
                        </div>
                        @if($send->needsApproval())
                            <span class="ds-badge ds-badge-warning">Needs Approval</span>
                        @endif
                    </div>

                    {{-- Recipient chain --}}
                    <div class="space-y-2 mb-4">
                        @foreach($send->recipients as $r)
                            @php
                                $urgency = $r->urgencyColor();
                                $urgencyStyle = match($urgency) {
                                    'red'    => 'background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);',
                                    'yellow' => 'background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);',
                                    default  => 'background: var(--surface-2); border: 1px solid var(--border);',
                                };
                                $rowStyle = ($r->status === 'sent') ? $urgencyStyle : 'background: var(--surface-2); border: 1px solid var(--border);';
                            @endphp
                            <div class="rounded-md p-3" style="{{ $rowStyle }}">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold" style="color: var(--text-muted);">{{ $r->signing_order }}.</span>
                                        <span class="text-xs font-medium uppercase" style="color: var(--text-muted);">{{ $r->recipient_role }}:</span>

                                        @if($r->status === 'approved')
                                            <span class="text-sm font-medium" style="color: var(--ds-green);">{{ $r->recipient_name }}</span>
                                            <span class="text-xs" style="color: var(--ds-green);">&mdash; approved {{ $r->returned_at?->format('d M') }}</span>
                                        @elseif($r->status === 'returned_pending_approval')
                                            <span class="text-sm font-medium" style="color: var(--ds-amber);">{{ $r->recipient_name }}</span>
                                            <span class="text-xs" style="color: var(--ds-amber);">&mdash; returned {{ $r->returned_at?->format('d M') }}</span>
                                        @elseif($r->status === 'sent')
                                            <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->recipient_name }}</span>
                                            <span class="text-xs" style="color: var(--text-muted);">&mdash; sent {{ $r->sent_at?->format('d M') }}</span>
                                            @if($r->daysSinceSent() > 0)
                                                @php
                                                    $daysColor = $urgency === 'red' ? 'var(--ds-crimson)' : ($urgency === 'yellow' ? 'var(--ds-amber)' : 'var(--text-muted)');
                                                    $daysWeight = $urgency === 'red' ? 'font-semibold' : '';
                                                @endphp
                                                <span class="text-xs {{ $daysWeight }}" style="color: {{ $daysColor }};">
                                                    ({{ $r->daysSinceSent() }} {{ $r->daysSinceSent() === 1 ? 'day' : 'days' }} ago)
                                                </span>
                                            @endif
                                        @elseif($r->status === 'waiting')
                                            <span class="text-sm" style="color: var(--text-muted);">{{ $r->recipient_name }}</span>
                                            <span class="text-xs" style="color: var(--text-muted);">&mdash; waiting</span>
                                        @endif
                                    </div>

                                    {{-- Status icon --}}
                                    <div>
                                        @if($r->status === 'approved')
                                            <span title="Approved" style="color: var(--ds-green);">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </span>
                                        @elseif($r->status === 'returned_pending_approval')
                                            <span title="Needs approval" style="color: var(--ds-amber);">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                                            </span>
                                        @elseif($r->status === 'sent')
                                            <span title="Sent, awaiting return" style="color: var(--brand-icon);">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </span>
                                        @elseif($r->status === 'waiting')
                                            <span title="Waiting for previous" style="color: var(--text-muted);">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Reminder info for sent recipients --}}
                                @if($r->status === 'sent' && $r->reminder_count > 0)
                                    <div class="text-xs mt-1 ml-6" style="color: var(--text-muted);">
                                        Reminders sent:
                                        @if($r->reminder_count >= 1) <span style="color: var(--text-secondary);">gentle</span> @endif
                                        @if($r->reminder_count >= 2) <span style="color: var(--text-secondary);">, firm</span> @endif
                                        @if($r->reminder_count >= 3) <span style="color: var(--text-secondary);">, final</span> @endif
                                    </div>
                                @endif

                                {{-- Needs approval banner --}}
                                @if($r->status === 'returned_pending_approval')
                                    <div class="mt-2 ml-6">
                                        <span class="ds-badge ds-badge-warning">Needs Your Approval</span>
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
                                    <span class="text-xs px-3 py-1.5 rounded-md" style="color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border);">
                                        Uploaded via link
                                    </span>
                                @elseif($r->return_method && $r->return_method !== 'upload')
                                    <span class="text-xs px-3 py-1.5 rounded-md" style="color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border);">
                                        Received via {{ str_replace('_', ' ', $r->return_method) }}
                                    </span>
                                @endif
                                <a href="{{ route('docuperfect.sales.review', [$send, $r]) }}"
                                   class="corex-btn-primary text-xs px-3 py-1.5 inline-flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    Review &amp; Approve
                                </a>
                            @endif

                            @if($r->status === 'sent')
                                <form action="{{ route('docuperfect.sales.remind', $r) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs px-3 py-1.5">
                                        Send Reminder to {{ $r->recipient_name }}
                                    </button>
                                </form>
                                <form action="{{ route('docuperfect.sales.mark-returned', $r) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs px-3 py-1.5">
                                        Mark {{ $r->recipient_name }} as Returned
                                    </button>
                                </form>
                                <button @click="showUploadModal_{{ $send->id }} = true" type="button"
                                        class="corex-btn-outline text-xs px-3 py-1.5">
                                    Upload on Behalf of {{ $r->recipient_name }}
                                </button>
                                <form action="{{ route('docuperfect.sales.resend', $r) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs px-3 py-1.5">
                                        Resend to {{ $r->recipient_name }}
                                    </button>
                                </form>

                                {{-- Upload on behalf modal --}}
                                <div x-show="showUploadModal_{{ $send->id }}" x-cloak x-transition.opacity
                                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click="showUploadModal_{{ $send->id }} = false">
                                    <div class="rounded-md shadow-xl max-w-md w-full mx-4 p-6 space-y-4" style="background: var(--surface); border: 1px solid var(--border);" @click.stop>
                                        <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Upload on Behalf of {{ $r->recipient_name }}</h3>
                                        <p class="text-sm" style="color: var(--text-secondary);">
                                            Upload the signed document received via WhatsApp, email, or in person.
                                        </p>
                                        <form action="{{ route('docuperfect.sales.uploadOnBehalf', [$send, $r]) }}"
                                              method="POST" enctype="multipart/form-data" @submit="uploadSubmitting_{{ $send->id }} = true">
                                            @csrf
                                            <div class="space-y-3">
                                                <div>
                                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Signed Document(s)</label>
                                                    <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png"
                                                           class="w-full text-sm file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-medium" style="color: var(--text-secondary);" required>
                                                    <p class="text-[10px] mt-1" style="color: var(--text-muted);">PDF, JPG, PNG (max 20MB each)</p>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">How was it received?</label>
                                                    <select name="receive_method" required
                                                            class="w-full rounded-md text-sm px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                        <option value="">Select...</option>
                                                        <option value="whatsapp">WhatsApp</option>
                                                        <option value="email">Email</option>
                                                        <option value="in_person">In Person</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-end gap-3 pt-4">
                                                <button @click="showUploadModal_{{ $send->id }} = false" type="button"
                                                        class="px-4 py-2.5 text-sm font-medium transition-all duration-300" style="color: var(--text-secondary);">Cancel</button>
                                                <button type="submit"
                                                        :disabled="uploadSubmitting_{{ $send->id }}"
                                                        class="corex-btn-primary rounded-md px-6 py-2.5 text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
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
                                class="text-xs font-medium px-3 py-1.5 transition-all duration-300"
                                style="color: var(--ds-crimson);">
                            Reject / Cancel
                        </button>

                        {{-- Cancel modal --}}
                        <div x-show="showCancelModal" x-cloak x-transition.opacity
                             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click="showCancelModal = false">
                            <div class="rounded-md shadow-xl max-w-md w-full mx-4 p-6 space-y-4" style="background: var(--surface); border: 1px solid var(--border);" @click.stop>
                                <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Cancel Document</h3>
                                <p class="text-sm" style="color: var(--text-secondary);">
                                    This will cancel the document and expire all pending signing requests.
                                </p>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Reason (required)</label>
                                    <textarea x-model="cancelReason" rows="3"
                                              class="w-full rounded-md text-sm px-3 py-2"
                                              style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                              placeholder="Why is this document being cancelled?"></textarea>
                                    <p x-show="cancelReason.length > 0 && cancelReason.length < 5" class="text-xs mt-1" style="color: var(--ds-crimson);">
                                        Reason must be at least 5 characters.
                                    </p>
                                </div>
                                <div class="flex items-center justify-end gap-3 pt-2">
                                    <button @click="showCancelModal = false"
                                            class="px-4 py-2.5 text-sm font-medium transition-all duration-300" style="color: var(--text-secondary);">Cancel</button>
                                    <form action="{{ route('docuperfect.sales.cancel', $send) }}" method="POST" @submit="submitting = true">
                                        @csrf
                                        <input type="hidden" name="rejection_reason" :value="cancelReason">
                                        <button type="submit"
                                                :disabled="cancelReason.length < 5 || submitting"
                                                class="corex-btn-primary rounded-md px-6 py-2.5 text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                                                style="background: var(--ds-crimson); border-color: var(--ds-crimson);">
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
    <div class="space-y-3">
        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--ds-green);">Completed ({{ number_format($completed->count()) }})</h3>

        <div class="space-y-3">
            @foreach($completed as $send)
                <div class="rounded-md p-4 transition-all duration-300" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                <span class="font-semibold" style="color: var(--text-primary);">{{ $send->document_name }}</span>
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                All {{ $send->recipients->count() }} {{ $send->recipients->count() === 1 ? 'recipient' : 'recipients' }} returned &mdash; completed {{ $send->completed_at?->format('d M Y') }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 space-y-1">
                        @foreach($send->recipients as $r)
                            <div class="text-xs" style="color: var(--text-secondary);">
                                {{ $r->signing_order }}. <span class="font-medium uppercase" style="color: var(--text-muted);">{{ $r->recipient_role }}:</span>
                                {{ $r->recipient_name }} &mdash; returned &amp; approved
                            </div>
                        @endforeach
                    </div>

                    @if($send->original_file_path)
                        <div class="mt-3">
                            <a href="{{ route('docuperfect.sales.download', $send) }}" class="text-xs font-medium transition-all duration-300" style="color: var(--brand-icon);">
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
    <div class="space-y-3">
        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expired ({{ number_format($expired->count()) }})</h3>

        <div class="space-y-3">
            @foreach($expired as $send)
                <div class="rounded-md p-4 opacity-60 transition-all duration-300" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="font-semibold" style="color: var(--text-secondary);">{{ $send->document_name }}</div>
                    <div class="text-xs" style="color: var(--text-muted);">
                        Sent by: {{ $send->sender->name ?? 'Unknown' }} &mdash; {{ $send->created_at->format('d M Y') }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Empty state --}}
    @if($inProgress->isEmpty() && $completed->isEmpty() && $expired->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No sales documents yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Upload and send your first document to start tracking signatures.</p>
            <a href="{{ route('docuperfect.sales.send') }}" class="corex-btn-primary">Upload &amp; Send</a>
        </div>
    @endif

</div>
@endsection
