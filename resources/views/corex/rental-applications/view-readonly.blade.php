{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $checklist = $rentalApplication->employment_type
        ? \App\Models\RentalApplicationDocumentRequirement::checklistFor($rentalApplication->agency_id, $rentalApplication->employment_type)
        : collect();
    $onFileTypeIds = $rentalApplication->documents->pluck('document_type_id')->filter()->all();
@endphp

@section('corex-content')
<div class="w-full space-y-5">

    {{--
        AT-392, Johan (asked three times): "opening a rental application
        should not be able to edit... open / view should show the
        application the applicant sent in. nothing more. no edits,
        nothing." This screen replaces the editable field form with the
        SAME generated PDF the module already produces for download —
        embedded read-only below, never a second rendering of the data.
    --}}
    <x-sticky-action-bar>
        <x-slot name="left">
            <div class="min-w-0">
                <h1 class="text-sm font-bold leading-tight truncate" style="color: var(--text-primary);">
                    {{ $rentalApplication->contact->full_name ?? 'Rental Application' }}
                </h1>
                <span class="ds-badge ds-badge-info">
                    {{ str_replace('_', ' ', $rentalApplication->status) }} — read-only, as submitted and signed
                </span>
            </div>
        </x-slot>
        <x-slot name="right">
            <a href="{{ route('corex.rental-applications.index') }}" class="corex-btn-outline text-xs">&larr; Back to list</a>
            <a href="{{ route('corex.rental-applications.pdf', $rentalApplication) }}" class="corex-btn-outline text-xs">Download PDF</a>

            @permission('rental_applications.create')
            <form method="POST" action="{{ route('corex.rental-applications.destroy', $rentalApplication) }}"
                  onsubmit="return confirm('Archive this rental application? It can be recovered by an admin.');" class="inline">
                @csrf
                @method('DELETE')
                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
            </form>
            @endpermission
        </x-slot>
    </x-sticky-action-bar>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);">{{ session('error') }}</div>
    @endif

    <div class="rounded-md p-3 text-xs" style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border); color: var(--text-secondary);">
        This is a faithful, read-only rendering of the application the applicant submitted and signed. It cannot be
        edited from here or by any other route on this record — that is deliberate: a signed application a third
        party could alter afterwards would not stand as evidence of anything.
    </div>

    {{--
        Johan, QA1 — "on returned applications theres statuses at the top,
        but theres no way to mark application status to what it is?" This
        is the agent's own subsequent workflow action (assess / withdraw),
        not an edit of the applicant's answers — it stays exactly as it
        was on the editable screen.
    --}}
    @permission('rental_applications.create')
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Application Status</h2>
        <form method="POST" action="{{ route('corex.rental-applications.update-status', $rentalApplication) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Set status to</label>
                <select name="status" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <option value="returned" disabled @selected(old('status', $rentalApplication->status) === 'returned')>Returned (awaiting review)</option>
                    @foreach(\App\Models\RentalApplication::AGENT_SETTABLE_STATUSES as $s)
                        <option value="{{ $s }}" @selected(old('status', $rentalApplication->status) === $s)>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
                    @endforeach
                </select>
                @error('status')
                    <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Note (optional)</label>
                <input type="text" name="note" value="{{ old('note') }}" maxlength="1000" placeholder="Reason for this decision..." class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                @error('note')
                    <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Update Status</button>
        </form>

        @if($rentalApplication->statusHistory->isNotEmpty())
        <div class="mt-4 pt-3 text-xs space-y-1" style="border-top: 1px solid var(--border); color: var(--text-muted);">
            @foreach($rentalApplication->statusHistory as $entry)
                <div>
                    {{ optional($entry->from_status)  ? str_replace('_', ' ', $entry->from_status) . ' → ' : '' }}{{ str_replace('_', ' ', $entry->to_status) }}
                    — {{ $entry->changedBy?->name ?? 'System' }}, {{ $entry->created_at->format('d M Y H:i') }}
                    @if($entry->note) — "<span style="white-space: pre-wrap;">{{ $entry->note }}</span>" @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>
    @endpermission

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Signatures</h2>
            <div class="text-xs space-y-1" style="color: var(--text-secondary);">
                <div>Declaration: {{ $rentalApplication->declarationSignature() ? '✓ Signed ' . $rentalApplication->declarationSignature()->signed_at->format('d M Y H:i') : 'Not yet signed' }}</div>
                <div>TPN Consent: {{ $rentalApplication->tpnConsentSignature() ? '✓ Signed ' . $rentalApplication->tpnConsentSignature()->signed_at->format('d M Y H:i') : 'Not yet signed' }}</div>
            </div>
        </div>

        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Supporting Documents</h2>
            <p class="text-xs" style="color: var(--text-muted);" @if($rentalApplication->documents->isNotEmpty()) hidden @endif>None uploaded yet.</p>
            <ul class="text-xs space-y-1" style="color: var(--text-secondary);" @if($rentalApplication->documents->isEmpty()) hidden @endif>
                @foreach($rentalApplication->documents as $doc)
                    <li>✓ <a href="{{ route('corex.rental-applications.documents.download', [$rentalApplication, $doc]) }}" style="color: var(--brand-icon, #2563eb);">{{ $doc->original_name }}</a> @if($doc->documentType) ({{ $doc->documentType->label }}) @endif
                        <span style="color: var(--text-muted);">— {{ $doc->uploaded_by ? 'added by ' . ($doc->uploader->name ?? 'an agent') : 'from applicant' }}</span>
                        @if($rentalApplication->submitted_at && $doc->created_at->greaterThanOrEqualTo($rentalApplication->submitted_at) && $doc->uploaded_by)
                            <span class="ds-badge ds-badge-warning" title="This document was added after the application was submitted">Added after submission</span>
                            <span style="color: var(--text-muted);">{{ $doc->created_at->format('d M Y H:i') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if($checklist->isNotEmpty())
                <p class="text-xs font-medium mt-3 mb-1" style="color: var(--text-secondary);">Checklist ({{ str_replace('_', ' ', $rentalApplication->employment_type) }}):</p>
                <ul class="text-xs space-y-1">
                    @foreach($checklist as $type)
                        <li style="color: {{ in_array($type->id, $onFileTypeIds) ? 'var(--ds-emerald, #059669)' : 'var(--ds-amber, #d97706)' }};">
                            {{ in_array($type->id, $onFileTypeIds) ? '✓' : '○ outstanding —' }} {{ $type->label }}
                        </li>
                    @endforeach
                </ul>
                <p class="text-[11px] mt-2" style="color: var(--text-muted);">Outstanding documents never block this application — informational only.</p>
            @endif
        </div>
    </div>

    {{--
        Johan, driving this himself (2026-09-08): "the embedded PDF took
        about five seconds to appear, showing an empty dark viewer panel
        the whole time with no indication anything was loading. I
        initially recorded it as broken." The PDF is generated server-side
        (RentalApplicationPdfService, Puppeteer) on each request, not
        cached — a real few-second wait is expected, so the fix is telling
        the agent that, not making it faster. x-show hides on the
        iframe's own @load event; no fixed timer, so it never lies about
        whether the PDF has actually arrived.
    --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ pdfLoaded: false }">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Submitted Application</h2>
        <div class="relative" style="height: 85vh;">
            <div x-show="!pdfLoaded" x-transition.opacity
                 class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm rounded-md"
                 style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border); color: var(--text-secondary);">
                <svg class="animate-spin" style="width: 1.75rem; height: 1.75rem; color: var(--brand-icon, #2563eb);" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-opacity="0.25"></circle>
                    <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                </svg>
                <span>Loading the signed application&hellip;</span>
            </div>
            <iframe src="{{ route('corex.rental-applications.pdf-inline', $rentalApplication) }}"
                    title="Rental application as submitted and signed"
                    @load="pdfLoaded = true"
                    x-show="pdfLoaded"
                    style="width: 100%; height: 100%; border: 1px solid var(--border); border-radius: 6px; background: #fff;">
                <p class="text-xs" style="color: var(--text-muted);">
                    Your browser can't display the PDF inline.
                    <a href="{{ route('corex.rental-applications.pdf', $rentalApplication) }}" style="color: var(--brand-icon, #2563eb);">Download it instead</a>.
                </p>
            </iframe>
        </div>
    </div>
</div>
@endsection
