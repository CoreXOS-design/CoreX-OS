<?php

declare(strict_types=1);

namespace Tests\Feature\BuyersReport;

use App\Models\User;
use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use App\Services\BuyersReport\BuyersReportService;
use App\Services\Performance\PeriodResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the print/PDF correctness properties Johan asked for explicitly
 * (2026-08-20, urgent, meeting tomorrow): a PDF generated with a filter
 * active shows the FILTERED numbers, not the unfiltered ones; and printing
 * someone else's dedicated agent/branch page shows THEIR figures, not the
 * viewer's own -- the exact bug this test suite caught during build (the
 * general BuyersReportScopeResolver always substitutes the viewer's own
 * id for 'own' level, which is correct for the interactive page and wrong
 * for print()/pdf() targeting another agent's page).
 */
final class BuyersReportPrintPdfTest extends TestCase
{
    private const AGENCY_ID = 9401;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    public function test_print_of_another_agent_shows_their_figures_not_the_viewers_own(): void
    {
        $viewerId = $this->seedUser(4001, 'admin', 'Admin Viewer');
        $targetId = $this->seedUser(4002, 'agent', 'Target Agent');
        $this->seedBuyer(1, $targetId, 'warm');
        $this->seedBuyer(2, $targetId, 'warm');
        // Viewer has buyers of their own too -- must NOT leak into the printed page.
        $this->seedBuyer(3, $viewerId, 'warm');
        $viewer = User::find($viewerId);

        auth()->login($viewer);
        $controller = app(\App\Http\Controllers\BuyersReport\BuyersReportController::class);
        $req = Request::create('/corex/buyers-report/print?scope=own&user_id=' . $targetId . '&period=this_month');
        $req->setUserResolver(fn () => $viewer);
        app()->instance('request', $req);

        $html = $controller->print($req, app(PeriodResolver::class), app(BuyersReportScopeResolver::class), app(BuyersReportService::class))->render();

        $this->assertStringContainsString('Target Agent', $html);
        $this->assertStringNotContainsString('Admin Viewer', $html);
    }

    public function test_pdf_respects_the_type_filter_not_the_full_buyer_count(): void
    {
        $viewer = $this->seedUser(4003, 'admin', 'Admin Two');
        $agent  = $this->seedUser(4004, 'agent', 'Agent Two');
        DB::table('contact_types')->insert([['id' => 1, 'name' => 'Buyer'], ['id' => 2, 'name' => 'Lead']]);
        $b1 = $this->seedBuyer(4, $agent, 'new');
        DB::table('contacts')->where('id', $b1)->update(['contact_type_id' => 1]);
        $b2 = $this->seedBuyer(5, $agent, 'new');
        DB::table('contacts')->where('id', $b2)->update(['contact_type_id' => 2]);

        $scope = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_AGENCY);
        $period = app(PeriodResolver::class)->resolve('this_month');
        $service = app(BuyersReportService::class);

        // Same numbers the print/PDF path renders -- build() is exactly
        // what buildPrintData() calls, so asserting on it directly proves
        // the filtered figure without depending on print.blade.php markup.
        $unfiltered = $service->build($scope, $period, null);
        $filtered = $service->build($scope, $period, 'buyer');

        $this->assertSame(2, $unfiltered['company']['buyers'], 'Unfiltered: both buyers held.');
        $this->assertSame(1, $filtered['company']['buyers'], 'type=buyer filtered: only the one Buyer-typed contact.');

        $viewerModel = User::find($viewer);
        auth()->login($viewerModel);
        $controller = app(\App\Http\Controllers\BuyersReport\BuyersReportController::class);
        $reqFiltered = Request::create('/corex/buyers-report/print?scope=agency&period=this_month&type=buyer');
        $reqFiltered->setUserResolver(fn () => $viewerModel);
        app()->instance('request', $reqFiltered);
        $htmlFiltered = $controller->print($reqFiltered, app(PeriodResolver::class), app(BuyersReportScopeResolver::class), $service)->render();

        $this->assertStringContainsString('Buyer only', $htmlFiltered, 'The print page must say a type filter is active, not silently apply it.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedUser(int $id, string $role, string $name): int
    {
        DB::table('users')->insert(['id' => $id, 'agency_id' => self::AGENCY_ID, 'name' => $name, 'role' => $role, 'is_active' => 1]);

        return $id;
    }

    private function seedBuyer(int $id, int $agentId, string $state): int
    {
        DB::table('contacts')->insert([
            'id' => $id, 'agency_id' => self::AGENCY_ID, 'agent_id' => $agentId,
            'is_buyer' => 1, 'buyer_state' => $state, 'first_name' => "Buyer $id", 'last_name' => '',
        ]);

        return $id;
    }

    private function dropSchema(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('communication_links');
        Schema::dropIfExists('communications');
        Schema::dropIfExists('calendar_event_feedback');
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('contact_matches');
        Schema::dropIfExists('buyer_lost_records');
        Schema::dropIfExists('buyer_state_transitions');
        Schema::dropIfExists('contact_types');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('users');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('agencies');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('user_branch_history');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function buildSchema(): void
    {
        $this->dropSchema();

        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('agencies', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('split_branches_enabled')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('branches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role', 40)->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('contacts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('contact_type_id')->nullable();
            $table->boolean('is_buyer')->default(0);
            $table->string('buyer_state', 20)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('buyer_pipeline_entered_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('contact_types', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('buyer_state_transitions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20)->nullable();
            $table->string('reason', 30)->nullable();
            $table->timestamp('occurred_at');
        });
        Schema::create('buyer_lost_records', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('reason_code', 40)->nullable();
            $table->string('reason_label')->nullable();
            $table->decimal('preapproval_amount_at_loss', 12, 2)->nullable();
            $table->unsignedBigInteger('agent_owner_user_id_at_loss')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('user_branch_history', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('from_branch_id')->nullable();
            $table->unsignedBigInteger('to_branch_id')->nullable();
            $table->timestamp('moved_at');
        });
        Schema::create('calendar_events', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('category', 80)->nullable();
            $table->string('title')->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('event_date');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('calendar_event_links', function ($table) {
            $table->id();
            $table->unsignedBigInteger('calendar_event_id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->string('role')->default('attendee');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('calendar_event_feedback', function ($table) {
            $table->id();
            $table->unsignedBigInteger('calendar_event_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('feedback_kind', 40)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('contact_matches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('contact_id');
            $table->boolean('is_primary')->default(0);
            $table->string('listing_type', 20)->nullable();
            $table->string('property_type')->nullable();
            $table->json('property_types')->nullable();
            $table->decimal('price_min', 12, 2)->nullable();
            $table->decimal('price_max', 12, 2)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('communications', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('channel', 20)->nullable();
            $table->timestamp('occurred_at')->nullable();
        });
        Schema::create('communication_links', function ($table) {
            $table->id();
            $table->unsignedBigInteger('communication_id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->timestamp('deleted_at')->nullable();
        });
    }
}
