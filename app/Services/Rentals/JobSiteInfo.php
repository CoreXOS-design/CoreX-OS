<?php

namespace App\Services\Rentals;

use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;

/**
 * AT-459 — the "getting there and getting in" facts that the crew link, the contractor link and both emails show the same way:
 * who lets the worker in (the access contact), who lives there (the tenant) and the Open in Maps link. One place, so the four
 * surfaces can never drift apart.
 *
 * The access contact lives on the WORK ORDER; a job card reads it from its work order. A job card with no work order keeps its
 * own name + phone (and its own `access_notes`). Each field falls back one step (work order -> card -> property note) so
 * nothing an office typed before this existed disappears.
 *
 * Whether the TENANT may be shown is the agency's own setting (crew_link_show_tenant_contact) and is decided by the caller,
 * never here: this class only knows how to read the people, not whether the agency lets them be shown.
 */
final class JobSiteInfo
{
    /** @return array{name: ?string, phone: ?string, tel: ?string, note: ?string, any: bool} */
    public static function accessContact(?RentalWorkOrder $workOrder, ?RentalJobCard $card = null): array
    {
        $card ??= $workOrder?->jobCard;
        $workOrder ??= $card?->workOrder;
        $property = $workOrder?->property ?? $card?->property;

        $name = self::clean($workOrder?->access_contact_name) ?? self::clean($card?->access_contact_name);
        $phone = self::clean($workOrder?->access_contact_phone) ?? self::clean($card?->access_contact_phone);
        $note = self::clean($workOrder?->access_note) ?? self::clean($card?->access_notes) ?? self::clean($property?->access_notes);

        return [
            'name' => $name,
            'phone' => $phone,
            'tel' => self::tel($phone),
            'note' => $note,
            'any' => $name !== null || $phone !== null || $note !== null,
        ];
    }

    /**
     * Save the access contact. The WORK ORDER holds it; the job card's own copies are cleared when it has a work order, so that blanking
     * a field really blanks it (a stale copy on the card could otherwise reappear through the fallback). A card with no work order
     * keeps its own copy (`access_contact_name`, `access_contact_phone`, `access_notes`).
     *
     * @param array{access_contact_name?: ?string, access_contact_phone?: ?string, access_note?: ?string, access_notes?: ?string} $in
     */
    public static function saveAccessContact(?RentalWorkOrder $workOrder, ?RentalJobCard $card, array $in): void
    {
        $name = self::clean($in['access_contact_name'] ?? null);
        $phone = self::clean($in['access_contact_phone'] ?? null);
        $note = self::clean($in['access_note'] ?? ($in['access_notes'] ?? null));

        if ($workOrder) {
            $workOrder->forceFill(['access_contact_name' => $name, 'access_contact_phone' => $phone, 'access_note' => $note])->save();
            $card?->forceFill(['access_contact_name' => null, 'access_contact_phone' => null, 'access_notes' => null])->save();

            return;
        }

        $card?->forceFill(['access_contact_name' => $name, 'access_contact_phone' => $phone, 'access_notes' => $note])->save();
    }

    /** @return array<int, array{name: string, phone: ?string, tel: ?string}> primary tenant first; [] when there is no lease */
    public static function tenants(?int $leaseId): array
    {
        if (! $leaseId) {
            return [];
        }

        return LeaseTenant::query()
            ->where('lease_id', $leaseId)
            ->with('contact')
            ->orderByDesc('is_primary')->orderBy('id')
            ->get()
            ->map(function ($t) {
                if (! $t->contact) {
                    return null;
                }
                $phone = self::clean($t->contact->phone);

                return ['name' => (string) $t->contact->full_name, 'phone' => $phone, 'tel' => self::tel($phone)];
            })
            ->filter()->values()->all();
    }

    /** Google Maps link: the property's GPS point when it has one, otherwise the address text. */
    public static function mapUrl(?Property $property, string $address): ?string
    {
        if ($property && $property->latitude && $property->longitude) {
            return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($property->latitude . ',' . $property->longitude);
        }

        return $address !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address) : null;
    }

    /** Digits and a leading + only, ready for a tel: link; null when there is no number. */
    public static function tel(?string $phone): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', (string) $phone);

        return $digits !== '' ? $digits : null;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
