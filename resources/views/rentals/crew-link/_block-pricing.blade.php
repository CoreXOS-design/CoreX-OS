{{--
    .ai/specs/rental-work-orders.md §17.5 — BUILD 1: the crew's "Parts & labour" panel.

    (a) "Price this job" — the office pressed "Ask crew to price this job": a banner with the office's note, and every line the
        crew adds while it is open belongs to that request.
    (b) "Extras during the job" — available on any open card: a note and up to 3 photos per line.

    The crew types the ACTUAL COST of each line and nothing else about money: there is no selling price, markup, margin, job
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
                    <div class="cj-muted">{{ ucfirst($l['kind']) }} &middot; {{ $l['quantity'] }}{{ $l['unit'] ? ' ' . $l['unit'] : '' }}@if($l['is_extra']) &middot; extra @endif</div>
                </div>
                @if($pricesOn && $l['cost_total'] !== null)<div style="white-space:nowrap; font-weight:700;">R {{ $l['cost_total'] }}</div>@endif
            </div>
            <div style="margin-top:6px;"><span class="cj-chip" style="{{ $chipStyle($l['state']) }}">{{ $l['state_label'] }}</span></div>
            @if($l['reason'])<div class="cj-muted" style="margin-top:4px;">Reason: {{ $l['reason'] }}</div>@endif
            @if($l['note'])<div class="cj-muted" style="margin-top:4px;">Note: {{ $l['note'] }}</div>@endif
            @if(count($l['photos']))
                <div class="cj-photos">@foreach($l['photos'] as $p)<a href="{{ $p['url'] }}" target="_blank" rel="noopener"><img src="{{ $p['url'] }}" alt="" loading="lazy"></a>@endforeach</div>
            @endif

            @if($l['editable'] && $canWrite)
                <details class="cj-add" style="margin-top:6px;">
                    <summary>Change this line</summary>
                    <form method="POST" action="{{ $lineUrl('line_update', $l['id']) }}" enctype="multipart/form-data">
                        @csrf
                        <label class="cj-label">Part or labour</label>
                        <select name="type"><option value="part" @selected($l['kind'] === 'part')>Part</option><option value="labour" @selected($l['kind'] === 'labour')>Labour</option></select>
                        <label class="cj-label">What is it</label>
                        <input type="text" name="description" value="{{ $l['description'] }}" maxlength="255" required>
                        <label class="cj-label">How many</label>
                        <input type="number" name="quantity" value="{{ $l['quantity'] }}" step="0.01" min="0.01" inputmode="decimal" required>
                        <label class="cj-label">Unit (e.g. each, hour)</label>
                        <input type="text" name="unit" value="{{ $l['unit'] }}" maxlength="30">
                        @if($pricesOn)
                            <label class="cj-label">What it cost you, each (R)</label>
                            <input type="number" name="unit_cost" value="{{ $l['unit_cost'] }}" step="0.01" min="0" inputmode="decimal" required>
                        @endif
                        <label class="cj-label">Note (optional)</label>
                        <textarea name="note" maxlength="1000">{{ $l['note'] }}</textarea>
                        <label class="cj-label">Add photos (up to {{ $block['photo_limit'] }} on a line)</label>
                        <input type="file" name="photos[]" accept="image/*" multiple style="width:100%; min-height:48px; font-size:15px;">
                        <button type="submit" class="cj-btn primary small">Save changes</button>
                    </form>
                </details>
                <form method="POST" action="{{ $lineUrl('line_archive', $l['id']) }}" onsubmit="return confirm('Remove this line?');">
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
                            <option value="{{ $c['id'] }}" data-desc="{{ $c['description'] }}" data-kind="{{ $c['kind'] }}" data-unit="{{ $c['unit'] }}">{{ $c['label'] }}</option>
                        @endforeach
                    </select>
                @endif
                <label class="cj-label">Part or labour</label>
                <select name="type" class="cj-kind"><option value="part">Part</option><option value="labour">Labour</option></select>
                <label class="cj-label">What is it</label>
                <input type="text" name="description" class="cj-desc" maxlength="255" autocomplete="off" placeholder="e.g. geyser element">
                <label class="cj-label">How many</label>
                <input type="number" name="quantity" value="1" step="0.01" min="0.01" inputmode="decimal" required>
                <label class="cj-label">Unit (e.g. each, hour)</label>
                <input type="text" name="unit" class="cj-unit" maxlength="30" autocomplete="off">
                @if($pricesOn)
                    <label class="cj-label">What it cost you, each (R)</label>
                    <input type="number" name="unit_cost" step="0.01" min="0" inputmode="decimal" required>
                @endif
                <label class="cj-label">Note (optional)</label>
                <textarea name="note" maxlength="1000" placeholder="Why is this needed?"></textarea>
                <label class="cj-label">Photos (optional, up to {{ $block['photo_limit'] }})</label>
                <input type="file" name="photos[]" accept="image/*" capture="environment" multiple style="width:100%; min-height:48px; font-size:15px;">
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
            var pick = root.querySelector('.cj-cat-pick');
            if (!pick) { return; }
            pick.addEventListener('change', function () {
                var o = pick.options[pick.selectedIndex], form = pick.form;
                if (!o || !o.value) { return; }
                form.querySelector('.cj-desc').value = o.dataset.desc || '';
                if (o.dataset.kind) { form.querySelector('.cj-kind').value = o.dataset.kind; }
                if (o.dataset.unit) { form.querySelector('.cj-unit').value = o.dataset.unit; }
            });
            root.querySelectorAll('form').forEach(function (f) {
                f.addEventListener('submit', function () { var b = f.querySelector('button[type=submit]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
            });
        })();
    </script>
</div>
@endif
