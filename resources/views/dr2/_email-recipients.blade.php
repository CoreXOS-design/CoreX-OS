{{--
    CX-113 Phase G (Johan, 2026-08-22) — "cant see all the email addresses it was sent
    from or sent to? who the recipients are etc etc etc." Renders ABOVE the reused
    compliance thread-bubble (CommunicationBodyController::show() concatenates the two)
    rather than editing that shared partial, which Comms Suspense/Archive also render —
    this block is DR2-only.

    $to / $cc are null when this row predates the Phase G ingestion fix (the role split
    was never persisted for it) — $legacyRecipients carries the best honest fallback
    (the full merged participant set, unlabeled) instead of a false "To"/"Cc" claim.
--}}
@php
    $rows = [];
    if (filled($communication->from_identifier)) {
        $rows[] = ['label' => 'From', 'addresses' => [$communication->from_identifier]];
    }
    if ($legacyRecipients !== null) {
        if (! empty($legacyRecipients)) {
            $rows[] = ['label' => 'Recipients', 'addresses' => $legacyRecipients];
        }
    } else {
        if (! empty($to)) {
            $rows[] = ['label' => 'To', 'addresses' => $to];
        }
        if (! empty($cc)) {
            $rows[] = ['label' => 'Cc', 'addresses' => $cc];
        }
    }
@endphp
<div class="mb-3 pb-3" style="border-bottom:1px solid var(--border,#e5e7eb);">
    @if($legacyRecipients !== null)
        <div class="text-xs mb-1.5" style="color:var(--text-muted,#9ca3af);">
            Captured before recipient roles were tracked — full address list shown, To/Cc unknown.
        </div>
    @endif
    @foreach($rows as $row)
        <div class="flex items-start gap-2 text-xs mb-1" style="line-height:1.5;">
            <span class="flex-shrink-0" style="width:4.5rem;color:var(--text-muted,#9ca3af);font-weight:600;">{{ $row['label'] }}</span>
            <span style="min-width:0;flex:1;color:var(--text-primary);">
                @foreach($row['addresses'] as $i => $addr)
                    @if($i > 0)<br>@endif
                    {{ $addr }}@if(! empty($annotations[strtolower(trim($addr))]))<span style="color:var(--text-muted,#9ca3af);"> — {{ $annotations[strtolower(trim($addr))] }}</span>@endif
                @endforeach
            </span>
        </div>
    @endforeach
</div>
