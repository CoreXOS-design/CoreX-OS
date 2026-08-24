<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Johan, 2026-08-25 — cc1's audit found that ticking "deceased" on a
 * recipient row made the party display correctly in the document body but
 * created NO real signing participant to stand in for them: the executor
 * had to be added as a second, disconnected, unprompted manual step, and
 * nothing blocked a send that skipped it. "Certain problem = hard block,
 * not a warning." This covers
 * ESignWizardController::assertDeceasedRecipientsHaveSubstituteSigner() —
 * the gate that runs in prepareSigning() right after $recipients is
 * finalised, before any SignatureRequest is created.
 *
 * The private method takes a plain $recipients array (the exact shape
 * step_data['recipients']['recipients'] carries after expansion/sort) and
 * has no DB dependency of its own, so it is exercised directly via
 * reflection rather than standing up a full flow/template/property fixture
 * per case — the full end-to-end send path (real SignatureRequest rows,
 * rendered clause, mail forced to 'log', rolled back) is proven separately
 * against real QA1 data per Johan's verification protocol.
 */
final class DeceasedSubstituteSignerBlockTest extends TestCase
{
    // No RefreshDatabase — the method under test takes a plain PHP array and
    // has no DB dependency of its own (the controller has no constructor),
    // so this stays a true unit test rather than paying for a schema
    // migrate/reload it does not need.

    private function assertDeceased(array $recipients): void
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'assertDeceasedRecipientsHaveSubstituteSigner');
        $m->setAccessible(true);
        $m->invoke(app(ESignWizardController::class), $recipients);
    }

    /** Piet/Sannie exact shape: Piet deceased, executor bound as a real recipient in the chain — must pass. */
    public function test_deceased_recipient_with_recipient_type_substitute_passes(): void
    {
        $recipients = [
            [
                'name' => 'Piet Pretorius', 'role' => 'seller',
                '_recipient_local_key' => 'piet-key', '_is_deceased' => true,
                '_recipient_template_id' => 1,
                '_slot_bindings' => [
                    'deceased' => ['type' => 'self'],
                    'executor' => ['type' => 'recipient', 'recipient_local_key' => 'executor-key'],
                ],
            ],
            [
                'name' => 'Sannie Pretorius', 'role' => 'seller',
                '_recipient_local_key' => 'sannie-key', '_is_deceased' => false,
            ],
            [
                'name' => 'Koos Executor', 'role' => 'seller',
                '_recipient_local_key' => 'executor-key', '_is_deceased' => false,
            ],
        ];

        $this->assertDeceased($recipients); // no exception = pass
        $this->addToAssertionCount(1);
    }

    /** The exact bug: only a display-only type:'contact' binding — no real signer ever created. Must block. */
    public function test_deceased_recipient_with_only_contact_type_binding_blocks(): void
    {
        $recipients = [
            [
                'name' => 'Piet Pretorius', 'role' => 'seller',
                '_recipient_local_key' => 'piet-key', '_is_deceased' => true,
                '_recipient_template_id' => 1,
                '_slot_bindings' => [
                    'deceased' => ['type' => 'self'],
                    'executor' => ['type' => 'contact', 'contact_id' => 999],
                ],
            ],
            [
                'name' => 'Sannie Pretorius', 'role' => 'seller',
                '_recipient_local_key' => 'sannie-key', '_is_deceased' => false,
            ],
        ];

        try {
            $this->assertDeceased($recipients);
            $this->fail('Expected ValidationException — a contact-only binding never receives a signing request.');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('recipients');
            $this->assertStringContainsString('Piet Pretorius', $message, 'The error must name the specific party.');
            $this->assertStringContainsString('deceased', $message);
        }
    }

    /** Deceased ticked, replace modal never opened at all — no template, no bindings. Must block. */
    public function test_deceased_recipient_with_no_replacement_at_all_blocks(): void
    {
        $recipients = [
            [
                'name' => 'Piet Pretorius', 'role' => 'seller',
                '_recipient_local_key' => 'piet-key', '_is_deceased' => true,
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->assertDeceased($recipients);
    }

    /** A substitute who is themselves deceased cannot stand in — must block, not silently pass. */
    public function test_substitute_who_is_also_deceased_does_not_satisfy_the_rule(): void
    {
        $recipients = [
            [
                'name' => 'Piet Pretorius', 'role' => 'seller',
                '_recipient_local_key' => 'piet-key', '_is_deceased' => true,
                '_recipient_template_id' => 1,
                '_slot_bindings' => [
                    'executor' => ['type' => 'recipient', 'recipient_local_key' => 'also-dead-key'],
                ],
            ],
            [
                'name' => 'Also Dead', 'role' => 'seller',
                '_recipient_local_key' => 'also-dead-key', '_is_deceased' => true,
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->assertDeceased($recipients);
    }

    /** Ordinary recipients (nobody deceased) — Sannie's own case — must never trip the gate. */
    public function test_no_deceased_recipients_never_blocks(): void
    {
        $recipients = [
            ['name' => 'Sannie Pretorius', 'role' => 'seller', '_recipient_local_key' => 'sannie-key', '_is_deceased' => false],
            ['name' => 'Buyer Person', 'role' => 'buyer', '_recipient_local_key' => 'buyer-key'],
        ];

        $this->assertDeceased($recipients);
        $this->addToAssertionCount(1);
    }
}
