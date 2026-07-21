<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect;

use App\Models\Docuperfect\SignatureRequest;
use PHPUnit\Framework\TestCase;

/**
 * AT-324/AT-325 — the canonical per-recipient key is the ONE mapping from a
 * SignatureRequest (base party_role + role_index) to the composite key every
 * other e-sign surface uses (signing_order_json / parties_json / partyProgress /
 * signed_initials). Bare = index 1; the Nth same-role recipient = role_N. This is
 * what stops a signed 2nd co-seller being misread as the next signer.
 */
final class SignatureRequestCanonicalKeyTest extends TestCase
{
    private function req(string $role, ?int $index): SignatureRequest
    {
        $r = new SignatureRequest();
        $r->party_role = $role;
        $r->role_index = $index;

        return $r;
    }

    public function test_first_of_role_is_the_bare_role(): void
    {
        $this->assertSame('seller', $this->req('seller', 1)->canonicalPartyKey());
        $this->assertSame('agent', $this->req('agent', 1)->canonicalPartyKey());
    }

    public function test_second_and_later_same_role_carry_the_index(): void
    {
        $this->assertSame('seller_2', $this->req('seller', 2)->canonicalPartyKey());
        $this->assertSame('landlord_3', $this->req('landlord', 3)->canonicalPartyKey());
    }

    public function test_missing_role_index_defaults_to_bare_role(): void
    {
        // A legacy row with no role_index behaves as the first (bare) recipient.
        $this->assertSame('buyer', $this->req('buyer', null)->canonicalPartyKey());
    }

    public function test_matches_signing_order_keys_for_a_two_seller_doc(): void
    {
        // Reproduces doc 452: order = [agent, seller, seller_2].
        $requests = [
            $this->req('agent', 1),
            $this->req('seller', 1),
            $this->req('seller', 2),
        ];
        $keys = array_map(fn ($r) => $r->canonicalPartyKey(), $requests);
        $this->assertSame(['agent', 'seller', 'seller_2'], $keys);
    }
}
