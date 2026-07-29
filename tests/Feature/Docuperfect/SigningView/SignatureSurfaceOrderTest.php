<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Services\Docuperfect\SigningSurfaceResolver;
use Tests\TestCase;

/**
 * AT-303 BUG-5 — an injected same-role signature surface must stay grouped with
 * its family, never stranded after a different-role block.
 *
 * A stale MDF blade ships ONE seller surface + the agent surface (DOM order:
 * seller, agent). For a 2-seller mandate the resolver injects the missing
 * seller_2 surface. Before the fix it appendChild'd seller_2 at the end — AFTER
 * the agent (seller_1 → AGENT → seller_2 stranded). It must land between seller_1
 * and the agent.
 */
final class SignatureSurfaceOrderTest extends TestCase
{
    public function test_injected_second_seller_surface_is_grouped_before_the_agent(): void
    {
        $body = '<div class="sig-section">'
            . '<p class="signature-line" data-marker-party="seller" data-marker-type="signature">Seller</p>'
            . '<p class="signature-line" data-marker-party="agent" data-marker-type="signature">Agent</p>'
            . '</div>';

        $recipients = [
            ['role' => 'seller', 'name' => 'Seller One'],
            ['role' => 'seller', 'name' => 'Seller Two'],
        ];

        $out = app(SigningSurfaceResolver::class)->resolve($body, $recipients, 'Agent Smith', true);

        $posSeller1 = strpos($out, 'data-marker-party="seller"');
        $posSeller2 = strpos($out, 'data-marker-party="seller_2"');
        $posAgent   = strpos($out, 'data-marker-party="agent"');

        $this->assertNotFalse($posSeller2, 'seller_2 surface must be injected');
        $this->assertNotFalse($posAgent, 'agent surface must be present');

        // The two sellers group together, THEN the agent — not seller, agent, seller_2.
        $this->assertTrue($posSeller1 < $posSeller2, 'seller_1 must precede seller_2');
        $this->assertTrue($posSeller2 < $posAgent, 'seller_2 must be grouped before the agent (not stranded after it)');
    }
}
