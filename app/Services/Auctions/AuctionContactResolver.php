<?php

namespace App\Services\Auctions;

use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Services\ContactDuplicateService;
use Illuminate\Support\Facades\DB;

/**
 * Match-or-create the Contact (pillar) behind a public auction-site visitor — buyer sign-up and enquiries both use it,
 * so a person is never minted twice. Public requests have no staff user, and a staff member browsing the same site
 * must not stamp the new row into THEIR agency, so every write here goes through the trusted-ingress helpers.
 */
class AuctionContactResolver
{
    public const SOURCE_NAME = 'Auction site';

    public function __construct(private ContactDuplicateService $duplicates) {}

    /**
     * @return array{0: Contact, 1: bool} the contact and whether it already existed
     */
    public function resolve(int $agencyId, string $name, ?string $email, ?string $phone, ?int $agentId = null, ?int $clientUserId = null, ?int $branchId = null): array
    {
        $existing = null;
        if ($email || $phone) {
            $existing = $this->duplicates->findDuplicates(['email' => $email, 'phone' => $phone], $agencyId)->first();
        }
        if ($existing) {
            return [$existing, true];
        }

        [$first, $last] = $this->splitName($name);
        $typeId = ContactType::query()->where('name', 'Buyer')->value('id') ?? ContactType::query()->where('name', 'Lead')->value('id');
        $sourceId = $this->sourceId($agencyId);
        // Always an explicit branch OF THIS AGENCY: otherwise BelongsToBranch would copy the branch of whichever staff member
        // happens to be browsing the public site (possibly another agency's) onto the new contact.
        $branchId = $this->branchFor($agencyId, $branchId);

        $contact = Contact::withoutAgencyStamping(function () use ($agencyId, $first, $last, $email, $phone, $typeId, $sourceId, $agentId, $clientUserId, $branchId) {
            return DB::transaction(function () use ($agencyId, $first, $last, $email, $phone, $typeId, $sourceId, $agentId, $clientUserId, $branchId) {
                $c = new Contact([
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'phone' => $phone,
                    'contact_type_id' => $typeId,
                    'contact_source_id' => $sourceId,
                    'created_by_user_id' => $agentId,
                    'client_user_id' => $clientUserId,
                    'agency_id' => $agencyId,
                    'branch_id' => $branchId,
                    'notes' => 'Auto-created from the public auction site.',
                ]);
                $c->agency_id = $agencyId;
                $c->save();

                return $c;
            });
        });

        return [$contact, false];
    }

    private function sourceId(int $agencyId): int
    {
        $existing = ContactSource::queryWithoutAgencyScope()->where('agency_id', $agencyId)->where('name', self::SOURCE_NAME)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) ContactSource::withoutAgencyStamping(fn () => ContactSource::create([
            'agency_id' => $agencyId, 'name' => self::SOURCE_NAME, 'color' => '#0ea5e9', 'sort_order' => 22, 'is_active' => true,
        ]))->id;
    }

    /** The given branch if it belongs to the agency, else the agency's first (head office) branch, else null. */
    private function branchFor(int $agencyId, ?int $branchId): ?int
    {
        $q = \App\Models\Branch::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at');
        if ($branchId && (clone $q)->whereKey($branchId)->exists()) {
            return $branchId;
        }

        return $q->orderBy('id')->value('id');
    }

    /** @return array{0: string, 1: string} */
    public function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return ['Auction', 'visitor'];
        }
        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
