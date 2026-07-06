<?php

declare(strict_types=1);

namespace Tests\Unit\Communications;

use App\Services\Communications\EmailQuoteStripper;
use PHPUnit\Framework\TestCase;

/**
 * AT-182 thread de-duplication — the email quote stripper over the REAL input space
 * (Gmail, Outlook, plaintext ">" chains, forwards, signatures) plus the uncertainty rule
 * (when it can't confidently isolate the new part, keep the FULL body — duplicated beats
 * lost). Pure PHPUnit, no DB.
 */
final class EmailQuoteStripperTest extends TestCase
{
    private EmailQuoteStripper $stripper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripper = new EmailQuoteStripper();
    }

    public function test_gmail_on_wrote_quote_is_stripped_to_the_new_reply(): void
    {
        $body = "Thanks — Tuesday at 10am works for the viewing.\n\n"
            . "On Mon, 6 Jul 2026 at 08:14, Jane Seller <jane@example.com> wrote:\n"
            . "> Hi, are you available this week to view the property?\n"
            . "> Regards, Jane\n";

        $r = $this->stripper->strip($body);

        $this->assertTrue($r['stripped']);
        $this->assertSame('Thanks — Tuesday at 10am works for the viewing.', $r['display']);
        $this->assertStringNotContainsString('are you available', $r['display']);
    }

    public function test_outlook_original_message_divider_is_stripped(): void
    {
        $body = "Confirmed, please proceed with the offer.\n\n"
            . "-----Original Message-----\n"
            . "From: Agent <agent@hfc.co.za>\n"
            . "Sent: Monday, 6 July 2026 07:00\n"
            . "To: Buyer\nSubject: Offer\n\nPlease confirm the offer amount.\n";

        $r = $this->stripper->strip($body);

        $this->assertTrue($r['stripped']);
        $this->assertSame('Confirmed, please proceed with the offer.', $r['display']);
    }

    public function test_outlook_from_header_block_is_stripped(): void
    {
        $body = "See my answer below.\n\n"
            . "From: Jane Seller <jane@example.com>\n"
            . "Sent: 06 July 2026 08:14\n"
            . "To: Agent <agent@hfc.co.za>\n"
            . "Subject: RE: Viewing\n\n"
            . "Can we view on Tuesday?\n";

        $r = $this->stripper->strip($body);

        $this->assertTrue($r['stripped']);
        $this->assertSame('See my answer below.', $r['display']);
    }

    public function test_trailing_plaintext_quote_block_without_marker_is_stripped(): void
    {
        $body = "Yes, R1,250,000 is acceptable.\n\n"
            . "> We are offering R1,250,000 for the property.\n"
            . "> Let me know if that works.\n";

        $r = $this->stripper->strip($body);

        $this->assertTrue($r['stripped']);
        $this->assertSame('Yes, R1,250,000 is acceptable.', $r['display']);
    }

    public function test_forward_with_a_note_keeps_the_note_and_strips_the_forwarded_block(): void
    {
        $body = "FYI — see the buyer's message below.\n\n"
            . "---------- Forwarded message ----------\n"
            . "From: Buyer <buyer@example.com>\n"
            . "Subject: Interested\n\nI would like to make an offer.\n";

        $r = $this->stripper->strip($body);

        $this->assertTrue($r['stripped']);
        $this->assertSame("FYI — see the buyer's message below.", $r['display']);
    }

    public function test_signature_delimiter_is_trimmed(): void
    {
        $body = "Please find the mandate attached.\n\n-- \nJohan Reichel\nHome Finders Coastal\n039 315 0000\n";

        $r = $this->stripper->strip($body);

        $this->assertSame('Please find the mandate attached.', $r['display']);
    }

    // ── The UNCERTAINTY RULE — never hide potentially-unique content ──────────────

    public function test_bare_forward_with_no_new_note_falls_back_to_full_body(): void
    {
        // No note above the forwarded block → stripping would leave nothing → keep full.
        $body = "---------- Forwarded message ----------\n"
            . "From: Buyer <buyer@example.com>\n\nI would like to make an offer.\n";

        $r = $this->stripper->strip($body);

        $this->assertFalse($r['stripped']);
        $this->assertSame($body, $r['display']);
    }

    public function test_fresh_email_with_no_quoting_is_returned_whole(): void
    {
        $body = "Hi Johan,\n\nHere are the three properties we discussed. Let me know which to view.\n\nThanks,\nAgent";

        $r = $this->stripper->strip($body);

        $this->assertFalse($r['stripped']);
        $this->assertSame($body, $r['display']);
    }

    public function test_single_quoted_line_is_not_mistaken_for_history(): void
    {
        // One "> quoted" line inside a sentence must NOT trigger a whole-history strip.
        $body = "As you said:\n> the price is firm\nI agree, let's proceed.";

        $r = $this->stripper->strip($body);

        $this->assertFalse($r['stripped']);
        $this->assertSame($body, $r['display']);
    }

    public function test_empty_body_is_safe(): void
    {
        $r = $this->stripper->strip(null);
        $this->assertFalse($r['stripped']);
        $this->assertSame('', $r['display']);
    }

    public function test_earliest_boundary_wins_when_multiple_markers_present(): void
    {
        // "On … wrote:" appears before a nested ">" block; strip from the earliest.
        $body = "Agreed.\n\nOn Mon wrote:\n> earlier\n> stuff\n\n-----Original Message-----\nolder\n";
        $r = $this->stripper->strip($body);

        $this->assertTrue($r['stripped']);
        $this->assertSame('Agreed.', $r['display']);
    }
}
