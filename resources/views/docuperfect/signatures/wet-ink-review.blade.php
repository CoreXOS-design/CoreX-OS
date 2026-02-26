@extends('layouts.nexus')

@section('nexus-content')
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
        <a href="{{ route('docuperfect.rental') }}"
           class="text-sm text-white/70 hover:text-white">Back to Rental</a>
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

    {{-- Main content: side by side --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Left: Marker checklist --}}
        <div class="ds-status-card p-4 space-y-4">
            <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">Signature Checklist</h3>
            <p class="text-xs text-slate-500">
                Review the uploaded scan and mark each signature position as Present, Missing, or Unclear.
            </p>

            <div class="space-y-3">
                @foreach($markers as $index => $marker)
                <div class="p-3 rounded-xl border border-slate-200 bg-slate-50">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <span class="text-sm font-medium text-slate-700">
                                Page {{ $marker->page_number }}:
                                {{ ucfirst($marker->type) }}
                                @if($marker->label)
                                    &mdash; {{ $marker->label }}
                                @endif
                            </span>
                            <div class="text-xs text-slate-400">
                                Position: {{ round($marker->x_position) }}%, {{ round($marker->y_position) }}% from top-left
                            </div>
                        </div>
                    </div>

                    <input type="hidden" :name="'checklist[{{ $index }}][marker_id]'" value="{{ $marker->id }}">

                    <div class="flex gap-2">
                        <label class="flex-1">
                            <input type="radio"
                                   name="checklist_status_{{ $marker->id }}"
                                   value="present"
                                   x-model="checklist[{{ $marker->id }}]"
                                   class="sr-only peer">
                            <div class="p-2 rounded-lg border-2 text-center text-xs font-medium cursor-pointer transition-all
                                        peer-checked:border-emerald-500 peer-checked:bg-emerald-50 peer-checked:text-emerald-700
                                        border-slate-200 text-slate-500 hover:border-emerald-300">
                                Present
                            </div>
                        </label>
                        <label class="flex-1">
                            <input type="radio"
                                   name="checklist_status_{{ $marker->id }}"
                                   value="missing"
                                   x-model="checklist[{{ $marker->id }}]"
                                   class="sr-only peer">
                            <div class="p-2 rounded-lg border-2 text-center text-xs font-medium cursor-pointer transition-all
                                        peer-checked:border-red-500 peer-checked:bg-red-50 peer-checked:text-red-700
                                        border-slate-200 text-slate-500 hover:border-red-300">
                                Missing
                            </div>
                        </label>
                        <label class="flex-1">
                            <input type="radio"
                                   name="checklist_status_{{ $marker->id }}"
                                   value="unclear"
                                   x-model="checklist[{{ $marker->id }}]"
                                   class="sr-only peer">
                            <div class="p-2 rounded-lg border-2 text-center text-xs font-medium cursor-pointer transition-all
                                        peer-checked:border-amber-500 peer-checked:bg-amber-50 peer-checked:text-amber-700
                                        border-slate-200 text-slate-500 hover:border-amber-300">
                                Unclear
                            </div>
                        </label>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Summary --}}
            <div class="p-3 rounded-xl bg-slate-100 text-sm">
                <div class="flex items-center gap-4">
                    <span class="text-emerald-600 font-medium"><span x-text="statusCounts.present"></span> Present</span>
                    <span class="text-red-600 font-medium"><span x-text="statusCounts.missing"></span> Missing</span>
                    <span class="text-amber-600 font-medium"><span x-text="statusCounts.unclear"></span> Unclear</span>
                    <span class="text-slate-400"><span x-text="statusCounts.unchecked"></span> Unchecked</span>
                </div>
            </div>
        </div>

        {{-- Right: Uploaded scan viewer --}}
        <div class="ds-status-card p-4 space-y-4">
            <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">Uploaded Scan</h3>

            @if(count($uploadFiles) === 0)
                <div class="text-sm text-slate-500 text-center py-8">No files uploaded.</div>
            @else
                <div class="space-y-3">
                    @foreach($uploadFiles as $index => $file)
                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="flex items-center justify-between px-3 py-2 bg-slate-50 border-b border-slate-200">
                            <span class="text-xs font-medium text-slate-600">{{ $file['name'] }}</span>
                            @if($file['exists'])
                            <a href="{{ route('docuperfect.signatures.wetInkFile', ['document' => $document->id, 'signingRequest' => $signingRequest->id, 'fileIndex' => $index]) }}"
                               target="_blank"
                               class="text-xs text-blue-600 hover:underline">
                                Open Full Size
                            </a>
                            @endif
                        </div>

                        @if($file['exists'])
                            @if(in_array(strtolower($file['extension']), ['jpg', 'jpeg', 'png']))
                                <img src="{{ route('docuperfect.signatures.wetInkFile', ['document' => $document->id, 'signingRequest' => $signingRequest->id, 'fileIndex' => $index]) }}"
                                     class="w-full"
                                     alt="Uploaded scan">
                            @elseif(strtolower($file['extension']) === 'pdf')
                                <div class="p-4 text-center text-sm text-slate-500">
                                    <svg class="w-10 h-10 mx-auto text-slate-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    PDF file — click "Open Full Size" to view
                                </div>
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
                <a href="{{ route('docuperfect.rental') }}"
                   class="text-sm text-slate-500 hover:text-slate-700 font-medium">&larr; Back to Rental</a>
                <div class="flex gap-3">
                    <button type="submit"
                            @click.prevent="decision = 'rejected'; $nextTick(() => { if($refs.rejectionNote && !$refs.rejectionNote.value.trim()) { $refs.rejectionNote.focus(); return; } $el.closest('form').submit(); })"
                            class="rounded-lg px-5 py-2.5 text-sm font-medium bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-colors">
                        Reject &mdash; Request Re-sign
                    </button>
                    <button type="submit"
                            @click.prevent="decision = 'approved'; $el.closest('form').submit();"
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

<script>
function wetInkReview() {
    const markers = @json($markers->map(fn($m) => ['id' => $m->id, 'page_number' => $m->page_number, 'type' => $m->type, 'label' => $m->label])->values());

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
