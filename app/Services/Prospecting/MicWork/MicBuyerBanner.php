<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\Contact;
use App\Models\ContactMatch;

/**
 * "Prospecting for a buyer" banner on the Work tab (mic-work-tab.md 13.14): the buyer, the wishlist MIC matched on (as chips) and the two
 * pop-up links. Everything the banner shows is built here; the view only prints it.
 *
 * Which wishlist: MIC does NOT score on one wishlist. PropertyMatchScoringService::canonicalBestAcross() scores EVERY active, countable
 * wishlist of the buyer and keeps the best score per advert; prospecting_buyer_matches stores the contact, not the wishlist. So the banner
 * shows the buyer's primary active countable wishlist (else the newest one) and says "best of N wishlists" when there is more than one.
 */
final class MicBuyerBanner
{
    private const FEATURE_CHIPS = 4;

    /**
     * @return array<string,mixed>|null null when the buyer is not in this agency
     */
    public function forBuyer(int $contactId, int $agencyId, bool $canOpenMatches): ?array
    {
        $contact = Contact::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find($contactId);
        if (! $contact) {
            return null;
        }

        $lists = ContactMatch::withoutGlobalScopes()->where('agency_id', $agencyId)->where('contact_id', $contactId)->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)->with('createdBy:id,name')->get()
            ->filter(fn (ContactMatch $m) => $m->isCountable())
            ->sortByDesc(fn (ContactMatch $m) => ($m->is_primary ? 10_000_000_000 : 0) + ($m->created_at?->timestamp ?? 0))->values();
        $shown = $lists->first();

        $name = trim((string) ($contact->first_name ?? '') . ' ' . (string) ($contact->last_name ?? ''));
        $out = [
            'buyer' => ['id' => $contact->id, 'name' => $name !== '' ? $name : ((string) ($contact->email ?: 'this buyer')), 'url' => route('corex.contacts.show', $contact)],
            'chips' => [],
            'wishlist' => null,
            'wishlist_count' => $lists->count(),
            'wishlists_url' => route('corex.contacts.show', $contact) . '?tab=matches',
            'results_url' => null,
            'edit_url' => null,
        ];
        if (! $shown) {
            return $out;
        }

        $out['chips'] = $this->chips($shown);
        $out['wishlist'] = [
            'id' => $shown->id,
            'primary' => (bool) $shown->is_primary,
            'saved' => $shown->created_at?->format('j M Y'),
            'by' => $shown->createdBy?->name,
        ];
        if ($canOpenMatches) {
            $out['results_url'] = route('corex.contacts.matches.results', [$contact, $shown]);
            $out['edit_url'] = route('corex.contacts.matches.edit', [$contact, $shown]);
        }

        return $out;
    }

    /**
     * The wishlist as chips, in the Core Match results header's order and style: [label, tone] with tone info (price) or default.
     *
     * @return list<array{0:string,1:string,2:?string}> label, tone, full text for the title (when the label is cut)
     */
    public function chips(ContactMatch $m): array
    {
        $c = [[$m->listingTypeLabel(), $m->listing_type === 'rental' ? 'info' : 'success', null]];
        if ($m->price_min || $m->price_max) {
            $c[] = [strtoupper($m->priceRangeLabel()), 'info', null];
        }
        if (filled($m->category)) {
            $c[] = [$this->human((string) $m->category), 'default', null];
        }
        foreach ($m->propertyTypeLabels() as $type) {
            $c[] = [$type, 'default', null];
        }
        $subs = $m->suburbList();
        if ($subs) {
            $full = implode(', ', $subs);
            $c[] = [count($subs) > 3 ? implode(', ', array_slice($subs, 0, 3)) . ' +' . (count($subs) - 3) : $full, 'default', count($subs) > 3 ? $full : null];
        } else {
            $c[] = ['Any suburb', 'default', null];
        }
        foreach ([[$m->beds_min, 'bed'], [$m->baths_min, 'bath'], [$m->garages_min ?: $m->parking_min, 'garage']] as [$n, $word]) {
            if ($n) {
                $c[] = [(int) $n . '+ ' . $word . ((int) $n > 1 ? 's' : ''), 'default', null];
            }
        }
        foreach ([['Must have', $m->must_have_features], ['Avoid', $m->deal_breakers]] as [$label, $features]) {
            $list = is_array($features) ? array_values(array_filter(array_map(fn ($f) => $this->human((string) $f), $features))) : [];
            if ($list) {
                $full = $label . ': ' . implode(', ', $list);
                $c[] = [count($list) > self::FEATURE_CHIPS ? $label . ': ' . implode(', ', array_slice($list, 0, self::FEATURE_CHIPS)) . ' +' . (count($list) - self::FEATURE_CHIPS) : $full, 'default', count($list) > self::FEATURE_CHIPS ? $full : null];
            }
        }

        return $c;
    }

    private function human(string $v): string
    {
        return ucwords(trim(str_replace(['_', '-'], ' ', $v)));
    }
}
