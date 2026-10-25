<?php

namespace App\Services\Rentals;

use App\Models\ClientUser;
use App\Models\RentalPortalSetting;
use App\Models\RentalPushPreference;
use App\Services\Push\PushDispatchSummary;
use App\Services\Push\PushNotificationService;
use Illuminate\Support\Collection;

/**
 * AT-508 (P1) — the ONE place a rentals phone notification for a tenant or owner is decided and sent.
 * .ai/specs/at-508-mobile-rentals.md §13. It does not send anything itself: it works out WHO should get a category and hands the
 * rest to PushNotificationService (idempotency, rate cap, retry, dead-token pruning) — no second push system.
 *
 *   mandatory category  -> always sent (decision needed, urgent, appointments, work finished, notice outcomes)
 *   switchable category -> the person's own choice if they made one, else the agency default (push_informational_default, on if never set)
 */
class RentalClientPushService
{
    public function __construct(private PushNotificationService $push) {}

    /** @return array<string, array{audience:string, mandatory:bool, label:string, description:string}> */
    public function catalogue(): array
    {
        return (array) config('rental-push.categories', []);
    }

    public function isMandatory(string $category): bool
    {
        return (bool) ($this->catalogue()[$category]['mandatory'] ?? false);
    }

    public function isSwitchable(string $category): bool
    {
        return isset($this->catalogue()[$category]) && ! $this->isMandatory($category);
    }

    /**
     * The categories one person may switch (the app shows ONLY these) with their current setting. $audience = 'tenant' | 'owner'.
     *
     * @return list<array{key:string,label:string,description:string,enabled:bool,default:bool}>
     */
    public function switchableFor(ClientUser $user, string $audience, ?int $agencyId): array
    {
        $default = RentalPortalSetting::pushInformationalDefaultFor($agencyId);
        $own = RentalPushPreference::where('client_user_id', $user->id)->pluck('enabled', 'category');

        $rows = [];
        foreach ($this->catalogue() as $key => $c) {
            if ($c['mandatory'] || ! in_array($c['audience'], [$audience, 'both'], true)) {
                continue;
            }
            $rows[] = [
                'key' => $key, 'label' => $c['label'], 'description' => $c['description'],
                'enabled' => $own->has($key) ? (bool) $own[$key] : $default,
                'default' => $default,
            ];
        }

        return $rows;
    }

    /** @throws \InvalidArgumentException an unknown category, or a mandatory one (those cannot be switched off) */
    public function setPreference(ClientUser $user, string $category, bool $enabled): void
    {
        if (! isset($this->catalogue()[$category])) {
            throw new \InvalidArgumentException('Unknown notification.');
        }
        if ($this->isMandatory($category)) {
            throw new \InvalidArgumentException('This notification cannot be switched off.');
        }
        RentalPushPreference::updateOrCreate(['client_user_id' => $user->id, 'category' => $category], ['enabled' => $enabled]);
    }

    public function wants(ClientUser $user, string $category, ?int $agencyId): bool
    {
        if ($this->isMandatory($category)) {
            return true;
        }
        $own = RentalPushPreference::where('client_user_id', $user->id)->where('category', $category)->value('enabled');

        return $own !== null ? (bool) $own : RentalPortalSetting::pushInformationalDefaultFor($agencyId);
    }

    /**
     * Send one category to some contacts (tenants / owners). Contacts with no app login (no client_user_id) are skipped silently — they still get the email.
     *
     * @param  iterable<\App\Models\Contact>  $contacts
     * @param  string  $dedupe  stable key for THIS fact (e.g. "fault:12|step:owner_approved") — the same fact is never pushed to the same phone twice
     * @param  array<string,scalar>  $data  deep-link fields for the app (subject_type, subject_id, …)
     */
    public function sendToContacts(iterable $contacts, string $category, string $title, string $body, string $dedupe, array $data = [], ?int $agencyId = null): PushDispatchSummary
    {
        if (! isset($this->catalogue()[$category])) {
            throw new \InvalidArgumentException("Unknown rental push category {$category}.");
        }

        $ids = Collection::make($contacts)->pluck('client_user_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return PushDispatchSummary::empty();
        }

        $recipients = ClientUser::whereIn('id', $ids)->get()
            ->filter(fn (ClientUser $u) => $this->wants($u, $category, $agencyId))
            ->pluck('id')->all();

        if (empty($recipients)) {
            return PushDispatchSummary::empty();
        }
        // The same FACT is told once, however many times the code path that reports it runs again (an owner chase retried, a mail resent).
        // Claimed only now: a fact nobody wanted (switched off) must not use up the claim for when somebody does.
        if (! \Illuminate\Support\Facades\Cache::add('rental-push:once:' . sha1("{$category}|{$dedupe}"), 1, now()->addDays(30))) {
            return PushDispatchSummary::empty();
        }

        return $this->push->sendToClientUserIds($recipients, "rental:{$category}|{$dedupe}", [
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_merge(['type' => 'rental', 'category' => $category, 'mandatory' => $this->isMandatory($category) ? '1' : '0'], $data),
        ]);
    }
}
