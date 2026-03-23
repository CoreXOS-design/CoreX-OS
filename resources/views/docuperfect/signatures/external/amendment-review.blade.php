<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review Amendments — Home Finders Coastal</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-gray-50 min-h-screen">

<div x-data="amendmentReview()" class="max-w-3xl mx-auto px-4 py-8">

    {{-- Header --}}
    <div class="bg-white rounded-2xl shadow-sm border p-6 mb-6">
        @if(!empty($branding['logo_url']))
            <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['agency_name'] ?? 'Agency' }}" class="h-10 mb-4">
        @endif
        <h1 class="text-xl font-bold text-gray-900">Amendment Review Required</h1>
        <p class="text-sm text-gray-600 mt-1">
            A party has added conditions to <strong>{{ $document->name ?? 'this document' }}</strong>.
            You must review and initial each amendment to continue.
        </p>
        <div class="mt-3 flex items-center gap-2 text-sm">
            <span class="text-gray-500">Document version:</span>
            <span class="font-semibold text-blue-600">v{{ $template->document_version ?? 1 }}</span>
        </div>
    </div>

    {{-- Progress --}}
    <div class="bg-white rounded-2xl shadow-sm border p-4 mb-6">
        <div class="flex items-center justify-between text-sm">
            <span class="text-gray-600">Progress</span>
            <span class="font-semibold" x-text="acceptedCount + ' of ' + totalAmendments + ' reviewed'"></span>
        </div>
        <div class="mt-2 bg-gray-200 rounded-full h-2 overflow-hidden">
            <div class="h-full bg-green-500 rounded-full transition-all duration-300"
                 :style="'width:' + (totalAmendments > 0 ? (acceptedCount / totalAmendments * 100) : 0) + '%'"></div>
        </div>
    </div>

    {{-- Amendments List --}}
    @foreach($amendments as $idx => $amendment)
    <div class="bg-white rounded-2xl shadow-sm border mb-4 overflow-hidden"
         :class="{ 'ring-2 ring-green-400': statuses[{{ $amendment->id }}] === 'accepted', 'ring-2 ring-red-400': statuses[{{ $amendment->id }}] === 'rejected' }">

        <div class="px-6 py-4 border-b bg-amber-50">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-amber-700 uppercase tracking-wide">Amendment {{ $idx + 1 }}</span>
                    <span class="ml-2 text-xs text-gray-500">{{ ucfirst($amendment->amendment_type) }}</span>
                </div>
                <div class="text-xs text-gray-500">
                    Added by {{ $amendment->amendedByRequest->signer_name ?? 'Unknown' }}
                    ({{ ucfirst($amendment->amendedByRequest->party_role ?? '') }})
                </div>
            </div>
        </div>

        <div class="px-6 py-4">
            @if($amendment->section_reference)
                <div class="text-xs text-gray-500 mb-2">Section: {{ $amendment->section_reference }}</div>
            @endif

            @if($amendment->original_text)
                <div class="mb-3">
                    <div class="text-xs font-medium text-gray-500 mb-1">Previous text:</div>
                    <div class="text-sm text-gray-600 bg-red-50 border border-red-200 rounded-lg p-3 line-through">
                        {{ $amendment->original_text }}
                    </div>
                </div>
            @endif

            <div class="mb-4">
                <div class="text-xs font-medium text-gray-500 mb-1">{{ $amendment->original_text ? 'New text:' : 'Added condition:' }}</div>
                <div class="text-sm text-gray-900 bg-green-50 border border-green-200 rounded-lg p-3 font-medium">
                    {{ $amendment->new_text }}
                </div>
            </div>

            {{-- Action buttons --}}
            <div x-show="statuses[{{ $amendment->id }}] === 'pending'" class="space-y-3">
                {{-- Initial pad for acceptance --}}
                <div x-show="showInitialPad === {{ $amendment->id }}">
                    <div class="text-xs font-medium text-gray-700 mb-1">Draw your initial below to accept:</div>
                    <canvas id="initial-canvas-{{ $amendment->id }}"
                            class="border-2 border-dashed border-gray-300 rounded-lg w-full bg-white"
                            style="height: 100px;"
                            x-init="$nextTick(() => { if(showInitialPad === {{ $amendment->id }}) initSignaturePad({{ $amendment->id }}) })"></canvas>
                    <div class="flex items-center gap-2 mt-2">
                        <button @click="clearPad({{ $amendment->id }})" class="text-xs text-gray-500 hover:text-gray-700 underline">Clear</button>
                        <button @click="submitAcceptance({{ $amendment->id }})"
                                class="px-4 py-1.5 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700">
                            Confirm Initial
                        </button>
                        <button @click="showInitialPad = null" class="text-xs text-gray-500 hover:text-gray-700 underline">Cancel</button>
                    </div>
                </div>

                <div x-show="showInitialPad !== {{ $amendment->id }}" class="flex items-center gap-3">
                    <button @click="showInitialPad = {{ $amendment->id }}; $nextTick(() => initSignaturePad({{ $amendment->id }}))"
                            class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700">
                        Accept & Initial
                    </button>
                    <button @click="showRejectForm = {{ $amendment->id }}"
                            class="px-4 py-2 bg-white border border-red-300 text-red-600 text-sm font-medium rounded-lg hover:bg-red-50">
                        Reject
                    </button>
                </div>

                {{-- Reject form --}}
                <div x-show="showRejectForm === {{ $amendment->id }}" class="mt-3">
                    <textarea x-model="rejectReasons[{{ $amendment->id }}]"
                              class="w-full border border-gray-300 rounded-lg p-3 text-sm" rows="3"
                              placeholder="Please provide a reason for rejecting this amendment..."></textarea>
                    <div class="flex items-center gap-2 mt-2">
                        <button @click="submitRejection({{ $amendment->id }})"
                                class="px-4 py-1.5 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700">
                            Confirm Rejection
                        </button>
                        <button @click="showRejectForm = null" class="text-xs text-gray-500 hover:text-gray-700 underline">Cancel</button>
                    </div>
                </div>
            </div>

            {{-- Accepted state --}}
            <div x-show="statuses[{{ $amendment->id }}] === 'accepted'" class="flex items-center gap-2 text-green-600">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <span class="text-sm font-medium">Accepted — initialled</span>
            </div>

            {{-- Rejected state --}}
            <div x-show="statuses[{{ $amendment->id }}] === 'rejected'" class="flex items-center gap-2 text-red-600">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                <span class="text-sm font-medium">Rejected</span>
            </div>
        </div>
    </div>
    @endforeach

    {{-- Accept All button --}}
    @if($amendments->count() > 1)
    <div x-show="acceptedCount < totalAmendments" class="bg-white rounded-2xl shadow-sm border p-4 mb-4 text-center">
        <button @click="acceptAll()"
                class="px-6 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700"
                :disabled="processing">
            <span x-show="!processing">Accept All & Initial</span>
            <span x-show="processing">Processing...</span>
        </button>
        <p class="text-xs text-gray-500 mt-1">Uses your initial for all remaining amendments</p>
    </div>
    @endif

    {{-- Complete --}}
    <div x-show="acceptedCount === totalAmendments && totalAmendments > 0" x-cloak
         class="bg-green-50 rounded-2xl border border-green-200 p-6 text-center">
        <svg class="w-12 h-12 text-green-500 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <h3 class="text-lg font-semibold text-green-800">All Amendments Reviewed</h3>
        <p class="text-sm text-green-600 mt-1">Your review has been recorded. The signing flow will continue once all parties have reviewed.</p>
    </div>
</div>

<script>
function amendmentReview() {
    const amendmentIds = @json($amendments->pluck('id'));
    const token = @json($token);

    return {
        totalAmendments: amendmentIds.length,
        acceptedCount: 0,
        statuses: Object.fromEntries(amendmentIds.map(id => [id, 'pending'])),
        showInitialPad: null,
        showRejectForm: null,
        rejectReasons: {},
        pads: {},
        processing: false,

        initSignaturePad(amendmentId) {
            const canvas = document.getElementById('initial-canvas-' + amendmentId);
            if (!canvas) return;
            canvas.width = canvas.offsetWidth;
            canvas.height = canvas.offsetHeight;
            this.pads[amendmentId] = new SignaturePad(canvas, {
                penColor: 'rgb(0, 0, 0)',
                minWidth: 1.5,
                maxWidth: 3,
            });
        },

        clearPad(amendmentId) {
            if (this.pads[amendmentId]) {
                this.pads[amendmentId].clear();
            }
        },

        async submitAcceptance(amendmentId) {
            const pad = this.pads[amendmentId];
            if (!pad || pad.isEmpty()) {
                alert('Please draw your initial first.');
                return;
            }

            this.processing = true;
            try {
                const initialImage = pad.toDataURL('image/png');
                const res = await fetch(`/sign/${token}/amendment/${amendmentId}/accept`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ initial_image: initialImage }),
                });

                const data = await res.json();
                if (data.ok) {
                    this.statuses[amendmentId] = 'accepted';
                    this.acceptedCount++;
                    this.showInitialPad = null;
                } else {
                    alert(data.error || 'Failed to accept amendment.');
                }
            } catch (e) {
                alert('Network error. Please try again.');
            }
            this.processing = false;
        },

        async submitRejection(amendmentId) {
            const reason = this.rejectReasons[amendmentId];
            if (!reason || !reason.trim()) {
                alert('Please provide a reason for rejection.');
                return;
            }

            this.processing = true;
            try {
                const res = await fetch(`/sign/${token}/amendment/${amendmentId}/reject`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ reason: reason }),
                });

                const data = await res.json();
                if (data.ok) {
                    this.statuses[amendmentId] = 'rejected';
                    this.acceptedCount++;
                    this.showRejectForm = null;
                } else {
                    alert(data.error || 'Failed to reject amendment.');
                }
            } catch (e) {
                alert('Network error. Please try again.');
            }
            this.processing = false;
        },

        async acceptAll() {
            this.processing = true;
            // Use a single initial for all
            const firstPending = amendmentIds.find(id => this.statuses[id] === 'pending');
            if (!firstPending) return;

            // Show initial pad for the first pending one
            this.showInitialPad = firstPending;
            await this.$nextTick();
            this.initSignaturePad(firstPending);

            // Wait for user to draw initial, then submit for all
            // We'll override the confirm button to batch
            this._batchMode = true;
            this.processing = false;
        },
    };
}
</script>

</body>
</html>
