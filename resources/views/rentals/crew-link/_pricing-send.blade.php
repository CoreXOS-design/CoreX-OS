{{--
    AT-465 - the ONE send: the findings box, and the sticky "Send to office (n)" bar. Rendered inside #cj-send-wrap by _block-pricing,
    and returned as `send_html` by the no-reload line handlers (spec §17.43) so the bar appears the moment the first line is saved.
    Needs $block['draft_count'], $block['first_send'] and $actions['lines_send'].
--}}
@if(!empty($block['draft_count']))
    <form id="cj-send-form" method="POST" action="{{ $actions['lines_send'] }}" class="cj-send-form" style="margin-top:14px;">
        @csrf
        <div id="cj-send"></div>
        <label class="cj-label" for="cj-findings">What did you find, and what is needed?@if(empty($block['first_send'])) <span class="cj-muted">(optional this time)</span>@endif</label>
        <textarea id="cj-findings" name="findings" rows="4" maxlength="{{ \App\Services\Rentals\CrewJobService::MAX_FINDINGS_LENGTH }}" @if(!empty($block['first_send'])) required @endif placeholder="e.g. Basin trap is cracked and leaking. Needs a new trap and about 2 hours to fit.">{{ old('findings') }}</textarea>
        <div class="cj-muted" style="margin-top:6px;"><span data-draft-phrase>Your list ({{ $block['draft_count'] }} {{ $block['draft_count'] === 1 ? 'line' : 'lines' }})</span>, and the photos and receipts on it, go to the office with this.</div>
    </form>
    <div class="cj-sendbar" data-crew-sendbar>
        <div class="in">
            <button type="submit" form="cj-send-form" class="cj-btn ok" data-send-btn>Send to office ({{ $block['draft_count'] }})</button>
            <div class="cj-muted">By sending you confirm the costs are what you actually paid.</div>
        </div>
    </div>
@endif
