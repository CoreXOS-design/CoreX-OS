<?php

declare(strict_types=1);

namespace Tests\Unit\SellerOutreach;

use App\Services\SellerOutreach\SellerOutreachTemplateValidator;
use PHPUnit\Framework\TestCase;

/**
 * AT-46 — optional tracking link + new agency merge fields.
 *
 * Pure unit test (validator has no DB/Laravel deps), so it runs in ms and is
 * the single relevant test file for this change.
 */
final class SellerOutreachTemplateValidatorTest extends TestCase
{
    private SellerOutreachTemplateValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SellerOutreachTemplateValidator();
    }

    public function test_consent_template_passes_without_tracking_link_when_flag_off(): void
    {
        // HFC's WhatsApp consent ask: STOP clause, the new agency fields, NO link.
        $body = "Hi {seller_name}, {agent_name} from {agency_name} here about your "
            . "place in {property_suburb}. PPRA {agency_ppra_no}. Reply YES to hear "
            . "from us, or STOP to opt out. Contact us on {agency_contact}.";

        $result = $this->validator->validate('whatsapp', null, $body, includeTrackingLink: false);

        $this->assertTrue($result->passes(), 'consent template should pass with the tracking link turned off');
        $this->assertSame([], $result->errors);
    }

    public function test_normal_template_still_fails_without_tracking_link(): void
    {
        $body = "Hi {seller_name}, we have buyers. Reply STOP to opt out.";

        // Default (flag on) — the existing hard rule is unchanged.
        $resultDefault = $this->validator->validate('whatsapp', null, $body);
        $this->assertTrue($resultDefault->fails());
        $this->assertArrayHasKey('tracking_link_missing', $resultDefault->errors);

        // Explicit flag on — same.
        $resultOn = $this->validator->validate('whatsapp', null, $body, includeTrackingLink: true);
        $this->assertTrue($resultOn->fails());
        $this->assertArrayHasKey('tracking_link_missing', $resultOn->errors);
    }

    public function test_template_with_tracking_link_and_stop_passes(): void
    {
        $body = "Hi {seller_name}. {tracking_link} Reply STOP to opt out.";
        $this->assertTrue($this->validator->validate('whatsapp', null, $body)->passes());
    }

    public function test_stop_clause_is_mandatory_regardless_of_flag(): void
    {
        $noStop = "Hi {seller_name}, contact {agency_contact}.";

        $flagOff = $this->validator->validate('whatsapp', null, $noStop, includeTrackingLink: false);
        $this->assertArrayHasKey('opt_out_missing', $flagOff->errors, 'STOP required even with the link off');

        $flagOn = $this->validator->validate('whatsapp', null, $noStop . ' {tracking_link}', includeTrackingLink: true);
        $this->assertArrayHasKey('opt_out_missing', $flagOn->errors, 'STOP required with the link on');
    }

    public function test_email_subject_rule_unchanged(): void
    {
        $body = "Hi {seller_name}. {tracking_link} Reply STOP to opt out.";
        $missing = $this->validator->validate('email', '', $body);
        $this->assertArrayHasKey('subject_required', $missing->errors);

        $present = $this->validator->validate('email', 'A subject', $body);
        $this->assertTrue($present->passes());
    }

    public function test_new_agency_fields_are_registered_and_not_flagged_unknown(): void
    {
        $this->assertContains('agency_ppra_no', SellerOutreachTemplateValidator::KNOWN_MERGE_FIELDS);
        $this->assertContains('agency_contact', SellerOutreachTemplateValidator::KNOWN_MERGE_FIELDS);

        $body = "Hi {seller_name} {agent_name} {agency_name} {property_suburb} "
            . "{agency_ppra_no} {agency_contact}. STOP.";
        $this->assertSame([], $this->validator->unknownMergeFields($body),
            'the new fields must not surface as unknown-field warnings');
    }

    public function test_at48_footer_fields_are_registered_and_optional_markers_not_flagged(): void
    {
        $this->assertContains('agency_ffc', SellerOutreachTemplateValidator::KNOWN_MERGE_FIELDS);
        $this->assertContains('agent_ffc', SellerOutreachTemplateValidator::KNOWN_MERGE_FIELDS);
        $this->assertContains('branch_or_company_tel', SellerOutreachTemplateValidator::KNOWN_MERGE_FIELDS);

        // The literal HFC footer, optional-segment markers and all.
        $footer = "You can stop anytime by replying STOP. {agency_name} · FFC "
            . "{agency_ffc}{?agent_ffc} · Agent FFC {agent_ffc}{/agent_ffc} · {branch_or_company_tel}.";

        // {?agent_ffc} / {/agent_ffc} control markers must NOT register as unknown fields.
        $this->assertSame([], $this->validator->unknownMergeFields($footer),
            'optional-segment markers and the new footer fields must be clean');

        // And the footer still satisfies the consent-template rules (no link, STOP present).
        $this->assertTrue(
            $this->validator->validate('whatsapp', null, $footer, includeTrackingLink: false)->passes()
        );
    }
}
