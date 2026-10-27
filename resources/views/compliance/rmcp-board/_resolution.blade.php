{{--
    The board resolution approving an RMCP version (FIC Act s42(2A)). One wording for every agency - the agency's own
    name, registration number, version, dates and RMCP fingerprint are the only things that change. Rendered ONCE at send and
    frozen on the letter (rmcp_board_letters.letter_html), so what the board signs can never drift afterwards.
    Needs: $legalName, $regNo, $versionNumber, $rmcpTitle, $effectiveFrom (Carbon), $nextReviewDue (Carbon|null), $fingerprint.
--}}
<div class="resolution">
    <p class="kicker">Financial Intelligence Centre Act 38 of 2001</p>
    <h1>Board approval of the Risk Management and Compliance Programme</h1>
    <p class="entity"><strong>{{ $legalName }}</strong><br>
        Registration number {{ $regNo !== '' ? $regNo : '______________' }}</p>

    <p><strong>Resolved</strong> by the board of directors / senior management of {{ $legalName }} that:</p>
    <ol>
        <li>In terms of section 42(2A) of the Financial Intelligence Centre Act 38 of 2001, the Risk Management and Compliance Programme of {{ $legalName }} (the <em>RMCP</em>), <strong>version {{ $versionNumber }}</strong>, titled &ldquo;{{ $rmcpTitle }}&rdquo;, is approved.</li>
        <li>The RMCP takes effect on <strong>{{ $effectiveFrom->format('d F Y') }}</strong> and replaces any earlier version from that date.@if($nextReviewDue) It is to be reviewed again by <strong>{{ $nextReviewDue->format('d F Y') }}</strong>.@endif</li>
        <li>Each of the undersigned has read the RMCP and accepts, on behalf of the board / senior management, responsibility for seeing that {{ $legalName }} follows it.</li>
    </ol>
    <p class="fingerprint">RMCP fingerprint: {{ $fingerprint }}. It identifies the exact text the board is approving.</p>
</div>
