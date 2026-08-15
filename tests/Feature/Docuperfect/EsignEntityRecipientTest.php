<?php

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\Docuperfect\EsignRecipientPreset;
use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ESIGN recipient builder (Johan 2026-08-15) — entity/company recipient expands
 * to its proxy-aware signing rep(s) with "herein represented by" phrasing.
 */
class EsignEntityRecipientTest extends TestCase
{
    use RefreshDatabase;

    private function entityWithReps(int $agencyId, int $reps, ?int $proxyIdx = null): array
    {
        $entity = Contact::factory()->create(['agency_id' => $agencyId, 'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Estate Late John Smith', 'entity_reg_no' => 'EST-1']);
        $repModels = [];
        for ($i = 0; $i < $reps; $i++) {
            $r = Contact::factory()->create(['agency_id' => $agencyId, 'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Rep' . $i, 'last_name' => 'Person', 'email' => "rep{$i}@x.test"]);
            ContactRepresentative::create([
                'entity_contact_id' => $entity->id, 'representative_contact_id' => $r->id,
                'capacity' => 'Executor', 'signs_as_proxy' => ($proxyIdx === $i),
            ]);
            $repModels[] = $r;
        }
        return [$entity->fresh(), $repModels];
    }

    private function expand(array $recipients, User $user): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'expandEntityRecipients');
        $m->setAccessible(true);
        return $m->invoke(app(ESignWizardController::class), $recipients, $user);
    }

    public function test_phrasing_template_renders_and_collapses_empty_capacity(): void
    {
        $agency = Agency::factory()->create();
        [$entity, $reps] = $this->entityWithReps($agency->id, 1);
        $preset = EsignRecipientPreset::defaultFor($agency->id);

        $phrase = $preset->renderPhrase($entity, $reps[0], 'Executor');
        $this->assertSame('Estate Late John Smith, herein represented by Rep0 Person (Executor)', $phrase);

        // missing capacity → no dangling "()"
        $noCapPhrase = EsignRecipientPreset::substitute('{entity_name} rep {rep_name} ()', $entity, $reps[0], null);
        $this->assertStringNotContainsString('()', $noCapPhrase);
    }

    public function test_entity_recipient_expands_to_all_reps_no_proxy(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        [$entity] = $this->entityWithReps($agency->id, 3);

        $out = $this->expand([['role' => 'seller', 'name' => $entity->entity_name, 'email' => '', '_contact_id' => $entity->id]], $user);

        $this->assertCount(3, $out);
        foreach ($out as $r) {
            $this->assertSame('seller', $r['role']);
            $this->assertSame($entity->id, $r['_entity_contact_id']);
            $this->assertStringContainsString('herein represented by', $r['name']);
            $this->assertNotSame('', $r['email']);        // rep email, not the entity's
            // caption for the signature-block "on behalf of" attribution
            $this->assertStringContainsString('on behalf of', $r['_signature_caption']);
            $this->assertStringContainsString('Executor', $r['_signature_caption']);
        }
    }

    public function test_entity_recipient_with_proxy_expands_to_single_signer(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        [$entity, $reps] = $this->entityWithReps($agency->id, 4, proxyIdx: 1);

        $out = $this->expand([['role' => 'seller', '_contact_id' => $entity->id]], $user);

        $this->assertCount(1, $out);
        $this->assertSame($reps[1]->id, $out[0]['_contact_id']);
        $this->assertSame('rep1@x.test', $out[0]['email']);
    }

    public function test_rep_less_entity_flagged_not_dropped(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $entity = Contact::factory()->create(['agency_id' => $agency->id, 'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Rep-less Pty']);

        $out = $this->expand([['role' => 'seller', '_contact_id' => $entity->id]], $user);

        $this->assertCount(1, $out);
        $this->assertTrue($out[0]['_entity_needs_representative']);
    }

    public function test_natural_person_recipient_passes_through(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $person = Contact::factory()->create(['agency_id' => $agency->id, 'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Jo', 'last_name' => 'Soap']);

        $out = $this->expand([['role' => 'buyer', 'name' => 'Jo Soap', '_contact_id' => $person->id]], $user);

        $this->assertCount(1, $out);
        $this->assertArrayNotHasKey('_entity_contact_id', $out[0]);
    }
}
