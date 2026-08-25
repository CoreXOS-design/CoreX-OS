<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Flow 330, Finding A (Johan, 2026-08-26) — the signature-block component
 * (signature-block.blade.php) reads party_names/recipients_by_role directly
 * to decide how many "Thus done and signed by the Seller..." lines and
 * signature cells to render. Built from the raw recipient list with no
 * participation check, a deceased seller who is correctly NOT_REQUIRED got
 * her own blank, unexecutable signature cell anyway — three seller blocks
 * for two actual signers. Covers
 * ESignWizardController::filterToSigningParticipants() — the filter now
 * applied before recipients_by_role/party_names are built, on both
 * prepareSigning() and prepareWetInk(). Mirrors
 * SignatureRequest::isSigningParticipant()/nonSigningReason()'s two rules
 * against the wizard array's own _is_deceased/_is_proxy flags (no DB
 * dependency — this runs before the SignatureRequest rows exist).
 */
final class SignatureBlockSigningParticipantsTest extends TestCase
{
    private function filter(array $recipients): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'filterToSigningParticipants');
        $m->setAccessible(true);

        return $m->invoke(app(ESignWizardController::class), $recipients);
    }

    /** The exact bug: a deceased seller must not get a signature block, even though her substitute does. */
    public function test_deceased_recipient_is_excluded_from_signature_block_inputs(): void
    {
        $recipients = [
            ['name' => 'Anine', 'role' => 'seller', '_is_deceased' => true],
            ['name' => 'Elize', 'role' => 'seller', '_is_deceased' => false],
            ['name' => 'Andre', 'role' => 'seller', '_is_deceased' => false],
        ];

        $out = $this->filter($recipients);

        $this->assertSame(['Elize', 'Andre'], array_column($out, 'name'), 'Only the two real signers get a signature block.');
    }

    /** A flagged proxy signs; every OTHER same-role recipient is collapsed out of the signature blocks too. */
    public function test_proxy_collapses_the_rest_of_the_group_from_signature_blocks(): void
    {
        $recipients = [
            ['name' => 'Rep One', 'role' => 'seller', '_is_proxy' => true],
            ['name' => 'Rep Two', 'role' => 'seller', '_is_proxy' => false],
            ['name' => 'Rep Three', 'role' => 'seller', '_is_proxy' => false],
        ];

        $out = $this->filter($recipients);

        $this->assertSame(['Rep One'], array_column($out, 'name'), 'Only the proxy gets a signature block; the collapsed group does not.');
    }

    /** A proxy in ONE role group must not collapse a different role's ordinary recipients. */
    public function test_proxy_in_one_role_does_not_affect_a_different_role(): void
    {
        $recipients = [
            ['name' => 'Seller Proxy', 'role' => 'seller', '_is_proxy' => true],
            ['name' => 'Seller Other', 'role' => 'seller', '_is_proxy' => false],
            ['name' => 'Buyer One', 'role' => 'buyer', '_is_proxy' => false],
        ];

        $out = $this->filter($recipients);

        $this->assertSame(['Seller Proxy', 'Buyer One'], array_column($out, 'name'));
    }

    /** No deceased/proxy anywhere — every ordinary recipient keeps their signature block. */
    public function test_ordinary_recipients_all_pass_through(): void
    {
        $recipients = [
            ['name' => 'Anna', 'role' => 'seller'],
            ['name' => 'Ben', 'role' => 'seller'],
        ];

        $out = $this->filter($recipients);

        $this->assertCount(2, $out);
    }
}
