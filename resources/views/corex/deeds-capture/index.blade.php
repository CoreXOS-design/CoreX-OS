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
                    // Owner-data build part 2 (Johan, 2026-08-19) — an open conflict
                    // (a scraped owner that disagreed with the one already on file,
                    // App\Http\Controllers\Api\DeedsCaptureController::reconcileOwners())
                    // is not yet a confirmed owner and must never render in the normal
                    // Owner(s) list as if it were one; it gets its own comparison box below.
                    $owners = $tp->owners->reject(fn ($o) => $o->isOpenConflict())->values(); // multi-owner (2026-08-12) — falls back to $owner below for pre-migration captures
                    $openConflicts = $openConflictsByTp[$tp->id] ?? collect();

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

                    // CX-102 part 2 — the recorded match decision for this row, and
                    // the (source_type, source_ref) pair the reject endpoint needs.
                    // subject_key is "deeds_capture:{source_ref}" and source_ref can
                    // itself contain a colon (e.g. "cmainfo:n0et..."), so split on
                    // the FIRST colon only.
                    $matchDecision = $matchDecisionByTp[$tp->id] ?? null;
                    $matchDecisionSourceRef = null;
                    if ($matchDecision) {
                        $matchDecisionSourceRef = substr($matchDecision->subject_key, strlen($matchDecision->subject_type) + 1);
                    } else {
                        // 2026-08-19 (Johan's screen read) — a row can be "already
                        // tracked" with no PropertyMatchDecision on file at all: it
                        // was matched before this feature existed (tracked property
                        // #468 itself is exactly this case). The reject endpoint
                        // still needs the source ref to act on — read it straight
                        // off the TP's own source_chain rather than leave the
                        // control dead on precisely the row this was built for.
                        foreach (($tp->source_chain ?? []) as $entry) {
                            if (($entry['type'] ?? null) === 'deeds_capture' && !empty($entry['ref'])) {
                                $matchDecisionSourceRef = $entry['ref'];
                            }
                        }
                    }

                    // Johan (2026-08-19), after seeing the screen: "how does an
                    // agent know this is stock or not?" CX-101's own definition
                    // (Property::isOnMarket()/isStaleStock()), never a second one.
                    $stockStatus = $stockStatusByTp[$tp->id] ?? ['state' => 'not_promoted', 'property' => null];

                    // Johan, verbatim, after looking at his own screen: "how does
                    // an agent know this is stock or not, not rocket scientists...
                    // never two chips that say opposite things, one status, one
                    // sentence." ONE unified plain sentence per row, computed once
                    // here — no chips, no ids, no "tracked"/"enriched"/"fields",
                    // every word an agent would actually say.
                    // Confirm button (Johan, 2026-08-19): street + number only — the
                    // full "STREET, SUBURB, TOWN, PROVINCE" string is right for
                    // identifying WHICH property in the sentence above, but repeated
                    // on a button it's just noise the agent has to read past.
                    $shortStreetAddress = fn ($p) => trim((string) ($p->street_number ?? '') . ' ' . (string) ($p->street_name ?? '')) ?: null;

                    $isAlreadyTracked = $tp->capture_kind !== 'deeds_capture';
                    if (!$isAlreadyTracked) {
                        $rowStatusLine = "New to us — we don't have this property yet.";
                        $rowWhyLine = null;
                        $rowConfirmName = $shortStreetAddress($tp) ?: ($headline !== '' ? $headline : 'this property');
                    } elseif ($stockStatus['state'] === 'live') {
                        $matchedAddress = $stockStatus['property']->address ?: 'a property already on your books';
                        $rowStatusLine = 'We think this is the same as ' . $matchedAddress
                            . ' — currently on the market with ' . ($stockStatus['property']->agent->name ?? 'one of your agents') . '.';
                        $rowWhyLine = $matchDecision->reason ?? null;
                        $rowConfirmName = $shortStreetAddress($stockStatus['property']) ?: $matchedAddress;
                    } elseif ($stockStatus['state'] === 'stale') {
                        $matchedAddress = $stockStatus['property']->address ?: 'a property already on your books';
                        $lastWorked = $stockStatus['property']->last_activity_at ?? $stockStatus['property']->updated_at;
                        $rowStatusLine = 'We think this is the same as ' . $matchedAddress
                            . ' — not on the market, last worked ' . ($lastWorked ? \Illuminate\Support\Carbon::parse($lastWorked)->diffForHumans() : 'a while ago') . '.';
                        $rowWhyLine = $matchDecision->reason ?? null;
                        $rowConfirmName = $shortStreetAddress($stockStatus['property']) ?: $matchedAddress;
                    } else {
                        // already tracked, but no existing live property match found —
                        // still a genuine match worth confirming (this is #468's own
                        // case), just not yet real agency stock. The system is not
                        // UNCERTAIN whether it matches here — it matched — the gap
                        // (for rows from before this feature existed) is only that
                        // the reason was never recorded, so say that plainly rather
                        // than inventing doubt that was never there.
                        $rowStatusLine = 'We already have this property on file, but it is not on your books.';
                        $rowWhyLine = $matchDecision->reason ?? 'Not recorded for older captures.';
                        $rowConfirmName = $shortStreetAddress($tp) ?: ($headline !== '' ? $headline : 'this property');
                    }
                @endphp
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        {{-- Property --}}
                        <div class="min-w-0 flex-1">
                            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Property</div>
                            <div class="font-semibold text-sm" style="color: var(--text-primary);">
                                {{ $headline !== '' ? $headline : 'This property' }}
                            </div>

                            {{-- Johan, after reading his own screen: "at what stage are you
                                 going to understand that we are working with agents, not
                                 rocket scientists?" ONE sentence, agent language, never a
                                 second contradicting chip. No ids anywhere — the "view" link
                                 opens the record without ever printing its number. --}}
                            <div class="text-sm mt-1.5" style="color: var(--text-primary);">
                                {{ $rowStatusLine }}
                                @if($isAlreadyTracked)
                                    <a href="{{ route('corex.tracked-properties.show', $tp->id) }}"
                                       target="_blank" rel="noopener" class="font-semibold no-underline"
                                       style="color: var(--brand-icon, #2563eb);"
                                       title="Opens in a new tab — you won't lose your place in this list.">
                                        View →
                                    </a>
                                @endif
                            </div>
                            @if($rowWhyLine)
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    Why: {{ $rowWhyLine }}
                                </div>
                            @endif

                            {{-- Deeds-capture duplicate-match take rule (Johan, 2026-08-21) —
                                 everything needed to decide, on the SAME flag: the literal
                                 status, the exact day count, which date field it came from
                                 (and whether that's a fallback), and the resulting band. A
                                 guessed age is never presented as a known one. --}}
                            @php $age = $stockStatus['age'] ?? null; @endphp
                            @if($age)
                                @php
                                    $bandColor = match ($age->band) {
                                        'active_blocked', 'no_go' => 'var(--ds-red, #dc2626)',
                                        'needs_approval' => 'var(--ds-amber, #f59e0b)',
                                        'auto_take' => 'var(--ds-green, #059669)',
                                        default => 'var(--text-muted)',
                                    };
                                @endphp
                                <div class="text-xs mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5" style="color: var(--text-muted);">
                                    <span class="font-semibold" style="color: {{ $bandColor }};">{{ $age->actionLabel() }}</span>
                                    <span>· Status: {{ $stockStatus['property']->statusBadge() }}</span>
                                    @if($age->days !== null)
                                        <span>· Off market {{ $age->days }} {{ $age->days === 1 ? 'day' : 'days' }}
                                            ({{ $age->dateFieldLabel() }}{{ $age->isFallback ? ' — estimated, not directly recorded' : '' }})</span>
                                    @endif
                                </div>
                            @endif

                            {{-- "Two clear controls" (Johan) — the primary confirm lives in the
                                 Action column on the right (relabelled below to say what it
                                 does); this is its pair: a real, always-visible button, not a
                                 question hidden under a summary. Clicking it reveals the
                                 optional candidate picker + reason before the actual submit,
                                 same toggle pattern already used for "what this capture found"
                                 further down this same file. --}}
                            @if($isAlreadyTracked)
                                <div class="mt-2" x-data="{ open: false }">
                                    <button type="button" @click="open = !open"
                                            class="text-xs font-semibold px-2.5 py-1 rounded"
                                            style="background: transparent; color: var(--ds-crimson, #dc2626); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 40%, transparent);">
                                        No — different property
                                    </button>
                                    <div x-show="open" x-cloak class="mt-2 space-y-2" style="max-width: 26rem;">
                                        <form method="POST"
                                              action="{{ route('corex.deeds-capture.reject-match', $tp->id) }}"
                                              class="space-y-2"
                                              onsubmit="return confirm('This will stop treating this deed as that property. It will get its own record instead — nothing is deleted.');">
                                            @csrf
                                            <input type="hidden" name="source_type" value="deeds_capture">
                                            <input type="hidden" name="source_ref" value="{{ $matchDecisionSourceRef }}">

                                            @if($matchDecision && !empty($matchDecision->candidates) && count($matchDecision->candidates) > 1)
                                                <div>
                                                    <label class="block text-[11px] font-semibold mb-1" style="color: var(--text-muted);">
                                                        More than one property looked possible — pick the right one, or leave this as "none of these":
                                                    </label>
                                                    <select name="replacement_tracked_property_id" class="w-full rounded text-xs px-2 py-1.5" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                        <option value="">None of these — give it its own record</option>
                                                        @foreach($matchDecision->candidates as $candidate)
                                                            @if((int) $candidate['id'] !== (int) $tp->id)
                                                                <option value="{{ $candidate['id'] }}">{{ $candidate['label'] }}</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endif

                                            <div>
                                                <label class="block text-[11px] font-semibold mb-1" style="color: var(--text-muted);">Why (optional):</label>
                                                <input type="text" name="reason" maxlength="500" placeholder="e.g. different street number, wrong building"
                                                       class="w-full rounded text-xs px-2 py-1.5" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            </div>

                                            <button type="submit"
                                                    class="text-xs font-semibold px-3 py-1.5 rounded"
                                                    style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color: var(--ds-crimson, #dc2626); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 35%, transparent);">
                                                Confirm — different property
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endif

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
                             but shown as a distinct "Directors" group — never as owners.
                             Current-vs-past (2026-08-19, .ai/specs/deeds-capture.md §7) — an
                             ownership-history capture can carry an earlier transfer's owner
                             alongside the current one (e.g. a 1993 seller). Split BEFORE render so
                             "Owner(s)" only ever shows who owns it NOW; a past owner renders in its
                             own "Previous owner(s)" group below, same visual treatment as
                             Directors — never mixed into the same list an agent would read as
                             "who to phone". A row with no ownership_status at all (the simple,
                             non-history capture path — the vast majority of captures) is treated
                             as current, same as it always has been. --}}
                        @php
                            $directorRows = $owners->filter(fn ($o) => $o->role === 'director')->values();
                            $nonDirectorRows = $owners->reject(fn ($o) => $o->role === 'director')->values();
                            $pastOwnerRows = $nonDirectorRows->filter(fn ($o) => $o->ownership_status === \App\Models\Prospecting\TrackedPropertyOwner::OWNERSHIP_PAST)->values();
                            $ownerRows = $nonDirectorRows->reject(fn ($o) => $o->ownership_status === \App\Models\Prospecting\TrackedPropertyOwner::OWNERSHIP_PAST)->values();
                            $ownerIdLabel = function ($row) {
                                return match ($row->id_type) {
                                    'company_reg' => 'Company reg',
                                    'trust_reg'   => 'Trust reg',
                                    default       => 'ID',
                                };
                            };
                            $isEntityOwner = fn ($row) => $row->contact && $row->contact->contact_kind === \App\Models\Contact::TYPE_ENTITY;
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
                                            @if($isEntityOwner($ownerRow))
                                                <span class="text-[10px] font-medium" style="color: var(--text-muted);">— entity</span>
                                            @endif
                                        </div>
                                        <div class="text-xs mt-0.5 flex items-center gap-2" style="color: var(--text-muted);">
                                            @if($ownerRow->id_number)
                                                <span>{{ $ownerIdLabel($ownerRow) }}: {{ $ownerRow->id_number }}</span>
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
                                        @if($ownerRow->deed_reference || $ownerRow->ownership_share_pct !== null)
                                            <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                                                @if($ownerRow->deed_reference)Deed {{ $ownerRow->deed_reference }}@endif
                                                @if($ownerRow->ownership_share_pct !== null) · {{ rtrim(rtrim(number_format((float) $ownerRow->ownership_share_pct, 4), '0'), '.') }}%@endif
                                            </div>
                                        @endif
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

                        {{-- Previous owner(s) (2026-08-19, .ai/specs/deeds-capture.md §7) — an
                             earlier transfer's owner (e.g. a 1993 seller), captured as a contact
                             but never linked to the property as its owner on promote (§7.11).
                             Same visual treatment as Directors above (own group, own heading
                             colour, "— entity" suffix reused) so an agent reads it the same way:
                             a distinct group of people who are NOT who to phone about the sale. --}}
                        @if($pastOwnerRows->isNotEmpty())
                            <div class="min-w-0" style="min-width: 14rem;">
                                <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                                    Previous owner{{ $pastOwnerRows->count() > 1 ? 's' : '' }} <span style="color: var(--text-muted);">· earlier deed, not the current owner</span>
                                </div>
                                @foreach($pastOwnerRows as $pastRow)
                                    <div @if(!$loop->first) class="mt-2 pt-2" style="border-top:1px solid var(--border);" @endif>
                                        <div class="font-semibold text-sm" style="color: var(--text-secondary);">
                                            {{ $pastRow->contact ? trim($pastRow->contact->first_name . ' ' . (string) $pastRow->contact->last_name) : ($pastRow->name ?? 'Unnamed') }}
                                            @if($isEntityOwner($pastRow))
                                                <span class="text-[10px] font-medium" style="color: var(--text-muted);">— entity</span>
                                            @endif
                                        </div>
                                        <div class="text-xs mt-0.5 flex items-center gap-2" style="color: var(--text-muted);">
                                            @if($pastRow->id_number)
                                                <span>{{ $ownerIdLabel($pastRow) }}: {{ $pastRow->id_number }}</span>
                                                <button type="button" x-data="{ copied: false }"
                                                        @click="navigator.clipboard.writeText({{ Js::from($pastRow->id_number) }}); copied = true; setTimeout(() => copied = false, 1500)"
                                                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded"
                                                        style="border:1px solid var(--border); color: var(--brand-icon, #2563eb);"
                                                        x-text="copied ? 'Copied!' : 'Copy ID'"></button>
                                            @else
                                                <span>No ID</span>
                                            @endif
                                        </div>
                                        @if($pastRow->deed_reference || $pastRow->ownership_share_pct !== null)
                                            <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                                                @if($pastRow->deed_reference)Deed {{ $pastRow->deed_reference }}@endif
                                                @if($pastRow->ownership_share_pct !== null) · {{ rtrim(rtrim(number_format((float) $pastRow->ownership_share_pct, 4), '0'), '.') }}%@endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Owner disagreement (owner-data build part 2, Johan 2026-08-19) —
                             "if the owner information varies, the agent needs to inspect and
                             see which is broken... this needs to be an active choice." Never
                             auto-merged (Api\DeedsCaptureController::reconcileOwners()); shown
                             independently of the "No — different property" control above, since
                             the property match can be right even when the owner disagrees. --}}
                        @if($openConflicts->isNotEmpty())
                            <div class="min-w-0 basis-full rounded-md p-3" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent);">
                                @foreach($openConflicts as $conflict)
                                    <div @if(!$loop->first) class="mt-3 pt-3" style="border-top:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent);" @endif>
                                        <div class="text-sm font-semibold" style="color: var(--text-primary);">
                                            The latest capture found a different owner. Which one is right?
                                        </div>
                                        <div class="mt-2 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));">
                                            <div>
                                                <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">On your books</div>
                                                @forelse($ownerRows as $currentRow)
                                                    <div class="text-sm font-semibold" style="color: var(--text-primary);">
                                                        {{ $currentRow->contact ? trim($currentRow->contact->first_name . ' ' . (string) $currentRow->contact->last_name) : ($currentRow->name ?? 'Unnamed owner') }}
                                                    </div>
                                                    <div class="text-xs" style="color: var(--text-muted);">
                                                        {{ $currentRow->id_number ? ($ownerIdLabel($currentRow) . ': ' . $currentRow->id_number) : 'No owner ID' }}
                                                    </div>
                                                @empty
                                                    <div class="text-sm" style="color: var(--text-muted);">No owner on file yet.</div>
                                                @endforelse
                                                <form method="POST" action="{{ route('corex.deeds-capture.owner-conflict.resolve', [$tp->id, $conflict->id]) }}" class="mt-1.5"
                                                      onsubmit="return confirm('Keep the owner already on your books, and leave the other name on file unused?');">
                                                    @csrf
                                                    <input type="hidden" name="decision" value="dismiss">
                                                    <button type="submit" class="text-xs font-semibold px-2.5 py-1 rounded"
                                                            style="background: transparent; color: var(--text-primary); border: 1px solid var(--border);">
                                                        Keep this owner
                                                    </button>
                                                </form>
                                            </div>
                                            <div>
                                                <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                                                    From the latest capture
                                                    @if($conflict->created_at && $ownerRows->isNotEmpty() && $ownerRows->first()->created_at && $conflict->created_at->gt($ownerRows->first()->created_at))
                                                        <span style="color: var(--ds-amber, #f59e0b);">· more recent</span>
                                                    @endif
                                                </div>
                                                <div class="text-sm font-semibold" style="color: var(--text-primary);">
                                                    {{ $conflict->contact ? trim($conflict->contact->first_name . ' ' . (string) $conflict->contact->last_name) : ($conflict->name ?? 'Unnamed owner') }}
                                                </div>
                                                <div class="text-xs" style="color: var(--text-muted);">
                                                    {{ $conflict->id_number ? ($ownerIdLabel($conflict) . ': ' . $conflict->id_number) : 'No owner ID' }}
                                                </div>
                                                <form method="POST" action="{{ route('corex.deeds-capture.owner-conflict.resolve', [$tp->id, $conflict->id]) }}" class="mt-1.5"
                                                      onsubmit="return confirm({{ Js::from('Update the owner to ' . trim((string) ($conflict->contact ? trim($conflict->contact->first_name . ' ' . (string) $conflict->contact->last_name) : $conflict->name)) . '?') }});">
                                                    @csrf
                                                    <input type="hidden" name="decision" value="use">
                                                    <button type="submit" class="text-xs font-semibold px-2.5 py-1 rounded"
                                                            style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color: color-mix(in srgb, var(--ds-amber, #f59e0b) 70%, black); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, transparent);">
                                                        Use this owner instead
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Action --}}
                        <div class="flex-shrink-0 flex flex-col items-end gap-2">
                            {{-- One-button promote+ingest (2026-08-19, Johan, verbatim from last
                                 night): tick the numbers you want, click THIS button once — no
                                 separate Ingest step in the ordering, no panel collapsing out from
                                 under the user. The id here is the target of every nested TVA
                                 block's checkboxes below (via the HTML5 form="" attribute — those
                                 inputs are NOT inside this <form> tag in the DOM, they submit into
                                 it anyway), so one click carries both writes. --}}
                            {{-- Johan, after reading his own screen: "every button says what it
                                 will DO." Same action as always (promote()) — the label now
                                 names what actually happens instead of "Promote to property +
                                 contact", which meant nothing to an agent. --}}
                            @if($stockStatus['property'] ?? null)
                                {{-- Deeds-capture duplicate-match take rule (Johan, 2026-08-21) —
                                     "the Same/Different confirmation buttons sit with this panel,
                                     since this is where the decision is now made." The form and its
                                     buttons render with the comparison panel below, not here. --}}
                                <div class="text-xs" style="color: var(--text-muted);">Decide using the comparison below ↓</div>
                            @else
                                <form id="promote-form-{{ $tp->id }}" method="POST" action="{{ route('corex.deeds-capture.promote', $tp->id) }}"
                                      onsubmit="return confirm('Add this as a new property and link the owner? Any ticked contact numbers below will be added too.');">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button, #0ea5e9);">
                                        Add as a new property
                                    </button>
                                </form>
                            @endif
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

                    {{-- Side-by-side comparison panel (Johan, 2026-08-21): "current property -
                         what we have from properties, vs new scraped property - showing details
                         side by side that matches. that will allow agent to make simple call
                         right there and then." Inline, full-width — plenty of screen space,
                         no modal. Rows come from PropertyDuplicateMatchEvidence::panelRows(),
                         the SAME evidence PropertyMatchDecisionService records on confirmation,
                         so this can never show the agent something different from what gets
                         logged when they decide. --}}
                    @php $panel = $stockStatus['panel'] ?? null; @endphp
                    @if($panel)
                        <div class="mt-3 rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                                <div class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-muted);">Is this the same property?</div>
                                @if($panel['candidateCount'] > 1)
                                    {{-- Johan: "if more than one candidate exists, SAY SO on screen." Only the
                                         top candidate (the one resolvePropertyMatch() itself would use) is
                                         detailed below — see the deploy report for what fuller support would cost. --}}
                                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color: var(--ds-amber, #f59e0b);">
                                        {{ $panel['candidateCount'] }} possible matches found — showing the top one
                                    </span>
                                @endif
                            </div>
                            <div class="text-[10px] mb-3" style="color: var(--text-muted);">
                                <span style="color: var(--ds-green, #059669); font-weight:600;">Strong match</span> ·
                                <span style="color: var(--ds-amber, #f59e0b); font-weight:600;">Weak / partial</span> ·
                                <span style="color: var(--ds-crimson, #c41e3a); font-weight:600;">Differs</span> ·
                                <span style="color: var(--text-muted); font-weight:600;">Not recorded</span>
                            </div>

                            <div class="overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead>
                                    <tr>
                                        <th class="text-left pb-2 pr-3" style="color: var(--text-muted); font-weight:600;">Field</th>
                                        <th class="text-left pb-2 pr-3" style="color: var(--text-muted); font-weight:600;">
                                            Existing property
                                            <a href="{{ route('corex.properties.show', $stockStatus['property']->id) }}" target="_blank" rel="noopener"
                                               class="font-normal no-underline ml-1" style="color: var(--brand-icon, #2563eb);"
                                               title="Opens in a new tab — you won't lose your place here.">Open property →</a>
                                        </th>
                                        <th class="text-left pb-2" style="color: var(--text-muted); font-weight:600;">Scraped deed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($panel['rows'] as $row)
                                        @php
                                            $rowColor = match ($row['strength']) {
                                                'strong' => 'var(--ds-green, #059669)',
                                                'weak' => 'var(--ds-amber, #f59e0b)',
                                                'differs' => 'var(--ds-crimson, #c41e3a)',
                                                default => 'var(--text-muted)',
                                            };
                                        @endphp
                                        <tr style="border-top: 1px solid var(--border);">
                                            <td class="py-1.5 pr-3 align-top" style="color: var(--text-secondary); white-space: nowrap;">
                                                {{ $row['label'] }}
                                                @if($row['used'])
                                                    <span class="text-[9px] font-semibold ml-1 px-1 py-0.5 rounded"
                                                          style="background: color-mix(in srgb, var(--brand-icon, #2563eb) 15%, transparent); color: var(--brand-icon, #2563eb);"
                                                          title="The matcher used this field to propose this match">USED TO MATCH</span>
                                                @endif
                                            </td>
                                            <td class="py-1.5 pr-3 align-top" style="color: {{ $rowColor }};">{{ $row['existing'] ?? 'Not recorded' }}</td>
                                            <td class="py-1.5 align-top" style="color: {{ $rowColor }};">{{ $row['scraped'] ?? 'Not recorded' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>

                            <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);">
                                <form id="promote-form-{{ $tp->id }}" method="POST" action="{{ route('corex.deeds-capture.promote', $tp->id) }}"
                                      onsubmit="return confirm({{ Js::from('Update ' . $rowConfirmName . ' with these details and link the owner? Any ticked contact numbers below will be added too.') }});">
                                    @csrf
                                    <div class="text-xs mb-2" style="color: var(--text-muted);">
                                        If different, why? (only used if you pick "Different property")
                                        <select name="reject_reason_code" class="ml-1 text-xs rounded-md px-1.5 py-1" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            @foreach(\App\Services\Prospecting\PropertyMatchDecisionService::REJECT_REASON_CODES as $code => $label)
                                                <option value="{{ $code }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="submit" name="match_decision" value="same" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button, #0ea5e9);">
                                            Same property — {{ $rowConfirmName }}
                                        </button>
                                        <button type="submit" name="match_decision" value="different" formnovalidate class="text-xs font-semibold px-4 py-2 rounded-md" style="background: transparent; border:1px solid var(--border); color: var(--text-primary);">
                                            Different property — add as new
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endif

                    {{-- Ownership parse status (2026-08-19, .ai/specs/deeds-capture.md §7.9) — a
                         parse failure NEVER blocks the property capture above; this is where the
                         reason lives. 'ok' (the default, and every non-history capture) renders
                         nothing. Reuses the same amber color-mix treatment already used twice on
                         this card (the "Already tracked" and "correction" badges above) rather
                         than inventing a new warning style. --}}
                    @if($tp->ownership_parse_status && $tp->ownership_parse_status !== 'ok')
                        <div class="text-xs mt-3 px-3 py-2 rounded-md" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent); color: var(--text-secondary);">
                            <span style="color: var(--ds-amber, #f59e0b); font-weight:600;">
                                {{ $tp->ownership_parse_status === 'failed' ? 'Ownership not captured —' : 'Ownership needs a look —' }}
                            </span>
                            {{ $tp->ownership_parse_note }}
                        </div>
                    @endif

                    {{-- What this capture changed (2026-08-19, Johan — .ai/specs/deeds-capture.md
                         §6 Part B). Copied from the existing expandable pattern in
                         corex/properties/intelligence/_cross-source-timeline.blade.php — same
                         x-data="{ open: false }" toggle-button shape, no new UI convention.
                         Renders only when THIS capture actually filled or replaced something;
                         unchanged fields are never listed. --}}
                    @if($deedsChanges && (count($deedsChanges['filled']) || count($deedsChanges['replaced']) || count($deedsChanges['cleared'] ?? [])))
                        @php
                            $filledCount = count($deedsChanges['filled']);
                            $replacedCount = count($deedsChanges['replaced']);
                            $clearedCount = count($deedsChanges['cleared'] ?? []);
                            // Johan, after reading his own screen: kill "Enriched", kill
                            // "field(s)", kill the "correction" chip — say it in one plain
                            // sentence, in words an agent uses (a detail, not a field). This
                            // already happened at capture time (deferring that write is a
                            // separate, not-yet-built change) — so this is a report, not a
                            // promise, and says so honestly rather than pretending it's still
                            // pending confirmation.
                            $whatChangedParts = array_values(array_filter([
                                $filledCount > 0 ? ('updated ' . $filledCount . ' detail' . ($filledCount === 1 ? '' : 's')) : null,
                                $replacedCount > 0 ? ('replaced ' . $replacedCount . ' that ' . ($replacedCount === 1 ? 'was' : 'were') . ' different') : null,
                                $clearedCount > 0 ? ('cleared ' . $clearedCount . ' that no longer applied') : null,
                            ]));
                            // "We already updated 10 details on that property, and replaced 4
                            // that were different." (Johan, 2026-08-19) — "on that property"
                            // anchors the first clause; further clauses just continue the sentence.
                            $whatChangedSummary = 'We already ' . $whatChangedParts[0] . ' on that property'
                                . (count($whatChangedParts) > 1 ? ', and ' . implode(', and ', array_slice($whatChangedParts, 1)) : '')
                                . '.';
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
                                        {{ $whatChangedSummary }}
                                    </span>
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
                                {{-- 'cleared' (2026-08-19, cc3) — a stored placeholder (e.g.
                                     property_type stuck at "-") got wiped back to blank because
                                     this capture also only had a placeholder to offer. Neutral
                                     styling (not the amber "correction" treatment) — this isn't a
                                     real value being overwritten, it's junk being removed. --}}
                                @foreach($deedsChanges['cleared'] ?? [] as $change)
                                    <div class="flex items-start gap-3 p-2.5 rounded-md"
                                         style="background: var(--surface); border: 1px solid var(--border);">
                                        <div class="w-7 h-7 rounded-md flex items-center justify-center flex-shrink-0"
                                             style="background: var(--surface-2);">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="color: var(--text-muted);">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs font-semibold" style="color: var(--text-primary);">{{ $deedsFieldLabels[$change['field']] ?? $change['field'] }}</div>
                                            <div class="text-[11px] mt-0.5" style="color: var(--text-secondary);">
                                                <span style="color: var(--text-muted); text-decoration: line-through;">{{ $deedsFormatValue($change['field'], $change['previous']) }}</span>
                                                <span style="color: var(--text-muted);"> → cleared (was a placeholder, not a real value)</span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- TVA (The Virtual Agent) captured contacts (2026-08-12) — display UNDER the
                         CMA details for this same property, per spec. NESTED here (a suspense
                         record exists to promote), so formId is passed: the block renders its
                         checkboxes wired to the property's promote form above via form="", with
                         NO submit button of its own — ticking here and clicking Promote is one
                         action (2026-08-19, Johan). --}}
                    @foreach(($tvaByProperty[$tp->id] ?? []) as $tvaCapture)
                        @include('corex.deeds-capture._tva-capture', ['capture' => $tvaCapture, 'formId' => 'promote-form-' . $tp->id])
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
                    {{-- Standalone (no suspense record to promote, or it's already promoted) —
                         genuinely no Promote button to merge with, so this keeps its own
                         independent Ingest form exactly as before (formId omitted). --}}
                    @include('corex.deeds-capture._tva-capture', ['capture' => $tvaCapture, 'formId' => null])
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
