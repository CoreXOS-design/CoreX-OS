<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\ViewingPack;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * .ai/specs/viewing-pack.md "Whose details on the cover" (AT-509) — another agent
 * downloaded a buyer pack for this agent's buyer and chose to put THEIR OWN details
 * on the cover. Sent to the agent the buyer is under. In-app (database) only, like
 * the other agent-to-agent heads-ups (OtherAgencyStockUnlockRequestedNotification).
 */
class BuyerPackMadeByAnotherAgentNotification extends Notification
{
    use Queueable;

    public const EVENT_KEY = 'buyer_pack.made_by_another_agent';

    public function __construct(
        protected ViewingPack $pack,
        protected User $madeBy,
        protected string $buyerName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $buyer = $this->buyerName !== '' ? $this->buyerName : 'your buyer';

        return [
            'event_key'  => self::EVENT_KEY,
            'title'      => "{$this->madeBy->name} made a buyer pack for {$buyer}",
            'body'       => "The pack shows {$this->madeBy->name}'s contact details, not yours.",
            'action_url' => $this->pack->contact_id
                ? route('corex.contacts.show', $this->pack->contact_id, false) . '?tab=viewings'
                : null,
            'pack_id'    => $this->pack->id,
            'contact_id' => $this->pack->contact_id,
            'made_by_id' => $this->madeBy->id,
        ];
    }
}
