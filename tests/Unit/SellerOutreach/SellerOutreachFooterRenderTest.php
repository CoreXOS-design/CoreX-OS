<?php

declare(strict_types=1);

namespace Tests\Unit\SellerOutreach;

use App\Services\SellerOutreach\SellerOutreachComposerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * AT-48 — outreach footer: company FFC + sending-agent FFC + branch/company tel.
 *
 * Exercises the composer's private renderBody() (and its optional-segment
 * collapse) directly so the graceful-omit behaviour is proven without a DB.
 * renderBody() touches no constructor dependencies, so the service is built via
 * newInstanceWithoutConstructor(). Pure unit test — runs in ms.
 */
final class SellerOutreachFooterRenderTest extends TestCase
{
    /** The exact footer the HFC consent templates carry (AT-48). */
    private const FOOTER = 'You can stop anytime by replying STOP. '
        . '{agency_name} · FFC {agency_ffc}{?agent_ffc} · Agent FFC {agent_ffc}{/agent_ffc} · {branch_or_company_tel}.';

    private function render(string $template, array $fields): string
    {
        $ref = new ReflectionClass(SellerOutreachComposerService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('renderBody');
        $method->setAccessible(true);

        return $method->invoke($svc, $template, $fields);
    }

    private function fields(string $agentFfc): array
    {
        return [
            'agency_name' => 'Home Finders Coastal',
            'agency_ffc' => '202615038880000',
            'agent_ffc' => $agentFfc,
            'branch_or_company_tel' => '071 351 0291',
        ];
    }

    public function test_footer_renders_full_segment_when_agent_ffc_present(): void
    {
        $out = $this->render(self::FOOTER, $this->fields('0502216'));

        $this->assertSame(
            'You can stop anytime by replying STOP. '
            . 'Home Finders Coastal · FFC 202615038880000 · Agent FFC 0502216 · 071 351 0291.',
            $out
        );
        $this->assertStringNotContainsString('{', $out, 'no stray merge braces');
    }

    public function test_footer_omits_agent_ffc_segment_when_blank(): void
    {
        $out = $this->render(self::FOOTER, $this->fields(''));

        $this->assertSame(
            'You can stop anytime by replying STOP. '
            . 'Home Finders Coastal · FFC 202615038880000 · 071 351 0291.',
            $out
        );
        $this->assertStringNotContainsString('Agent FFC', $out, 'no dangling label');
        $this->assertStringNotContainsString(' ·  · ', $out, 'no stray double separator');
        $this->assertStringNotContainsString('{', $out, 'no stray merge braces');
    }

    public function test_footer_omits_agent_ffc_segment_when_whitespace_only(): void
    {
        // ffc_number could be a blank-but-present string; trim() must treat it as empty.
        $out = $this->render(self::FOOTER, $this->fields('   '));

        $this->assertStringNotContainsString('Agent FFC', $out);
        $this->assertSame(
            'You can stop anytime by replying STOP. '
            . 'Home Finders Coastal · FFC 202615038880000 · 071 351 0291.',
            $out
        );
    }

    public function test_optional_segment_does_not_disturb_normal_body_tokens(): void
    {
        // A body with NO optional markers must render exactly as the plain loop did.
        $body = 'Hi {seller_name}, {agent_name} here. {tracking_link} Reply STOP.';
        $out = $this->render($body, [
            'seller_name' => 'Thandi',
            'agent_name' => 'Johan Reichel',
            'tracking_link' => '{tracking_link}', // left literal for the sender to fill
        ]);

        $this->assertSame('Hi Thandi, Johan Reichel here. {tracking_link} Reply STOP.', $out);
    }
}
