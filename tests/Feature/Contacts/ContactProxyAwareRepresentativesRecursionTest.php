<?php

namespace Tests\Feature\Contacts;

use App\Exceptions\UnresolvableRepresentativeChainException;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Job 1 fast-follow (Johan/cc1, 2026-08-26) — Contact::proxyAwareRepresentatives()'s
 * recursion (added 3afc42c42) gated recursion strictly on $rep->isEntity(), so a
 * NATURAL-PERSON representative was always treated as a recursion leaf, even one
 * who is themselves represented by someone else. Two silent failures resulted,
 * both flagged by cc1's own reproduction:
 *   - A natural-person-to-natural-person cycle (A represented by B, B represented
 *     by A) resolved silently instead of throwing cycleDetected() — recursion
 *     stopped at B before ever walking back to A, so the cycle check never ran.
 *   - A natural-person-only multi-hop chain (A→B→C) truncated to one hop (just B)
 *     instead of resolving through to C, the real bottom of the chain.
 * Fixed by recursing whenever a representative has ANY representative of their
 * own (entity OR natural person), not only when isEntity() is true. No dedicated
 * unit test existed for this method's recursion before this file — the prior
 * commit's proof was a live/rolled-back tinker run, not a committed, re-runnable
 * test (see Job 2's evidence-integrity finding, 2026-08-26).
 */
final class ContactProxyAwareRepresentativesRecursionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $this->agencyId = (int) $agency->id;
        $this->branchId = (int) \Illuminate\Support\Facades\DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makePerson(string $first): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => $first, 'last_name' => 'Test' . uniqid(),
        ]);
    }

    private function makeEntity(string $name): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_ENTITY,
            'entity_name' => $name, 'first_name' => $name, 'last_name' => '',
        ]);
    }

    private function link(Contact $entityOrParty, Contact $rep, string $capacity = 'Power of Attorney'): void
    {
        ContactRepresentative::create([
            'entity_contact_id' => $entityOrParty->id,
            'representative_contact_id' => $rep->id,
            'capacity' => $capacity,
        ]);
    }

    public function test_natural_person_to_natural_person_cycle_throws(): void
    {
        $a = $this->makePerson('A');
        $b = $this->makePerson('B');
        $this->link($a, $b); // A represented by B
        $this->link($b, $a); // B represented by A — cycle

        $this->expectException(UnresolvableRepresentativeChainException::class);
        $a->fresh()->signingRepresentatives();
    }

    public function test_natural_person_to_natural_person_cycle_error_names_fixing_the_links(): void
    {
        $a = $this->makePerson('A');
        $b = $this->makePerson('B');
        $this->link($a, $b);
        $this->link($b, $a);

        try {
            $a->fresh()->signingRepresentatives();
            $this->fail('Expected UnresolvableRepresentativeChainException.');
        } catch (UnresolvableRepresentativeChainException $e) {
            $this->assertStringContainsString('Fix the representative links before re-sending.', $e->getMessage());
        }
    }

    /** A→B→C, all natural persons, C has nobody representing them — must resolve to C, not truncate at B. */
    public function test_natural_person_only_three_hop_chain_resolves_fully(): void
    {
        $a = $this->makePerson('A');
        $b = $this->makePerson('B');
        $c = $this->makePerson('C');
        $this->link($a, $b); // A represented by B
        $this->link($b, $c); // B represented by C

        $signers = $a->fresh()->signingRepresentatives();

        $this->assertCount(1, $signers, 'The chain must resolve through to the real bottom signer, not stop at B.');
        $this->assertSame($c->id, $signers->first()->id);
    }

    /** Baseline: a natural-person rep with nobody representing THEM is still a plain leaf. */
    public function test_natural_person_representative_with_no_further_rep_is_still_a_leaf(): void
    {
        $a = $this->makePerson('A');
        $b = $this->makePerson('B');
        $this->link($a, $b);

        $signers = $a->fresh()->signingRepresentatives();

        $this->assertCount(1, $signers);
        $this->assertSame($b->id, $signers->first()->id);
    }

    /** Regression: the original mixed shape (natural person → entity → natural person) still resolves. */
    public function test_natural_person_represented_by_entity_represented_by_natural_person_resolves(): void
    {
        $piet = $this->makePerson('Piet');
        $estate = $this->makeEntity('Late Estate of Piet');
        $koos = $this->makePerson('Koos');
        $this->link($piet, $estate);
        $this->link($estate, $koos);

        $signers = $piet->fresh()->signingRepresentatives();

        $this->assertCount(1, $signers);
        $this->assertSame($koos->id, $signers->first()->id);
    }

    /** Regression: an entity rep with no representative of its own must still refuse, not silently leaf out. */
    public function test_entity_representative_with_no_representative_of_its_own_still_throws(): void
    {
        $piet = $this->makePerson('Piet');
        $estate = $this->makeEntity('Late Estate of Piet, unrepresented');
        $this->link($piet, $estate); // estate has no representative of its own

        $this->expectException(UnresolvableRepresentativeChainException::class);
        $piet->fresh()->signingRepresentatives();
    }
}
