<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, 14 Oct 2026 (cc5 re-walk): the "Already signed" page promised a copy "once all parties have signed" - but when everyone HAS signed, the copy
 * waits for the agent's approval. The page now says what actually happens next.
 */
final class AlreadySignedWordingTest extends TestCase
{
    use RefreshDatabase;

    private function page(bool $awaitingAgentApproval): string
    {
        $this->withoutVite();
        $request = new SignatureRequest(['token' => 'x', 'signer_name' => 'Olivia Owner', 'party_role' => 'landlord']);

        return view('docuperfect.signatures.external.already-completed', [
            'request' => $request, 'downloadAvailable' => false, 'awaitingAgentApproval' => $awaitingAgentApproval,
            'agentName' => null, 'agencyName' => 'Agency', 'agencyLogo' => null, 'agencyColor' => '#0b2a4a',
        ])->with('errors', new \Illuminate\Support\ViewErrorBag())->render();
    }

    public function test_when_everyone_has_signed_the_page_says_the_agent_checks_it_before_the_copy_goes_out(): void
    {
        $html = $this->page(true);
        $this->assertStringContainsString('Everyone has signed. Your agent checks the signed document first', $html);
        $this->assertStringContainsString('once they have approved it', $html);
        $this->assertStringNotContainsString('once all parties have signed.', $html);
    }

    public function test_while_others_have_still_to_sign_the_copy_waits_for_everyone_and_the_agents_approval(): void
    {
        $html = $this->page(false);
        $this->assertStringContainsString('once all parties have signed and your agent has approved it', $html);
        $this->assertStringNotContainsString('once all parties have signed.', $html);
    }
}
