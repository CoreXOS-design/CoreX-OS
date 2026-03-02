@extends('layouts.corex')

@section('corex-content')
@php
    $templateType = $document->template->template_type ?? 'rentals';
    $isSalesTemplate = $templateType === 'sales';
    $backRoute = $isSalesTemplate ? route('docuperfect.sales') : route('docuperfect.rental');
    $backLabel = $isSalesTemplate ? 'Back to Sales' : 'Back to Rental';
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4"
     x-data="wetInkReview()">

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">
                Wet Ink Review &mdash; {{ $document->name }}
            </h2>
            <div class="text-sm text-white/60">
                Uploaded by {{ $signingRequest->signer_name }}
                ({{ ucfirst(str_replace('_', ' ', $signingRequest->party_role)) }})
                @if($signingRequest->updated_at)
                    on {{ $signingRequest->updated_at->format('d M Y') }}
                @endif
            </div>
        </div>
        <a href="{{ $backRoute }}"
           class="text-sm text-white/70 hover:text-white">{{ $backLabel }}</a>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- Previous inspection history --}}
    @if($previousInspections->isNotEmpty())
    <div class="ds-status-card p-4">
        <h3 class="text-sm font-semibold text-slate-700 mb-2">Previous Reviews</h3>
        <div class="space-y-2">
            @foreach($previousInspections as $inspection)
            <div class="flex items-center gap-3 text-sm p-2 rounded-lg {{ $inspection->isApproved() ? 'bg-emerald-50' : 'bg-red-50' }}">
                <span class="{{ $inspection->isApproved() ? 'text-emerald-600' : 'text-red-600' }} font-medium">
                    {{ ucfirst($inspection->result) }}
                </span>
                <span class="text-slate-500">by {{ $inspection->inspector->name ?? 'Unknown' }}</span>
                <span class="text-slate-400">{{ $inspection->created_at->format('d M Y H:i') }}</span>
                @if($inspection->notes)
                    <span class="text-slate-500 italic">&mdash; {{ $inspection->notes }}</span>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Main content: side by side — document dominant (75%), checklist compact (25%) --}}
    <div class="flex flex-col lg:flex-row gap-4">

        {{-- Left: Marker checklist (25%) --}}
        <div class="w-full lg:flex-shrink-0 ds-status-card p-3 space-y-2" style="max-width: 25%; max-height: 85vh; overflow-y: auto;">
            <h3 class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Signature Checklist</h3>
            <p class="text-[10px] text-slate-500 leading-tight">
                Review the scan and mark each position.
            </p>

            <div class="space-y-1">
                @foreach($markers as $index => $marker)
                <div class="px-2 py-1.5 rounded-lg border border-slate-200 bg-slate-50">
                    <div class="flex items-center justify-between gap-1.5">
                        <div class="flex-1 min-w-0">
                            <span class="text-[11px] font-medium text-slate-700 leading-tight">
                                P{{ $marker->page_number }}: {{ ucfirst($marker->type) }}
                                @if($marker->label)
                                    &mdash; {{ $marker->label }}
                                @endif
                            </span>
                        </div>

                        <input type="hidden" :name="'checklist[{{ $index }}][marker_id]'" value="{{ $marker->id }}">

                        <div class="flex gap-0.5 shrink-0">
                            <label>
                                <input type="radio"
                                       name="checklist_status_{{ $marker->id }}"
                                       value="present"
                                       x-model="checklist[{{ $marker->id }}]"
                                       class="sr-only peer">
                                <div class="px-1.5 py-0.5 rounded-full border text-[9px] font-semibold cursor-pointer transition-all
                                            peer-checked:border-emerald-500 peer-checked:bg-emerald-50 peer-checked:text-emerald-700
                                            border-slate-200 text-slate-400 hover:border-emerald-300">
                                    ✓
                                </div>
                            </label>
                            <label>
                                <input type="radio"
                                       name="checklist_status_{{ $marker->id }}"
                                       value="missing"
                                       x-model="checklist[{{ $marker->id }}]"
                                       class="sr-only peer">
                                <div class="px-1.5 py-0.5 rounded-full border text-[9px] font-semibold cursor-pointer transition-all
                                            peer-checked:border-red-500 peer-checked:bg-red-50 peer-checked:text-red-700
                                            border-slate-200 text-slate-400 hover:border-red-300">
                                    ✗
                                </div>
                            </label>
                            <label>
                                <input type="radio"
                                       name="checklist_status_{{ $marker->id }}"
                                       value="unclear"
                                       x-model="checklist[{{ $marker->id }}]"
                                       class="sr-only peer">
                                <div class="px-1.5 py-0.5 rounded-full border text-[9px] font-semibold cursor-pointer transition-all
                                            peer-checked:border-amber-500 peer-checked:bg-amber-50 peer-checked:text-amber-700
                                            border-slate-200 text-slate-400 hover:border-amber-300">
                                    ?
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Summary --}}
            <div class="px-2 py-1.5 rounded-lg bg-slate-100 text-[10px]">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-emerald-600 font-medium"><span x-text="statusCounts.present"></span> Present</span>
                    <span class="text-red-600 font-medium"><span x-text="statusCounts.missing"></span> Missing</span>
                    <span class="text-amber-600 font-medium"><span x-text="statusCounts.unclear"></span> Unclear</span>
                    <span class="text-slate-400"><span x-text="statusCounts.unchecked"></span> Unchecked</span>
                </div>
            </div>
        </div>

        {{-- Right: Uploaded scan viewer (75%) --}}
        <div class="w-full flex-1 ds-status-card p-3 space-y-2">
            <h3 class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Uploaded Scan</h3>

            @if(count($uploadFiles) === 0)
                <div class="text-sm text-slate-500 text-center py-8">No files uploaded.</div>
            @else
                <div class="space-y-3">
                    @foreach($uploadFiles as $index => $file)
                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="flex items-center justify-between px-3 py-1 bg-slate-50 border-b border-slate-200">
                            <span class="text-xs font-medium text-slate-600">{{ $file['name'] }}</span>
                            @if($file['exists'])
                            <a href="{{ route('docuperfect.signatures.wetInkFile', ['document' => $document->id, 'signingRequest' => $signingRequest->id, 'fileIndex' => $index]) }}"
                               target="_blank"
                               class="text-[10px] text-blue-600 hover:underline">
                                Open Full Size &#8599;
                            </a>
                            @endif
                        </div>

                        @if($file['exists'])
                            @if(in_array(strtolower($file['extension']), ['jpg', 'jpeg', 'png']))
                                <img src="{{ route('docuperfect.signatures.wetInkFile', ['document' => $document->id, 'signingRequest' => $signingRequest->id, 'fileIndex' => $index]) }}"
                                     class="w-full"
                                     alt="Uploaded scan">
                            @elseif(strtolower($file['extension']) === 'pdf')
                                <iframe src="{{ route('docuperfect.signatures.wetInkFile', ['document' => $document->id, 'signingRequest' => $signingRequest->id, 'fileIndex' => $index]) }}#toolbar=1&navpanes=0&scrollbar=1&view=FitH"
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

    {{-- Upload on Behalf --}}
    @if(!$signingRequest->wet_ink_upload_path)
    <div class="ds-status-card p-4 space-y-3">
        <h3 class="text-sm font-semibold text-slate-700">Upload on Behalf</h3>
        <p class="text-xs text-slate-500">If you received the signed document via WhatsApp, email, or in person, you can upload it here on behalf of the signer.</p>
        <form action="{{ route('docuperfect.signatures.uploadOnBehalf', ['document' => $document->id, 'signingRequest' => $signingRequest->id]) }}"
              method="POST" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-slate-600 mb-1">Signed Document(s)</label>
                <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png"
                       class="w-full text-sm text-slate-600 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" required>
            </div>
            <div class="w-48">
                <label class="block text-xs font-medium text-slate-600 mb-1">How was it received?</label>
                <select name="receive_method" required
                        class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-cyan-500 focus:border-cyan-500">
                    <option value="">Select...</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="email">Email</option>
                    <option value="in_person">In Person</option>
                </select>
            </div>
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Upload on Behalf</button>
        </form>
    </div>
    @endif

    {{-- Decision form --}}
    <form action="{{ route('docuperfect.signatures.wetInkDecision', ['document' => $document->id, 'signingRequest' => $signingRequest->id]) }}"
          method="POST"
          @submit="prepareSubmit($event)">
        @csrf

        {{-- Hidden checklist data (populated by JS) --}}
        <template x-for="(marker, index) in markerList" :key="marker.id">
            <div>
                <input type="hidden" :name="'checklist[' + index + '][marker_id]'" :value="marker.id">
                <input type="hidden" :name="'checklist[' + index + '][status]'" :value="checklist[marker.id] || 'unchecked'">
            </div>
        </template>

        <div class="ds-status-card p-4 space-y-4">
            {{-- Notes --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Notes (optional)</label>
                <textarea name="notes" rows="2"
                          class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-cyan-500 focus:border-cyan-500"
                          placeholder="Any observations about the uploaded document..."></textarea>
            </div>

            {{-- Rejection note (shown when rejecting) --}}
            <div x-show="decision === 'rejected'" x-transition>
                <label class="block text-sm font-medium text-red-700 mb-1">Rejection Reason (required)</label>
                <textarea name="rejection_note" rows="3" x-ref="rejectionNote"
                          class="w-full rounded-lg border-red-300 text-sm px-3 py-2 focus:ring-red-500 focus:border-red-500"
                          placeholder="Explain which signatures are missing or unclear..."></textarea>
            </div>

            <input type="hidden" name="result" x-model="decision">

            {{-- Action buttons --}}
            <div class="flex items-center justify-between pt-2">
                <a href="{{ $backRoute }}"
                   class="text-sm text-slate-500 hover:text-slate-700 font-medium">&larr; {{ $backLabel }}</a>
                <div class="flex gap-3">
                    <button type="submit"
                            @click.prevent="decision = 'rejected'; $nextTick(() => { if($refs.rejectionNote && !$refs.rejectionNote.value.trim()) { $refs.rejectionNote.focus(); return; } $el.closest('form').submit(); })"
                            class="rounded-lg px-5 py-2.5 text-sm font-medium bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-colors">
                        Reject &mdash; Request Re-sign
                    </button>
                    <button type="submit"
                            @click.prevent="decision = 'approved'; $nextTick(() => $el.closest('form').submit())"
                            :disabled="!allChecked || statusCounts.missing > 0 || statusCounts.unclear > 0"
                            class="rounded-lg px-5 py-2.5 text-sm font-medium transition-colors"
                            :class="allChecked && statusCounts.missing === 0 && statusCounts.unclear === 0
                                ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                : 'bg-slate-100 text-slate-400 cursor-not-allowed'">
                        Approve
                    </button>
                </div>
            </div>

            <p class="text-xs text-slate-400">
                Approve is only available when all markers are marked "Present".
            </p>
        </div>
    </form>
</div>

@php
    $markersJson = $markers->map(fn($m) => ['id' => $m->id, 'page_number' => $m->page_number, 'type' => $m->type, 'label' => $m->label])->values();
@endphp
<script>
function wetInkReview() {
    const markers = @json($markersJson);

    // Build initial checklist state
    const checklist = {};
    markers.forEach(m => { checklist[m.id] = null; });

    return {
        markerList: markers,
        checklist: checklist,
        decision: null,

        get statusCounts() {
            const values = Object.values(this.checklist);
            return {
                present: values.filter(v => v === 'present').length,
                missing: values.filter(v => v === 'missing').length,
                unclear: values.filter(v => v === 'unclear').length,
                unchecked: values.filter(v => !v).length,
            };
        },

        get allChecked() {
            return Object.values(this.checklist).every(v => v !== null);
        },

        prepareSubmit(event) {
            // Decision is set by the button click handlers
        },
    };
}
</script>
@endsection
