<?php

namespace App\Support;

use App\Models\Contact;

/**
 * THE ONE place a link into the tenant / owner portal is built - for every mail and notification in the rentals area (tenant, owner,
 * fault, work order, appointment, inspection, lease, portal invite) and for the lease screen's copy-link. A test fails if any
 * Mailable or Notification in the rentals area builds a portal URL any other way.
 *
 * Why: a bare `url('/portal')` opens whoever happens to be signed in on that device (8 Oct 2026: a mail written to the TENANT opened
 * the OWNER's portal on a shared browser, "Signed in as Siyabonga"). Every link built here therefore says WHO it is for, WHICH side of
 * the portal it opens, and WHERE it lands:
 *
 *   /portal?r=<recipient>&as=owner|tenant&fault=ID | wo=ID | insp=ID | lease=ID | docs=1
 *
 *   r    a SIGNED recipient reference: the contact id and the address the mail was written to, with an HMAC made from the app key.
 *        Not guessable and not forgeable, and it carries no readable address in the URL. The page reads it SERVER-side, so the
 *        sign-in is pre-filled for that person, the "this link is for somebody else" card can name them, and the agency's header
 *        shows before sign-in.
 *   as   the view the mail was written for. A person who is both tenant and owner on the same lease lands in THAT view, whichever
 *        side they used last.
 *   target  the fault / work order / inspection / lease / documents the mail is about; the portal opens it after sign-in.
 *
 * `?email=` (the old personal link) still works - {@see self::forEmail()} builds the lease-screen copy link with it too.
 */
final class PortalLink
{
    public const VIEW_TENANT = 'tenant';
    public const VIEW_OWNER = 'owner';

    /** Target keys the portal understands, in the order they are written. */
    public const TARGETS = ['fault', 'wo', 'insp', 'lease', 'docs'];

    /** A link for a known contact (the usual case: the mail is going to $contact->email). */
    public static function forContact(Contact $contact, string $view, array $target = []): string
    {
        return self::build((string) $contact->email, $view, $target, (int) $contact->id);
    }

    /**
     * A link for a person known by the address the mail goes to - and, when the caller has it, the contact it is for ($contactId). Pass
     * the contact id whenever there is one: two people can share one address (and so one login), and the id is what tells the portal
     * WHICH of them this link is for (the name in the header, who a repair report is filed under).
     */
    public static function forEmail(string $email, string $view, array $target = [], int $contactId = 0): string
    {
        return self::build($email, $view, $target, $contactId);
    }

    /**
     * The personal link (the lease screen's COPY link, the portal invite, the signed-lease copy): `?email=` readable - the form every
     * link of this kind has always had - plus the signed reference and the view. The portal reads either the same way.
     */
    public static function personal(string $email, string $view, array $target = [], int $contactId = 0): string
    {
        $email = strtolower(trim($email));
        $query = ['email' => $email];
        // ...plus the signed reference, so the page can tell a genuine link from a hand-typed address
        if (preg_match('/^[^\s@]+@[^\s@]+$/', $email)) {
            $payload = rtrim(strtr(base64_encode(json_encode(['c' => max(0, $contactId), 'e' => $email])), '+/', '-_'), '=');
            $query['r'] = $payload . '.' . self::sign($payload);
        }
        $query['as'] = $view === self::VIEW_OWNER ? self::VIEW_OWNER : self::VIEW_TENANT;
        foreach (self::TARGETS as $key) {
            if (isset($target[$key]) && $target[$key] !== false && $target[$key] !== null && $target[$key] !== '') {
                $query[$key] = $key === 'docs' ? 1 : (int) $target[$key];
            }
        }

        return url('/portal') . '?' . http_build_query($query);
    }

    /**
     * For code that has NO recipient (an old test, a preview). Opens the portal on the sign-in with no one named - exactly the link
     * this class exists to avoid, so real sends never use it: they pass the person they are writing to.
     */
    public static function unaddressed(string $view, array $target = []): string
    {
        return self::compose(null, $view, $target);
    }

    /**
     * Read a signed recipient reference. Null for anything that is not exactly what {@see self::build()} made.
     *
     * @return array{contact_id:int, email:string}|null
     */
    public static function parse(mixed $ref): ?array
    {
        if (! is_string($ref) || $ref === '' || strlen($ref) > 600 || substr_count($ref, '.') !== 1) {
            return null;
        }
        [$payload, $sig] = explode('.', $ref, 2);
        if (! hash_equals(self::sign($payload), $sig)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $data = $json ? json_decode($json, true) : null;
        if (! is_array($data) || ! isset($data['e']) || ! is_string($data['e']) || ! preg_match('/^[^\s@]+@[^\s@]+$/', $data['e'])) {
            return null;
        }

        return ['contact_id' => (int) ($data['c'] ?? 0), 'email' => strtolower($data['e'])];
    }

    /** The view a mail to a contact with this role in the rental should open. */
    public static function viewForRole(string $role): string
    {
        return in_array(strtolower($role), ['landlord', 'owner', 'lessor'], true) ? self::VIEW_OWNER : self::VIEW_TENANT;
    }

    private static function build(string $email, string $view, array $target, int $contactId): string
    {
        $email = strtolower(trim($email));
        $ref = null;
        if (preg_match('/^[^\s@]+@[^\s@]+$/', $email)) {
            $payload = rtrim(strtr(base64_encode(json_encode(['c' => $contactId, 'e' => $email])), '+/', '-_'), '=');
            $ref = $payload . '.' . self::sign($payload);
        }

        return self::compose($ref, $view, $target);
    }

    private static function compose(?string $ref, string $view, array $target): string
    {
        $query = [];
        if ($ref) {
            $query['r'] = $ref;
        }
        $query['as'] = $view === self::VIEW_OWNER ? self::VIEW_OWNER : self::VIEW_TENANT;
        foreach (self::TARGETS as $key) {
            if (isset($target[$key]) && $target[$key] !== false && $target[$key] !== null && $target[$key] !== '') {
                $query[$key] = $key === 'docs' ? 1 : (int) $target[$key];
            }
        }

        return url('/portal') . '?' . http_build_query($query);
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'portal-link|' . $payload, (string) config('app.key')), 0, 32);
    }
}
