@extends('layouts.corex')

{{--
    .ai/specs/rental-inspections.md — the inspection detail screen: a read
    view of what was recorded, plus administrative lifecycle (cancel /
    archive / restore). Recording new observations/photos/signatures still
    happens on the property's Rental Images tab (§1/§4).
--}}

@php
    $statusBadgeClass = match ($inspection->status) {
        'completed' => 'ds-badge-success',
        'cancelled' => 'ds-badge-danger',
        'awaiting_signature', 'in_progress' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
    $conditionBadgeClass = fn ($condition) => $condition === 'good' ? 'ds-badge-success' : 'ds-badge-danger';
@endphp

@section('content')
<div class="p-6 max-w-3xl mx-auto space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);">{{ $errors->first() }}</div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}</h1>
            <span class="ds-badge {{ $statusBadgeClass }}">{{ ucfirst(str_replace('_', ' ', $inspection->status)) }}</span>
            <span class="text-xs" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', '-', $inspection->type)) }} inspection</span>
        </div>
        <div class="flex items-center gap-2">
            {{-- The printable tick-box form — takes it to the property in wet
                 ink, scans it back in (OMR reader lane builds on this). --}}
            <a href="{{ route('corex.rental-inspections.form', $inspection) }}" class="corex-btn-outline text-xs">Download printable form</a>
            {{-- Johan, 2026-09-23, approved — the COMPLETED report: no photos,
                 a QR/link to the public page instead. A different document
                 from the printable form above (that one is blank, for
                 capture BEFORE an inspection; this one is the record AFTER). --}}
            <a href="{{ route('corex.rental-inspections.report', $inspection) }}" class="corex-btn-outline text-xs">Download report (PDF)</a>
            @if($inspection->type === 'out')
                {{-- rental-inspection-form.md §7 — the in-vs-out deposit comparison. --}}
                <a href="{{ route('corex.rental-inspections.deposit-comparison', $inspection) }}" class="corex-btn-outline text-xs">Move-in vs move-out comparison</a>
            @endif
            <a href="{{ route('corex.rental-inspections.index') }}" class="corex-btn-outline text-xs">&larr; All inspections</a>
        </div>
    </div>

    {{-- Johan's ruling, 2026-09-23 — "Next inspection" records the chain:
         In -> Routine -> Routine -> Out, any length. Hidden once a
         successor already exists (the migration's own unique index
         enforces this server-side too — one chain, never a fork), and In
         is never offered here since it can only ever be the first link
         (RentalInspection::startNext()'s own guard). --}}
    @permission('rental_inspections.create')
        @if(! $inspection->nextInChain)
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <form method="POST" action="{{ route('corex.rental-inspections.next', $inspection) }}" class="flex items-center gap-2 flex-wrap">
                    @csrf
                    <span class="text-xs font-semibold" style="color: var(--text-secondary);">Next inspection:</span>
                    <select name="type" class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                        <option value="ad_hoc">Routine (mid-tenancy)</option>
                        <option value="out">Out</option>
                    </select>
                    <button type="submit" class="corex-btn-primary text-xs">Start</button>
                    <span class="text-xs" style="color: var(--text-muted);">Compares against this inspection's own recorded condition, room by room.</span>
                </form>
            </div>
        @else
            <p class="text-xs" style="color: var(--text-muted);">
                Next in this chain:
                <a href="{{ route('corex.rental-inspections.show', $inspection->nextInChain) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">
                    {{ ucfirst(str_replace('_', '-', $inspection->nextInChain->type)) }}-inspection
                </a>
            </p>
        @endif
    @endpermission
    @if($inspection->previousInspection)
        <p class="text-xs" style="color: var(--text-muted);">
            Follows:
            <a href="{{ route('corex.rental-inspections.show', $inspection->previousInspection) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">
                {{ ucfirst(str_replace('_', '-', $inspection->previousInspection->type)) }}-inspection
            </a>
        </p>
    @endif

    {{-- Johan, 2026-09-23, approved — the public link a tenant/landlord
         with no CoreX login uses (also what the PDF's QR/link points at).
         Regenerating shows a NEW link and immediately invalidates any
         previous one — RentalInspection::generatePublicLink()'s own
         docblock. --}}
    @permission('rental_inspections.create')
        <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold">Public link</h2>
            @if($inspection->publicLinkIsValid())
                <p class="text-xs break-all" style="color: var(--text-secondary);">{{ route('rental-inspections.public.show', $inspection->public_token) }}</p>
                <p class="text-xs" style="color: var(--text-muted);">Live until {{ $inspection->public_token_expires_at->format('Y-m-d') }}.</p>
            @else
                <p class="text-xs" style="color: var(--text-muted);">No live link — generate one to share, or download the report above (it generates one automatically).</p>
            @endif
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('corex.rental-inspections.public-link.generate', $inspection) }}">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">{{ $inspection->publicLinkIsValid() ? 'Regenerate' : 'Generate' }} link</button>
                </form>
                @if($inspection->publicLinkIsValid())
                    <form method="POST" action="{{ route('corex.rental-inspections.public-link.revoke', $inspection) }}" onsubmit="return confirm('Revoke this link? Anyone with the current link or PDF will lose access immediately.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Revoke</button>
                    </form>
                @endif
            </div>
        </div>
    @endpermission

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div><span style="color: var(--text-muted);">Tenant(s):</span> {{ $inspection->lease?->tenantNames() ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Recorded by:</span> {{ $inspection->createdBy?->name ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Scheduled:</span> {{ $inspection->scheduled_for?->format('Y-m-d') ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Completed:</span> {{ $inspection->completed_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Fault-report deadline:</span> {{ $inspection->fault_report_deadline_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div><span style="color: var(--text-muted);">Signing deadline:</span> {{ $inspection->signing_deadline_at?->format('Y-m-d H:i') ?? '—' }}</div>
        </div>

        @if($inspection->status === 'cancelled')
            <p class="text-xs" style="color: var(--ds-crimson);">Cancelled {{ $inspection->cancelled_at?->format('Y-m-d') }} by {{ $inspection->cancelledBy?->name }}: {{ $inspection->cancel_reason }}</p>
        @endif

        <div class="flex gap-2 pt-2">
            @permission('rental_inspections.create')
                @if(!in_array($inspection->status, ['completed', 'cancelled'], true))
                    <button type="button" onclick="document.getElementById('cancel-inspection-form').classList.toggle('hidden')" class="corex-btn-outline text-xs">Cancel inspection</button>
                @endif
                {{-- 2026-09-20 — isDeletable()/the completed-status restriction are both
                     retired: archiving is now unconditional (real evidence is never
                     destroyed by a soft delete), matching RentalInspectionController::
                     destroy(). This button is only hidden once the record is already
                     archived, below. --}}
                @if(!$inspection->trashed())
                    <form method="POST" action="{{ route('corex.rental-inspections.destroy', $inspection) }}" onsubmit="return confirm('Archive this inspection?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                    </form>
                @endif
                @if($inspection->trashed())
                    <form method="POST" action="{{ route('corex.rental-inspections.restore', $inspection->id) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                    </form>
                @endif
            @endpermission
        </div>

        <form id="cancel-inspection-form" method="POST" action="{{ route('corex.rental-inspections.cancel', $inspection) }}" class="hidden space-y-2 pt-2">
            @csrf
            <label class="text-xs font-medium">Reason for cancellation (required)</label>
            <textarea name="cancel_reason" required class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
            <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Confirm cancel</button>
        </form>
    </div>

    @if($inspection->discrepancies->isNotEmpty())
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Discrepancies</h2>
        @foreach($inspection->discrepancies as $discrepancy)
            <div class="text-sm space-y-1" style="border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                <div class="flex items-center justify-between">
                    <span>{{ $discrepancy->item?->label ?? 'Unknown item' }}</span>
                    @if($discrepancy->resolved_at)
                        <span class="ds-badge ds-badge-success">Resolved</span>
                    @else
                        <span class="ds-badge ds-badge-danger">Unresolved</span>
                    @endif
                </div>
                <div class="text-xs" style="color: var(--text-muted);">
                    {{ $discrepancy->observations->pluck('condition')->map(fn ($c) => ucfirst($c))->implode(' vs ') }}
                </div>
                @if($discrepancy->resolved_at)
                    <div class="text-xs" style="color: var(--text-muted);">Resolved by {{ $discrepancy->resolvedBy?->name }}: {{ $discrepancy->resolution_note }}</div>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    @if($comparisonRows !== null)
        {{-- Johan's ruling, 2026-09-23 — §2 (predecessor left/read-only,
             this inspection's own value right), §3 (row shows the
             immediate predecessor's value; the full run is on demand, via
             plain <details> — no Alpine, nothing here can fall into the
             :style-clobber trap that cost four rounds on the recording
             screen tonight). Replaces the flat list below entirely once a
             predecessor exists — §6, the FIRST inspection in a chain has
             none, and falls through to that flat list unchanged. --}}
        <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold">
                Compared against the {{ $inspection->previousInspection->type }}-inspection
                <span class="text-xs" style="color: var(--text-muted);">({{ $inspection->previousInspection->scheduled_for?->format('Y-m-d') ?? $inspection->previousInspection->created_at->format('Y-m-d') }})</span>
            </h2>
            @foreach($comparisonRows as $roomId => $roomRows)
                @php
                    // Shorthand @php(...) form mis-parses on a nested-paren
                    // expression like first()->room (it closes on the FIRST
                    // ")" it finds — from first(), not the statement's own
                    // end — corrupting every directive compiled after it in
                    // the whole file. Block form has no such fragility.
                    $room = $roomRows->first()->room;
                @endphp
                <div class="space-y-1.5">
                    <h3 class="text-xs font-bold uppercase tracking-wide" style="color: var(--text-secondary);">{{ $room?->label ?? 'General' }}</h3>
                    <table class="w-full text-sm" style="border-collapse: collapse;">
                        <thead>
                            <tr class="text-xs" style="color: var(--text-muted);">
                                <th class="text-left font-normal py-1">Item</th>
                                <th class="text-left font-normal py-1">Previous</th>
                                <th class="text-left font-normal py-1">Current</th>
                                <th class="text-left font-normal py-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($roomRows as $row)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="py-1.5 align-top">{{ $row->item->label }}</td>
                                    <td class="py-1.5 align-top">
                                        @if($row->predecessor)
                                            <span class="ds-badge {{ $conditionBadgeClass($row->predecessor->condition) }}">{{ ucfirst($row->predecessor->condition) }}</span>
                                            @if($row->predecessor->notes)
                                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $row->predecessor->notes }}</div>
                                            @endif
                                        @else
                                            <span class="text-xs" style="color: var(--text-muted);">—</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 align-top">
                                        @if($row->current)
                                            <span class="ds-badge {{ $conditionBadgeClass($row->current->condition) }}">{{ ucfirst($row->current->condition) }}</span>
                                            @if($row->current->notes)
                                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $row->current->notes }}</div>
                                            @endif
                                        @else
                                            <span class="text-xs" style="color: var(--text-muted);">Not yet recorded</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 align-top">
                                        @if($row->history->count() > 1)
                                            <details>
                                                <summary class="text-xs cursor-pointer" style="color: var(--brand-icon, #0ea5e9);">Full history ({{ $row->history->count() }})</summary>
                                                <div class="text-xs mt-1 space-y-0.5" style="color: var(--text-secondary);">
                                                    @foreach($row->history as $entry)
                                                        <div>{{ ucfirst($entry->inspection_type) }} ({{ $entry->scheduled_for?->format('Y-m-d') ?? '—' }}): {{ ucfirst($entry->observation->condition) }}</div>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @else
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Observations</h2>
        @forelse($inspection->observations as $observation)
            <div class="text-sm flex items-center justify-between" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                <span>{{ $observation->item?->label ?? 'Unknown item' }}
                    <span class="ds-badge {{ $conditionBadgeClass($observation->condition) }}">{{ ucfirst($observation->condition) }}</span>
                    @if($observation->notes) — {{ $observation->notes }} @endif
                </span>
                <span class="text-xs" style="color: var(--text-muted);">
                    {{ $observation->observedByUser?->name ?? $observation->observedByContact?->full_name }}
                    · {{ $observation->created_at?->format('Y-m-d H:i') }}
                    @if($observation->photos->isNotEmpty()) · {{ $observation->photos->count() }} photo(s) @endif
                </span>
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No observations recorded yet.</p>
        @endforelse
    </div>
    @endif

    {{-- .ai/specs/rental-inspection-form.md §13 — the OMR scan reader, part 2
         of cc5's printable-form job. Upload the wet-ink-marked printed form
         back in; nothing is applied to the inspection until a human confirms
         on the review screen. --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Scanned forms</h2>
        @permission('rental_inspections.create')
            <form method="POST" action="{{ route('corex.rental-inspections.scans.store', $inspection) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                @csrf
                <input type="file" name="scan" accept="application/pdf,image/jpeg,image/png,image/heic,image/heif" required class="text-xs">
                <button type="submit" class="corex-btn-outline text-xs">Upload scan</button>
            </form>
        @endpermission
        @forelse($inspection->scans as $scan)
            @php
                $scanStatusLabel = match ($scan->status) {
                    'needs_review' => 'Needs review',
                    'applied' => 'Applied',
                    'version_mismatch' => 'Form version mismatch',
                    'failed' => 'Could not be read',
                    default => 'Processing',
                };
                $scanStatusBadge = match ($scan->status) {
                    'applied' => 'ds-badge-success',
                    'needs_review' => 'ds-badge-info',
                    'version_mismatch', 'failed' => 'ds-badge-danger',
                    default => 'ds-badge-muted',
                };
            @endphp
            <div class="text-sm flex items-center justify-between" style="border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                <span>{{ $scan->original_filename }}
                    <span class="ds-badge {{ $scanStatusBadge }}">{{ $scanStatusLabel }}</span>
                </span>
                <span class="text-xs flex items-center gap-2" style="color: var(--text-muted);">
                    {{ $scan->uploadedBy?->name }} · {{ $scan->created_at?->format('Y-m-d H:i') }}
                    @if(in_array($scan->status, ['needs_review', 'applied'], true))
                        <a href="{{ route('corex.rental-inspections.scans.review', [$inspection, $scan]) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">Review</a>
                    @endif
                    <a href="{{ route('corex.rental-inspections.scans.download', [$inspection, $scan]) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">Download original</a>
                    @permission('rental_inspections.create')
                        <form method="POST" action="{{ route('corex.rental-inspections.scans.destroy', [$inspection, $scan]) }}" onsubmit="return confirm('Archive this scan?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="underline" style="color: var(--ds-crimson); background: none; border: 0;">Archive</button>
                        </form>
                    @endpermission
                </span>
            </div>
            @if($scan->failure_reason)
                <p class="text-xs" style="color: var(--ds-crimson);">{{ $scan->failure_reason }}</p>
            @endif
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No scans uploaded yet.</p>
        @endforelse
    </div>

    @if($inspection->signatures->isNotEmpty())
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Signatures</h2>
        {{--
            §15.5/§15.8, Stage 5 — the unambiguous rendering Johan's ruling
            requires: "a refusal must never be able to look like a signature,
            on screen or on the PDF." Branches on `disposition` alone (never
            on whether party_signature_path happens to be null), and a
            signed row's image and a refused row's reason block share no
            markup — different shape, not just different colour, so this
            still reads correctly in black-and-white print. Neither is
            styled as an error or a warning: both are simply facts about how
            the inspection ended (Johan: "none of these should feel like
            [an error state] in the UI").
        --}}
        @foreach($inspection->signatures as $signature)
            @php
                $partyLabel = match($signature->party_role) {
                    'agent' => 'Agent',
                    'landlord' => 'Landlord' . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
                    default => 'Tenant' . ($signature->partyContact ? ' — ' . $signature->partyContact->full_name : ''),
                };
                $reasonLabel = collect($refusalReasonPresets)->firstWhere('key', $signature->refusal_reason_preset)['label']
                    ?? $signature->refusal_reason_preset;
            @endphp
            <div class="text-sm py-2" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <span style="color: var(--text-primary);">{{ $partyLabel }}</span>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $signature->disposition_recorded_at?->format('Y-m-d H:i') }}</span>
                </div>
                @if($signature->disposition === 'signed')
                    <div class="mt-1.5">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Signed</span>
                        @if($signature->party_signature_path)
                            <div class="mt-1">
                                <img src="{{ $signature->party_signature_path }}" alt="{{ $partyLabel }}'s signature"
                                     style="max-height: 60px; background: #fff; border: 1px solid var(--border); border-radius: 4px; padding: 4px;">
                            </div>
                        @endif
                    </div>
                @elseif($signature->disposition === 'wet_ink')
                    {{--
                        .ai/specs/rental-inspections.md §16 — evidence of a real
                        signature, on paper, never rendered as an e-signature: no
                        signature-image markup, no "Signed" label, its own shape
                        entirely so it cannot be mistaken for either the signed or
                        the refused block above, cold, eighteen months from now.
                    --}}
                    <div class="mt-1.5 rounded-md px-3 py-2" style="background: var(--surface-2);">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Signed on paper (wet-ink)</span>
                        @if($signature->superseded_at)
                            <div class="text-xs mt-0.5" style="color: var(--ds-amber, #d97706);">
                                Superseded — replaced by a corrected upload{{ $signature->supersededBy ? ' below' : '' }}.
                            </div>
                        @elseif($signature->wet_ink_upload_path)
                            <div class="text-xs mt-0.5">
                                <a href="{{ $signature->wet_ink_upload_path }}" target="_blank" rel="noopener" class="underline" style="color: var(--brand-icon, #0ea5e9);">View uploaded page</a>
                            </div>
                        @endif
                        @if($signature->recordedByUser)
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                Uploaded by {{ $signature->recordedByUser->name }} on {{ $signature->disposition_recorded_at?->format('Y-m-d H:i') }} — attested by the agent's own signature below.
                            </div>
                        @endif
                    </div>
                @else
                    <div class="mt-1.5 rounded-md px-3 py-2" style="background: var(--surface-2);">
                        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Refused to sign</span>
                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">
                            Reason: {{ $reasonLabel }}{{ $signature->refusal_reason_note ? ' — ' . $signature->refusal_reason_note : '' }}
                        </div>
                        @if($signature->recordedByUser)
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                Recorded by {{ $signature->recordedByUser->name }} — attested by the agent's own signature below.
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    {{-- .ai/specs/rental-inventory.md §4 — reachable from where the work
         happens, not only the sidebar list (Johan, 2026-09-22). --}}
    @include('corex.rental-inventories.partials._related-inventories', ['property' => $inspection->property])
</div>
@endsection
