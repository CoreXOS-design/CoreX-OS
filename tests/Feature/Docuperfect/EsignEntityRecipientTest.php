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

    /**
     * Fault 3, round 4 (Johan, 2026-08-24): flow 279 rendered "...herein
     * represented by HA Pretorius (Representative)" — a role label
     * identifying nobody — because {capacity}'s fallback for "unset" was the
     * literal word "Representative", and capacity is unset far more often
     * than not (it has a real UI — Contact -> Representatives -> Capacity —
     * but is frequently left blank). The rep's OWN ID must render instead,
     * the same convention a natural-person party already gets everywhere
     * else in this system.
     */
    public function test_a_representative_with_no_capacity_renders_their_own_id_not_the_word_representative(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $entity = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => '1502 Beaumont Prop CC', 'entity_reg_no' => '201001792823', 'first_name' => '1502 Beaumont Prop CC', 'last_name' => '']);
        $rep = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'HA', 'last_name' => 'Pretorius', 'id_number' => '7004065141082']);
        ContactRepresentative::create(['entity_contact_id' => $entity->id, 'representative_contact_id' => $rep->id, 'capacity' => null]);

        $preset = EsignRecipientPreset::defaultFor($agency->id);
        $phrase = $preset->renderPhrase($entity, $rep->fresh(), null);

        $this->assertSame('1502 Beaumont Prop CC, herein represented by HA Pretorius (ID: 7004065141082)', $phrase);
        $this->assertStringNotContainsString('Representative)', $phrase, 'The role-label fallback must never appear.');
    }

    /**
     * Johan's own instinct, honoured: a representative with NEITHER capacity
     * NOR an ID on file renders as a bare name — nothing in brackets that
     * implies information the record doesn't actually carry.
     */
    public function test_a_representative_with_no_capacity_and_no_id_renders_as_a_bare_name(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $entity = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Bare Trading CC', 'first_name' => 'Bare Trading CC', 'last_name' => '']);
        $rep = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Jo', 'last_name' => 'Soap']);
        ContactRepresentative::create(['entity_contact_id' => $entity->id, 'representative_contact_id' => $rep->id, 'capacity' => null]);

        $preset = EsignRecipientPreset::defaultFor($agency->id);
        $phrase = $preset->renderPhrase($entity, $rep->fresh(), null);

        $this->assertSame('Bare Trading CC, herein represented by Jo Soap', $phrase);
        $this->assertStringNotContainsString('(', $phrase, 'No bracket implying information that is not on file.');
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
            // cc1's audit, escalated by Johan 2026-08-24 — 'name' used to be
            // the FULL document-body clause ($label), and this exact field
            // feeds createSigningRequest()'s signerName param — the real
            // email greeting ("Hi {name}") and every other consumer of
            // signer_name (signing pages, audit log, the completed PDF's
            // own attestation binding). It must be the representative's OWN
            // bare name, nothing else — the same way a natural-person
            // recipient's 'name' always has been. The full clause still
            // lives on _representation_label (display-only, unread by any
            // signer-identity path) and _party_clause_text (the document
            // body, checked separately below).
            $this->assertSame(trim($r['first_name'] . ' ' . $r['last_name']), $r['name']);
            $this->assertStringNotContainsString('herein represented by', $r['name'], 'The signer-name field must never carry the legal clause.');
            $this->assertStringContainsString('herein represented by', $r['_representation_label']);
            $this->assertNotSame('', $r['email']);        // rep email, not the entity's
            // caption for the signature-block "on behalf of" attribution
            $this->assertStringContainsString('on behalf of', $r['_signature_caption']);
            $this->assertStringContainsString('Executor', $r['_signature_caption']);
        }

        // Fault 3, round 5 (Johan, 2026-08-24) — "display and signing are not
        // being treated as separate questions." SIGNING correctly produced 3
        // rows (every non-proxied rep signs), but each row's OWN
        // _party_clause_text — the document body's actual clause — used to
        // be resolved independently per row and always picked the SAME ONE
        // "canonical" rep, regardless of which of the 3 rows it was
        // attached to (flow 280's exact failure: three identical company
        // mentions, each naming only the primary signatory; the other two
        // representatives named nowhere). DISPLAY must list every
        // representative and must be IDENTICAL across every row for the
        // same entity — the clause describes the SAME legal party
        // regardless of who happens to be opening which link.
        $clauseTexts = array_unique(array_column($out, '_party_clause_text'));
        $this->assertCount(1, $clauseTexts, 'Every expanded row for the SAME entity must carry the IDENTICAL clause text.');
        $clause = $clauseTexts[0];
        foreach ($out as $r) {
            $repName = trim($r['first_name'] . ' ' . $r['last_name']);
            $this->assertStringContainsString($repName, $clause, "Every representative ({$repName}) must be named in the shared clause — not just the one whose row this is.");
        }
    }

    public function test_entity_recipient_with_proxy_expands_to_single_signer(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        [$entity, $reps] = $this->entityWithReps($agency->id, $branchId, 4, proxyIdx: 1);

        $out = $this->expand([['role' => 'seller', '_contact_id' => $entity->id]], $user);

        // SIGNING: only the proxy gets a row — Johan's rule, "only the proxy
        // needs to sign" — this was already correct and must stay so.
        $this->assertCount(1, $out);
        $this->assertSame($reps[1]->id, $out[0]['_contact_id']);
        $this->assertSame('rep1@x.test', $out[0]['email']);
        // cc1's audit — the signer-name field is the proxy's OWN bare name,
        // never the clause (see the no-proxy test above for the full context).
        $this->assertSame($reps[1]->fresh()->full_name, $out[0]['name']);

        // DISPLAY: the proxy's own row must still NAME EVERY representative,
        // not just the one who happens to be the sole signer — "all parties
        // still show... only the proxy signs" (Elize's rule, verbatim). This
        // was flow 281's exact failure: HA Pretorius/Steve Jobs vanished
        // from the document entirely because collapsing to one SIGNING row
        // also collapsed the DISPLAY clause down to that one person.
        $clause = $out[0]['_party_clause_text'];
        foreach ($reps as $rep) {
            $this->assertStringContainsString($rep->fresh()->full_name, $clause, 'Every representative must be named, whether or not they are the one signing.');
        }
        $this->assertStringContainsString('duly authorised representative', $clause, 'The proxy is annotated in the clause, not silently indistinguishable from the others.');
    }

    /**
     * Fault 3, round 5 (Johan, 2026-08-24), bug 1 — expandEntityRecipients()
     * correctly produces 3 signing rows for 3 non-proxied reps, but
     * resolveFieldGroupValue()'s "and"-join treats every row sharing
     * role=seller as a SEPARATE party, tripling the (now-identical) clause:
     * flow 280's exact failure, the CC named three times as though three
     * separate companies. expandRecipientsForMerge() must collapse rows
     * sharing the same entity down to ONE for merge/preview purposes — the
     * document has one seller, however many people sign for it. This does
     * NOT apply to expandEntityRecipients()'s own raw output, which the
     * real SignatureRequest-creation loop still needs un-collapsed (every
     * signer needs their own row there).
     */
    public function test_merge_dedupes_one_row_per_entity_even_though_signing_needs_several(): void
    {
        [$agency, $branchId] = $this->makeAgencyWithBranch();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        [$entity] = $this->entityWithReps($agency->id, $branchId, 3);

        $stepData = [
            'recipients' => ['recipients' => [
                ['role' => 'seller', 'name' => $entity->entity_name, '_contact_id' => $entity->id],
            ]],
        ];

        // expandEntityRecipients() itself still returns all 3 (signing needs them).
        $rawExpanded = $this->expand($stepData['recipients']['recipients'], $user);
        $this->assertCount(3, $rawExpanded, 'expandEntityRecipients() itself is unchanged — signing still needs one row per signer.');

        // But the MERGE path collapses to one.
        $merged = $this->callPrivate('expandRecipientsForMerge', [$stepData, $user]);
        $mergedRecipients = $merged['recipients']['recipients'];

        $this->assertCount(1, $mergedRecipients, 'The document has ONE seller — merge/preview must show the entity once, not once per signer.');
        $this->assertSame($entity->id, $mergedRecipients[0]['_entity_contact_id']);
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
        // cc1's audit — the document body reads _party_clause_text (checked
        // here), never 'name': that field is the representative's own bare
        // name now, the value that becomes the real signer_name.
        $this->assertStringContainsString('herein represented by', $merged['recipients']['recipients'][0]['_party_clause_text']);
        $this->assertStringNotContainsString('herein represented by', $merged['recipients']['recipients'][0]['name']);

        // The original $stepData passed in must be untouched — same variable, re-read.
        $this->assertSame($entity->id, $stepData['recipients']['recipients'][0]['_contact_id'],
            'expandRecipientsForMerge() must not mutate the caller\'s own $stepData.');
    }
}
