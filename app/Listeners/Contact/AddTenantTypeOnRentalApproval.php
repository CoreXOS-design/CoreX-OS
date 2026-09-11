<?php

declare(strict_types=1);

namespace App\Listeners\Contact;

use App\Events\RentalApplication\RentalApplicationApproved;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\RentalApplicationQualifyingSetting;
use Illuminate\Support\Facades\Log;

/**
 * AT-392 contact-type ruling, 2026-09-11 — Johan, verbatim: "contact type
 * can be added, not changed. the scenario exists where a seller or any
 * contact type can become a tenant. the scenario exists that the seller of
 * unit a decides to rent but their property has not sold yet. so that
 * contact will be dealt with as a seller on their property but also as a
 * tenant inside rentals." An approved rental application ADDS Tenant to
 * the contact's existing types — never replaces, never clears.
 *
 * Deliberately the SAME mechanism as PromoteOwnerToSellerOnPropertyLink
 * (Johan: "find how the existing auto-tag-on-link behaviour does it and
 * follow that mechanism rather than writing a second, parallel one") —
 * this is that mechanism used as a pure ADD instead of a swap: compute the
 * contact's current effective parent-type set, add Tenant if absent, sync
 * the union back. Idempotent by construction — a contact already carrying
 * Tenant is left untouched, including its sub-tags.
 *
 * Wired by an EXPLICIT `Event::listen()` in AppServiceProvider::register()
 * (the "AT-392" block, next to RecomputeRentalApplicationStatus's own
 * registration) — NOT by auto-discovery. AT-261 turned discovery off
 * agency-wide after every listener fired twice in production; the explicit
 * catalogue in boot() is the only thing that wires any listener up now.
 * (PromoteOwnerToSellerOnPropertyLink's own docblock still claims
 * discovery wires it — that comment is stale post-AT-261; it too is
 * actually registered explicitly, a few lines above this one's own entry.
 * Confirmed by reading the code, not by trusting the comment — that
 * comment is exactly what led this listener to be built with no
 * registration at all on the first pass.) Failure-isolated: a problem
 * here must never break the approval itself.
 *
 * Only fires on RentalApplicationApproved — decline/withdrawal never tag
 * anything (Johan: "my read is that only approval tags"; nothing in the
 * existing auto-tag-on-link pattern ties a type to an outcome that didn't
 * happen — a declined or withdrawn applicant never became a tenant).
 * Whether approval tags at all is agency-configurable
 * (RentalApplicationQualifyingSetting::tagContactAsTenantOnApprovalFor()),
 * default on.
 */
class AddTenantTypeOnRentalApproval
{
    public function handle(RentalApplicationApproved $event): void
    {
        try {
            if (! RentalApplicationQualifyingSetting::tagContactAsTenantOnApprovalFor((int) $event->application->agency_id)) {
                return;
            }

            $contactId = $event->application->contact_id;
            if (! $contactId) {
                return;
            }

            $contact = Contact::withoutGlobalScopes()->find($contactId);
            if (! $contact) {
                return;
            }

            $tenant = ContactType::query()->where('name', 'Tenant')->first();
            if (! $tenant) {
                return;
            }
            $tenantId = (int) $tenant->id;

            // Effective parent set = the multi-parent pivot plus the primary
            // mirror (covers writer-created contacts whose type is
            // mirror-only) — same computation as
            // PromoteOwnerToSellerOnPropertyLink.
            $effective = $contact->parentTypes()->pluck('contact_types.id')->map(fn ($i) => (int) $i)->all();
            if ($contact->contact_type_id) {
                $effective[] = (int) $contact->contact_type_id;
            }
            $effective = array_values(array_unique($effective));

            if (in_array($tenantId, $effective, true)) {
                return; // already a Tenant — idempotent, nothing to do
            }

            // ADD, never remove — every existing parent stays exactly as it
            // was; Tenant is appended to the set.
            $newParents = array_values(array_unique(array_merge($effective, [$tenantId])));

            // Every existing sub-tag is preserved untouched — this is an
            // addition, not a re-sync of the whole type/tag picture.
            $keepTagIds = $contact->tags()->pluck('contact_tags.id')->map(fn ($i) => (int) $i)->all();

            $contact->syncTypeAssignments($newParents, $keepTagIds);
        } catch (\Throwable $e) {
            Log::warning('AddTenantTypeOnRentalApproval failed', [
                'rental_application_id' => $event->application->id ?? null,
                'contact_id' => $event->application->contact_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
