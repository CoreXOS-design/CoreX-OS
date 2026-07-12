<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Services\Docuperfect\ImporterAiService;
use App\Services\Docuperfect\RoleBlockDetectionService;
use Tests\TestCase;

/**
 * The field-naming prompt was RENTAL-ONLY, and it silently wrecked every sales import.
 *
 * Measured on the real HFC documents before this fix: the prompt mentioned Lessor/Lessee ten
 * times and Seller/Purchaser zero times, its worked example was a lease, and its "Available
 * field keys" list — which it is explicitly told not to deviate from — carried no sale fields
 * at all. So the model was boxed in:
 *
 *   - on the EATS (a SALE mandate) it mapped the seller's name/address/contact to RENTAL
 *     Lessor fields, and the contract stamped as data-role-block="lessor" on a sales document;
 *   - on the Disclosure and the OTP it mapped NOTHING (0 of 11, 0 of 129 blanks) — it had no
 *     sales vocabulary to match against, so every blank fell through to manual.
 *
 * The machinery was right; the labels were wrong. These tests hold the prompt to a contract:
 * it must teach both document types, and it must not lose the lease behaviour that works.
 *
 * (A prompt cannot be unit-tested for what the model *does* — but it can be held to what it
 * OFFERS. Every failure above traces to a word that was missing from the prompt.)
 */
final class ImporterAiSalesVocabularyTest extends TestCase
{
    private function prompt(): string
    {
        return app(ImporterAiService::class)->fieldPrompt();
    }

    /** The document type must be decided BEFORE any party is assigned — it changes them all. */
    public function test_the_prompt_makes_the_model_decide_the_document_type_first(): void
    {
        $p = $this->prompt();

        $this->assertStringContainsString('DECIDE THE DOCUMENT TYPE FIRST', $p);
        $this->assertStringContainsString('SALE document', $p);
        $this->assertStringContainsString('LEASE document', $p);
    }

    /** The sales roles exist at all — they did not before. */
    public function test_the_prompt_teaches_the_sales_parties(): void
    {
        $p = $this->prompt();

        $this->assertStringContainsString('"seller"', $p);
        $this->assertStringContainsString('AUTHORITY TO SELL', $p);
        $this->assertStringContainsString('OFFER TO PURCHASE', $p);
    }

    /**
     * SA documents say "Purchaser". The database contact type and the role-block engine both
     * say "buyer". The prompt must recognise the legal word and emit the system's role, or the
     * suggestion lands on nothing.
     */
    public function test_the_prompt_maps_the_legal_word_purchaser_onto_the_system_role_buyer(): void
    {
        $p = $this->prompt();

        $this->assertStringContainsString('Recognise "Purchaser", emit "buyer"', $p);
        $this->assertStringContainsString('"buyer"', $p);
    }

    /** Every role the prompt offers must be a role the engine can actually resolve. */
    public function test_every_role_the_prompt_offers_is_a_real_role_base(): void
    {
        foreach (['seller', 'buyer', 'lessor', 'lessee', 'agent'] as $role) {
            $this->assertContains(
                $role,
                RoleBlockDetectionService::ROLE_BASES,
                "the prompt offers assigned_to '{$role}' — the engine must be able to resolve it"
            );
        }
    }

    /** Sale field keys, and a worked SALE example — the model was told never to invent keys. */
    public function test_the_prompt_offers_sale_field_keys_and_a_sale_example(): void
    {
        $p = $this->prompt();

        $this->assertStringContainsString('property.gross_price', $p);
        $this->assertStringContainsString('property.erf_number', $p);
        $this->assertStringContainsString('computed.price_in_words', $p);

        // A worked example in the sales shape — the only example used to be a lease.
        $this->assertStringContainsString('"assigned_to": "seller"', $p);
        $this->assertStringContainsString('Exclusive Authority To Sell', $p);
    }

    /**
     * The labels are what the importer matches against the real named fields
     * (_findBestNamedFieldMatch is label-first), so a bare "Id Number" matches nothing useful.
     */
    public function test_the_prompt_requires_party_qualified_labels(): void
    {
        $p = $this->prompt();

        $this->assertStringContainsString('Seller Id Number', $p);
        $this->assertStringContainsString('never a bare "Id Number"', $p);
    }

    /** NO REGRESSION: the lease behaviour that already worked must survive intact. */
    public function test_the_lease_vocabulary_is_not_lost(): void
    {
        $p = $this->prompt();

        $this->assertStringContainsString('"lessor"', $p);
        $this->assertStringContainsString('"lessee"', $p);
        $this->assertStringContainsString('deal.rental_amount', $p);
        $this->assertStringContainsString('deal.escalation_percentage', $p);
        $this->assertStringContainsString('Name → Address → ID Number', $p);
    }

    /** The prompt must still demand pure JSON and an entry for every blank. */
    public function test_the_response_contract_is_intact(): void
    {
        $p = $this->prompt();

        $this->assertStringContainsString('Return an entry for EVERY blank number provided', $p);
        $this->assertStringContainsString('PURE JSON', $p);
    }
}
