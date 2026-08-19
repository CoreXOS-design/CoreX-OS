@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Deeds Capture</h1>
        <p class="text-sm text-white/60 mt-1">
            Properties captured from CMA / deeds lookups, held here for review. These are kept separate from
            Market Intelligence. Confirm a capture to create a real property and link the owner as its owner.
        </p>
    </div>

    @if(session('success'))
        {{-- success_link (2026-08-14) — optional, set only by promote() (and,
             2026-08-17, ingestTva()) alongside 'success'; dismiss actions keep
             sending a plain string with no link, so this stays backward-
             compatible rather than changing the shape of session('success')
             itself. Closes the "action named with no way to take it" dead-end.
             success_link_label defaults to promote()'s original copy so that
             call site is unaffected; ingestTva() supplies its own ("Open
             contact →") since the link target differs (a contact, not a
             property). --}}
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green, #16a34a) 35%, transparent); color: var(--text-primary);">
            {{ session('success') }}
            @if(session('success_link'))
                <a href="{{ session('success_link') }}" class="font-semibold underline" style="color: var(--ds-green, #16a34a);">{{ session('success_link_label', 'Open property →') }}</a>
            @endif
        </div>
    @endif
    @if(session('info'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-secondary);">{{ session('info') }}</div>
    @endif

    @if($captures->isEmpty())
        <div class="rounded-md p-8 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-sm" style="color: var(--text-muted);">No deeds captures waiting. Capture a property from CMA Info to see it here.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach($captures as $tp)
                @php
                    $addr = collect([
                        trim(($tp->street_number ?? '') . ' ' . ($tp->street_name ?? '')),
                        $tp->complex_name,
                        $tp->suburb,
                        $tp->town,
                        $tp->province,
                    ])->filter(fn ($v) => trim((string) $v) !== '')->implode(', ');

                    // Sectional-title headline swap (2026-08-14) — same sectional
                    // detection as the matcher (presence of scheme/section): for a
                    // sectional unit the primary identity is the COMPLEX + SECTION,
                    // not the street (a scheme's street address is shared by every
                    // unit in it, so leading with it reads as if every card is the
                    // same property). Freehold is untouched — $addr as before.
                    // section_number requires a digit — some pre-fix captures have
                    // leaked label text ("Flat number") into this column, and that
                    // garbage must not trigger the sectional headline on what's
                    // actually a freehold record.
                    // hasRealSection (2026-08-14) — shared by the headline AND the
                    // detail line below (the "· Section …" segment). CONFIRMED LIVE:
                    // pre-fix captures leaked the literal string "Flat number" into
                    // section_number on records that are actually FREEHOLD (56 Avenue
                    // Svea, 53 Broadway — both scheme_name/scheme_number NULL), so a
                    // bare truthiness check on section_number displays that garbage as
                    // if it were a real section. Same digit guard as $isSectional.
                    $hasRealSection = filled($tp->section_number) && preg_match('/\d/', (string) $tp->section_number);
                    $isSectional = filled($tp->scheme_name) || filled($tp->scheme_number) || $hasRealSection;
                    if ($isSectional) {
                        $schemeLabel = collect([
                            $tp->complex_name ?: $tp->scheme_name,
                            $hasRealSection ? ('Section ' . $tp->section_number) : null,
                        ])->filter(fn ($v) => trim((string) $v) !== '')->implode(' — ');
                        $headline = collect([$schemeLabel, $tp->suburb])->filter(fn ($v) => trim((string) $v) !== '')->implode(', ');
                        if ($headline === '') $headline = $addr; // no scheme/complex name captured — fall back rather than show nothing
                        $secondaryAddr = collect([
                            trim(($tp->street_number ?? '') . ' ' . ($tp->street_name ?? '')),
                            $tp->town,
                            $tp->province,
                        ])->filter(fn ($v) => trim((string) $v) !== '')->implode(', ');
                    } else {
                        $headline = $addr;
                        $secondaryAddr = '';
                    }

                    $owner = $tp->ownerContact;
                    $owners = $tp->owners; // multi-owner (2026-08-12) — falls back to $owner below for pre-migration captures

                    // 2026-08-19 (Johan, .ai/specs/deeds-capture.md §6 Part B) — what THIS
                    // capture actually did to this property, not just that it did something.
                    // Human labels for the raw TrackedProperty column keys that can appear in
                    // field_changes (TrackedPropertyMatchOrCreateService::enrich()).
                    $deedsFieldLabels = [
                        'street_number' => 'Street number', 'street_name' => 'Street name',
                        'unit_number' => 'Unit number', 'complex_name' => 'Complex name',
                        'suburb' => 'Suburb', 'town' => 'Municipality', 'province' => 'Province',
                        'postal_code' => 'Postal code',
                        'latitude' => 'Latitude', 'longitude' => 'Longitude',
                        'cma_gps_lat' => 'GPS latitude (CMA)', 'cma_gps_lng' => 'GPS longitude (CMA)',
                        'erf_number' => 'Erf number', 'title_deed_number' => 'Title deed number',
                        'cadastral_extent' => 'Extent (m²)', 'property_type' => 'Property type',
                        'deeds_office' => 'Deeds office', 'scheme_name' => 'Scheme name',
                        'scheme_number' => 'Scheme number', 'section_number' => 'Section number',
                        'last_known_sold_price' => 'Sold price', 'last_known_sold_date' => 'Sold date',
                        'bond_holder' => 'Bond holder', 'bond_amount' => 'Bond amount',
                        'sale_type' => 'Sale type', 'deeds_registered_date' => 'Registered date',
                    ];
                    $deedsFormatValue = function ($field, $val) {
                        if ($val === null || $val === '') return '—';
                        if (in_array($field, ['bond_amount', 'last_known_sold_price'], true)) {
                            return 'R ' . number_format((float) $val, 0, '.', ',');
                        }
                        if (in_array($field, ['last_known_sold_date', 'deeds_registered_date'], true)) {
                            try { return \Illuminate\Support\Carbon::parse($val)->format('j M Y'); } catch (\Throwable $e) { return (string) $val; }
                        }
                        if (in_array($field, ['latitude', 'longitude', 'cma_gps_lat', 'cma_gps_lng'], true)) {
                            return number_format((float) $val, 7);
                        }
                        return (string) $val;
                    };
                    $deedsChanges = $fieldChangesByTp[$tp->id] ?? null;
                @endphp
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        {{-- Property --}}
                        <div class="min-w-0 flex-1">
                            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Property</div>
                            <div class="font-semibold text-sm flex items-center gap-2 flex-wrap" style="color: var(--text-primary);">
                                {{ $headline !== '' ? $headline : ('Tracked property #' . $tp->id) }}
                                {{-- DEEDS BUG 1 fix (2026-08-19) — this row is showing on the
                                     deeds_captured_at marker, not because it's classified as a
                                     deeds capture (capture_kind='deeds_capture'). It's an EXISTING
                                     record (a prospecting/P24 lead, or a scheme unit already
                                     tracked) that a deeds capture just landed on — flag it rather
                                     than let it look identical to a brand-new deeds capture. --}}
                                @if($tp->capture_kind !== 'deeds_capture')
                                    <span class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded"
                                          style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent); color: var(--ds-amber, #f59e0b); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, transparent);"
                                          title="This deed matched a property already tracked from another source — the deed enriched it rather than creating a new record.">
                                        Already tracked · deed linked
                                    </span>
                                @endif
                            </div>
                            @if($secondaryAddr !== '')
                                <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $secondaryAddr }}</div>
                            @endif
                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                @if($tp->scheme_name)Scheme: {{ $tp->scheme_name }}@if($tp->scheme_number) ({{ $tp->scheme_number }})@endif @endif
                                @if($hasRealSection) · Section {{ $tp->section_number }}@endif
                                @if($tp->erf_number) · Erf {{ $tp->erf_number }}@endif
                                @if($tp->title_deed_number) · Deed {{ $tp->title_deed_number }}@endif
                                @if($tp->cadastral_extent) · {{ $tp->cadastral_extent }} m²@endif
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                @if($tp->last_known_sold_price)Sold R {{ number_format((float) $tp->last_known_sold_price, 0, '.', ',') }}@endif
                                @if($tp->last_known_sold_date) on {{ \Illuminate\Support\Carbon::parse($tp->last_known_sold_date)->format('Y-m-d') }}@endif
                                @if($tp->bond_holder) · Bond: {{ $tp->bond_holder }}@if($tp->bond_amount) R {{ number_format((float) $tp->bond_amount, 0, '.', ',') }}@endif @endif
                                @if($tp->sale_type) · {{ $tp->sale_type }}@endif
                            </div>
                        </div>

                        {{-- Owner(s) — multi-owner (2026-08-12): CMA properties can list more than
                             one registered owner; loop tracked_property_owners when present, else
                             fall back to the single ownerContact (pre-migration captures).
                             Entity model (2026-08-14): a company is the sole OWNER; its DIRECTORS
                             are captured on the same deed (role='director') so agents can work them,
                             but shown as a distinct "Directors" group — never as owners. --}}
                        @php
                            $directorRows = $owners->filter(fn ($o) => $o->role === 'director')->values();
                            $ownerRows    = $owners->reject(fn ($o) => $o->role === 'director')->values();
                        @endphp
                        <div class="min-w-0" style="min-width: 14rem;">
                            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                                Owner{{ $ownerRows->count() > 1 ? 's' : '' }}
                            </div>
                            @if($ownerRows->isNotEmpty())
                                @foreach($ownerRows as $ownerRow)
                                    <div @if(!$loop->first) class="mt-2 pt-2" style="border-top:1px solid var(--border);" @endif>
                                        <div class="font-semibold text-sm" style="color: var(--text-primary);">
                                            {{ $ownerRow->contact ? trim($ownerRow->contact->first_name . ' ' . (string) $ownerRow->contact->last_name) : ($ownerRow->name ?? 'Unnamed owner') }}
                                        </div>
                                        <div class="text-xs mt-0.5 flex items-center gap-2" style="color: var(--text-muted);">
                                            @if($ownerRow->id_number)
                                                <span>{{ $ownerRow->id_type === 'company_reg' ? 'Company reg' : 'ID' }}: {{ $ownerRow->id_number }}</span>
                                                {{-- Copy ID (2026-08-12) — TVA flow: paste this into TVA's person lookup. --}}
                                                <button type="button" x-data="{ copied: false }"
                                                        @click="navigator.clipboard.writeText({{ Js::from($ownerRow->id_number) }}); copied = true; setTimeout(() => copied = false, 1500)"
                                                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded"
                                                        style="border:1px solid var(--border); color: var(--brand-icon, #2563eb);"
                                                        x-text="copied ? 'Copied!' : 'Copy ID'"></button>
                                            @else
                                                <span style="color: var(--ds-amber, #f59e0b);">No owner ID</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @elseif($owner)
                                <div class="font-semibold text-sm" style="color: var(--text-primary);">{{ trim($owner->first_name . ' ' . (string) $owner->last_name) }}</div>
                                <div class="text-xs mt-0.5 flex items-center gap-2" style="color: var(--text-muted);">
                                    @if($owner->id_number)
                                        <span>{{ $owner->id_type === 'company_reg' ? 'Company reg' : 'ID' }}: {{ $owner->id_number }}</span>
                                        <button type="button" x-data="{ copied: false }"
                                                @click="navigator.clipboard.writeText({{ Js::from($owner->id_number) }}); copied = true; setTimeout(() => copied = false, 1500)"
                                                class="text-[10px] font-semibold px-1.5 py-0.5 rounded"
                                                style="border:1px solid var(--border); color: var(--brand-icon, #2563eb);"
                                                x-text="copied ? 'Copied!' : 'Copy ID'"></button>
                                    @else
                                        <span style="color: var(--ds-amber, #f59e0b);">No owner ID</span>
                                    @endif
                                </div>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    @if(trim((string) $owner->phone) !== '')
                                        {{ $owner->phone }}
                                    @else
                                        <span style="color: var(--text-muted);">Phone pending — Virtual Agent (phase 2)</span>
                                    @endif
                                </div>
                            @else
                                <div class="text-xs" style="color: var(--text-muted);">No owner captured.</div>
                            @endif
                        </div>

                        {{-- Directors (entity model 2026-08-14) — a company owner's directors,
                             captured on the deed as REPRESENTATIVES (not owners). Shown as the
                             natural persons to work: copy the ID into TVA's person lookup. --}}
                        @if($directorRows->isNotEmpty())
                            <div class="min-w-0" style="min-width: 14rem;">
                                <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--brand-icon, #2563eb);">
                                    Director{{ $directorRows->count() > 1 ? 's' : '' }} <span style="color: var(--text-muted);">· people to work</span>
                                </div>
                                @foreach($directorRows as $directorRow)
                                    <div @if(!$loop->first) class="mt-2 pt-2" style="border-top:1px solid var(--border);" @endif>
                                        <div class="font-semibold text-sm" style="color: var(--text-primary);">
                                            {{ $directorRow->contact ? trim($directorRow->contact->first_name . ' ' . (string) $directorRow->contact->last_name) : ($directorRow->name ?? 'Director') }}
                                            <span class="text-[10px] font-medium" style="color: var(--text-muted);">— director</span>
                                        </div>
                                        <div class="text-xs mt-0.5 flex items-center gap-2" style="color: var(--text-muted);">
                                            @if($directorRow->id_number)
                                                <span>ID: {{ $directorRow->id_number }}</span>
                                                <button type="button" x-data="{ copied: false }"
                                                        @click="navigator.clipboard.writeText({{ Js::from($directorRow->id_number) }}); copied = true; setTimeout(() => copied = false, 1500)"
                                                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded"
                                                        style="border:1px solid var(--border); color: var(--brand-icon, #2563eb);"
                                                        x-text="copied ? 'Copied!' : 'Copy ID'"></button>
                                            @else
                                                <span style="color: var(--ds-amber, #f59e0b);">No ID</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Action --}}
                        <div class="flex-shrink-0 flex flex-col items-end gap-2">
                            <form method="POST" action="{{ route('corex.deeds-capture.promote', $tp->id) }}"
                                  onsubmit="return confirm('Create a property from this deeds capture and link the owner?');">
                                @csrf
                                <button type="submit" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button, #0ea5e9);">
                                    Promote to property + contact
                                </button>
                            </form>
                            {{-- Remove (2026-08-13) — soft delete, reversible; wrong details / duplicates. --}}
                            <form method="POST" action="{{ route('corex.deeds-capture.dismiss', $tp->id) }}"
                                  onsubmit="return confirm('Remove this capture from the list? It will no longer show here, but nothing is permanently deleted.');">
                                @csrf
                                <button type="submit" class="text-xs font-semibold px-3 py-1 rounded-md"
                                        style="background:transparent; color:#ef4444; border:1px solid rgba(239,68,68,0.4);">
                                    Remove
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- What this capture changed (2026-08-19, Johan — .ai/specs/deeds-capture.md
                         §6 Part B). Copied from the existing expandable pattern in
                         corex/properties/intelligence/_cross-source-timeline.blade.php — same
                         x-data="{ open: false }" toggle-button shape, no new UI convention.
                         Renders only when THIS capture actually filled or replaced something;
                         unchanged fields are never listed. --}}
                    @if($deedsChanges && (count($deedsChanges['filled']) || count($deedsChanges['replaced'])))
                        @php
                            $filledCount = count($deedsChanges['filled']);
                            $replacedCount = count($deedsChanges['replaced']);
                            $summaryParts = array_filter([
                                $filledCount > 0 ? ($filledCount . ' field' . ($filledCount === 1 ? '' : 's') . ' updated') : null,
                                $replacedCount > 0 ? ($replacedCount . ' replaced') : null,
                            ]);
                        @endphp
                        <div class="mt-3" x-data="{ open: false }">
                            <button type="button" @click="open = !open"
                                    class="w-full flex items-center justify-between p-2.5 rounded-md text-left"
                                    style="background: var(--surface-2); border: 1px solid var(--border);">
                                <span class="flex items-center gap-2 flex-wrap">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" style="color: var(--text-secondary);">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487 18.549 2.8a2.121 2.121 0 1 1 3 3l-1.687 1.688m-3-3-9.193 9.193a3 3 0 0 0-.8 1.36l-.812 3.153a.75.75 0 0 0 .91.91l3.153-.812a3 3 0 0 0 1.36-.8l9.193-9.193m-3-3 3 3"/>
                                    </svg>
                                    <span class="text-sm font-semibold" style="color: var(--text-primary);">
                                        Enriched — {{ implode(', ', $summaryParts) }}
                                    </span>
                                    @if($replacedCount > 0)
                                        <span class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded"
                                              style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent); color: var(--ds-amber, #f59e0b); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, transparent);"
                                              title="This capture corrected a value that was already there — the old value is shown below.">
                                            correction
                                        </span>
                                    @endif
                                </span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--text-muted);" :class="open ? 'rotate-180' : ''">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                                </svg>
                            </button>

                            <div x-show="open" x-cloak class="mt-2 space-y-1.5">
                                @if($deedsChanges['date'])
                                    <div class="text-[10px] px-1" style="color: var(--text-muted);">
                                        This capture ran {{ \Illuminate\Support\Carbon::parse($deedsChanges['date'])->diffForHumans() }}
                                        ({{ \Illuminate\Support\Carbon::parse($deedsChanges['date'])->format('j M Y H:i') }}).
                                    </div>
                                @endif
                                {{-- Replaced first — this is the one Johan needs to see, it's where a mistake would hide. --}}
                                @foreach($deedsChanges['replaced'] as $change)
                                    <div class="flex items-start gap-3 p-2.5 rounded-md"
                                         style="background: var(--surface); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, var(--border));">
                                        <div class="w-7 h-7 rounded-md flex items-center justify-center flex-shrink-0"
                                             style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent);">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="color: var(--ds-amber, #f59e0b);">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs font-semibold" style="color: var(--text-primary);">{{ $deedsFieldLabels[$change['field']] ?? $change['field'] }}</div>
                                            <div class="text-[11px] mt-0.5" style="color: var(--text-secondary);">
                                                <span style="color: var(--ds-crimson, #dc2626); text-decoration: line-through;">{{ $deedsFormatValue($change['field'], $change['previous']) }}</span>
                                                <span style="color: var(--text-muted);"> → </span>
                                                <span style="color: var(--ds-green, #16a34a); font-weight: 600;">{{ $deedsFormatValue($change['field'], $change['new']) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                                @foreach($deedsChanges['filled'] as $change)
                                    <div class="flex items-start gap-3 p-2.5 rounded-md"
                                         style="background: var(--surface); border: 1px solid var(--border);">
                                        <div class="w-7 h-7 rounded-md flex items-center justify-center flex-shrink-0"
                                             style="background: color-mix(in srgb, var(--ds-green, #16a34a) 12%, transparent);">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="color: var(--ds-green, #16a34a);">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs font-semibold" style="color: var(--text-primary);">{{ $deedsFieldLabels[$change['field']] ?? $change['field'] }}</div>
                                            <div class="text-[11px] mt-0.5" style="color: var(--text-secondary);">
                                                <span style="color: var(--text-muted);">was empty</span>
                                                <span style="color: var(--text-muted);"> → </span>
                                                <span style="color: var(--ds-green, #16a34a); font-weight: 600;">{{ $deedsFormatValue($change['field'], $change['new']) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- TVA (The Virtual Agent) captured contacts (2026-08-12) — display UNDER the
                         CMA details for this same property, per spec. --}}
                    @foreach(($tvaByProperty[$tp->id] ?? []) as $tvaCapture)
                        @include('corex.deeds-capture._tva-capture', ['capture' => $tvaCapture])
                    @endforeach
                </div>
            @endforeach
        </div>

        <div>{{ $captures->links() }}</div>
    @endif

    {{-- Standalone TVA captures — no matching suspense record, or the record
         they matched isn't currently on this list (dismissed/promoted). --}}
    @if($tvaStandalone->isNotEmpty())
        <div class="rounded-md px-6 py-4 mt-6" style="background: var(--brand-default, #0b2a4a);">
            <h2 class="text-base font-bold text-white">TVA captures — no matching property</h2>
            <p class="text-xs text-white/60 mt-1">Either no deeds-capture record shares this ID number, or its matched property is no longer on this list.</p>
        </div>
        <div class="space-y-3 mt-3">
            @foreach($tvaStandalone as $tvaCapture)
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    @include('corex.deeds-capture._tva-capture', ['capture' => $tvaCapture])
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
