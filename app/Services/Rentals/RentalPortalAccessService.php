<?php

namespace App\Services\Rentals;

use App\Support\PortalLink;
use App\Exceptions\Rentals\PortalAccessException;
use App\Mail\Rentals\RentalPortalInviteMail;
use App\Models\Agency;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\RentalPortalSetting;
use App\Models\User;
use App\Services\ClientAuthService;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-portal-access.md §16 — the ONE place a contact is given, shown and sent tenant / landlord
 * portal access from the agent's side (the lease screen cards, the contact page button, lease signing).
 *
 * A person is ONE login: a ClientUser is one account per email address, and any number of that person's
 * contacts point at it. "Create client login" therefore never fails because the login (or the contact's own
 * email) already exists — it attaches this contact to the login for that email, after confirming the email
 * really belongs to this person: it is on this contact, or on another contact in this agency, or the login
 * is already tied to a contact in this agency. An email that belongs to someone outside the agency is refused
 * in plain words (never a bare "already in use") so one contact's portal can never be opened by a stranger.
 *
 * The portal's own first-time access (email → code → choose a password, ClientAuthService::findOrCreateClientUser)
 * is untouched; this service only makes the agent-side half work and adds the link/invite.
 */
class RentalPortalAccessService
{
    public const ROLE_TENANT = 'tenant';
    public const ROLE_LANDLORD = 'landlord';

    /** What the web portal really offers each role today (shell.blade.php tabs) — nothing promised beyond this. */
    public const OFFERS = [
        self::ROLE_TENANT => 'view your lease and the property, report a fault and follow it through to a fix, and see your documents',
        self::ROLE_LANDLORD => 'approve or decline repair decisions, see your properties and tenancy, and follow faults and jobs',
    ];

    public function __construct(private readonly ClientAuthService $auth)
    {
    }

    /** Portal switched on for this audience in this agency (the Rentals features switch AND the agency's own tenant / landlord toggle). */
    public function enabledFor(?int $agencyId, string $role): bool
    {
        if (! $agencyId) {
            return false;
        }

        $on = $role === self::ROLE_LANDLORD
            ? RentalPortalSetting::landlordPortalEnabledFor($agencyId)
            : RentalPortalSetting::tenantPortalEnabledFor($agencyId);
        if (! $on) {
            return false;
        }

        $agency = Agency::withoutGlobalScopes()->find($agencyId);

        return $agency !== null && app(AgencyFeatureService::class)->enabled('rentals', $agency);
    }

    /**
     * Give $contact a portal login for $email (default: the contact's own email), or attach it to the one that
     * already exists. Idempotent. Throws PortalAccessException with a plain-words reason when it must not.
     *
     * @return array{outcome:string, client_user:ClientUser, message:string}  outcome: created | attached | already | switched
     */
    public function attach(Contact $contact, ?string $email = null, ?User $actor = null, ?Request $request = null, ?string $tempPassword = null): array
    {
        $email = strtolower(trim((string) ($email ?? $contact->email)));
        $name = $this->nameOf($contact);

        if ($email === '') {
            throw new PortalAccessException("{$name} has no email address saved yet. Add one to the contact first — a portal login is the person's email.", 'no_email');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalAccessException('That does not look like a valid email address.', 'invalid_email');
        }

        $current = $contact->client_user_id ? ClientUser::find($contact->client_user_id) : null;
        if ($current && strtolower((string) $current->email) === $email) {
            return ['outcome' => 'already', 'client_user' => $current, 'message' => "Portal access is already set up for {$email}."];
        }

        $isPlaceholder = $this->isPlaceholderEmail($email);
        $onThisAgency = ! $isPlaceholder && $this->contactIdsWithEmail((int) $contact->agency_id, $email) !== [];
        $login = ClientUser::where('email', $email)->first();

        if ($login) {
            $tiedHere = Contact::withoutGlobalScopes()
                ->where('agency_id', $contact->agency_id)->whereNull('deleted_at')
                ->where('client_user_id', $login->id)->exists();

            if (! $onThisAgency && ! $tiedHere) {
                throw new PortalAccessException(
                    $login->isAgencyManaged()
                        ? 'That login belongs to a client managed by another agency, so it cannot be attached here. Use the email address saved on this contact instead.'
                        : "That email already has a CoreX portal login that belongs to someone who is not on your contact list for {$name}, so it cannot be attached here. Check the email on the contact, or ask {$name} which address they use.",
                    'belongs_to_someone_else'
                );
            }
        } elseif (! $isPlaceholder && ! $onThisAgency) {
            throw new PortalAccessException(
                "That email is not saved on {$name}'s contact. Add it to the contact first, so the login always belongs to the person on file.",
                'email_not_on_contact'
            );
        }

        $outcome = DB::transaction(function () use ($contact, $email, $current, &$login, $tempPassword) {
            $switched = false;
            if ($current && $current->id !== $login?->id) {
                $this->unlink($contact, $current);
                $switched = true;
            }

            $created = false;
            if (! $login) {
                $login = ClientUser::create([
                    'email' => $email,
                    'password' => $tempPassword ? Hash::make($tempPassword) : null,
                    'password_must_change' => ! empty($tempPassword),
                    'password_set_at' => $tempPassword ? now() : null,
                    'created_by_agency_id' => $contact->agency_id,
                    'current_agency_id' => $contact->agency_id,   // a new login starts IN its agency (without it every portal call answered "Select an agency first")
                ]);
                $created = true;
            }

            $contact->forceFill(['client_user_id' => $login->id])->saveQuietly();

            return $switched ? 'switched' : ($created ? 'created' : 'attached');
        });

        $this->auth->log($login, (int) $contact->agency_id, $contact->id, $tempPassword ? 'password_set' : 'lookup', $request ?? request(), [
            'source' => 'agent_' . $outcome,
            'agent_user_id' => $actor?->id,
        ]);

        $message = match ($outcome) {
            'created' => $tempPassword
                ? "Portal access created for {$email}. Share the temporary password with {$name} securely."
                : "Portal access set up for {$email}. {$name} confirms the email with a code the first time they open the link.",
            'switched' => "Portal login for {$name} moved to {$email}.",
            default => "{$name} is now on the existing portal login for {$email}.",
        };

        return ['outcome' => $outcome, 'client_user' => $login, 'message' => $message];
    }

    /** The contact's email has changed (or the login is a placeholder): move this contact to a login on the email now saved. */
    public function switchToContactEmail(Contact $contact, ?User $actor = null, ?Request $request = null): array
    {
        $email = strtolower(trim((string) $contact->email));
        if ($email === '') {
            throw new PortalAccessException($this->nameOf($contact) . ' has no email address saved yet. Add one to the contact first.', 'no_email');
        }

        return $this->attach($contact, $email, $actor, $request);
    }

    /**
     * Where a contact stands, for the lease screen cards.
     *
     * @return array{state:string,label:string,tone:string,login_email:?string,contact_email:?string,url:?string,enabled:bool,managed_by:?string,can_share:bool,can_switch:bool,can_set_up:bool}
     */
    public function status(Contact $contact, string $role): array
    {
        $enabled = $this->enabledFor((int) $contact->agency_id, $role);
        $contactEmail = trim((string) $contact->email) !== '' ? strtolower(trim((string) $contact->email)) : null;
        $login = $contact->client_user_id ? ClientUser::find($contact->client_user_id) : null;

        $base = [
            'state' => 'not_set_up', 'label' => 'Not set up', 'tone' => 'muted',
            'login_email' => $login?->email, 'contact_email' => $contactEmail, 'url' => null,
            'enabled' => $enabled, 'managed_by' => null,
            'can_share' => false, 'can_switch' => false, 'can_set_up' => false,
        ];

        if (! $enabled) {
            return ['state' => 'disabled', 'label' => 'Portal access is switched off', 'tone' => 'muted'] + $base;
        }

        if (! $login) {
            if ($contactEmail === null) {
                return ['state' => 'no_email', 'label' => 'No email on the contact'] + $base;
            }

            return ['can_set_up' => true, 'url' => $this->portalUrl($contactEmail, PortalLink::viewForRole($role))] + $base;
        }

        $managedBy = null;
        if ($login->isAgencyManaged() && $login->created_by_agency_id && (int) $login->created_by_agency_id !== (int) $contact->agency_id) {
            $managedBy = Agency::withoutGlobalScopes()->whereKey($login->created_by_agency_id)->value('name') ?: 'another agency';
        }

        $differs = $contactEmail !== null && ! $this->isPlaceholderEmail($contactEmail) && strtolower((string) $login->email) !== $contactEmail;
        $placeholderLogin = $login->isAgencyManaged() && ! $login->hasPassword();

        if ($differs && $managedBy === null) {
            return [
                'state' => 'email_changed',
                'label' => $placeholderLogin ? 'Login is a placeholder address' : 'Contact email changed',
                'tone' => 'warning', 'can_switch' => true, 'url' => $this->portalUrl($login->email, PortalLink::viewForRole($role)),
            ] + $base;
        }

        [$state, $label, $tone] = match (true) {
            (bool) $login->password_must_change => ['must_change', 'Must change password', 'warning'],
            $login->hasPassword() => ['active', 'Active', 'success'],
            default => ['pending', 'Pending OTP', 'info'],
        };

        return [
            'state' => $state, 'label' => $label, 'tone' => $tone, 'managed_by' => $managedBy,
            'can_share' => true, 'url' => $this->portalUrl($login->email, PortalLink::viewForRole($role)),
        ] + $base;
    }

    /** The personal link: the portal, with the person's email already filled in and the side it is for (the code is still what proves it is them). Built by PortalLink. */
    public function portalUrl(string $email, string $view = PortalLink::VIEW_TENANT, array $target = []): string
    {
        return PortalLink::personal($email, $view, $target);
    }

    /**
     * Email the person their portal link — setting access up first when it does not exist yet. The email goes to
     * the address on the contact, through the sending agent's own mailbox path like every rental mail.
     */
    public function sendInvite(Contact $contact, string $role, User $agent, ?Lease $lease = null, ?Request $request = null): string
    {
        $name = $this->nameOf($contact);
        if (! $this->enabledFor((int) $contact->agency_id, $role)) {
            throw new PortalAccessException('Portal access is switched off for ' . $role . 's in your agency settings.', 'disabled');
        }

        $result = $this->attach($contact, null, $agent, $request);
        $login = $result['client_user'];
        if ($login->isAgencyManaged()) {
            throw new PortalAccessException("{$name}'s login is a placeholder address that cannot receive email. Switch it to their real email first.", 'placeholder_login');
        }

        $roles = $lease ? $this->rolesOnLease($lease, $contact) : [$role];
        app(RentalMailDispatcher::class)->send(
            $contact->email,
            (new RentalPortalInviteMail($contact, $roles ?: [$role], $this->portalUrl($login->email, PortalLink::viewForRole($role)), $agent))->fromAgent($agent)
        );

        $this->auth->log($login, (int) $contact->agency_id, $contact->id, 'portal_invite_sent', $request ?? request(), [
            'agent_user_id' => $agent->id, 'lease_id' => $lease?->id,
        ]);

        return "Portal link emailed to {$contact->email}.";
    }

    /** Which portal audience(s) this contact is on this lease: tenant, landlord, or both. */
    public function rolesOnLease(Lease $lease, Contact $contact): array
    {
        $roles = [];
        if ($lease->tenantContacts()->contains('id', $contact->id)) {
            $roles[] = self::ROLE_TENANT;
        }
        if ($lease->landlordContacts()->contains('id', $contact->id)) {
            $roles[] = self::ROLE_LANDLORD;
        }

        return $roles;
    }

    /**
     * When a lease is signed: give each of its tenants and landlords portal access, from the email on their
     * contact — if the agency has automatic access switched on and that audience's portal is on. A person
     * without an email, or whose email cannot be attached, is skipped (logged) — signing is never undone by this.
     *
     * @return array<int,string>  contact id => outcome (created | attached | already | switched | skipped:<reason>)
     */
    public function provisionForSignedLease(Lease $lease): array
    {
        $out = [];
        if (! RentalPortalSetting::autoPortalAccessOnSigningFor((int) $lease->agency_id)) {
            return $out;
        }

        $lease->loadMissing(['tenants.contact', 'property']);
        $parties = [self::ROLE_TENANT => $lease->tenantContacts(), self::ROLE_LANDLORD => $lease->landlordContacts()];
        foreach ($parties as $role => $contacts) {
            if (! $this->enabledFor((int) $lease->agency_id, $role)) {
                continue;
            }
            foreach ($contacts as $contact) {
                try {
                    $out[$contact->id] = $this->attach($contact)['outcome'];
                } catch (PortalAccessException $e) {
                    $out[$contact->id] = 'skipped:' . $e->reason;
                } catch (\Throwable $e) {
                    Log::warning('Portal access on signing failed for a contact', ['lease_id' => $lease->id, 'contact_id' => $contact->id, 'error' => $e->getMessage()]);
                    $out[$contact->id] = 'skipped:error';
                }
            }
        }

        return $out;
    }

    /**
     * The "Your CoreX portal" block for a signed-lease copy going to $signerEmail, or null when it must not appear:
     * the agency has automatic access off, the signer is not a tenant / landlord of the lease, that audience's portal
     * is off, or there is no real email to log in with.
     *
     * @return array{url:string, roles:array<int,string>, offers:array<int,string>}|null
     */
    public function mailBlockForLease(Lease $lease, string $signerEmail): ?array
    {
        $email = strtolower(trim($signerEmail));
        if ($email === '' || $this->isPlaceholderEmail($email)
            || ! RentalPortalSetting::autoPortalAccessOnSigningFor((int) $lease->agency_id)) {
            return null;
        }

        $lease->loadMissing(['tenants.contact', 'property']);
        $roles = [];
        foreach ([self::ROLE_TENANT => $lease->tenantContacts(), self::ROLE_LANDLORD => $lease->landlordContacts()] as $role => $contacts) {
            if (! $this->enabledFor((int) $lease->agency_id, $role)) {
                continue;
            }
            if ($contacts->contains(fn (Contact $c) => $this->contactCarriesEmail($c, $email))) {
                $roles[] = $role;
            }
        }
        if ($roles === []) {
            return null;
        }

        return [
            // a MAIL: the person, the side it is for, opening on this lease
            'url' => $this->portalUrl($email, PortalLink::viewForRole($roles[0]), ['lease' => $lease->id]),
            'roles' => $roles,
            'offers' => array_map(fn (string $r) => self::OFFERS[$r], $roles),
        ];
    }

    public function isPlaceholderEmail(string $email): bool
    {
        $domain = ltrim((string) config('clientauth.fake_email_domain', 'corexclient.co.za'), '@');

        return str_ends_with(strtolower(trim($email)), '@' . strtolower($domain));
    }

    /** Contacts in the agency carrying this email — on the contact itself or on any of its saved emails. */
    private function contactIdsWithEmail(int $agencyId, string $email): array
    {
        $mirror = DB::table('contacts')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereRaw('LOWER(email) = ?', [$email])->pluck('id');
        // The agency is read from the CONTACT, not from the email row's own copy of it.
        $child = DB::table('contact_emails')
            ->join('contacts', 'contacts.id', '=', 'contact_emails.contact_id')
            ->where('contacts.agency_id', $agencyId)->whereNull('contacts.deleted_at')
            ->whereNull('contact_emails.deleted_at')
            ->where('contact_emails.email_normalised', $email)
            ->pluck('contact_emails.contact_id');

        return $mirror->merge($child)->unique()->values()->all();
    }

    private function contactCarriesEmail(Contact $contact, string $email): bool
    {
        return in_array($contact->id, $this->contactIdsWithEmail((int) $contact->agency_id, $email), true);
    }

    /** Detach a contact from its old login; the login itself goes only when nobody else is on it and it is ours to remove. */
    private function unlink(Contact $contact, ClientUser $old): void
    {
        $contact->forceFill(['client_user_id' => null])->saveQuietly();

        $stillLinked = Contact::withoutGlobalScopes()->where('client_user_id', $old->id)->exists();
        $oursToRemove = ! $old->isAgencyManaged()
            || $old->created_by_agency_id === null
            || (int) $old->created_by_agency_id === (int) $contact->agency_id;

        if (! $stillLinked && $oursToRemove) {
            $old->tokens()->delete();
            $old->delete();
        }
    }

    private function nameOf(Contact $contact): string
    {
        return trim((string) $contact->full_name) ?: ('Contact #' . $contact->id);
    }
}
