{{--
    .ai/specs/rental-work-orders.md §17.5 — BUILD 1: the crew's "Parts & labour" panel.

    (a) "Price this job" — the office pressed "Ask crew to price this job": a banner with the office's note, and every line the
        crew adds while it is open belongs to that request.
    (b) "Extras during the job" — available on any open card: a note and up to 3 photos per line.

    AT-459: a PART line has the crew's actual cost (pre-filled from the list) and an optional receipt; a LABOUR line is HOURS only —
    the crew never types, sees or is told a labour rate or amount (the office prices it).
    The crew types the ACTUAL COST of each part and nothing else about money: there is no selling price, markup, margin, job
    total, owner or approval figure anywhere in this partial or in the $block it reads (CrewPricingBlock — guarded by
    CrewPayloadNeverCarriesSellingTest). Lines the crew adds are DRAFTS — in no total anywhere — until "Send to office".

    Receives $block = CrewPricingBlock::for($card, $ctx) ([] for a closed card) and inherits $actions from _job-body:
      line_add, line_update (url with the literal "__LINE__"), line_archive (same), lines_send.
    Every form works with JavaScript off; the script below only pre-fills a picked catalogue item.
--}}
@if(!empty($block))
@php
    $canWrite = !empty($actions['line_add']);
    $pricesOn = (bool) ($block['prices_on'] ?? false);
    $lineUrl = fn ($key, $id) => str_replace('__LINE__', $id, $actions[$key] ?? '');
    $chipStyle = fn ($state) => match ($state) {
        'accepted' => 'background:#eafaf0; color:#1f8a4c;',
        'rejected', 'declined' => 'background:#fdecea; color:#c0392b;',
        'sent' => 'background:#e6f7fb; color:#076e84;',
        default => 'background:#eef3f8; color:#26435f;',
    };
@endphp
<div class="cj-card cj-pricing" id="cj-pricing" data-crew-pricing>
    <style>
        .cj-pricing input[type=number], .cj-pricing textarea { width: 100%; min-height: 48px; padding: 11px 12px; border: 1px solid var(--cj-border); border-radius: 10px; font: inherit; font-size: 16px; background: #fff; }
        .cj-pricing textarea { min-height: 76px; }
        .cj-pricing details.cj-add > summary { cursor: pointer; list-style: none; min-height: 48px; display: flex; align-items: center; font-weight: 800; font-size: 15px; color: #076e84; }
        .cj-pricing details.cj-add > summary::-webkit-details-marker { display: none; }
        .cj-pricing .cj-line { padding: 11px 0; border-top: 1px solid var(--cj-border); }
        .cj-pricing .cj-line:first-of-type { border-top: 0; }
        .cj-pricing .cj-line .top { display: flex; justify-content: space-between; gap: 10px; font-size: 15px; }
        .cj-pricing .cj-ask { background: #fff8e6; border: 1px solid #f1dfa6; border-radius: 12px; padding: 12px 14px; margin-bottom: 12px; font-size: 14px; line-height: 1.45; }
        .cj-pricing .cj-photos { margin-top: 8px; }
        .cj-pricing .cj-btn.small { min-height: 44px; font-size: 14px; padding: 9px; margin-top: 8px; }
        .cj-pricing [hidden] { display: none !important; }
        .cj-pricing .cj-receipt { display: flex; gap: 8px; }
        .cj-pricing .cj-receipt .cj-filebtn { position: relative; flex: 1 1 0; min-height: 52px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--cj-border); }
        .cj-pricing .cj-receipt .cj-filebtn.has-file { background: #eafaf0; color: #1f8a4c; border-color: #1f8a4c; }
        .cj-pricing .cj-sr { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; font-size: 0; }
        .cj-pricing .cj-receipt-name { margin-top: 6px; }
    </style>
    <h2>Parts &amp; labour</h2>

    @if(!empty($block['request']))
        <div class="cj-ask" data-price-request>
            <strong>The office asked you to price this job.</strong>
            @if(!empty($block['request']['note']))<div style="margin-top:4px;">{{ $block['request']['note'] }}</div>@endif
            <div class="cj-muted" style="margin-top:4px;">Add every part and piece of labour with what it actually cost you, then press &ldquo;Send to office&rdquo;.</div>
        </div>
    @else
        <div class="cj-muted" style="margin-bottom:8px;">Need something extra on this job? Add it here with what it cost and send it to the office. Nothing counts until the office accepts it.</div>
    @endif

    @forelse($block['lines'] as $l)
        <div class="cj-line" data-line-id="{{ $l['id'] }}" data-line-state="{{ $l['state'] }}">
            <div class="top">
                <div><strong>{{ $l['description'] }}</strong>
                    <div class="cj-muted">{{ ucfirst($l['kind']) }} &middot; {{ $l['quantity'] }}{{ $l['unit'] ? ' ' . $l['unit'] : ($l['kind'] === 'labour' ? ' hours' : '') }}@if($l['is_extra']) &middot; extra @endif</div>
                </div>
                @if($pricesOn && $l['cost_total'] !== null)<div style="white-space:nowrap; font-weight:700;">R {{ $l['cost_total'] }}</div>@endif
            </div>
            <div style="margin-top:6px;"><span class="cj-chip" style="{{ $chipStyle($l['state']) }}">{{ $l['state_label'] }}</span></div>
            @if($l['reason'])<div class="cj-muted" style="margin-top:4px;">Reason: {{ $l['reason'] }}</div>@endif
            @if($l['note'])<div class="cj-muted" style="margin-top:4px;">Note: {{ $l['note'] }}</div>@endif
            @if(count($l['photos']))
                <div class="cj-photos">@foreach($l['photos'] as $p)<a href="{{ $p['url'] }}" target="_blank" rel="noopener"><img src="{{ $p['url'] }}" alt="" loading="lazy"></a>@endforeach</div>
            @endif
            @if(!empty($l['receipt_url']))
                <div class="cj-muted" style="margin-top:4px;" data-receipt-added>Receipt added &middot; <a href="{{ $l['receipt_url'] }}" target="_blank" rel="noopener">view</a></div>
            @endif

            @if($l['editable'] && $canWrite)
                <details class="cj-add" style="margin-top:6px;">
                    <summary>Change this line</summary>
                    <form method="POST" action="{{ $lineUrl('line_update', $l['id']) }}" enctype="multipart/form-data" class="cj-line-form">
                        @csrf
                        @include('rentals.crew-link._pricing-line-fields', ['l' => $l, 'block' => $block, 'pricesOn' => $pricesOn])
                        <button type="submit" class="cj-btn primary small">Save changes</button>
                    </form>
                </details>
                <form method="POST" action="{{ $lineUrl('line_archive', $l['id']) }}" data-confirm="Remove this line?" data-confirm-danger data-confirm-label="Remove">
                    @csrf
                    <button type="submit" class="cj-btn ghost small">Remove this line</button>
                </form>
            @endif
        </div>
    @empty
        <div class="cj-muted" data-no-lines>You have not added anything yet.</div>
    @endforelse

    @if($canWrite)
        <details class="cj-add" style="margin-top:10px;" @if(empty($block['lines']) && !empty($block['request'])) open @endif>
            <summary>+ Add a part or labour</summary>
            <form method="POST" action="{{ $actions['line_add'] }}" enctype="multipart/form-data" class="cj-line-form">
                @csrf
                @if(count($block['catalogue']))
                    <label class="cj-label" for="cj-cat">Pick from the list (optional)</label>
                    <select id="cj-cat" name="rental_catalogue_item_id" class="cj-cat-pick">
                        <option value="">— type it yourself —</option>
                        @foreach($block['catalogue'] as $c)
                            <option value="{{ $c['id'] }}" data-desc="{{ $c['description'] }}" data-kind="{{ $c['kind'] }}" data-unit="{{ $c['unit'] }}" @if($pricesOn && $c['cost'] !== null) data-cost="{{ $c['cost'] }}" @endif>{{ $c['label'] }}</option>
                        @endforeach
                    </select>
                @endif
                @include('rentals.crew-link._pricing-line-fields', ['l' => null, 'block' => $block, 'pricesOn' => $pricesOn])
                @if(!empty($block['request']))
                    <label class="cj-confirm"><input type="checkbox" name="is_extra" value="1"><span>This is an extra, not part of the price you were asked for.</span></label>
                @endif
                <button type="submit" class="cj-btn primary">Add line</button>
            </form>
        </details>

        @if(!empty($block['draft_count']))
            <form method="POST" action="{{ $actions['lines_send'] }}" class="cj-send-form" style="margin-top:14px;">
                @csrf
                <label class="cj-confirm"><input type="checkbox" name="confirm" value="1" required><span>I have added everything, and the costs are what I actually paid.</span></label>
                <button type="submit" class="cj-btn ok">Send to office ({{ $block['draft_count'] }})</button>
            </form>
        @endif
    @endif

    <script>
        (function () {
            var root = document.currentScript.parentNode;
            // One behaviour for every line form (Add and Change): which fields a Part and a Labour line show, and what a picked item fills in.
            function wire(form) {
                var pick = form.querySelector('.cj-cat-pick'), kind = form.querySelector('.cj-kind'), desc = form.querySelector('.cj-desc');
                var qty = form.querySelector('.cj-qty'), unit = form.querySelector('.cj-unit'), cost = form.querySelector('.cj-cost');
                var hint = form.querySelector('.cj-cost-hint'), partOnly = form.querySelector('.cj-part-only'), qtyLabel = form.querySelector('.cj-qty-label');
                var qtyTouched = false, costTouched = false, itemUnit = '';
                if (qty) { qty.addEventListener('input', function () { qtyTouched = true; }); }
                if (cost) { cost.addEventListener('input', function () { costTouched = true; if (hint) { hint.hidden = true; } }); }

                function isLabour() { return kind && kind.value === 'labour'; }
                function hoursLabel() { return itemUnit && !/^hours?$/i.test(itemUnit) ? itemUnit.replace(/s$/i, '') + 's' : 'Hours'; }
                function apply() {
                    var labour = isLabour();
                    if (partOnly) { partOnly.hidden = labour; }
                    if (cost) { cost.disabled = labour; cost.required = !labour && !(pick && pick.value); }
                    if (unit) { unit.disabled = labour; }
                    if (qtyLabel) { qtyLabel.textContent = labour ? hoursLabel() : qtyLabel.dataset.partLabel; }
                    // hours have no sensible default: an untouched "1" is cleared so the crew types what they worked
                    if (qty && labour && !qtyTouched && qty.value === '1' && !form.dataset.edit) { qty.value = ''; }
                    if (qty && !labour && !qtyTouched && qty.value === '') { qty.value = '1'; }
                }
                if (kind) { kind.addEventListener('change', apply); }
                form.dataset.edit = form.action.indexOf('/lines/') !== -1 ? '1' : '';

                if (pick) {
                    pick.addEventListener('change', function () {
                        var o = pick.options[pick.selectedIndex];
                        if (!o || !o.value) { itemUnit = ''; apply(); return; }
                        desc.value = o.dataset.desc || '';
                        if (o.dataset.kind) { kind.value = o.dataset.kind; }
                        itemUnit = o.dataset.unit || '';
                        if (unit && o.dataset.unit) { unit.value = o.dataset.unit; }
                        if (cost && o.dataset.kind !== 'labour' && o.dataset.cost && !costTouched) { cost.value = o.dataset.cost; if (hint) { hint.hidden = false; } }
                        apply();
                    });
                }
                form.querySelectorAll('.cj-filebtn input[type=file]').forEach(function (inp) {
                    inp.addEventListener('change', function () {
                        var name = form.querySelector('.cj-receipt-name'), btn = inp.closest('.cj-filebtn');
                        form.querySelectorAll('.cj-filebtn').forEach(function (b) { b.classList.remove('has-file'); });
                        form.querySelectorAll('.cj-filebtn input[type=file]').forEach(function (o) { if (o !== inp) { o.value = ''; } });
                        if (inp.files && inp.files.length) { btn.classList.add('has-file'); if (name) { name.textContent = 'Receipt ready: ' + inp.files[0].name; } }
                    });
                });
                apply();
                form.addEventListener('submit', function () { var b = form.querySelector('button[type=submit]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
            }
            root.querySelectorAll('.cj-line-form').forEach(wire);
            root.querySelectorAll('form:not(.cj-line-form)').forEach(function (f) {
                f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
            });
        })();
    </script>
</div>
@endif
