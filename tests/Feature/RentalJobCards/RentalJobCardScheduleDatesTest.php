<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Models\PerformanceSetting;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.22 (2026-10-05, Johan): the schedule
 * "Set" button 500'd whenever a date was filled in (the controller handed
 * date STRINGS to a method that takes date objects), and the printed job
 * card / owner quote PDFs ran to the paper edge (the template's own margin
 * reset overrode the page margins) with wide tables cut off.
 */
final class RentalJobCardScheduleDatesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private Property $property;
    private RentalJobCardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Schedule Agency', 'slug' => 'schedule-' . uniqid()]);
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        $branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $branch->id,
            'title' => '1 Schedule Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        RentalWorkOrderSetting::create([
            'agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true,
            'show_prices_on_printed_job_card' => true, 'no_approval_spend_threshold' => 100000,
        ]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        RentalVatType::seedDefaultsFor($this->agency->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
        $this->service = app(RentalJobCardService::class);
    }

    private function card(string $title = 'Schedule job'): RentalJobCard
    {
        return $this->service->createForProperty($this->property, ['title' => $title], $this->admin);
    }

    private function set(RentalJobCard $card, array $data)
    {
        return $this->actingAs($this->admin)->from(route('corex.rental-job-cards.show', $card))
            ->post(route('corex.rental-job-cards.schedule', $card), $data);
    }

    // ── 1. Schedule "Set" ────────────────────────────────────────────────

    public function test_setting_both_dates_saves_them_and_the_card_shows_them(): void
    {
        $card = $this->card();

        $this->set($card, ['scheduled_at' => '2026-10-12T09:00', 'due_at' => '2026-10-14T17:30'])
            ->assertRedirect(route('corex.rental-job-cards.show', $card))
            ->assertSessionHasNoErrors();

        $fresh = $card->fresh();
        $this->assertSame('2026-10-12 09:00', $fresh->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-14 17:30', $fresh->due_at->format('Y-m-d H:i'));
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $fresh->status);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();
        $this->assertStringContainsString('2026-10-12 09:00', $html);
        $this->assertStringContainsString('2026-10-14 17:30', $html);
    }

    public function test_the_dates_are_read_in_the_agency_timezone_and_stored_in_the_app_timezone(): void
    {
        $card = $this->card();

        $this->set($card, ['scheduled_at' => '2026-10-12T09:00', 'due_at' => '2026-10-12T10:00'])->assertSessionHasNoErrors();

        // The wall-clock the agent typed is what the card shows back, whatever the app timezone is.
        $this->assertSame(config('app.timezone'), $card->fresh()->scheduled_at->timezoneName);
        $this->assertSame('2026-10-12 09:00', $card->fresh()->scheduled_at->copy()->setTimezone($this->agency->outreachTimezone())->format('Y-m-d H:i'));
    }

    public function test_seconds_and_a_bare_date_are_accepted(): void
    {
        $card = $this->card();

        $this->set($card, ['scheduled_at' => '2026-10-12T09:00:30', 'due_at' => '2026-10-15'])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-12 09:00:30', $card->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 00:00', $card->fresh()->due_at->format('Y-m-d H:i'));
    }

    public function test_either_date_on_its_own_works(): void
    {
        $card = $this->card();

        $this->set($card, ['scheduled_at' => '2026-10-12T09:00', 'due_at' => ''])->assertSessionHasNoErrors();
        $this->assertNotNull($card->fresh()->scheduled_at);
        $this->assertNull($card->fresh()->due_at);

        $this->set($card, ['scheduled_at' => '', 'due_at' => '2026-10-20T12:00'])->assertSessionHasNoErrors();
        $this->assertNull($card->fresh()->scheduled_at);
        $this->assertSame('2026-10-20 12:00', $card->fresh()->due_at->format('Y-m-d H:i'));
    }

    public function test_blank_dates_still_clear(): void
    {
        $card = $this->card();
        $this->set($card, ['scheduled_at' => '2026-10-12T09:00', 'due_at' => '2026-10-14T17:00'])->assertSessionHasNoErrors();

        $this->set($card, ['scheduled_at' => '', 'due_at' => ''])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($card->fresh()->scheduled_at);
        $this->assertNull($card->fresh()->due_at);
    }

    /** @dataProvider badDates */
    public function test_a_bad_date_is_a_validation_message_not_a_500_and_changes_nothing(string $bad): void
    {
        $card = $this->card();
        $this->set($card, ['scheduled_at' => '2026-10-12T09:00', 'due_at' => '2026-10-14T17:00'])->assertSessionHasNoErrors();

        $this->set($card, ['scheduled_at' => $bad, 'due_at' => '2026-10-14T17:00'])
            ->assertRedirect(route('corex.rental-job-cards.show', $card))
            ->assertSessionHasErrors('scheduled_at');

        $this->assertSame('2026-10-12 09:00', $card->fresh()->scheduled_at->format('Y-m-d H:i'), 'a refused Set must leave the saved dates alone');
    }

    public static function badDates(): array
    {
        return [
            'words' => ['next tuesday'],
            'relative' => ['tomorrow'],
            'overflow day' => ['2026-02-31T10:00'],
            'month 13' => ['2026-13-01T10:00'],
            'hour 25' => ['2026-10-12T25:00'],
            'zero date' => ['0000-00-00 00:00'],
            'year out of range' => ['9999-10-12T09:00'],
            'garbage' => ['<script>alert(1)</script>'],
        ];
    }

    public function test_both_bad_dates_are_reported_together(): void
    {
        $card = $this->card();

        $this->set($card, ['scheduled_at' => 'nope', 'due_at' => 'also nope'])->assertSessionHasErrors(['scheduled_at', 'due_at']);
    }

    public function test_due_before_scheduled_is_refused(): void
    {
        $card = $this->card();

        $this->set($card, ['scheduled_at' => '2026-10-14T09:00', 'due_at' => '2026-10-12T09:00'])
            ->assertSessionHasErrors('due_at');

        $this->assertNull($card->fresh()->scheduled_at);
        $this->assertNull($card->fresh()->due_at);
    }

    public function test_due_equal_to_scheduled_is_fine(): void
    {
        $card = $this->card();

        $this->set($card, ['scheduled_at' => '2026-10-14T09:00', 'due_at' => '2026-10-14T09:00'])->assertSessionHasNoErrors();
        $this->assertNotNull($card->fresh()->due_at);
    }

    public function test_the_typed_values_come_back_into_the_boxes_after_a_refusal(): void
    {
        $card = $this->card();

        $this->set($card, ['scheduled_at' => '2026-10-14T09:00', 'due_at' => '2026-10-12T09:00']);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="scheduled_at"[^>]*value="2026-10-14T09:00"/', $html);
        $this->assertMatchesRegularExpression('/name="due_at"[^>]*value="2026-10-12T09:00"/', $html);
    }

    public function test_a_closed_card_still_refuses_scheduling_with_a_message(): void
    {
        $card = $this->card();
        $card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();

        $this->set($card, ['scheduled_at' => '2026-10-12T09:00', 'due_at' => ''])->assertSessionHasErrors('rental_job_card');
        $this->assertNull($card->fresh()->scheduled_at);
    }

    // ── 1b. The list's date filter (same controller) ─────────────────────

    public function test_the_list_filter_to_date_includes_cards_due_later_that_same_day(): void
    {
        $card = $this->card('Due late on the 14th');
        $this->set($card, ['scheduled_at' => '2026-10-14T08:00', 'due_at' => '2026-10-14T17:30'])->assertSessionHasNoErrors();

        $inRange = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index', ['date_from' => '2026-10-14', 'date_to' => '2026-10-14']))->assertOk()->getContent();
        $this->assertStringContainsString('Due late on the 14th', $inRange, 'a card due 17:30 on the "to" day belongs in a range ending that day');

        $before = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index', ['date_to' => '2026-10-13']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Due late on the 14th', $before);

        $after = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index', ['date_from' => '2026-10-15']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Due late on the 14th', $after);
    }

    public function test_a_junk_filter_date_is_a_validation_message(): void
    {
        $this->actingAs($this->admin)->from(route('corex.rental-job-cards.index'))
            ->get(route('corex.rental-job-cards.index', ['date_from' => 'banana']))
            ->assertSessionHasErrors('date_from');
    }

    // ── 2. Printed job card + owner quote inside the page margins ────────

    private function longCard(): RentalJobCard
    {
        $card = $this->card('Replace the corroded main geyser isolation valve and re-seal the drip tray on the second floor guest bathroom, then test the pressure');
        $card->forceFill(['access_notes' => str_repeat('Please call the tenant before arriving and wait at the gate. ', 6)])->save();
        $vat = RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
        $task = $this->service->addTask($card, 'Isolate the water supply to the entire second floor wing and drain the geyser fully before any work is carried out, confirming with the tenant first', $this->admin);
        $this->service->addLine($card, ['description' => 'Brass isolation valve 22mm with lever handle — heavy duty, pressure rated, includes PTFE tape and compression olives (supplied and fitted)', 'quantity' => 1, 'unit_price' => 1234.5, 'rental_vat_type_id' => $vat->id], $this->admin, $task);
        $this->service->addLine($card, ['description' => 'Supercalifragilisticexpialidocious_unbreakable_part_reference_ABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789', 'quantity' => 2, 'unit_price' => 99.99, 'rental_vat_type_id' => $vat->id], $this->admin, $task);

        return $card->fresh();
    }

    public function test_the_pdf_templates_no_longer_reset_the_page_margins(): void
    {
        foreach (['print', 'quote-pdf'] as $view) {
            $source = file_get_contents(resource_path("views/corex/rental-job-cards/{$view}.blade.php"));
            $this->assertStringContainsString('@page { margin: 24px 32px; }', $source);
            $this->assertDoesNotMatchRegularExpression('/html,\s*body\s*\{[^}]*margin:\s*0/i', $source, "{$view}: a margin reset on html/body overrides @page in dompdf and removes the page margins");
        }
        $this->assertStringContainsString('table-layout: fixed', file_get_contents(resource_path('views/corex/rental-job-cards/_pdf-lines-table.blade.php')));
    }

    /** @dataProvider documents */
    public function test_every_word_on_the_printed_card_and_the_quote_sits_inside_the_a4_margins(string $method): void
    {
        $bin = trim((string) shell_exec('command -v pdftotext'));
        if ($bin === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed here.');
        }
        $pdf = app(RentalDocumentPdfService::class)->{$method}($this->longCard())->output();
        $path = tempnam(sys_get_temp_dir(), 'jc') . '.pdf';
        file_put_contents($path, $pdf);
        $bbox = (string) shell_exec($bin . ' -bbox ' . escapeshellarg($path) . ' - 2>/dev/null');
        @unlink($path);

        preg_match_all('/<word xMin="([\d.]+)" yMin="[\d.]+" xMax="([\d.]+)"/', $bbox, $m);
        $this->assertNotEmpty($m[1], 'no words found in the PDF');
        $left = 24.0 - 0.5;                 // @page margin 32px = 24pt (+ rounding)
        $right = 595.28 - 24.0 + 0.5;       // A4 width − 24pt
        $this->assertGreaterThanOrEqual($left, min(array_map('floatval', $m[1])), 'text starts left of the page margin');
        $this->assertLessThanOrEqual($right, max(array_map('floatval', $m[2])), 'text runs past the right page margin');
    }

    public static function documents(): array
    {
        return ['printed job card' => ['jobCardPrintPdf'], 'owner quote' => ['jobCardQuotePdf']];
    }
}
