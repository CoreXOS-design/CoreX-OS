<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Models\RentalJobCard;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\RentalDocumentPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.27.1 Q10 — "Print with link": the QR on
 * the paper is minted on demand, replaces any earlier link, needs the share
 * permission, and is NEVER in the normal print.
 */
final class RentalJobCardPrintWithLinkTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Print');
        $this->card = $this->makeJobCard();
    }

    public function test_the_normal_print_has_no_qr_and_mints_no_link(): void
    {
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.print', $this->card))->assertOk();

        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->count());
        $this->assertNull($this->renderedQr(null));
    }

    public function test_print_with_link_mints_a_link_and_streams_a_pdf(): void
    {
        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.print', ['rentalJobCard' => $this->card, 'with_link' => 1]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $token = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->whereNull('revoked_at')->first();
        $this->assertNotNull($token);
        $this->assertSame(1, $this->card->updates()->where('update_type', 'link_issued')->count());
    }

    public function test_print_with_link_replaces_the_earlier_link(): void
    {
        $issued = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin);

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.print', ['rentalJobCard' => $this->card, 'with_link' => 1]))->assertOk();

        $this->get('/secure/job-cards/' . $issued['raw_token'])->assertSee('no longer available');
        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->whereNull('revoked_at')->count());
    }

    public function test_the_qr_block_is_in_the_pdf_view_only_when_a_link_was_minted(): void
    {
        $this->assertNull($this->renderedQr(null));
        $qr = $this->renderedQr('https://example.invalid/secure/job-cards/' . str_repeat('a', 64));
        $this->assertNotNull($qr);
        $this->assertStringStartsWith('data:image/png;base64,', $qr);
    }

    public function test_print_with_link_needs_the_share_permission(): void
    {
        $agent = $this->agentWith(['rental_job_cards.view' => 'all']);

        $this->actingAs($agent)->get(route('corex.rental-job-cards.print', ['rentalJobCard' => $this->card, 'with_link' => 1]))->assertForbidden();
        $this->actingAs($agent)->get(route('corex.rental-job-cards.print', $this->card))->assertOk();
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->count());
    }

    public function test_a_closed_card_cannot_print_with_a_link(): void
    {
        $this->card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.print', ['rentalJobCard' => $this->card, 'with_link' => 1]))->assertSessionHasErrors('crew_link');
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->count());
    }

    /** Renders the print view the way the PDF service does and returns the QR data URI it embedded (or null). */
    private function renderedQr(?string $url): ?string
    {
        $service = new class extends RentalDocumentPdfService {
            public ?string $qr = null;
        };
        $reflect = new \ReflectionMethod(RentalDocumentPdfService::class, 'jobCardPrintPdf');
        $pdf = $reflect->invoke($service, $this->card, $url, '1 Jan 2027');
        $html = (string) $pdf->getDomPDF()->outputHtml();

        return str_contains($html, 'Open this job on your phone') ? (preg_match('#data:image/png;base64,[A-Za-z0-9+/=]+#', $html, $m) ? $m[0] : 'present-without-image') : null;
    }
}
