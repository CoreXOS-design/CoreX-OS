<?php

namespace Tests\Unit\PrivateProperty;

use App\Services\PrivateProperty\PpFaultTranslator;
use PHPUnit\Framework\TestCase;

/**
 * The agent must never see a raw .NET SoapException wall-of-text. PpFaultTranslator
 * turns each PP fault class into a short, human message.
 */
class PpFaultTranslatorTest extends TestCase
{
    private const PP60 = 'System.Web.Services.Protocols.SoapException: Server was unable to process request. ---> PPLSystems.Web.WebServices.AgentImport.ASAPIException: PP60 - The address details are insufficient, please add a Scheme/Complex name and resubmit. at PPLSystems.Web.WebServices.AgentImport.AgentImport.MapProperty(Property property, Listing listingImport, Branch branch, String reference, String uniqueId)';

    public function test_pp60_maps_to_short_complex_name_message(): void
    {
        $out = PpFaultTranslator::friendly(self::PP60);
        $this->assertSame('Complex/Scheme name is required for this listing.', $out);
        $this->assertStringNotContainsString('SoapException', $out);
        $this->assertStringNotContainsString(' at ', $out);
    }

    public function test_unmapped_pp_code_falls_back_to_pp_text_without_stack_trace(): void
    {
        $raw = '...ASAPIException: PP77 - A brand new rule. at PPLSystems.Foo.Bar(x)';
        $this->assertSame('A brand new rule.', PpFaultTranslator::friendly($raw));
    }

    public function test_network_timeout_is_friendly(): void
    {
        $this->assertSame(
            'Private Property is not responding right now. Please try again in a moment.',
            PpFaultTranslator::friendly('Error Fetching http headers')
        );
    }

    public function test_inner_exception_without_pp_code_is_surfaced_cleanly(): void
    {
        $raw = 'System.Web.Services.Protocols.SoapException: Server was unable to process request. ---> System.Exception: Could not Authenticate - Invalid Username / Password or Invalid BranchId at PPLSystems.Web.WebServices.TokenManager.VerifyTokenAndReturnFeedProviderId(SqlCommand cmd)';
        $out = PpFaultTranslator::friendly($raw);
        $this->assertStringContainsString('Could not Authenticate', $out);
        $this->assertStringNotContainsString('SoapException', $out);
        $this->assertStringNotContainsString('TokenManager', $out);
    }

    public function test_empty_fault_is_safe(): void
    {
        $this->assertNotSame('', PpFaultTranslator::friendly(null));
    }
}
