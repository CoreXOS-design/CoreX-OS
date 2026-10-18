<?php

namespace App\Support;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * The sender NAME on every outgoing mail that did not set one itself.
 *
 * A mailable with no explicit From name leaves under `config('mail.from.name')` - one install-wide env value. QA1's was "Home Finders
 * Coastal", so a second agency's tenant read "Home Finders Coastal" as the sender of its fault mails (Cape walk, 14 Oct 2026). Johan: a
 * client sees the AGENCY's name. So, just before send, a message still carrying the install default (or no name) is renamed after the
 * agency it is about - found from the mailable's own data (the lease / fault / work order / agent / contact it was built from) - and
 * a message that names no agency leaves under the platform's own neutral name (PlatformSender::name()), never another agency's.
 * A mailable that chose its own sender ("<agent> via <agency>", the agency name) is left exactly as it set it.
 */
final class MailFromName
{
    public static function rebrand(MessageSending $event): void
    {
        try {
            $message = $event->message;
            $from = method_exists($message, 'getFrom') ? $message->getFrom() : [];
            if ($from === []) {
                return;
            }

            $current = trim((string) $from[0]->getName());
            $default = trim((string) config('mail.from.name'));
            if ($current !== '' && strcasecmp($current, $default) !== 0) {
                return; // the mail chose its own sender name
            }

            $agency = self::agencyIn((array) $event->data);
            $name = $agency ? ClientBrand::name($agency, PlatformSender::name()) : PlatformSender::name();

            if ($name !== '' && strcasecmp($name, $current) !== 0) {
                $message->from(new Address($from[0]->getAddress(), $name));
            }
        } catch (\Throwable $e) {
            // naming a sender must never stop a mail
            report($e);
        }
    }

    /** The agency a message is about: an Agency, or the first record (or `agency_id`) in its data that belongs to one. */
    public static function agencyIn(array $data): ?Agency
    {
        foreach ($data as $key => $value) {
            if ($value instanceof Agency) {
                return $value;
            }
        }
        foreach ($data as $key => $value) {
            $id = 0;
            if ($value instanceof Model) {
                $id = (int) ($value->getAttribute('agency_id') ?? 0);
            } elseif (is_array($value)) {
                $id = (int) ($value['agency_id'] ?? 0);
            } elseif (is_int($value) && in_array((string) $key, ['agencyId', 'agency_id'], true)) {
                $id = $value;
            }
            if ($id > 0) {
                return Agency::withoutGlobalScopes()->find($id);
            }
        }

        return null;
    }
}
