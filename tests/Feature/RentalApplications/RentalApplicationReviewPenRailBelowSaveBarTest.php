<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QA1, 2026-10-07 — Johan, blocking his rentals test: the Income highlighter
 * was hidden underneath the document's "N marks / Save N marks" bar. Both that
 * bar and the pen rail (Income, Expense, other pens, Note, stroke, undo/redo)
 * were position:sticky at top:0 of the same scroll box, with the bar painted on
 * top. The fix is one shared number: the bar has a fixed height
 * (--rr-save-bar-h) and the rail pins below it at exactly that offset.
 *
 * Pixel overlap is a browser fact, not observable from PHPUnit; this pins the
 * markup contract that makes the overlap impossible, so a later edit that
 * re-pins the rail to top:0 (or drops the bar's fixed height) fails here.
 */
final class RentalApplicationReviewPenRailBelowSaveBarTest extends TestCase
{
    use RefreshDatabase;

    private function reviewHtml(string $role = 'admin'): string
    {
        $this->withoutVite();
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ramsgate']);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role]);
        $app = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'returned', 'submitted_at' => now()->subDay(),
        ]);

        Storage::fake('local');
        $path = 'rental-applications/' . $app->id . '/documents/' . Str::random(20) . '.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake content');
        DB::table('documents')->insert([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'original_name' => 'Statement.pdf', 'storage_path' => $path, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 100,
            'document_type_id' => null, 'source_type' => 'rental_application', 'source_id' => $app->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));
        $response->assertOk();

        return $response->getContent();
    }

    public function test_pen_rail_pins_below_the_save_bar_never_at_top_zero(): void
    {
        $html = $this->reviewHtml();

        // The bar exists and has a fixed height driven by the shared variable.
        self::assertStringContainsString('rr-doc-save-bar', $html);
        self::assertMatchesRegularExpression('/#continuousViewScroll\s*\{\s*--rr-save-bar-h:\s*\d+px;/', $html);
        self::assertMatchesRegularExpression('/\.rr-doc-save-bar\s*\{[^}]*height:\s*var\(--rr-save-bar-h\)/s', $html);

        // The rail exists, is sticky, and its offset IS the bar's height — not 0.
        self::assertMatchesRegularExpression(
            '/class="rr-pen-rail[^"]*"[^>]*style="[^"]*position:\s*sticky;\s*top:\s*var\(--rr-save-bar-h,\s*0px\)/s',
            $html,
            'the pen rail must pin below the Save bar (top: var(--rr-save-bar-h)), or the first pen (Income) hides under it'
        );
        self::assertDoesNotMatchRegularExpression(
            '/class="rr-pen-rail[^"]*"[^>]*style="[^"]*position:\s*sticky;\s*top:\s*0[;"]/s',
            $html
        );
    }

    public function test_every_pen_stays_reachable_on_a_short_window_and_the_bar_comes_first(): void
    {
        $html = $this->reviewHtml();

        // On a short window the rail scrolls inside its own space instead of running off the bottom.
        self::assertMatchesRegularExpression(
            '/class="rr-pen-rail[^"]*"[^>]*style="[^"]*max-height:\s*calc\([^"]*--rr-save-bar-h[^"]*overflow-y:\s*auto/s',
            $html
        );

        // Document order: the bar, then the rail with the Income / Expense / Note pens, then the undo control.
        $bar = strpos($html, '<div class="flex items-center gap-3 mb-1 rr-doc-save-bar"');
        $rail = strpos($html, 'class="rr-pen-rail');
        $note = strpos($html, 'pickNoteTool()', (int) $rail);
        $undo = strpos($html, 'title="Undo (Ctrl+Z)"', (int) $rail);
        self::assertNotFalse($bar);
        self::assertNotFalse($rail);
        self::assertGreaterThan($bar, $rail, 'the Save bar renders before the pen rail');
        self::assertNotFalse($note);
        self::assertNotFalse($undo);
        self::assertGreaterThan($rail, $undo);
    }
}
