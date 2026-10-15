@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14 (AT-442) and §17.39 (AT-460, 15 Oct 2026) - the OFFICE job card screen.

    AT-460 rework (Johan, after walking job card 97: "when entering a screen the response received and next actions are the ones that should be prominently displayed. no
    one wants to go hunt for what was returned or what should happen next"). Layout and prominence only - every form below posts to the route and field names it always did.

    Top to bottom: header (title, status, address, the one-line description; Print / View work order / All job cards) -> property & lease tabs -> the stepper
    (Quoted > Owner approved > Booked > In progress > Completed > Disputed) -> ONE bar: RETURNED (the latest thing that came back into the office, click = scroll to
    it) | NEXT (one sentence, one primary button, at most one secondary) -> two columns, one page scroll:
      LEFT   The job (facts) / Tasks & lines (one header, bands per task, crew lines inline, add-line row, totals + "Apply markup...") / Sign-off / Completion check /
             Signed copy / Photos / History.
      RIGHT  (sticky; first on a narrow screen) Money & approval - one card; Crew - one card; Cancel job card, a small link.
    What the bar and the cards say comes from RentalJobCardScreen (stepper, returned, next, "Authorised") and RentalJobCardStageService (which stage, what can be pressed);
    this file only lays it out. The old create-mode branch (a card that did not exist yet) went with it: RentalJobCardController::create() has redirected to the work-order
    form since BUILD 3 (§17.3.1), so no request has reached it since.
--}}

@php
    $statusBadgeClass = match ($jobCard->status) {
        'completed' => 'ds-badge-success',
        'cancelled', 'disputed' => 'ds-badge-danger',
        'in_progress', 'scheduled' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
    $isOpen = ! in_array($jobCard->status, ['completed', 'cancelled'], true);
    $displayProperty = $jobCard->property;
    $displayFaultReport = $jobCard->rentalFaultReport;
    $displayWorkOrder = $jobCard->workOrder;
    $landlords = $jobCard->lease ? $jobCard->lease->landlordContacts() : ($displayProperty ? collect([$displayProperty->landlordContact()])->filter() : collect());
    $jcDescription = trim((string) ($displayWorkOrder?->description ?: $displayFaultReport?->description));

    $jcAgency = $jobCard->agency ?? auth()->user()->agency;
    $catalogueItemsJson = $catalogueItems->map(fn ($ci) => [
        'id' => $ci->id, 'code' => $ci->code, 'description' => $ci->description, 'label' => $ci->label(),
        'kind' => $ci->kind(), 'unit' => $ci->catalogueUnit?->name,
        'priceForLine' => $jcAgency ? app(\App\Services\Rentals\RentalJobCardVatService::class)->catalogueDefaultPriceForLine($ci, $jcAgency) : (($ci->selling_excl !== null && ! $ci->pricedByMarkup()) ? (float) $ci->selling_excl : null),
        // §17.4.4 — the catalogue's default COST prefills the Cost box, but only for someone who can see and set costs.
        'costForLine' => (($canViewCosts ?? false) && ($canPrice ?? false) && $jcAgency) ? app(\App\Services\Rentals\RentalJobCardVatService::class)->catalogueDefaultCostForLine($ci, $jcAgency) : null,
        // §17.39 — a markup-priced item has no catalogue selling to show: the picker says what the markup is instead.
        'markupHint' => $ci->pricedByMarkup() ? ($ci->markup_type === 'rand' ? '+ R' . number_format((float) $ci->markup_value, 2) : 'markup ' . rtrim(rtrim(number_format((float) $ci->markup_value, 2), '0'), '.') . ' %') : null,
        'vatTypeId' => $ci->default_rental_vat_type_id,
        'customVatRate' => $ci->default_custom_vat_rate !== null ? (float) $ci->default_custom_vat_rate : null,
    ])->values();

    $jcBar = $screen['next'];
    $jcReturned = $screen['returned'];
    $jcButton = function (?array $b, bool $primary) {
        if (! $b) { return ''; }
        $cls = $primary ? 'corex-btn-primary text-xs' : 'corex-btn-outline text-xs';
        $attr = $primary ? 'data-next-button' : 'data-next-secondary';
        if ($b['kind'] === 'form') {
            return '<button type="submit" form="' . e($b['form']) . '" class="' . $cls . '" ' . $attr . '>' . e($b['label']) . '</button>';
        }
        return '<a href="' . e($b['target']) . '" class="' . $cls . '" data-jc-go'
            . (! empty($b['click']) ? ' data-jc-click="' . e($b['click']) . '"' : '')
            . (! empty($b['focus']) ? ' data-jc-focus="' . e($b['focus']) . '"' : '')
            . ' ' . $attr . '>' . e($b['label']) . '</a>';
    };
@endphp

@section('content')
@include('corex.rental-job-cards._screen-style')
<div class="p-4 md:p-6 max-w-7xl mx-auto space-y-3 cx-equal-btns" data-jc-screen>
    @if($errors->any())
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- A. header --}}
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold">{{ $jobCard->title }}</h1>
            <div class="flex flex-wrap items-center gap-2">
                <span class="ds-badge {{ $statusBadgeClass }}" title="{{ ucfirst(str_replace('_', ' ', $jobCard->status)) }}" data-stage-label>{{ $stage['label'] }}</span>
                @if($jobCard->worker_signed_off_at)
                    <span class="ds-badge ds-badge-success" title="{{ $jobCard->worker_signed_off_at->format('Y-m-d H:i') }}">Crew completed — {{ $jobCard->crewCompletionLabel() }}</span>
                @endif
                <span class="text-xs" style="color: var(--text-muted);">{{ $displayProperty?->buildDisplayAddress() ?? 'Unknown property' }}{{ $displayProperty?->trashed() ? ' (archived)' : '' }}</span>
            </div>
            @if($jcDescription !== '')
                <p class="text-xs pt-1 truncate" style="color: var(--text-muted); max-width: 70ch;" title="{{ $jcDescription }}" data-jc-description>{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $jcDescription), 160) }}</p>
            @endif
        </div>
        @include('partials.header-actions-style')
        <div class="flex flex-wrap items-center gap-2" data-header-actions>
            <a href="{{ route('corex.rental-job-cards.print', $jobCard) }}" target="_blank" class="corex-btn-outline text-xs">Print job card</a>
            @if($crewLinksEnabled && ($stage['can']['crew_link'] ?? false))
                @permission('rental_job_cards.share')
                <a href="{{ route('corex.rental-job-cards.print', ['rentalJobCard' => $jobCard, 'with_link' => 1]) }}" target="_blank" class="corex-btn-outline text-xs" data-confirm="Print with a crew link? This creates a new link (the old one stops working) and puts its QR code on the paper.">Print with link</a>
                @endpermission
            @endif
            @if($jobCard->rental_work_order_id)
                @feature('rental-work-orders')
                <a href="{{ route('corex.rental-work-orders.show', $jobCard->rental_work_order_id) }}" class="corex-btn-outline text-xs">View work order</a>
                @endfeature
            @endif
            <a href="{{ route('corex.rental-job-cards.index') }}" class="corex-btn-outline text-xs">&larr; All job cards</a>
        </div>
    </div>

    <x-rental-context-bar :property="$displayProperty" :lease="$jobCard->lease" current="work_orders" />
    @include('partials.paused-by-owner-banner', ['pauseSubject' => $jobCard])

    {{-- B. where this job is --}}
    <ol class="wo-strip" data-wo-strip aria-label="Where this job is">
        @foreach($screen['steps'] as $st)
            <li class="wo-step" data-step="{{ $st['key'] }}" data-state="{{ $st['state'] }}" @if($st['state'] === 'current') aria-current="step" @endif>
                <span class="wo-dot">@if($st['state'] === 'done')&#10003;@elseif($st['state'] === 'current')&#9679;@else&nbsp;@endif</span>
                <span class="wo-step-label">{{ $st['label'] }}</span>
                @if($st['at'])<span class="wo-step-at">{{ $st['at'] }}</span>@endif
            </li>
        @endforeach
    </ol>

    {{-- C. Returned | Next - the first thing under the stepper, one block --}}
    <div class="wo-next wo-next-{{ $jcBar['tone'] }}" data-next-step data-land-on-next data-stage="{{ $stage['key'] }}" data-next="{{ $jcBar['key'] }}">
        <div class="jc-bar-half" data-returned data-returned-anchor="{{ $jcReturned['anchor'] }}">
            <div><span class="wo-next-label">{{ $jcReturned['label'] }}</span>
                <a href="#{{ $jcReturned['anchor'] }}" class="jc-returned-link" data-jc-go data-returned-text>{{ $jcReturned['text'] }}</a>@if($jcReturned['waiting'])<span class="jc-waiting">waiting for you</span>@endif</div>
        </div>
        <div class="jc-bar-half">
            <div class="jc-bar-next">
                <div><span class="wo-next-label">Next</span><span data-next-text>{{ $jcBar['text'] }}</span></div>
                <div class="wo-btnrow">{!! $jcButton($jcBar['button'], true) !!}{!! $jcButton($jcBar['secondary'], false) !!}</div>
            </div>
        </div>
    </div>

    {{-- D. the two columns: the work on the left, the money and the crew on the right --}}
    <div id="jc-layout" class="jc-layout">
        <div id="jc-left-col" class="jc-left">
            @include('corex.rental-job-cards._job-facts')
            @include('corex.rental-job-cards._tasks-lines')
            @include('corex.rental-job-cards._signoff-box')
            @if($stage['can']['signed_copy'] || $signedCopies->isNotEmpty())
                @include('corex.rental-job-cards._signed-copy-panel')
            @endif
            @if($jobCard->workOrder)
                <div id="jc-completion">@include('corex.rental-job-cards._completion-panel')</div>
            @endif

            {{-- Photos --}}
            @php
                $jcPhotos = $jobCard->photos->concat($jobCard->workOrder?->photos ?? collect());
                $jcFaultPhotos = $displayFaultReport ? $displayFaultReport->photos : collect();
            @endphp
            <div class="jc-card space-y-3">
                <button type="button" onclick="document.getElementById('jc-photos').classList.toggle('hidden')" class="text-sm font-semibold w-full text-left">Photos ({{ $jcPhotos->count() + $jcFaultPhotos->count() }})</button>
                <div id="jc-photos" class="{{ session('jc_open_photos') ? '' : 'hidden ' }}space-y-3">
                    @if($jcPhotos->isEmpty() && $jcFaultPhotos->isEmpty())
                        <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
                    @else
                        <div class="grid grid-cols-4 gap-2">
                            @foreach($jcFaultPhotos as $photo)
                                <div>
                                    <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded-md w-full h-24 object-cover"></a>
                                    <span class="text-xs" style="color: var(--text-muted);">Fault report</span>
                                </div>
                            @endforeach
                            @foreach($jcPhotos as $photo)
                                <div>
                                    <a href="{{ $photo->storage_path }}" target="_blank"><img src="{{ $photo->storage_path }}" class="rounded-md w-full h-24 object-cover"></a>
                                    <span class="text-xs" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $photo->photo_type)) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @permission('rental_job_cards.create')
                    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.photos.store', $jobCard) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <select name="photo_type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                            <option value="reported">Before</option>
                            <option value="in_progress">In progress</option>
                            <option value="completed">Completed (after)</option>
                        </select>
                        <input type="file" name="photo" accept="image/*" required class="text-xs">
                        <button type="submit" class="corex-btn-outline text-xs">Upload photo</button>
                    </form>
                    @endpermission
                </div>
            </div>

            {{-- History - collapsed, the count in the heading --}}
            @php $jcHistory = $jobCard->history(); @endphp
            <details class="jc-card" id="jc-history">
                <summary class="text-sm font-semibold cursor-pointer">History ({{ $jcHistory->count() }})</summary>
                <ul class="space-y-1 text-sm pt-2">
                    @foreach($jcHistory as $entry)
                        <li>
                            <span style="color: var(--text-muted);">{{ $entry['at']->format('Y-m-d H:i') }}</span>
                            — {{ $entry['action'] }}
                            @if($entry['from'] || $entry['to'])({{ $entry['from'] ?? '—' }} &rarr; {{ $entry['to'] ?? '—' }})@endif
                            @if($entry['note'])— {{ $entry['note'] }}@endif
                            @if($entry['actor'])<span style="color: var(--text-muted);">({{ $entry['actor'] }})</span>@endif
                        </li>
                    @endforeach
                </ul>
            </details>
        </div>

        <div id="jc-right-col" class="jc-right">
            @include('corex.rental-job-cards._money-card')
            @include('corex.rental-job-cards._crew-card')
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// The agency's active catalogue, printed ONCE — the in-place line editors
// (_add-line-row.blade.php, mode 'edit') read it from here instead of each
// embedding their own copy.
window.jcCatalogueItems = {!! \Illuminate\Support\Js::from($catalogueItemsJson) !!};

// 2026-10-05 round 3 (Johan QA1 findings A/B) — the catalogue item picker
// shared by every add-line row. A searchable combobox (filters the agency's
// own active items by code OR description client-side — small lists, no
// search endpoint needed) that, on pick, fills description/type/unit/unit
// price/VAT type directly via plain DOM lookups relative to its OWN row
// (`$el`) rather than Alpine's $refs. Every field it fills stays fully
// editable afterward — nothing here ever disables anything.
//
// 2026-10-05 round 4 (Johan): the dropdown list is teleported to <body> and
// placed `fixed` from the Item input's rectangle (place()) — in place it was
// as narrow as the 100px Item box and clipped. `opts` is only used by the
// in-place line EDITOR: the line's current catalogue item (selectedId) and,
// for a line with a code whose item is no longer in the active catalogue,
// its code as the box's text (fallbackQuery).
function catalogueLinePicker(items, opts) {
    opts = opts || {};
    return {
        items, query: '', open: false, selectedId: '', highlighted: -1, rootEl: null, listEl: null,
        dropStyle: 'display:none;',
        uid: 'jcpick' + Math.random().toString(36).slice(2, 8),
        // $el inside a method called from an x-for child's @click (e.g. pick(it))
        // resolves to the CLICKED child element, not this component's root — Alpine
        // binds magics per evaluation context, not per component. field() needs the
        // row's root to find sibling inputs, so init() captures it once, here, while
        // $el still means "the element x-data is declared on".
        init() {
            this.rootEl = this.$el;
            if (opts.selectedId) {
                this.selectedId = opts.selectedId;
                const current = this.items.find(it => String(it.id) === String(opts.selectedId));
                this.query = current ? current.label : (opts.fallbackQuery || '');
            }
            const reposition = () => { if (this.open) this.place(); };
            window.addEventListener('resize', reposition);
            document.addEventListener('scroll', reposition, true); // capture: the page scrolls inside #appScroll
            // The list lives in <body>, outside this component's DOM — close on a press anywhere else.
            document.addEventListener('mousedown', (e) => {
                if (!this.open) return;
                const input = this.$refs.itemInput;
                if ((input && input.contains(e.target)) || (this.listEl && this.listEl.contains(e.target))) return;
                this.open = false;
            });
        },
        show() {
            this.open = true;
            this.place();
        },
        onType() {
            // The typed text changed the list: highlight the first match so
            // "type a few letters, press Enter" picks it, and a stale index
            // from the previous list can never point past the new one.
            this.highlighted = this.filtered().length ? 0 : -1;
            this.show();
        },
        // Width: at least Item + Description (the two columns the list sits
        // under), never less than 360px, never wider than the window. Opens
        // below the input, or above it when there is little room below.
        place() {
            const input = this.$refs.itemInput;
            if (!input) return;
            const r = input.getBoundingClientRect();
            const scroller = document.getElementById('appScroll');
            if (scroller) {
                const p = scroller.getBoundingClientRect();
                if (r.bottom < p.top || r.top > p.bottom) { this.open = false; return; } // scrolled out of view
            }
            const grid = this.rootEl.querySelector('[style*="grid-template-columns"]');
            const descCell = grid && grid.children[1];
            const right = descCell ? descCell.getBoundingClientRect().right : r.right;
            const width = Math.min(Math.max(right - r.left, 360), window.innerWidth - 16);
            const left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
            const below = window.innerHeight - r.bottom - 8;
            const above = r.top - 8;
            const flip = below < 150 && above > below;
            const maxH = Math.max(100, Math.min(260, flip ? above : below));
            // The list's whole look lives here — Alpine's string :style replaces a static style attribute.
            this.dropStyle = 'position:fixed; z-index:70; overflow-y:auto; background:var(--surface); color:var(--text, inherit); border:1px solid var(--border); border-radius:6px; box-shadow:0 6px 18px rgba(0,0,0,0.18); '
                + 'left:' + Math.round(left) + 'px; width:' + Math.round(width) + 'px; max-height:' + Math.round(maxH) + 'px; '
                + (flip ? 'bottom:' + Math.round(window.innerHeight - r.top + 4) + 'px;' : 'top:' + Math.round(r.bottom + 4) + 'px;');
        },
        filtered() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.items;
            return this.items.filter(it => it.code.toLowerCase().includes(q) || it.description.toLowerCase().includes(q));
        },
        moveSelection(dir) {
            const list = this.filtered();
            if (!list.length) return;
            const wasOpen = this.open;
            this.show();
            // A closed list opens on the first press instead of skipping a row.
            this.highlighted = wasOpen || this.highlighted >= 0
                ? (this.highlighted + dir + list.length) % list.length
                : (dir > 0 ? 0 : list.length - 1);
            this.$nextTick(() => {
                const active = this.listEl && this.listEl.querySelector('[data-active="1"]');
                if (active) active.scrollIntoView({ block: 'nearest' });
            });
        },
        pickHighlighted() {
            if (!this.open) { this.show(); return; }
            const list = this.filtered();
            if (this.highlighted >= 0 && list[this.highlighted]) this.pick(list[this.highlighted]);
        },
        field(selector) {
            return this.rootEl.querySelector(selector);
        },
        pick(item) {
            this.selectedId = item.id;
            this.query = item.label;
            this.open = false;
            this.highlighted = -1;
            const d = this.field('[name="description"], [x-ref$="Desc"]'); if (d) d.value = item.description;
            const t = this.field('[name="type"], [x-ref$="Type"]'); if (t) t.value = item.kind || 'labour';
            const u = this.field('[name="unit"], [x-ref$="Unit"]'); if (u && item.unit) u.value = item.unit;
            // §17.4.3 — the selling price is NOT copied from the catalogue any more: left blank it resolves by the pricing rules
            // (the catalogue's default price is rule 5), so a picked item still prices itself but is not frozen as "typed by hand".
            const p = this.field('[name="unit_price"], [x-ref$="UnitPrice"]'); if (p) { p.value = ''; p.placeholder = item.markupHint ? item.markupHint : ((item.priceForLine !== null && item.priceForLine !== undefined) ? ('catalogue ' + item.priceForLine) : 'auto'); }
            const k = this.field('[name="unit_cost"], [x-ref$="UnitCost"]'); if (k && item.costForLine !== null && item.costForLine !== undefined) k.value = item.costForLine;
            const v = this.field('[name="rental_vat_type_id"], [x-ref$="VatType"]');
            if (v && item.vatTypeId) { v.value = item.vatTypeId; v.dispatchEvent(new Event('change')); }
            const c = this.field('[name="custom_vat_rate"]'); if (c && item.customVatRate !== null && item.customVatRate !== undefined) c.value = item.customVatRate;
        },
        clear() {
            this.selectedId = '';
            this.query = '';
            this.open = false;
            this.highlighted = -1;
        },
    };
}

// AT-460 - the Returned / Next bar and the cards point at places on this page ("data-jc-go"): scroll there (opening a folded block on the way), flash it, and - when the
// button names one - press the control that does the job (data-jc-click) or put the cursor in it (data-jc-focus). Nothing here submits anything by itself.
(function () {
    function open(el) { for (var n = el; n; n = n.parentElement) { if (n.tagName === 'DETAILS') { n.open = true; } } }
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('[data-jc-go]') : null;
        if (!a) { return; }
        var href = a.getAttribute('href') || '';
        var target = href.charAt(0) === '#' && href.length > 1 ? document.getElementById(href.slice(1)) : null;
        if (!target) { return; }
        e.preventDefault();
        open(target);
        if (target.scrollIntoView) { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        target.classList.add('jc-flash');
        setTimeout(function () { target.classList.remove('jc-flash'); }, 1800);
        var click = a.getAttribute('data-jc-click');
        var focus = a.getAttribute('data-jc-focus');
        setTimeout(function () {
            if (click) { var c = target.querySelector(click) || document.querySelector(click); if (c) { open(c); c.click(); } }
            if (focus) { var f = document.querySelector(focus); if (f) { open(f); f.focus({ preventScroll: true }); } }
        }, 350);
    });
})();

// Adding / editing / archiving a line is a plain form POST + redirect, so the browser loads a fresh document and the page starts at the top.
// Fix (kept from 5 Oct): any form marked `data-keep-scroll` records the page scroll position (#appScroll, the layout's real scroller) as it submits; the
// next load puts it back, then brings the changed line (jc_focus_line, flashed by the controller) or task into view with the minimum scroll needed and
// briefly highlights it.
(function () {
    var KEY = 'jcScroll:' + location.pathname;
    var focusLineId = {!! \Illuminate\Support\Js::from(session('jc_focus_line')) !!};
    var focusTaskId = {!! \Illuminate\Support\Js::from(session('jc_focus_task')) !!}; // §14.21 — task add / rename / restore / tick

    function scroller() { return document.getElementById('appScroll') || document.scrollingElement || document.documentElement; }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !form.hasAttribute || !form.hasAttribute('data-keep-scroll')) { return; } // a form still waiting on the confirm dialog
        try {
            sessionStorage.setItem(KEY, JSON.stringify({ y: scroller().scrollTop, t: Date.now() }));
        } catch (err) { /* private window / storage blocked — falls back to the old behaviour, nothing breaks */ }
    });

    var saved = null;
    try {
        var raw = sessionStorage.getItem(KEY);
        sessionStorage.removeItem(KEY);
        saved = raw ? JSON.parse(raw) : null;
        if (saved && Date.now() - saved.t > 120000) { saved = null; } // stale — not from the submit that just happened
    } catch (err) { saved = null; }
    if (saved) { window.cxKeepScroll = true; } // AT-464 - a keep-scroll form asked to stay put: the layout's "land on the Next bar" leaves this page alone

    function restore(highlight) {
        var box = scroller();
        if (saved && box) { box.scrollTop = saved.y; }
        // A failed validation reopens that line's editor — bring it into view.
        var open = document.querySelector('[id^="jc-edit-line-"]');
        var target = open
            || (focusLineId ? document.querySelector('[data-line-id="' + focusLineId + '"]') : null)
            || (focusTaskId ? document.querySelector('[data-task-id="' + focusTaskId + '"]') : null);
        if (target) {
            if (target.scrollIntoView) { target.scrollIntoView({ block: 'nearest' }); }
            if (highlight && !open) {
                target.style.transition = 'background-color .4s';
                target.style.backgroundColor = 'rgba(14,165,233,.16)';
                setTimeout(function () { target.style.backgroundColor = ''; }, 2200);
            }
        }
    }

    // Sync, then again once Alpine has rendered (an editor reopened by a failed validation only exists after it) and once everything has loaded.
    restore(false);
    document.addEventListener('DOMContentLoaded', function () { restore(false); });
    window.addEventListener('load', function () { restore(true); });
})();
</script>
@endpush
