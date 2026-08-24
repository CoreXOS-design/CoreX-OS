<?php

namespace Tests\Feature\Contacts;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entity-rep SHARED FOUNDATION (Johan, 2026-08-15) — capacity + proxy on the
 * entity<->rep link, and the proxy-aware signing/email resolvers consumed by
 * esign + DR2. Spec: .ai/specs/contact-entity-type.md §6.
 */
class ContactRepresentativeCapacityProxyTest extends TestCase
{
    use RefreshDatabase;

    private function makeEntityWithReps(int $repCount): array
    {
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $branchId = \Illuminate\Support\Facades\DB::table('branches')->insertGetId([
            'agency_id' => $agency->id, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $entity = Contact::create([
            'agency_id'    => $agency->id,
            'branch_id'    => $branchId,
            'contact_kind' => Contact::TYPE_ENTITY,
            'entity_name'  => 'Estate Late John Smith',
            'first_name'   => 'Estate Late John Smith',
            'last_name'    => '',
        ]);

        $reps = [];
        for ($i = 1; $i <= $repCount; $i++) {
            $reps[] = Contact::create([
                'agency_id'    => $agency->id,
                'branch_id'    => $branchId,
                'contact_kind' => Contact::TYPE_NATURAL_PERSON,
                'first_name'   => 'Rep' . $i,
                'last_name'    => 'Person',
            ]);
        }

        foreach ($reps as $rep) {
            ContactRepresentative::create([
                'entity_contact_id'         => $entity->id,
                'representative_contact_id' => $rep->id,
                'capacity'                  => 'Director',
            ]);
        }

        return [$entity->fresh(), $reps];
    }

    public function test_no_proxy_all_reps_sign(): void
    {
        [$entity] = $this->makeEntityWithReps(3);

        $this->assertFalse($entity->hasProxyRepresentative());
        $this->assertCount(3, $entity->signingRepresentatives());
        $this->assertCount(3, $entity->emailRepresentatives());
    }

    public function test_proxy_narrows_to_single_signer(): void
    {
        [$entity, $reps] = $this->makeEntityWithReps(4);

        $entity->representatives()->updateExistingPivot($reps[1]->id, ['signs_as_proxy' => true]);
        $entity->refresh();

        $this->assertTrue($entity->hasProxyRepresentative());
        $signers = $entity->signingRepresentatives();
        $this->assertCount(1, $signers);
        $this->assertSame($reps[1]->id, $signers->first()->id);
        // email set mirrors signing set (the signer is the emailee)
        $this->assertCount(1, $entity->emailRepresentatives());
    }

    public function test_capacity_persists_per_link(): void
    {
        [$entity, $reps] = $this->makeEntityWithReps(2);

        $entity->representatives()->updateExistingPivot($reps[0]->id, ['capacity' => 'Executor']);
        $entity->refresh();

        $byId = $entity->representatives->keyBy('id');
        $this->assertSame('Executor', $byId[$reps[0]->id]->pivot->capacity);
        $this->assertSame('Director', $byId[$reps[1]->id]->pivot->capacity);
    }

    public function test_natural_person_has_no_signing_reps(): void
    {
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $branchId = \Illuminate\Support\Facades\DB::table('branches')->insertGetId([
            'agency_id' => $agency->id, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $person = Contact::create([
            'agency_id'    => $agency->id,
            'branch_id'    => $branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name'   => 'Jo',
            'last_name'    => 'Soap',
        ]);

        $this->assertFalse($person->hasProxyRepresentative());
        $this->assertTrue($person->signingRepresentatives()->isEmpty());
    }

    public function test_write_path_enforces_single_proxy(): void
    {
        [$entity, $reps] = $this->makeEntityWithReps(3);
        $agency = $entity->agency_id;

        $user = \App\Models\User::factory()->create(['agency_id' => $agency, 'role' => 'admin']);

        // Set rep0 proxy, then rep1 proxy via the controller — rep0 must be demoted.
        $this->actingAs($user)
            ->patch(route('corex.contacts.representatives.update', [$entity, $reps[0]]), ['signs_as_proxy' => 1])
            ->assertRedirect();
        $this->actingAs($user)
            ->patch(route('corex.contacts.representatives.update', [$entity, $reps[1]]), ['signs_as_proxy' => 1])
            ->assertRedirect();

        $entity->refresh();
        $proxies = $entity->representatives->filter(fn ($r) => $r->pivot->signs_as_proxy);
        $this->assertCount(1, $proxies, 'exactly one proxy after two proxy-sets');
        $this->assertSame($reps[1]->id, $proxies->first()->id);
        $this->assertCount(1, $entity->signingRepresentatives());
    }
}
