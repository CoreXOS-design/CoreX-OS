<?php

declare(strict_types=1);

namespace Tests\Unit\Syndication;

use App\Models\Property;
use App\Services\Syndication\PortalContentValidator;
use PHPUnit\Framework\TestCase;

/**
 * AT-221 — portal content rules run BEFORE we send (the checks the portals run).
 * The set is extensible per portal; here we lock the confirmed live rule
 * (phone number in description, from the 2026-07-10 Property24 rejection).
 */
final class PortalContentValidatorTest extends TestCase
{
    private function prop(string $description): Property
    {
        $p = new Property();
        $p->description = $description;
        return $p;
    }

    public function test_a_phone_number_in_the_description_is_a_violation_for_each_portal(): void
    {
        $v = new PortalContentValidator();
        $p = $this->prop('Lovely unit. Call Angelique on 071 924 338 today!');

        $p24 = $v->violationsFor($p, PortalContentValidator::P24);
        $pp  = $v->violationsFor($p, PortalContentValidator::PP);

        $this->assertCount(1, $p24);
        $this->assertSame('phone_in_description', $p24[0]['key']);
        $this->assertStringContainsString('Property24', $p24[0]['message']);
        $this->assertStringContainsString('071 924 338', $p24[0]['message']);
        $this->assertCount(1, $pp);
        $this->assertStringContainsString('Private Property', $pp[0]['message']);
    }

    public function test_capture_message_names_every_portal_that_enforces_the_rule(): void
    {
        $v = new PortalContentValidator();
        $messages = $v->captureViolations($this->prop('Ph: 0719243380 for viewings.'));

        $this->assertCount(1, $messages, 'one message per problem, not one per portal');
        $this->assertStringContainsString('Property24 and Private Property', $messages[0]);
        $this->assertStringContainsString('0719243380', $messages[0]);
    }

    /**
     * @dataProvider phoneFormats
     */
    public function test_common_phone_formats_are_caught(string $description): void
    {
        $v = new PortalContentValidator();
        $this->assertNotEmpty($v->captureViolations($this->prop($description)), "should flag: {$description}");
    }

    public static function phoneFormats(): array
    {
        return [
            'spaced'      => ['Call 071 924 338'],
            'solid'       => ['Call 0719243380'],
            'dashed'      => ['Call 071-924-3380'],
            'intl +27'    => ['Call +27 71 924 3380'],
        ];
    }

    /**
     * @dataProvider cleanCopy
     */
    public function test_clean_copy_is_not_flagged(string $description): void
    {
        $v = new PortalContentValidator();
        $this->assertSame([], $v->captureViolations($this->prop($description)), "should NOT flag: {$description}");
    }

    public static function cleanCopy(): array
    {
        return [
            'price'      => ['Beautifully priced at R 931 000 — a rare find.'],
            'erf + year' => ['Erf 1234, built in 2019, 350 m2 under roof.'],
            'plain'      => ['Sea-view family home with three bedrooms and a double garage.'],
            'empty'      => [''],
        ];
    }
}
