<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDocumentMark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The capture ledger's "Add line manually" entry (and the edit path) must follow the same rules as
 * the old items screen it replaced: a transaction date cannot be in the future (Round 11 spec,
 * "a transaction date can't be in the future"), and money is typed into a TEXT box — never a native
 * browser number box, which eats the "." mid-typing — so the raw typed string is sanitised on the
 * server like every other rand field on this feature.
 */
final class RentalApplicationCaptureLedgerEntryRulesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private RentalApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        $this->application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'returned', 'submitted_at' => now()->subDay(),
        ]);
    }

    private function postEntry(string $uid, array $extra = [])
    {
        return $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.capture-entries.store-manual', $this->application),
            array_merge(['mark_uid' => $uid, 'entry_type' => 'income', 'entry_description' => 'Salary', 'entry_amount' => 10000], $extra),
        );
    }

    public function test_a_future_dated_manual_entry_is_refused_with_a_plain_message(): void
    {
        $response = $this->postEntry('future-1', ['entry_date' => now()->addDays(3)->toDateString()]);

        $response->assertStatus(422);
        $this->assertSame(
            'A ledger entry cannot be dated in the future — use the date on the statement.',
            $response->json('errors.entry_date.0'),
        );
        $this->assertDatabaseMissing('rental_application_document_marks', ['mark_uid' => 'future-1']);
    }

    public function test_today_and_past_dates_and_no_date_are_all_accepted(): void
    {
        $this->postEntry('today-1', ['entry_date' => now()->toDateString()])->assertOk();
        $this->postEntry('past-1', ['entry_date' => now()->subMonth()->toDateString()])->assertOk();
        $this->postEntry('nodate-1')->assertOk();
    }

    public function test_editing_an_entry_to_a_future_date_is_refused_too(): void
    {
        $this->postEntry('edit-1', ['entry_date' => now()->subDay()->toDateString()])->assertOk();

        $response = $this->actingAs($this->agent)->putJson(
            route('corex.rental-applications.capture-entries.update', [$this->application, 'edit-1']),
            ['entry_date' => now()->addWeek()->toDateString(), 'entry_amount' => 10000],
        );

        $response->assertStatus(422);
        $this->assertSame(now()->subDay()->toDateString(), RentalApplicationDocumentMark::where('mark_uid', 'edit-1')->value('entry_date')->toDateString());
    }

    #[DataProvider('typedAmounts')]
    public function test_a_typed_money_string_is_understood_not_rejected(string $typed, string $stored): void
    {
        $this->postEntry('amt-' . md5($typed), ['entry_amount' => $typed])->assertOk();

        $this->assertSame($stored, number_format((float) RentalApplicationDocumentMark::where('mark_uid', 'amt-' . md5($typed))->value('entry_amount'), 2, '.', ''));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function typedAmounts(): array
    {
        return [
            'plain decimal' => ['40638.40', '40638.40'],
            'comma thousands, dot decimal' => ['40,638.40', '40638.40'],
            'comma decimal' => ['40638,40', '40638.40'],
            'space thousands with R' => ['R40 638.40', '40638.40'],
            'whole rand' => ['15000', '15000.00'],
        ];
    }

    public function test_text_that_is_not_money_is_refused_plainly(): void
    {
        $response = $this->postEntry('bad-1', ['entry_amount' => 'lots']);

        $response->assertStatus(422);
        $this->assertSame('Enter the amount as a number, like 15000 or 15 000.50.', $response->json('errors.entry_amount.0'));
    }

    public function test_the_manual_amount_and_chip_amount_boxes_are_text_inputs_not_native_number_boxes(): void
    {
        $review = file_get_contents(resource_path('views/corex/rental-applications/review.blade.php'));
        $chip = file_get_contents(resource_path('views/corex/rental-applications/partials/document-highlighter-pages.blade.php'));

        $this->assertMatchesRegularExpression('/<input type="text" inputmode="decimal"[^>]*data-manual-entry-amount/', $review);
        $this->assertMatchesRegularExpression('/<input type="text" inputmode="decimal"[^>]*data-capture-chip-amount/', $chip);
        $this->assertDoesNotMatchRegularExpression('/<input type="number"[^>]*data-(manual-entry|capture-chip)-amount/', $review . $chip);
    }
}
