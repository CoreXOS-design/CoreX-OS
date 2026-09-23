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
    <title>Inspection report — {{ $inspection->property->buildDisplayAddress() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen p-4 sm:p-8">
    <div class="max-w-3xl mx-auto space-y-6">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ ucfirst($inspection->type) }}-inspection report</p>
            <h1 class="text-xl font-bold text-slate-800 mt-1">{{ $inspection->property->buildDisplayAddress() }}</h1>
            <p class="text-sm text-slate-500 mt-1">
                {{ $inspection->scheduled_for?->format('d M Y') ?? $inspection->created_at->format('d M Y') }}
                @if($inspection->completed_at) &middot; Completed {{ $inspection->completed_at->format('d M Y') }} @endif
            </p>
        </div>

        @forelse($rows as $roomId => $roomRows)
            {{-- Deliberately the multi-line @endphp block form here, not
                 Blade's inline single-parenthesis shorthand: that shorthand
                 mis-parses an expression containing its own nested
                 parentheses (closes on the wrong one), corrupting every
                 directive compiled after it in the whole file. Found the
                 hard way in the agency-level show.blade.php this same
                 round — see that file's own comment. Also: never write the
                 literal broken form as text inside a Blade comment block
                 like this one, even to document it — Blade's own directive
                 matching does not treat {{-- --}} content as fully inert,
                 and it corrupted compilation here exactly the same way the
                 very first time this comment was written. --}}
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
                                <span class="text-sm font-semibold text-slate-800">{{ ucfirst($row->observation->condition) }}</span>
                            </div>
                            @if($row->observation->notes)
                                <p class="text-sm text-slate-500 mt-1">{{ $row->observation->notes }}</p>
                            @endif
                            @if($row->observation->photos->isNotEmpty())
                                {{-- storage_path is already a full URL (same as every other
                                     inspection photo consumer — the recording partial's own
                                     <img :src="photo.storage_path"> reads it unwrapped). --}}
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @foreach($row->observation->photos as $photo)
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
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <p class="text-sm text-slate-500">No observations recorded yet.</p>
            </div>
        @endforelse

        @if($inspection->signatures->isNotEmpty())
            <div id="signatures" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">Signatures</h2>
                <div class="space-y-3">
                    @foreach($inspection->signatures as $signature)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600">{{ ucfirst(str_replace('_', ' ', $signature->signer_role)) }}</span>
                            <span class="text-slate-500">{{ $signature->signed_at?->format('d M Y, H:i') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</body>
</html>
