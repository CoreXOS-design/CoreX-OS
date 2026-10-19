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
 *   r    a SEALED recipient reference: the contact id and the address the mail was written to, encrypted with the app key and carrying its
 *        own expiry (the agency's `portal_link_days` setting, default 60). Opaque - the address is not readable in the URL - not forgeable,
 *        and not reusable for ever (audit 2026-10-19 D8). The page reads it SERVER-side, so the sign-in is pre-filled for that person, the
 *        "this link is for somebody else" card can name them, and the agency's header shows before sign-in. An EXPIRED reference is
 *        simply not recognised: the portal still opens, the person just signs in as normal. References sealed before this change (an
 *        unencrypted payload plus an HMAC, never expiring) are still read for the pre-fill - flagged `legacy` - but sign nobody in.
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
    public const TARGETS = ['fault', 'wo', 'insp', 'lease', 'docs', 'handling'];

    /** A link for a known contact (the usual case: the mail is going to $contact->email). */
    public static function forContact(Contact $contact, string $view, array $target = []): string
    {
        return self::build((string) $contact->email, $view, $target, (int) $contact->id, $contact->agency_id ? (int) $contact->agency_id : null);
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
            $query['r'] = self::seal($email, max(0, $contactId), null);
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
     * @return array{contact_id:int, email:string, legacy?:bool}|null
     */
    public static function parse(mixed $ref): ?array
    {
        if (! is_string($ref) || $ref === '' || strlen($ref) > 1200 || substr_count($ref, '.') !== 1) {
            return null;
        }
        if (str_starts_with($ref, self::SEALED_PREFIX)) {
            return self::unseal(substr($ref, strlen(self::SEALED_PREFIX)));
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

        // a reference sealed before encryption and expiry existed: recognised for the pre-fill only
        return ['contact_id' => (int) ($data['c'] ?? 0), 'email' => strtolower($data['e']), 'legacy' => true];
    }

    /** The view a mail to a contact with this role in the rental should open. */
    public static function viewForRole(string $role): string
    {
        return in_array(strtolower($role), ['landlord', 'owner', 'lessor'], true) ? self::VIEW_OWNER : self::VIEW_TENANT;
    }

    private static function build(string $email, string $view, array $target, int $contactId, ?int $agencyId = null): string
    {
        $email = strtolower(trim($email));
        $ref = null;
        if (preg_match('/^[^\s@]+@[^\s@]+$/', $email)) {
            $ref = self::seal($email, $contactId, $agencyId);
        }

        return self::compose($ref, $view, $target);
    }

    /** Marks a sealed (encrypted, expiring) reference; anything else is the older HMAC form. */
    private const SEALED_PREFIX = 'e1.';

    /**
     * Seal a recipient reference: the contact and the address, encrypted with the app key, with the moment it stops being recognised inside
     * the ciphertext. The lifetime is the agency's `portal_link_days` (the contact's agency, looked up from the contact when the caller has
     * no agency id; the platform default when there is neither).
     */
    private static function seal(string $email, int $contactId, ?int $agencyId): string
    {
        $agencyId ??= self::agencyIdOfContact($contactId);
        $days = \App\Models\RentalPortalSetting::portalLinkDaysFor($agencyId);
        $json = json_encode(['c' => $contactId, 'e' => $email, 'x' => now()->addDays($days)->timestamp]);

        return self::SEALED_PREFIX . rtrim(strtr(\Illuminate\Support\Facades\Crypt::encryptString($json), '+/', '-_'), '=');
    }

    /** @return array{contact_id:int, email:string}|null null when not sealed by this app, tampered with, malformed or expired */
    private static function unseal(string $sealed): ?array
    {
        $b64 = strtr($sealed, '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        try {
            $data = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($b64), true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data) || ! isset($data['e'], $data['x']) || ! is_string($data['e']) || ! is_numeric($data['x'])
            || ! preg_match('/^[^\s@]+@[^\s@]+$/', $data['e']) || (int) $data['x'] < now()->timestamp) {
            return null;
        }

        return ['contact_id' => (int) ($data['c'] ?? 0), 'email' => strtolower($data['e'])];
    }

    /** @var array<int, ?int> contact id => agency id, per request */
    private static array $agencyOfContact = [];

    private static function agencyIdOfContact(int $contactId): ?int
    {
        if ($contactId < 1) {
            return null;
        }

        return self::$agencyOfContact[$contactId] ??= (($id = Contact::withoutGlobalScopes()->whereKey($contactId)->value('agency_id')) ? (int) $id : null);
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
