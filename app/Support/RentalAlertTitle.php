<?php

namespace App\Support;

use App\Models\Property;

/**
 * The one rule for a rentals alert title: it ends " - <property short address>", so an agent can tell which property it is about
 * (14 Oct 2026 - rental_work_order.owner_decided had no address while its sisters did).
 *
 * Idempotent: a title that already ends with the address is only re-joined with " - " (an old " — " is normalised), never doubled.
 */
final class RentalAlertTitle
{
    public static function addressOf(?Property $property, ?int $fallbackPropertyId = null): string
    {
        $address = $property?->buildDisplayAddress() ?: ($property?->title ?: '');
        $address = trim((string) $address);
        if ($address !== '') {
            return $address;
        }
        $id = $property?->id ?? $fallbackPropertyId;

        return $id ? 'Property #' . $id : '';
    }

    public static function withProperty(string $title, ?Property $property, ?int $fallbackPropertyId = null): string
    {
        $address = self::addressOf($property, $fallbackPropertyId);
        $title = rtrim($title);
        if ($address === '') {
            return $title;
        }
        if (str_ends_with($title, $address)) {
            $head = preg_replace('/[\s\-—–:]+$/u', '', substr($title, 0, -strlen($address))) ?? '';

            return $head === '' ? $address : $head . ' - ' . $address;
        }

        return $title . ' - ' . $address;
    }
}
