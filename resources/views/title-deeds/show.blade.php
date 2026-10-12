@extends('layouts.corex-app')

@section('corex-content')
@php
    $archived = $deed->trashed();
    $collected = $deed->isCollected();
    $receipt = $deed->receipt;
    $label = 'block text-xs font-medium mb-0.5';
    $evStatus = ['sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped', 'prepared' => 'Waiting to be opened', 'opened' => 'Opened - not yet marked as sent', 'not_sent' => 'Not sent'];
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div>
                <a href="{{ route('title-deeds.index') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">&larr; Title Deeds</a>
                <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">{{ $deed->property_display }}</h1>
                <div class="text-sm mt-0.5" style="color: var(--text-secondary);">
                    {{ $archived ? 'Archived' : $deed->status_label }}@if(! $collected && ! $archived) · waiting {{ $deed->days_waiting }} {{ $deed->days_waiting === 1 ? 'day' : 'days' }}@endif
                </div>
            </div>
            @php
                $open = ! $archived && ! $collected;
                // ONE primary action: the next step for this status. Everything else is secondary; Archive sits apart.
                $primary = null;
                if ($open && $deed->status === 'received' && $can['notify']) { $primary = 'notify'; }
                elseif ($open && $can['handover']) { $primary = 'handover'; }
                elseif ($archived && $can['archive']) { $primary = 'restore'; }
                $pinOk = $settings['pin_used'] && $settings['pin_issued'] && ! $settings['locked'];
            @endphp
            <div class="flex flex-wrap items-center gap-2">
                @if($open && $can['notify'] && ($deed->status === 'received' || ! $settings['pin_used'] || ! $settings['pin_issued'] || $settings['locked']) && ! ($settings['locked'] && $settings['pin_used']))
                <form method="POST" action="{{ route('title-deeds.notify', $deed->id) }}">@csrf <input type="hidden" name="_form_token" value="{{ $tokens['notify'] }}">
                    <button type="submit" class="{{ $primary === 'notify' ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs">{{ $deed->status === 'received' ? 'Notify the owners' : 'Notify the owners again' }}</button>
                </form>
                @endif
                @if($open && $can['handover'])<a href="{{ route('title-deeds.handover', $deed->id) }}" class="{{ $primary === 'handover' ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs no-underline">Hand over</a>@endif
                @if($open && $can['notify'] && $pinOk)
                <form method="POST" action="{{ route('title-deeds.pin.resend', $deed->id) }}">@csrf <input type="hidden" name="_form_token" value="{{ $tokens['pin_resend'] }}">
                    <button type="submit" class="corex-btn-outline text-xs">Send the PIN again</button>
                </form>
                @endif
                @if($open && $can['notify'] && $settings['pin_used'])
                <form method="POST" action="{{ route('title-deeds.pin.new', $deed->id) }}">@csrf <input type="hidden" name="_form_token" value="{{ $tokens['pin_new'] }}">
                    <x-confirm-submit :plain="true" title="Issue a new PIN" message="The old PIN will stop working and the new one will be sent to every owner. Continue?" confirm-label="Issue a new PIN" class="corex-btn-outline text-xs">Issue a new PIN</x-confirm-submit>
                </form>
                @endif
                @if(! $archived && $can['edit'])<a href="{{ route('title-deeds.edit', $deed->id) }}" class="corex-btn-outline text-xs no-underline">{{ $collected ? 'Edit the notes' : 'Edit the details' }}</a>@endif
                @if($archived && $can['archive'])
                    <form method="POST" action="{{ route('title-deeds.restore', $deed->id) }}">@csrf<button type="submit" class="corex-btn-primary text-xs">Restore this title deed</button></form>
                @elseif($can['archive'])
                    {{-- Apart from the working actions, and always confirmed through the app dialog. --}}
                    <span class="hidden lg:inline-block self-stretch w-px mx-2" style="background: var(--border);" aria-hidden="true"></span>
                    <form method="POST" action="{{ route('title-deeds.destroy', $deed->id) }}">@csrf @method('DELETE')
                        <x-confirm-submit :plain="true" title="Archive this title deed" message="It leaves the active list but is kept. You can restore it from the Archived filter." confirm-label="Archive" :danger="true" class="corex-btn-outline text-xs">Archive this title deed</x-confirm-submit>
                    </form>
                @endif
            </div>
        </div>
        @if($open && $settings['pin_used'] && $settings['locked'])
            <p class="text-sm mt-2" style="color: var(--ds-crimson);">The PIN is locked after too many wrong attempts. Issue a new PIN to unlock it.</p>
        @endif
    </div>

    @foreach(['success' => 'green', 'error' => 'crimson', 'info' => 'amber'] as $key => $colour)
        @if(session($key))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-{{ $colour }}) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-{{ $colour }}) 30%, transparent); color: var(--text-primary);">{{ session($key) }}</div>@endif
    @endforeach
    @if($errors->any())<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);"><ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    @if($can['notify'] || $can['handover'])
        @include('title-deeds._prepared-whatsapp', ['prepared' => $prepared, 'deed' => $deed])
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div class="lg:col-span-2 rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div>
                <div class="{{ $label }}" style="color: var(--text-secondary);">Owner(s)</div>
                <div class="space-y-1">
                    @forelse($recipients as $r)
                    <div class="text-sm" style="color: var(--text-primary);">
                        {{ $r['name'] }}
                        <span class="text-xs" style="color: var(--text-muted);">
                            @if($r['archived']) contact archived
                            @else {{ $r['email'] ?? 'no email' }} · {{ $r['mobile'] ?? 'no mobile' }}
                            @endif
                        </span>
                    </div>
                    @empty
                    <div class="text-sm" style="color: var(--text-muted);">No owners on this record.</div>
                    @endforelse
                </div>
            </div>
            <dl class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                <div><dt class="{{ $label }}" style="color: var(--text-secondary);">Responsible agent</dt><dd style="color: var(--text-primary);">{{ $deed->agent?->name ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}" style="color: var(--text-secondary);">Branch</dt><dd style="color: var(--text-primary);">{{ $deed->branch?->name ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}" style="color: var(--text-secondary);">Received</dt><dd style="color: var(--text-primary);">{{ $deed->received_at?->format('d M Y') }}</dd></div>
                <div><dt class="{{ $label }}" style="color: var(--text-secondary);">Attorney firm</dt><dd style="color: var(--text-primary);">{{ $deed->attorney_firm ?: '—' }}</dd></div>
                <div><dt class="{{ $label }}" style="color: var(--text-secondary);">Kept at</dt><dd style="color: var(--text-primary);">@if($deed->storage_display){{ $deed->storage_display }}@else No storage location chosen @endif
                    @if(! $archived && ! $collected && $can['edit'] && (! $deed->storage_location_id))&middot; <a href="{{ route('title-deeds.edit', $deed->id) }}#td-location" class="font-semibold no-underline hover:underline" style="color: var(--brand-icon);">Choose a location</a>@endif</dd></div>
                @if($deed->listingAgent)<div><dt class="{{ $label }}" style="color: var(--text-secondary);">Listing agent</dt><dd style="color: var(--text-primary);">{{ $deed->listingAgent->name }}</dd></div>@endif
                @if($additionalAgents->isNotEmpty())<div><dt class="{{ $label }}" style="color: var(--text-secondary);">Also told on collection</dt><dd style="color: var(--text-primary);">{{ $additionalAgents->pluck('name')->implode(', ') }}</dd></div>@endif
                @if($dealLabel)<div class="col-span-2"><dt class="{{ $label }}" style="color: var(--text-secondary);">Deal</dt><dd style="color: var(--text-primary);">@if($deal)<a href="{{ route('deals-dr2.pipeline.view', $deal->id) }}" class="font-semibold no-underline hover:underline" style="color: var(--brand-icon);">{{ $dealLabel }}</a>@else {{ $dealLabel }} @endif</dd></div>@endif
                @if($deed->notified_at)<div><dt class="{{ $label }}" style="color: var(--text-secondary);">Owner first notified</dt><dd style="color: var(--text-primary);">{{ $deed->notified_at->format('d M Y H:i') }}</dd></div>@endif
            </dl>
            @if($deed->notes)
            <div><div class="{{ $label }}" style="color: var(--text-secondary);">Notes</div><div class="text-sm whitespace-pre-line" style="color: var(--text-primary);">{{ $deed->notes }}</div></div>
            @endif
            @if(! $collected && $settings['pin_used'] && $settings['pin_issued'])
            <div class="text-sm" style="color: {{ $settings['locked'] ? 'var(--ds-crimson)' : 'var(--text-secondary)' }};">
                @if($settings['locked']) The PIN is locked after too many wrong attempts. Issue a new PIN.
                @else PIN issued {{ $deed->pin_issued_at?->format('d M Y') }}@if($deed->pin_failed_attempts) · {{ $deed->pin_failed_attempts }} wrong {{ $deed->pin_failed_attempts === 1 ? 'attempt' : 'attempts' }}@endif
                @endif
            </div>
            @endif
        </div>

        <div class="rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="{{ $label }}" style="color: var(--text-secondary);">Scan of the title deed</div>
            @if($deed->scan_document_id && $deed->scan)
                <a href="{{ route('title-deeds.scan.download', $deed->id) }}" class="text-sm font-semibold" style="color: var(--brand-icon);">{{ $deed->scan->original_name }}</a>
                <div class="text-xs" style="color: var(--text-muted);">{{ $deed->scan_label }}</div>
            @else
                <div class="text-sm" style="color: var(--text-muted);">No scan.</div>
            @endif
            @if($earlierScans->isNotEmpty())
            <div class="text-xs space-y-0.5" style="color: var(--text-muted);">
                <div class="font-medium" style="color: var(--text-secondary);">Earlier scans</div>
                @foreach($earlierScans as $old)
                    <div><a href="{{ route('title-deeds.scan.earlier', [$deed->id, $old->id]) }}" class="no-underline hover:underline" style="color: var(--brand-icon);">{{ $old->original_name }}</a> &middot; {{ $old->created_at?->format('d M Y') }}</div>
                @endforeach
            </div>
            @endif
            @if($can['edit'] && ! $archived && ! $collected)
            <form method="POST" action="{{ route('title-deeds.scan.upload', $deed->id) }}" enctype="multipart/form-data" class="space-y-2">
                @csrf
                <input type="file" name="scan" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="block w-full text-xs" style="color: var(--text-secondary);">
                <button type="submit" class="corex-btn-outline text-xs">{{ $deed->scan_document_id ? 'Replace scan' : 'Upload scan' }}</button>
            </form>
            @endif
        </div>
    </div>

    @if($receipt)
    <div class="rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="text-sm font-semibold" style="color: var(--text-primary);">Collected</div>
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><dt class="{{ $label }}" style="color: var(--text-secondary);">By</dt><dd style="color: var(--text-primary);">{{ $receipt->collector_name }}@if($receipt->collector_type === 'representative') <span class="text-xs" style="color: var(--text-muted);">({{ $receipt->collector_relationship }})</span>@endif</dd></div>
            <div><dt class="{{ $label }}" style="color: var(--text-secondary);">When</dt><dd style="color: var(--text-primary);">{{ $deed->collected_at?->format('d M Y H:i') }}</dd></div>
            <div><dt class="{{ $label }}" style="color: var(--text-secondary);">Verified by</dt><dd style="color: var(--text-primary);">{{ $receipt->methodLabel() }}</dd></div>
            {{-- The proofs, as they were Required / Optional / Not used at the handover: captured ones are shown, a skipped Optional one says "not captured", a Not used one is not mentioned. --}}
            @php
                $pm = $receipt->proof_modes;
                $isRep = $receipt->collector_type === 'representative';
                $proofRows = [
                    ['PIN', $pm['pin'] ?? null, $receipt->method === 'pin' || ($isRep && $receipt->method === 'pin'), $receipt->method === 'pin' ? 'Checked' : null, null],
                    ['Signature', $pm['signature'] ?? null, $receipt->signature_path !== null, ($receipt->signed_via ?? 'screen') === 'link' ? 'On their own phone, from a link' : 'On the staff screen', 'signature'],
                    ["Collector's ID photo", $pm['id_photo'] ?? null, $receipt->collector_id_photo_document_id !== null, 'Photo kept with the record', 'id-photo'],
                ];
                if ($isRep) { $proofRows[] = ['Authority letter', $pm['letter'] ?? null, $receipt->authority_letter_document_id !== null, 'Letter kept with the record', 'letter']; }
            @endphp
            @foreach($proofRows as [$pname, $pmode, $got, $gotText, $fileKey])
                @if($pm === null)
                    @if($got && $fileKey)<div><dt class="{{ $label }}" style="color: var(--text-secondary);">{{ $pname }}</dt><dd style="color: var(--text-primary);">{{ $gotText }}</dd></div>@endif
                @elseif($pmode !== 'not_used')
                    <div><dt class="{{ $label }}" style="color: var(--text-secondary);">{{ $pname }}</dt>
                        <dd style="color: {{ $got ? 'var(--text-primary)' : 'var(--text-muted)' }};">@if($got){{ $gotText }}@if($fileKey) &middot; <a href="{{ route('title-deeds.receipt.file', [$deed->id, $fileKey]) }}" target="_blank" class="font-semibold no-underline hover:underline" style="color: var(--brand-icon);">View</a>@endif @else Not captured @endif</dd></div>
                @endif
            @endforeach
            <div><dt class="{{ $label }}" style="color: var(--text-secondary);">Handed over by</dt><dd style="color: var(--text-primary);">{{ $receipt->recordedBy?->name ?? '—' }}</dd></div>
            @if($receipt->collector_id_number)<div><dt class="{{ $label }}" style="color: var(--text-secondary);">Collector ID number</dt><dd style="color: var(--text-primary);">{{ $receipt->collector_id_number }}</dd></div>@endif
            @if($receipt->id_number_seen)<div><dt class="{{ $label }}" style="color: var(--text-secondary);">ID number seen</dt><dd style="color: var(--text-primary);">{{ $receipt->id_number_seen }}</dd></div>@endif
            @if($receipt->signed_ip)<div><dt class="{{ $label }}" style="color: var(--text-secondary);">Signed from</dt><dd class="text-xs break-all" style="color: var(--text-primary);">{{ $receipt->signed_ip }} · {{ \Illuminate\Support\Str::limit((string) $receipt->signed_user_agent, 60) }}</dd></div>@endif
            @if($receipt->override_reason)<div class="col-span-2"><dt class="{{ $label }}" style="color: var(--text-secondary);">Override reason</dt><dd style="color: var(--text-primary);">{{ $receipt->override_reason }}</dd></div>@endif
        </dl>
    </div>
    @endif

    @if($events->isNotEmpty())
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 text-sm font-semibold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">Messages to owners</div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr style="background: var(--surface-2);">
                    @foreach(['When', 'What', 'Owner', 'Channel', 'To', 'Result', 'By'] as $h)<th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{{ $h }}</th>@endforeach
                </tr></thead>
                <tbody>
                @foreach($events as $ev)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $ev->created_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-2">{{ \App\Models\TitleDeeds\TitleDeedEvent::KIND_LABELS[$ev->kind] ?? $ev->kind }}</td>
                        <td class="px-4 py-2">{{ $ev->owner_name ?: '—' }}</td>
                        <td class="px-4 py-2">{{ $ev->channel === 'email' ? 'Email' : 'WhatsApp' }}</td>
                        <td class="px-4 py-2">{{ $ev->destination_masked ?: '—' }}</td>
                        <td class="px-4 py-2">
                            <span style="color: {{ $ev->status === 'sent' ? 'var(--ds-green)' : ($ev->status === 'failed' ? 'var(--ds-crimson)' : 'var(--text-secondary)') }};">{{ $evStatus[$ev->status] ?? $ev->status }}</span>
                            @if($ev->error)<div class="text-xs" style="color: var(--text-muted);">{{ $ev->error }}</div>@endif
                            @if($ev->opened_at)<div class="text-xs" style="color: var(--text-muted);">Opened by {{ $ev->openedBy?->name ?? '—' }} at {{ $ev->opened_at->format('H:i') }}@if($ev->confirmed_at) · confirmed sent by {{ $ev->user?->name ?? '—' }} at {{ $ev->confirmed_at->format('H:i') }}@endif</div>@endif
                            @if($ev->status === 'not_sent')<div class="text-xs" style="color: var(--text-muted);">Not sent &middot; cleared by {{ $ev->user?->name ?? '—' }} on {{ $ev->updated_at?->format('d M Y \a\t H:i') }}</div>@endif
                        </td>
                        <td class="px-4 py-2">{{ $ev->user?->name ?? 'System' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 text-sm font-semibold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">History</div>
        <div class="divide-y" style="border-color: var(--border);">
            @forelse($audit as $a)
            <div class="px-4 py-2 text-sm flex flex-wrap gap-x-4" style="border-top: 1px solid var(--border);">
                <span class="whitespace-nowrap" style="color: var(--text-muted);">{{ $a->created_at?->format('d M Y H:i') }}</span>
                <span style="color: var(--text-primary);">{{ $a->summary ?: $a->event }}</span>
                <span style="color: var(--text-muted);">{{ $a->user?->name ?? 'System' }}</span>
            </div>
            @empty
            <div class="px-4 py-3 text-sm" style="color: var(--text-muted);">No history yet.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
