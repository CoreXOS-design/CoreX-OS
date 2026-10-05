{{--
    §41-follow-up (Job 3, 2026-09-28) — the ONE page a seller, tenant, or
    landlord with no CoreX login reaches from the inventory report PDF's
    link: shows this inventory and its photos and NOTHING else — no other
    properties, no agency internals, no navigation into the rest of CoreX.
    Mirrors rental-inspections/public/show.blade.php's own boundary and
    print-CSS pattern exactly (see that file's own docblock).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- §24 ruling — buyer acceptance is a POST from this unauthenticated
         page; Laravel starts a session (and its CSRF token) for every
         visitor regardless of login state, since this route sits in the
         `web` middleware group like every other route in this file. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Inventory report — {{ $inventory->property->buildDisplayAddress() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            body { background: #fff !important; padding: 0 !important; }
            .shadow-sm { box-shadow: none !important; }
            [id^="room-"], #signatures { break-inside: avoid; page-break-inside: avoid; }
            img { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen p-4 sm:p-8">
    <div class="max-w-3xl mx-auto space-y-6">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Inventory report</p>
                    <h1 class="text-xl font-bold text-slate-800 mt-1">{{ $inventory->property->buildDisplayAddress() }}</h1>
                    <p class="text-sm text-slate-500 mt-1">
                        Started {{ $inventory->created_at->format('d M Y') }}
                        @if($inventory->completed_at) &middot; Completed {{ $inventory->completed_at->format('d M Y') }} @endif
                    </p>
                </div>
                <button type="button" onclick="window.print()" class="no-print flex-none text-xs font-semibold px-3 py-2 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50">
                    Print
                </button>
            </div>
        </div>

        @forelse($linesByRoom as $roomId => $roomLines)
            @php
                $room = $roomLines->first()->room;
            @endphp
            <div id="room-{{ $roomId }}" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">{{ $room?->label ?? 'General' }}</h2>
                <div class="space-y-4">
                    @foreach($roomLines as $line)
                        <div class="border-t border-slate-100 pt-3 first:border-t-0 first:pt-0">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-slate-700">{{ $line->quantity }}x {{ $line->description }}</span>
                                @if($line->condition_key)
                                    <span class="text-sm font-semibold text-slate-800">{{ ucfirst($line->condition_key) }}</span>
                                @endif
                            </div>
                            @if($line->moveInPhotos->isNotEmpty())
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @foreach($line->moveInPhotos as $photo)
                                        <a href="{{ $photo->storage_path }}" target="_blank" rel="noopener">
                                            <img src="{{ $photo->storage_path }}" alt="" class="w-20 h-20 object-cover rounded-md border border-slate-200">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            @if($emptyRooms->isEmpty())
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                    <p class="text-sm text-slate-500">No items recorded yet.</p>
                </div>
            @endif
        @endforelse

        {{-- Report-fixes, 2026-09-28 (Johan) — a room explicitly checked and
             found to have nothing in it (RentalInventoryRoomMark) still gets
             its own section here — silently omitting it looked identical to
             a room nobody ever checked, exactly the distinction the mark
             exists to prove. --}}
        @foreach($emptyRooms as $room)
            <div id="room-{{ $room->id }}" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">{{ $room->label }}</h2>
                <p class="text-sm text-slate-500 italic">Checked — nothing recorded in this room.</p>
            </div>
        @endforeach

        @php $liveInventorySignatures = $inventory->signatures->whereNull('superseded_at'); @endphp
        @if($liveInventorySignatures->isNotEmpty())
            <div id="signatures" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">Signatures</h2>
                <div class="space-y-4">
                    {{-- Conductor brief 2026-09-29 — only LIVE (non-superseded)
                         rows: a corrected wet-ink upload's old row stays in
                         the record via supersededBy() but no longer prints
                         here — its replacement is the current one. --}}
                    @foreach($liveInventorySignatures as $signature)
                        {{-- Report-fixes, 2026-09-28 (Johan) — two fixes:
                             (1) the agent's own row never carries a
                             party_contact_id (an agent is a CoreX user, not
                             a Contact), so reading partyContact alone always
                             printed a bare "Agent" with no name — falls back
                             to recordedByUser, the real person who signed,
                             same fix as the show page and the PDF.
                             (2) the actual signature image was never shown
                             here at all — parity with the rental-inspection
                             public page, which already renders it. --}}
                        @php
                            // Conductor brief 2026-09-29 — wet_ink/awaiting_wet_ink
                            // added alongside signed/refused. A wet-ink upload
                            // is never presentable as an e-signature (same
                            // principle as a refusal never being presentable
                            // as one) — text + a link, never an <img> for a
                            // PDF upload (the exact bug this build fixes on
                            // the inspection public page — see that file's
                            // own note for the full root cause).
                            $statusLabel = match($signature->disposition) {
                                'signed' => 'Signed',
                                'wet_ink' => 'Signed on paper — scan on file',
                                'awaiting_wet_ink' => 'Awaiting paper signature',
                                default => 'Refused to sign',
                            };
                            $isImageScan = $signature->wet_ink_upload_path
                                && in_array(strtolower(pathinfo($signature->wet_ink_upload_path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'heic', 'heif']);
                        @endphp
                        @php $partyLabel = \App\Services\PartyRoleLabel::for($inventory->agency_id, $signature->party_role); @endphp
                        <div class="border-t border-slate-100 pt-3 first:border-t-0 first:pt-0">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-600">
                                    {{ $partyLabel }}{{ $signature->partyContact ? ' — ' . $signature->partyContact->full_name : ($signature->party_role === 'agent' && $signature->recordedByUser ? ' — ' . $signature->recordedByUser->name : '') }}
                                </span>
                                <span class="text-slate-500">
                                    {{ $statusLabel }}
                                    {{ $signature->disposition_recorded_at?->format('d M Y, H:i') }}
                                </span>
                            </div>
                            @if($signature->disposition === 'signed' && $signature->party_signature_path)
                                {{-- signature_image_src (audit M5) — party_signature_path lives on
                                     the private disk, never directly servable. --}}
                                <img src="{{ $signature->signature_image_src }}" alt="{{ $partyLabel }} signature" class="mt-2 h-16 border border-slate-200 rounded bg-white">
                            @elseif($signature->disposition === 'wet_ink' && $signature->wet_ink_upload_path)
                                @if($isImageScan)
                                    <a href="{{ $signature->wet_ink_upload_path }}" target="_blank" rel="noopener">
                                        <img src="{{ $signature->wet_ink_upload_path }}" alt="{{ $partyLabel }} wet-ink scan" class="mt-2 h-16 border border-slate-200 rounded bg-white">
                                    </a>
                                @else
                                    <a href="{{ $signature->wet_ink_upload_path }}" target="_blank" rel="noopener" class="mt-2 inline-block text-sm font-semibold underline text-sky-600">View uploaded scan (PDF)</a>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- §24 ruling (Johan, 2026-09-29) — the buyer's own signature of
             acceptance. Only ever rendered for a buyer who has NOT yet
             accepted (outstandingBuyers, computed server-side) — never
             touches the completed inventory above; a separate record
             entirely (RentalInventoryBuyerAcceptance). --}}
        @if($outstandingBuyers->isNotEmpty())
            <div id="buyer-acceptance" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6" x-data="buyerAcceptanceSigning({{ Js::from($inventory->public_token) }})">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">Buyer acceptance</h2>
                <p class="text-sm text-slate-500 mb-3">Please review the inventory above, then sign below to confirm you accept it as part of the sale.</p>
                @foreach($outstandingBuyers as $buyer)
                    <div class="border-t border-slate-100 pt-3 first:border-t-0 first:pt-0" x-show="!accepted.includes({{ $buyer->id }})">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-700">{{ $buyer->full_name }}</span>
                            <div class="flex items-center gap-2 no-print">
                                <button type="button" @click="openSigningFor({{ $buyer->id }})" class="text-xs font-semibold px-3 py-1.5 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50">Sign on screen</button>
                                <button type="button" @click="openWetInkFor({{ $buyer->id }})" class="text-xs font-semibold px-3 py-1.5 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50">Upload signed scan</button>
                            </div>
                        </div>
                        <template x-if="activeSigningKey === {{ $buyer->id }}">
                            <div class="space-y-2 pt-2 no-print">
                                <canvas x-init="$nextTick(() => initSignaturePadFor({{ $buyer->id }}, $el))" class="w-full block rounded-md border border-slate-300" style="height:120px; touch-action:none; cursor:crosshair; background:#fff;"></canvas>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="clearSignatureFor({{ $buyer->id }})" class="text-xs px-3 py-1.5 rounded-md border border-slate-200 text-slate-600">Clear</button>
                                    <button type="button" @click="saveSignature({{ $buyer->id }})" :disabled="busy" class="text-xs px-3 py-1.5 rounded-md text-white bg-sky-600" x-text="busy ? 'Saving…' : 'Save signature'"></button>
                                </div>
                            </div>
                        </template>
                        <template x-if="activeWetInkKey === {{ $buyer->id }}">
                            <div class="space-y-2 pt-2 no-print">
                                <p class="text-xs text-slate-500">Upload a photo or scan of the page you signed on paper.</p>
                                <input type="file" accept="image/*,application/pdf" @change="wetInkFile = $event.target.files[0] || null" class="text-sm">
                                <div>
                                    <button type="button" @click="saveWetInk({{ $buyer->id }})" :disabled="!wetInkFile || busy" class="text-xs px-3 py-1.5 rounded-md text-white bg-sky-600" x-text="busy ? 'Saving…' : 'Save upload'"></button>
                                </div>
                            </div>
                        </template>
                        <p x-show="error === {{ $buyer->id }}" x-text="errorMessage" class="text-xs mt-1" style="color:#dc2626;"></p>
                    </div>
                    <p x-show="accepted.includes({{ $buyer->id }})" class="text-sm text-emerald-600 py-2">Thank you, {{ $buyer->full_name }} — your acceptance has been recorded.</p>
                @endforeach
            </div>
        @endif
    </div>

    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <script>
    function buyerAcceptanceSigning(token) {
        return {
            csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            activeSigningKey: null,
            activeWetInkKey: null,
            pads: {},
            wetInkFile: null,
            busy: false,
            accepted: [],
            error: null,
            errorMessage: '',
            openSigningFor(id) { this.activeWetInkKey = null; this.activeSigningKey = this.activeSigningKey === id ? null : id; },
            openWetInkFor(id) { this.activeSigningKey = null; this.wetInkFile = null; this.activeWetInkKey = this.activeWetInkKey === id ? null : id; },
            initSignaturePadFor(id, el) {
                const dpr = window.devicePixelRatio || 1;
                el.width = el.offsetWidth * dpr;
                el.height = el.offsetHeight * dpr;
                el.getContext('2d').scale(dpr, dpr);
                this.pads[id] = new SignaturePad(el);
            },
            clearSignatureFor(id) { this.pads[id]?.clear(); },
            async saveSignature(buyerId) {
                const pad = this.pads[buyerId];
                if (!pad || pad.isEmpty()) { this.error = buyerId; this.errorMessage = 'Please sign before saving.'; return; }
                await this.submitJson(buyerId, { disposition: 'signed', signature_image: pad.toDataURL('image/png') });
            },
            async saveWetInk(buyerId) {
                if (!this.wetInkFile) return;
                const form = new FormData();
                form.append('buyer_contact_id', buyerId);
                form.append('disposition', 'wet_ink');
                form.append('wet_ink_file', this.wetInkFile);
                await this.submitForm(buyerId, form);
            },
            async submitJson(buyerId, extra) {
                this.busy = true; this.error = null;
                try {
                    const res = await fetch(`/rental-inventory-report/${token}/buyer-acceptance`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                        body: JSON.stringify({ buyer_contact_id: buyerId, ...extra }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) { throw new Error(data.message || 'Something went wrong.'); }
                    this.accepted.push(buyerId);
                    this.activeSigningKey = null;
                } catch (e) { this.error = buyerId; this.errorMessage = e.message; }
                finally { this.busy = false; }
            },
            async submitForm(buyerId, form) {
                this.busy = true; this.error = null;
                try {
                    const res = await fetch(`/rental-inventory-report/${token}/buyer-acceptance`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                        body: form,
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) { throw new Error(data.message || 'Something went wrong.'); }
                    this.accepted.push(buyerId);
                    this.activeWetInkKey = null;
                } catch (e) { this.error = buyerId; this.errorMessage = e.message; }
                finally { this.busy = false; }
            },
        };
    }
    </script>
</body>
</html>
