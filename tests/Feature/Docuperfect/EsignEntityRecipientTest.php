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

    private function makeAgencyWithBranch(): array
    {
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $branchId = \Illuminate\Support\Facades\DB::table('branches')->insertGetId([
            'agency_id' => $agency->id, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$agency, $branchId];
    }

    private function entityWithReps(int $agencyId, int $branchId, int $reps, ?int $proxyIdx = null): array
    {
        $entity = Contact::create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Estate Late John Smith', 'entity_reg_no' => 'EST-1', 'first_name' => 'Estate Late John Smith', 'last_name' => '']);
        $repModels = [];
        for ($i = 0; $i < $reps; $i++) {
            $r = Contact::create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Rep' . $i, 'last_name' => 'Person', 'email' => "rep{$i}@x.test"]);
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

    private function callPrivate(string $method, array $args)
    {
        $m = new ReflectionMethod(ESignWizardController::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(app(ESignWizardController::class), $args);
    }

    public function test_phrasing_template_renders_and_collapses_empty_capacity(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        [$entity, $reps] = $this->entityWithReps($agency->id, $branchId, 1);
        $preset = EsignRecipientPreset::defaultFor($agency->id);

        $phrase = $preset->renderPhrase($entity, $reps[0], 'Executor');
        $this->assertSame('Estate Late John Smith, herein represented by Rep0 Person (Executor)', $phrase);

        // missing capacity → no dangling "()"
        $noCapPhrase = EsignRecipientPreset::substitute('{entity_name} rep {rep_name} ()', $entity, $reps[0], null);
        $this->assertStringNotContainsString('()', $noCapPhrase);
    }

    public function test_entity_recipient_expands_to_all_reps_no_proxy(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        [$entity] = $this->entityWithReps($agency->id, $branchId, 3);

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
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        [$entity, $reps] = $this->entityWithReps($agency->id, $branchId, 4, proxyIdx: 1);

        $out = $this->expand([['role' => 'seller', '_contact_id' => $entity->id]], $user);

        $this->assertCount(1, $out);
        $this->assertSame($reps[1]->id, $out[0]['_contact_id']);
        $this->assertSame('rep1@x.test', $out[0]['email']);
    }

    public function test_rep_less_entity_flagged_not_dropped(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $entity = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Rep-less Pty', 'first_name' => 'Rep-less Pty', 'last_name' => '']);

        $out = $this->expand([['role' => 'seller', '_contact_id' => $entity->id]], $user);

        $this->assertCount(1, $out);
        $this->assertTrue($out[0]['_entity_needs_representative']);
    }

    public function test_natural_person_recipient_passes_through(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $person = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Jo', 'last_name' => 'Soap']);

        $out = $this->expand([['role' => 'buyer', 'name' => 'Jo Soap', '_contact_id' => $person->id]], $user);

        $this->assertCount(1, $out);
        $this->assertArrayNotHasKey('_entity_contact_id', $out[0]);
    }

    /**
     * Fault 3, round 3 (Johan, 2026-08-24) — flow 279's exact failure, caught
     * at the source. prepareRecipientsForMerge() used to call
     * expandEntityRecipients() itself and write the EXPANDED (representative-
     * substituted) row straight back into $stepData['recipients'] — the SAME
     * array showStep() seeds the recipients step's own editable form from.
     * The agent's screen still looked right (the composed clause sat in the
     * `name` field), but _contact_id/first_name/last_name had silently
     * become the representative's own. The agent clicked Next, that row got
     * saved AS THE RECIPIENT, and the company was gone from the data
     * permanently — undetected by every prior walk, because none of them
     * exercised a save-then-reload round trip; they only read back data that
     * was never round-tripped through the form at all.
     *
     * prepareRecipientsForMerge() must NEVER expand — its output is exactly
     * what a form gets seeded from and saves back.
     */
    public function test_prepare_recipients_for_merge_never_expands_an_entity(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        [$entity, $reps] = $this->entityWithReps($agency->id, $branchId, 1);

        $stepData = [
            'recipients' => ['recipients' => [
                ['role' => 'seller', 'name' => $entity->entity_name, '_contact_id' => $entity->id],
            ]],
        ];

        $out = $this->callPrivate('prepareRecipientsForMerge', [$stepData, null, $user, 3]);
        $recipients = $out['recipients']['recipients'];

        $this->assertCount(1, $recipients, 'No expansion — the entity stays ONE row, not one per representative.');
        $this->assertSame($entity->id, $recipients[0]['_contact_id'],
            'The recipient row a form seeds from must still point at the COMPANY, never the representative.');
        $this->assertArrayNotHasKey('_entity_contact_id', $recipients[0],
            'A key expandEntityRecipients() adds — its presence here would mean expansion leaked into the raw form.');
    }

    /**
     * expandRecipientsForMerge() is the ONLY place expansion may happen, and
     * only against a COPY — the caller's own $stepData (and whatever it
     * seeded a form from) must be untouched.
     */
    public function test_expand_recipients_for_merge_expands_without_mutating_the_caller(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        [$entity, $reps] = $this->entityWithReps($agency->id, $branchId, 1);

        $stepData = [
            'recipients' => ['recipients' => [
                ['role' => 'seller', 'name' => $entity->entity_name, '_contact_id' => $entity->id],
            ]],
        ];

        $merged = $this->callPrivate('expandRecipientsForMerge', [$stepData, $user]);

        $this->assertCount(1, $merged['recipients']['recipients']);
        $this->assertSame($reps[0]->id, $merged['recipients']['recipients'][0]['_contact_id'],
            'The MERGE copy expands to the representative — this is the one that feeds the document body.');
        $this->assertStringContainsString('herein represented by', $merged['recipients']['recipients'][0]['name']);

        // The original $stepData passed in must be untouched — same variable, re-read.
        $this->assertSame($entity->id, $stepData['recipients']['recipients'][0]['_contact_id'],
            'expandRecipientsForMerge() must not mutate the caller\'s own $stepData.');
    }
}
