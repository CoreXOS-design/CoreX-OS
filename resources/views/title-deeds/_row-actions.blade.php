{{--
    AT-450 round 3 (item A) - what a person can do with a list row, in words. No icon-only buttons anywhere:
      - ONE labelled button: the next step for the row's status ("Notify owners" / "Hand over"; "Open" when there is none);
      - a labelled "More" button that opens a panel of every other action, each as a worded button, Archive last and apart.
    Every action posts to the SAME route the record page uses (same one-time token, same audit); the state-changing ones are
    confirmed through ONE shared app dialog and come back to this list. The whole row also opens the record.
    Variables: $d (TitleDeed), $can, $pinUsed, $token (closure purpose,deedId -> one-time token).
--}}
@php
    $open = ! $d->trashed() && ! $d->isCollected();
    $name = $d->property_display;
    $pinIssued = $d->pin_version > 0 && $d->pin_encrypted !== null;
    $pinUsable = $pinUsed && $pinIssued && $d->pin_locked_at === null;
    $resend = $pinUsable && $d->status === 'notified';
    $oneChannelRoute = $resend ? route('title-deeds.pin.resend', $d->id) : route('title-deeds.notify', $d->id);
    $oneChannelPurpose = $resend ? 'pin_resend' : 'notify';
    $btn = 'corex-btn-outline text-xs';

    // The ONE next action for this status.
    $primary = null;
    if ($open && $d->status === 'received' && $can['notify']) {
        $primary = ['label' => 'Notify owners', 'ask' => ['url' => route('title-deeds.notify', $d->id), 'token' => $token('notify', $d->id), 'channel' => null, 'title' => 'Notify owners',
            'message' => 'Email every owner and prepare their WhatsApp for ' . $name . '?', 'label' => 'Notify owners']];
    } elseif ($open && $can['handover']) {
        $primary = ['label' => 'Hand over', 'href' => route('title-deeds.handover', $d->id)];
    }

    // Everything else, in a labelled panel.
    $items = [];
    if ($primary) {
        $items[] = ['label' => 'Open this title deed', 'href' => route('title-deeds.show', $d->id)];
    }
    if ($open && $can['handover'] && ($primary['label'] ?? '') !== 'Hand over') {
        $items[] = ['label' => 'Hand over', 'href' => route('title-deeds.handover', $d->id)];
    }
    if ($open && $can['notify']) {
        if ($resend) {
            $items[] = ['label' => 'Send the PIN again', 'ask' => ['url' => route('title-deeds.pin.resend', $d->id), 'token' => $token('pin_resend', $d->id), 'channel' => null, 'title' => 'Send the PIN again',
                'message' => 'Send the same PIN again to every owner of ' . $name . '?', 'label' => 'Send the PIN again']];
        }
        if ($pinUsed) {
            $items[] = ['label' => 'Issue a new PIN', 'ask' => ['url' => route('title-deeds.pin.new', $d->id), 'token' => $token('pin_new', $d->id), 'channel' => null, 'title' => 'Issue a new PIN',
                'message' => 'The old PIN will stop working and the new one will be sent to every owner. Continue?', 'label' => 'Issue a new PIN']];
        }
        $items[] = ['label' => 'Email the owners', 'ask' => ['url' => $oneChannelRoute, 'token' => $token($oneChannelPurpose, $d->id), 'channel' => 'email', 'title' => 'Email the owners',
            'message' => 'Email the owners of ' . $name . ' (to the address stored on each contact)?', 'label' => 'Send the email']];
        $items[] = ['label' => 'Prepare a WhatsApp to the owners', 'ask' => ['url' => $oneChannelRoute, 'token' => $token($oneChannelPurpose, $d->id), 'channel' => 'whatsapp', 'title' => 'Prepare a WhatsApp',
            'message' => 'Prepare the WhatsApp message for the owners of ' . $name . '? You then press "Open WhatsApp and send" and mark it as sent.', 'label' => 'Prepare the WhatsApp']];
    }
    if ($d->trashed() && $can['archive']) {
        $items[] = ['label' => 'Restore this title deed', 'ask' => ['url' => route('title-deeds.restore', $d->id), 'token' => null, 'channel' => null, 'method' => 'POST', 'title' => 'Restore this title deed',
            'message' => 'Bring ' . $name . ' back into the list?', 'label' => 'Restore']];
    }
    if (! $d->trashed() && $can['archive']) {
        $items[] = ['label' => 'Archive this title deed', 'danger' => true, 'ask' => ['url' => route('title-deeds.destroy', $d->id), 'token' => null, 'channel' => null, 'method' => 'DELETE', 'title' => 'Archive this title deed',
            'message' => 'Archive ' . $name . '? It leaves the list but is kept, and can be restored later.', 'label' => 'Archive']];
    }
    $more = ['title' => $name, 'items' => $items];
@endphp
<div class="inline-flex items-center gap-2">
    @if($primary && isset($primary['ask']))
        <button type="button" class="corex-btn-primary text-xs" @click="openAsk(@js($primary['ask']))">{{ $primary['label'] }}</button>
    @elseif($primary)
        <a href="{{ $primary['href'] }}" class="corex-btn-primary text-xs no-underline">{{ $primary['label'] }}</a>
    @else
        <a href="{{ route('title-deeds.show', $d->id) }}" class="{{ $btn }} no-underline">Open</a>
    @endif
    @if(count($items) > 0)
        <button type="button" class="{{ $btn }}" aria-haspopup="dialog" @click="openMore(@js($more))">More</button>
    @endif
</div>
