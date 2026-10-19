<?php

declare(strict_types=1);

namespace App\Services\AdTemplates;

use App\Models\Auction;
use App\Models\Property;
use Illuminate\Support\Carbon;

/**
 * AT-456 §B.9-1 — the extra listing facts the ad render kernel can print, worked out server-side from
 * the property's OWN data (never fabricated) and handed to the kernel inside Property::adData().
 *
 * Every value is ready-to-print text, or '' when the listing has no such value. The kernel leaves an
 * element with an empty value off a real ad entirely, so a listing without a levy shows no levy — never
 * "R 0", "null" or an empty coloured pill.
 *
 *   erf_size        "1 200 m²"  (or "2.5 ha" when the listing was captured in hectares)
 *   levy            "R 1 250 pm"
 *   rates_taxes     "R 900 pm"
 *   price_per_m2    "R 7 656 per m²"            sales only; floor size
 *   pet_friendly    "Pet friendly"              only when the listing says yes
 *   available_from  "Available now" / "Available from 1 Nov 2026"     rentals only
 *   rent_per_month  "R 12 500 per month"        rentals only; the rental price type decides the unit
 *   show_day        "Sat 25 Oct · 10:00–13:00"  the next upcoming show day of THIS listing
 *   auction_date    "12 Nov 2026 · 11:00"       from Property::currentAuctionLot()
 *   auction_venue   "Venue name, address"
 *
 * Times are shown in the owning agency's timezone (the app timezone until agencies carry their own).
 * Money is formatted like Property::formattedPrice() ("R 2 450 000").
 */
final class AdListingFacts
{
    /** Statuses after which a rental is no longer "available" and a sale no longer worth a price-per-m². */
    private const CLOSED_STATUSES = ['sold', 'transferred', 'rented', 'let_out'];

    /** An auction in any of these states is not an advertisable date (not announced, cancelled, moved, or over). */
    private const AUCTION_HIDDEN_STATUSES = [
        Auction::STATUS_DRAFT, Auction::STATUS_CANCELLED, Auction::STATUS_POSTPONED,
        Auction::STATUS_CLOSED, Auction::STATUS_SETTLED,
    ];

    /**
     * @return array<string, string> every key always present; '' = nothing to show.
     */
    public static function for(Property $property, ?Carbon $now = null): array
    {
        $now ??= now();
        $tz   = self::timezone($property);
        $auction = self::auction($property, $now, $tz);

        return [
            'erf_size'       => self::erfSize($property),
            'levy'           => self::monthly($property->levy),
            'rates_taxes'    => self::monthly($property->rates_taxes),
            'price_per_m2'   => self::pricePerM2($property),
            'pet_friendly'   => $property->pet_friendly === true ? 'Pet friendly' : '',
            'available_from' => self::availableFrom($property, $now, $tz),
            'rent_per_month' => self::rent($property),
            'show_day'       => self::showDay($property, $now, $tz),
            'auction_date'   => $auction['date'],
            'auction_venue'  => $auction['venue'],
        ];
    }

    /** "R 2 450 000" — the same grouping as Property::formattedPrice(). */
    public static function money(float|int $amount): string
    {
        return 'R ' . number_format((int) round($amount), 0, '.', ' ');
    }

    private static function timezone(Property $property): string
    {
        return $property->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    }

    private static function monthly(mixed $amount): string
    {
        $v = is_numeric($amount) ? (float) $amount : 0.0;

        return $v > 0 ? self::money($v) . ' pm' : '';
    }

    private static function erfSize(Property $p): string
    {
        $m2 = is_numeric($p->erf_size_m2) ? (float) $p->erf_size_m2 : 0.0;
        if ($m2 <= 0) {
            return '';
        }
        // The importer normalises every erf size to m² and keeps the unit it arrived in; a large plot
        // that was captured in hectares reads far better as hectares.
        $unit = strtolower(trim((string) $p->erf_area_unit));
        if ($m2 >= 10000 && ($unit === 'ha' || str_contains($unit, 'hectare'))) {
            return rtrim(rtrim(number_format($m2 / 10000, 2, '.', ' '), '0'), '.') . ' ha';
        }

        return number_format((int) round($m2), 0, '.', ' ') . ' m²';
    }

    private static function pricePerM2(Property $p): string
    {
        if ($p->isRental() || $p->price_on_application || in_array($p->normalizedStatus(), self::CLOSED_STATUSES, true)) {
            return '';
        }
        $price = (float) $p->effectivePrice();
        $size  = is_numeric($p->size_m2) ? (float) $p->size_m2 : 0.0;

        return ($price > 0 && $size > 0) ? self::money($price / $size) . ' per m²' : '';
    }

    private static function availableFrom(Property $p, Carbon $now, string $tz): string
    {
        if (! $p->isRental() || in_array($p->normalizedStatus(), self::CLOSED_STATUSES, true)) {
            return '';
        }
        $from = $p->lease_start_date;   // "available from" for a rental (see PropertyStatusFollowsLeaseService)
        if ($from !== null && $from->copy()->startOfDay()->gt($now->copy()->setTimezone($tz)->startOfDay())) {
            // The agent can hide the date itself from the public (the same switch the portals honour).
            return ($p->show_available_from_on_portals ?? true) ? 'Available from ' . $from->format('j M Y') : '';
        }

        return 'Available now';
    }

    private static function rent(Property $p): string
    {
        if (! $p->isRental() || $p->price_on_application || in_array($p->normalizedStatus(), self::CLOSED_STATUSES, true)) {
            return '';
        }
        $amount = (float) $p->effectivePrice();

        return $amount > 0 ? self::money($amount) . ' ' . self::rentUnit($p->rental_price_type) : '';
    }

    /** "per month" / "per week" / … from the agency's own rental price type; blank or unknown = per month. */
    private static function rentUnit(?string $type): string
    {
        $t = strtolower(trim((string) $type));

        return match (true) {
            in_array($t, ['', 'per month', 'per_month', 'monthly', 'month'], true)                        => 'per month',
            in_array($t, ['per week', 'per_week', 'weekly', 'week'], true)                                  => 'per week',
            in_array($t, ['per day', 'per_day', 'daily', 'day'], true)                                      => 'per day',
            in_array($t, ['per year', 'per_year', 'yearly', 'annually', 'year'], true)                      => 'per year',
            in_array($t, ['per sqm', 'per_sqm', 'persqm', 'per m2', 'per_m2', 'per m²', 'per square metre', 'per square meter'], true) => 'per m²',
            str_starts_with($t, 'per ') && mb_strlen($t) <= 24                                              => $t,
            default                                                                                         => 'per month',
        };
    }

    /** The next upcoming show day / open house (PropertyShowday) of this listing. */
    private static function showDay(Property $p, Carbon $now, string $tz): string
    {
        if (! $p->exists) {
            return '';
        }
        $day = $p->showdays()->where('active', true)->where('end_date', '>=', $now)->reorder('start_date')->first();
        if ($day === null || $day->start_date === null) {
            return '';
        }
        $start = $day->start_date->copy()->setTimezone($tz);
        $end   = ($day->end_date ?? $day->start_date)->copy()->setTimezone($tz);

        if ($end->lte($start)) {
            return $start->format('D j M') . ' · ' . $start->format('H:i');
        }
        if ($start->isSameDay($end)) {
            return $start->format('D j M') . ' · ' . $start->format('H:i') . '–' . $end->format('H:i');
        }

        return $start->format('D j M H:i') . ' – ' . $end->format('D j M H:i');
    }

    /**
     * Date and venue of the listing's open auction lot. Nothing when there is no open lot, the auction is not
     * announced (draft) / cancelled / postponed / over, or its start has already passed.
     *
     * @return array{date: string, venue: string}
     */
    private static function auction(Property $p, Carbon $now, string $tz): array
    {
        $none = ['date' => '', 'venue' => ''];
        if (! $p->exists) {
            return $none;
        }

        $auction = $p->currentAuctionLot()?->auction;
        if ($auction === null || in_array($auction->status, self::AUCTION_HIDDEN_STATUSES, true) || $auction->starts_at === null) {
            return $none;
        }
        if ($auction->starts_at->lt($now) && $auction->status !== Auction::STATUS_IN_PROGRESS) {
            return $none;
        }

        $name    = trim((string) $auction->venue_name);
        $address = trim((string) $auction->venue_address);
        $venue   = $name !== '' && $address !== '' ? $name . ', ' . $address : ($name !== '' ? $name : $address);
        if ($venue === '' && $auction->is_online_streamed) {
            $venue = 'Online auction';
        }

        return [
            'date'  => $auction->starts_at->copy()->setTimezone($tz)->format('j M Y') . ' · ' . $auction->starts_at->copy()->setTimezone($tz)->format('H:i'),
            'venue' => $venue,
        ];
    }
}
