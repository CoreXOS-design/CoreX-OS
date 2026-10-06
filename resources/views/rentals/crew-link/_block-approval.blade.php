{{--
    .ai/specs/rental-work-orders.md §17.7 / §17.8.4 — BUILD 2's slot: the approval chips.

    Receives $block = CrewApprovalBlock::for($card, $ctx) — a flat array of plain values ([] when there is nothing to show):
      emergency  bool   "Approved to proceed (emergency)" — a chip only: no owner name, no contact
      extras     list   {description, quantity, unit, state: approved|awaiting|declined, label} for extra work that became a variation
      hold       bool   at least one extra is awaiting the owner — "do not start" banner
    The crew sees the STATE only: never an owner name, contact, approval amount or selling.
--}}
@if(!empty($block))
    <div class="cj-card">
        @if(!empty($block['emergency']))
            <div style="margin-bottom:8px;"><span class="cj-chip ok">Approved to proceed (emergency)</span></div>
        @endif
        @if(!empty($block['hold']))
            <div style="background:#fff8e6; border:1px solid #f1dfa6; border-radius:10px; padding:10px 12px; font-size:14px; margin-bottom:10px;">
                <strong>Extra work is waiting for the owner.</strong> Carry on with the approved job, but do not start the extra items marked below until they are approved.
            </div>
        @endif
        @if(!empty($block['extras']))
            <h2>Extra work</h2>
            @foreach($block['extras'] as $extra)
                <div class="cj-row">
                    <div>{{ $extra['description'] }}</div>
                    <div class="q">
                        {{ $extra['quantity'] }} {{ $extra['unit'] }}
                        <div><span class="cj-chip {{ $extra['state'] === 'approved' ? 'ok' : '' }}" @if($extra['state'] === 'awaiting') style="background:#fff3cd;color:#8a5a00;" @elseif($extra['state'] === 'declined') style="background:#fde8e8;color:#a32020;" @endif>{{ $extra['label'] }}</span></div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endif
