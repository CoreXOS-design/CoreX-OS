{{--
    Property compliance-document checklist (AT-94 / Drive tab).
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    Mirrors the MarketingReadinessService gate EXACTLY — each required type is
    ticked when a non-soft-deleted Drive Document of that type is present on the
    property OR the seller contact (same presence the gate reads). Missing rows
    upload inline with the document type PRE-SET (so it can't be mistyped) via
    the existing PropertyFileController::store endpoint — on success the page
    reloads on the Drive tab and both this list and the readiness panel
    recompute.

    @var \App\Models\Property $property
    @var array $complianceChecklist
--}}
@if(!empty($complianceChecklist))
@php
    $presentCount = collect($complianceChecklist)->where('present', true)->count();
    $totalCount = count($complianceChecklist);
    $allPresent = $presentCount === $totalCount;
@endphp
<div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
    <div class="px-4 py-3 flex items-center justify-between" style="background:var(--surface-2);">
        <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" style="color:var(--brand-icon,#0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <span class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-primary);">Compliance Documents</span>
            <span class="text-[10px] cursor-help" style="color:var(--text-muted);"
                  title="Documents your agency requires before this property can be marketed. Ticked = a document of that type is on the Drive (or the seller's drive for FICA). Upload a missing one here and its type is set automatically.">&#9432;</span>
        </div>
        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded"
              style="{{ $allPresent ? 'background:rgba(16,185,129,.15); color:#047857;' : 'background:rgba(245,158,11,.15); color:#b45309;' }}">
            {{ $presentCount }} / {{ $totalCount }} on file
        </span>
    </div>

    <div>
        @foreach($complianceChecklist as $row)
        <div class="px-4 py-3 flex items-center justify-between gap-3" style="border-top:1px solid var(--border);">
            <div class="flex items-center gap-2.5 min-w-0">
                @if($row['present'])
                    <span class="flex items-center justify-center w-5 h-5 rounded-full flex-shrink-0" style="background:rgba(16,185,129,.15);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="#10b981" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </span>
                @else
                    <span class="flex items-center justify-center w-5 h-5 rounded-full flex-shrink-0" style="background:rgba(245,158,11,.15);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="#f59e0b" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                    </span>
                @endif
                <div class="min-w-0">
                    <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $row['label'] }}</div>
                    @if($row['present'] && $row['doc'])
                        <div class="text-[11px] truncate" style="color:var(--text-muted);">
                            @if(($row['doc']['source'] ?? null) === 'esign')
                                <span style="color:#22c55e;">&#10003; e-signed</span> &middot;
                            @endif
                            {{ $row['doc']['name'] }}
                        </div>
                    @elseif(!empty($row['is_contact_fica']))
                        <div class="text-[11px]" style="color:var(--text-muted);">{{ $row['detail'] }}{{ $row['present'] ? '' : ' · seller-level compliance' }}</div>
                    @elseif($row['present'] && !empty($row['satisfied_by_snapshot']))
                        <div class="text-[11px]" style="color:var(--text-muted);">Verified at go-live</div>
                    @elseif($row['present'])
                        <div class="text-[11px]" style="color:var(--text-muted);">On file</div>
                    @else
                        <div class="text-[11px]" style="color:var(--text-muted);">
                            Required — not yet on file{{ $row['upload_contact_id'] ? " · files to " . $row['upload_contact_name'] . "'s drive" : '' }}
                        </div>
                    @endif
                </div>
            </div>

            @unless($row['present'])
                @if(!empty($row['is_contact_fica']))
                    {{-- FICA is a seller-contact gate: link to the seller's FICA, no property upload --}}
                    <a href="{{ $row['action_url'] }}"
                       class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-3 py-1.5 rounded-md no-underline flex-shrink-0 transition hover:opacity-80"
                       style="background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.2);"
                       title="FICA is verified on the seller contact, not uploaded to the property.">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        {{ $row['action_label'] }}
                    </a>
                @else
                    <form method="POST" action="{{ $row['upload_url'] }}" enctype="multipart/form-data" class="flex-shrink-0">
                        @csrf
                        <input type="hidden" name="document_type_id" value="{{ $row['document_type_id'] }}">
                        @if($row['upload_contact_id'])
                            <input type="hidden" name="contact_id" value="{{ $row['upload_contact_id'] }}">
                        @endif
                        <label class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-3 py-1.5 rounded-md no-underline cursor-pointer transition hover:opacity-80"
                               style="background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.2);"
                               title="Upload a {{ $row['label'] }} — its document type is set automatically.">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                            Upload
                            <input type="file" name="file" class="hidden" onchange="if(this.files.length){ this.form.submit(); }">
                        </label>
                    </form>
                @endif
            @endunless
        </div>
        @endforeach
    </div>
</div>
@endif
