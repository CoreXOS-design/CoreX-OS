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

        @if($inventory->signatures->isNotEmpty())
            <div id="signatures" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600 mb-3">Signatures</h2>
                <div class="space-y-3">
                    @foreach($inventory->signatures as $signature)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600">
                                {{ ucfirst($signature->party_role) }}{{ $signature->partyContact ? ' — ' . $signature->partyContact->full_name : '' }}
                            </span>
                            <span class="text-slate-500">
                                {{ $signature->disposition === 'signed' ? 'Signed' : 'Refused to sign' }}
                                {{ $signature->disposition_recorded_at?->format('d M Y, H:i') }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</body>
</html>
