<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Mail\DealV2\DealPackMail;
use Tests\TestCase;

/**
 * AT-330 — DR2 pipeline document emails carried a generic, meaningless subject
 * ("Documents — 1806 [CX-D158]"). The subject is now composed from the property address +
 * the document detail the sender supplies (e.g. "Electrical COC Work Order"), while the
 * [CX-D…] token is ALWAYS retained — AT-231 inbound reply-filing resolves the deal from it.
 * This pins DealPackMail's envelope-subject contract (no DB needed).
 */
final class WorkOrderSubjectComposerTest extends TestCase
{
    private function subjectFor(string $subjectDetail, ?string $partLabel = null, string $token = '[CX-D158]'): string
    {
        $mail = new DealPackMail(
            recipientName: 'there',
            dealReference: '1806',
            propertyAddress: '380 Wilfred Street, Shelly Beach',
            messageBody: '',
            attachmentFiles: [],
            secureLinks: [],
            partLabel: $partLabel,
            dealToken: $token,
            messageId: null,
            subjectDetail: $subjectDetail,
        );

        return $mail->envelope()->subject;
    }

    public function test_meaningful_detail_builds_the_subject_and_keeps_the_token(): void
    {
        $subject = $this->subjectFor('380 Wilfred Street, Shelly Beach — Electrical COC Work Order');

        $this->assertSame('380 Wilfred Street, Shelly Beach — Electrical COC Work Order [CX-D158]', $subject);
    }

    public function test_blank_detail_falls_back_to_the_generic_documents_subject(): void
    {
        $subject = $this->subjectFor('');

        $this->assertSame('Documents — 1806 [CX-D158]', $subject);
    }

    public function test_part_label_is_appended_before_the_token(): void
    {
        $subject = $this->subjectFor('380 Wilfred Street — Electrical COC Work Order', 'Part 1 of 2');

        $this->assertSame('380 Wilfred Street — Electrical COC Work Order (Part 1 of 2) [CX-D158]', $subject);
    }
}
