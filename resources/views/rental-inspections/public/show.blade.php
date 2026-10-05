{{--
    Johan, 2026-09-23, approved — the ONE page a tenant or landlord with no
    CoreX login reaches from the report PDF's QR code / link: "it shows the
    inspection and its photos and NOTHING else — no other properties, no
    other tenancies, no agency internals, no navigation into the rest of
    CoreX." No header, no sidebar, no link back into the app anywhere on
    this page — self-contained by construction, not by convention.

    Per-room `id="room-{id}"` anchors below are what a room's own QR code
    (printed in that room's header on the PDF) jumps straight to — same
    link as the cover QR, just with a URL fragment, so revoking/expiring
    stays a single token to manage (§5 of the approved proposal).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inspection report — {{ $inspection->property?->buildDisplayAddress() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- §40, 2026-09-28 — Johan: "how do we print / share it now" —
         this page already has everything a printed record needs (photos,
         conditions, notes, signatures) and nothing this docblock's own
         "self-contained, no navigation" rule would need hidden, so print
         support is styling only, never a second route/view. Card shadows/
         backgrounds are screen-only decoration that print as ugly grey
         boxes or wastes toner; each room/signatures card gets
         break-inside:avoid so a room's own items never split across a
         page boundary. Photos print in colour (the only thing on this
         page with colour to preserve) — this page's own condition text
         is plain, unstyled text today (§36's severity-colour work only
         touched the recording screen and the signed PDF, never this
         page), out of scope for a print-CSS-only change. --}}
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
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ ucfirst($inspection->type) }}-inspection report</p>
                    <h1 class="text-xl font-bold text-slate-800 mt-1">{{ $inspection->property?->buildDisplayAddress() }}</h1>
                    <p class="text-sm text-slate-500 mt-1">
                        {{ $inspection->scheduled_for?->format('d M Y') ?? $inspection->created_at->format('d M Y') }}
                        @if($inspection->completed_at) &middot; Completed {{ $inspection->completed_at->format('d M Y') }} @endif
                    </p>
                </div>
                {{-- §40 — the only interactive element this deliberately-chrome-free
                     page carries; hidden on the printed output itself via .no-print. --}}
                <button type="button" onclick="window.print()" class="no-print flex-none text-xs font-semibold px-3 py-2 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50">
                    Print
                </button>
            </div>

            {{-- Report-fixes, 2026-09-28 (Johan, property 5294) — the header
                 block: everything the completed recording screen shows above
                 the room tables that this page never carried before. Every
                 field is optional — an older/incomplete record simply omits
                 the row rather than printing an empty value. --}}
            <dl class="mt-4 pt-4 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                @if($inspection->lease?->tenants?->isNotEmpty())
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Tenant{{ $inspection->lease->tenants->count() > 1 ? 's' : '' }}</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->lease->tenants->map(fn ($t) => $t->contact?->full_name)->filter()->implode(', ') }}</dd>
                    </div>
                @endif
                @if($inspection->property?->sellerOwnerContact())
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Landlord</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->property?->sellerOwnerContact()?->full_name }}</dd>
                    </div>
                @endif
                @if($inspection->createdBy)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Agent</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->createdBy->name }}</dd>
                    </div>
                @endif
                @if($inspection->property_type)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Property type</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->property_type }}</dd>
                    </div>
                @endif
                @if($inspection->furnished_status)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Furnished status</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->furnished_status }}</dd>
                    </div>
                @endif
                @if($inspection->electricity_meter_reading)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Electricity meter</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->electricity_meter_reading }}</dd>
                    </div>
                @endif
                @if($inspection->water_meter_reading)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Water meter</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->water_meter_reading }}</dd>
                    </div>
                @endif
                @if($inspection->keys_count !== null || $inspection->keys_description)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Keys</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->keys_count }}{{ $inspection->keys_description ? ' — ' . $inspection->keys_description : '' }}</dd>
                    </div>
                @endif
                @if($inspection->remotes_count !== null || $inspection->remotes_description)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Remotes</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->remotes_count }}{{ $inspection->remotes_description ? ' — ' . $inspection->remotes_description : '' }}</dd>
                    </div>
                @endif
                @if($inspection->type === 'out' && $inspection->move_in_date_recorded)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Original move-in date</dt>
                        <dd class="text-slate-700 text-right">{{ $inspection->move_in_date_recorded->format('d M Y') }}</dd>
                    </div>
                @endif
            </dl>
            @if($inspection->overall_notes)
                <p class="mt-4 pt-4 border-t border-slate-100 text-sm text-slate-600">
                    <span class="font-semibold text-slate-500 uppercase text-xs tracking-wide">Overall notes: </span>{{ $inspection->overall_notes }}
                </p>
            @endif
        </div>

        @forelse($rows as $roomId => $roomRows)
            {{-- Deliberately the multi-line @endphp block form here, not
                 Blade's inline single-parenthesis shorthand: that shorthand
                 mis-parses an expression containing its own nested
                 parentheses (closes on the wrong one), corrupting every
                 directive compiled after it in the whole file. Found the
                 hard way in the agency-level show.blade.php this same
                 round — see that file's own comment. Also: never write the
                 literal open/close comment-token pair as text inside a
                 Blade comment block like this one, even to document it —
                 Blade's own comment stripper is not nesting-aware, so a
                 comment containing another literal comment-open/close pair
                 closes early at the FIRST inner close token it finds,
                 leaking everything after it (up to the real close) onto
                 the page as visible text. This exact file shipped that
                 exact bug this way. --}}
            @php
                $room = $roomRows->first()->room;
            @endphp
            <div id="room-{{ $roomId }}" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">{{ $room?->label ?? 'General' }}</h2>
                <div class="space-y-4">
                    @foreach($roomRows as $row)
                        <div class="border-t border-slate-100 pt-3 first:border-t-0 first:pt-0">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-slate-700">{{ $row->item->label }}</span>
                                <span class="text-sm font-semibold" style="color: {{ $severityColors[$row->severity] }}">{{ $row->condition_label }}</span>
                            </div>
                            @if($row->observation->notes)
                                {{-- §36's callout treatment — red/amber for an issue severity,
                                     the calm blue tone otherwise (a blue OR grey-severity
                                     condition's note still gets blue — "never muted grey",
                                     matching the recording screen and the signed PDF). --}}
                                @php $tone = in_array($row->severity, ['red', 'amber'], true) ? $row->severity : 'blue'; @endphp
                                <p class="text-sm mt-1 py-1 pl-2 border-l-2 rounded-r" style="background-color: color-mix(in srgb, {{ $severityColors[$tone] }} 12%, white); border-left-color: {{ $severityColors[$tone] }};">{{ $row->observation->notes }}</p>
                            @endif
                            @if($row->photos->isNotEmpty())
                                {{-- storage_path is already a full URL (same as every other
                                     inspection photo consumer — the recording partial's own
                                     <img :src="photo.storage_path"> reads it unwrapped). --}}
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @foreach($row->photos as $photo)
                                        <a href="{{ $photo->storage_path }}" target="_blank" rel="noopener">
                                            <img src="{{ $photo->storage_path }}" alt="" class="w-20 h-20 object-cover rounded-md border border-slate-200">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if($roomNotes->get($roomId))
                    <p class="text-sm mt-4 pt-3 border-t border-slate-100 py-1 pl-2 border-l-2 rounded-r" style="background-color: color-mix(in srgb, {{ $severityColors['blue'] }} 12%, white); border-left-color: {{ $severityColors['blue'] }};">
                        <span class="font-semibold text-slate-500 uppercase text-xs tracking-wide">Room note: </span>{{ $roomNotes->get($roomId)->note }}
                    </p>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <p class="text-sm text-slate-500">No observations recorded yet.</p>
            </div>
        @endforelse

        {{-- Report-fixes, 2026-09-28 (Johan, property 5294) — rebuilt around
             RentalInspection::signatureSummaryRows(), the shared "who
             signed, and how" resolver (also used by the signed PDF). The
             previous version read `signer_role`/`signed_at`, columns that
             no longer exist since §15/§16's three-party rebuild — every row
             rendered blank. --}}
        <div id="signatures" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">Signatures</h2>
            <div class="space-y-4">
                @foreach($signatureRows as $row)
                    <div class="border-t border-slate-100 pt-3 first:border-t-0 first:pt-0">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-700 font-medium">{{ $row['role'] }}{{ $row['name'] ? ' — ' . $row['name'] : '' }}</span>
                            @if($row['not_required'])
                                <span class="text-slate-400">Not required</span>
                            @elseif($row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_REFUSED)
                                <span style="color: {{ $severityColors['red'] }}">Refused to sign</span>
                            @elseif($row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_WET_INK)
                                <span class="text-slate-600">Signed on paper — scan on file</span>
                            @elseif($row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK)
                                <span class="text-slate-400">Awaiting paper signature</span>
                            @elseif($row['signature']?->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_SIGNED)
                                <span style="color: {{ $severityColors['blue'] }}">Signed</span>
                            @else
                                <span class="text-slate-400">Outstanding</span>
                            @endif
                        </div>
                        @if($row['signature'])
                            <p class="text-xs text-slate-400 mt-0.5">{{ $row['signature']->disposition_recorded_at?->format('d M Y, H:i') }}</p>
                            @if($row['signature']->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_REFUSED)
                                <p class="text-sm mt-1 py-1 pl-2 border-l-2 rounded-r" style="background-color: color-mix(in srgb, {{ $severityColors['red'] }} 12%, white); border-left-color: {{ $severityColors['red'] }};">
                                    {{ $refusalReasonLabels->get($row['signature']->refusal_reason_preset, ucfirst(str_replace('_', ' ', $row['signature']->refusal_reason_preset))) }}
                                    @if($row['signature']->refusal_reason_note) — {{ $row['signature']->refusal_reason_note }} @endif
                                </p>
                            @elseif($row['signature']->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_SIGNED && $row['signature']->party_signature_path)
                                <img src="{{ $row['signature']->fileUrl('signature', $inspection->public_token) }}" alt="{{ $row['role'] }} signature" class="mt-2 h-16 border border-slate-200 rounded bg-white">
                            @elseif($row['signature']->disposition === \App\Models\RentalInspectionSignature::DISPOSITION_WET_INK && $row['signature']->wet_ink_upload_path)
                                {{-- Conductor brief 2026-09-29 — the bug this
                                     build fixes: a wet-ink upload is not
                                     always an image. A PDF scan rendered as
                                     an <img> here shows nothing (a PDF is not
                                     a browser-decodable image format) — the
                                     extension decides <a> (PDF) vs the
                                     existing linked-<img> (a real image).
                                     Served via fileUrl() (audit M4) — the
                                     scan lives on the private disk, never a
                                     directly-servable storage path. --}}
                                @php
                                    $isImageScan = in_array(strtolower(pathinfo($row['signature']->wet_ink_upload_path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'heic', 'heif']);
                                    $wetInkUrl = $row['signature']->fileUrl('wet-ink', $inspection->public_token);
                                @endphp
                                @if($isImageScan)
                                    <a href="{{ $wetInkUrl }}" target="_blank" rel="noopener">
                                        <img src="{{ $wetInkUrl }}" alt="{{ $row['role'] }} wet-ink upload" class="mt-2 h-16 border border-slate-200 rounded bg-white">
                                    </a>
                                @else
                                    <a href="{{ $wetInkUrl }}" target="_blank" rel="noopener" class="mt-2 inline-block text-sm font-semibold underline text-sky-600">View uploaded scan (PDF)</a>
                                @endif
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</body>
</html>
