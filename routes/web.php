<?php

/* IMPERSONATE_STOP_GLOBAL_2026 */
Route::post('/admin/impersonate/stop', [\App\Http\Controllers\Admin\ImpersonateController::class, 'stop'])
    ->middleware(['web','auth'])
    ->name('impersonate.stop');


use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorksheetController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\CompanySummaryController;
use App\Http\Controllers\Admin\ListingTargetController;
use App\Http\Controllers\Admin\ViewAsController;
use App\Http\Controllers\Admin\BranchAssignmentController;
use App\Http\Controllers\Admin\DealController;
use App\Http\Controllers\Agent\DealRegisterController;
use App\Http\Controllers\Admin\MonthlyGoalController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return redirect()->route('nexus.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // TEMP: Grant admin rights to current user
    Route::get('/make-me-admin', function () {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $user->role = 'admin';
        $user->save();
        return redirect()->route('nexus.dashboard')->with('success', 'You are now an Admin.');
    });

    // Ellie (AI Assistant)
    Route::get('/ellie', [\App\Http\Controllers\EllieController::class, 'index'])
        ->middleware('verified')
        ->name('ellie.index');

    Route::post('/ellie/send', [\App\Http\Controllers\EllieController::class, 'send'])
        ->middleware('verified')
        ->name('ellie.send');



    // ELLIE_ROUTES_2026
    Route::post('/ellie/rename', [\App\Http\Controllers\EllieController::class, 'rename'])
        ->name('ellie.rename');

    Route::post('/ellie/archive', [\App\Http\Controllers\EllieController::class, 'archive'])
        ->name('ellie.archive');

    Route::post('/ellie/unarchive', [\App\Http\Controllers\EllieController::class, 'unarchive'])
        ->name('ellie.unarchive');

    Route::get('/worksheet', [WorksheetController::class, 'index'])->name('worksheet.index');
    Route::post('/worksheet', [WorksheetController::class, 'store'])->name('worksheet.store');

    Route::get('/company-summary', [CompanySummaryController::class, 'index'])->name('company.summary');

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('admin')->name('admin.dashboard');

    Route::post('/admin/dashboard/expenses', [AdminDashboardController::class, 'saveExpenses'])
        ->middleware('admin')->name('admin.expenses.save');

    Route::get('/admin/branch-assignments', [BranchAssignmentController::class, 'index'])
        ->middleware('admin')->name('admin.branch-assignments');

    Route::post('/admin/branch-assignments', [BranchAssignmentController::class, 'update'])
        ->middleware('admin')->name('admin.branch-assignments.update');

    Route::post('/admin/branches', [BranchAssignmentController::class, 'createBranch'])
        ->middleware('admin')
        ->name('admin.branches.store');

    Route::post('/admin/branches/{branch}/delete', [BranchAssignmentController::class, 'deleteBranch'])
        ->middleware('admin')
        ->name('admin.branches.delete');

    Route::post('/admin/branch-settings/{branch}', [BranchAssignmentController::class, 'updateBranchSettings'])
        ->middleware('admin')
        ->name('admin.branch-settings.update');


    Route::get('/admin/users', [App\Http\Controllers\Admin\UserManagementController::class, 'index'])
        ->middleware('admin')->name('admin.users');

    Route::post('/admin/users/{user}/toggle', [App\Http\Controllers\Admin\UserManagementController::class, 'toggle'])
        ->middleware('admin')->name('admin.users.toggle');

    Route::post('/admin/users/{user}/delete', [App\Http\Controllers\Admin\UserManagementController::class, 'delete'])
        ->middleware('admin')->name('admin.users.delete');

    Route::post('/admin/users/{user}/defaults', [App\Http\Controllers\Admin\UserManagementController::class, 'updateDefaults'])
        ->middleware('admin')->name('admin.users.defaults.update');
    Route::post('/admin/users/{user}/role', [App\Http\Controllers\Admin\UserManagementController::class, 'updateRole'])->middleware('admin')->name('admin.users.role.update');

    Route::get('/admin/listing-targets', [ListingTargetController::class, 'index'])
        ->middleware('branch_manager')->name('admin.listing-targets');

    Route::post('/admin/listing-targets', [ListingTargetController::class, 'store'])
        ->middleware('branch_manager')->name('admin.listing-targets.store');

    // Deals
    Route::get('/admin/deals', [DealController::class, 'index'])->name('admin.deals')->middleware('admin_or_bm');
    // Agent: My Deals (read-only, remarks via log)
    Route::get('/agent/deals', [DealRegisterController::class, 'index'])->name('agent.deals.index');
    Route::get('/agent/deals/{deal}/log', [DealRegisterController::class, 'log'])->name('agent.deals.log');
    Route::post('/agent/deals/{deal}/remark', [DealRegisterController::class, 'addRemark'])->name('agent.deals.remark');


    Route::get('/admin/deals/create', [DealController::class, 'create'])->name('admin.deals.create')->middleware('admin_or_bm');

    Route::post('/admin/deals', [DealController::class, 'store'])->name('admin.deals.store')->middleware('admin_or_bm');

    Route::get('/admin/deals/{deal}/edit', [DealController::class, 'edit'])->name('admin.deals.edit')->middleware('admin_or_bm');
    Route::get('/admin/deals/{deal}/log', [DealController::class, 'log'])->name('admin.deals.log')->middleware('admin_or_bm');
    Route::post('/admin/deals/{deal}/remark', [DealController::class, 'addRemark'])->name('admin.deals.remark')->middleware('admin_or_bm');

    Route::post('/admin/deals/{deal}', [DealController::class, 'update'])->name('admin.deals.update')->middleware('admin_or_bm');
    Route::post('/admin/deals/{deal}/quick', [DealController::class, 'quickUpdate'])->name('admin.deals.quickUpdate')->middleware('admin_or_bm');

    // Deal Settlement (Per-deal Pay screen)
    Route::get('/admin/deals/{deal}/settle', [DealController::class, 'settle'])
        ->middleware('admin_or_bm')->name('admin.deals.settle');

    Route::post('/admin/deals/{deal}/settle', [DealController::class, 'saveSettlement'])
        ->middleware('admin_or_bm')->name('admin.deals.settle.save');

    // Deal Settlement Printing
    Route::get('/admin/deals/{deal}/settle/print', [DealController::class, 'printSettlement'])
        ->middleware('admin_or_bm')->name('admin.deals.settle.print');

    Route::get('/admin/deals/{deal}/settle/print/{user}', [DealController::class, 'printAgentPayslip'])
        ->middleware('admin_or_bm')->name('admin.deals.settle.print.agent');

    Route::post('/admin/view-as', [ViewAsController::class, 'update'])->name('admin.viewas.update');
    Route::post('/admin/view-as/reset', [ViewAsController::class, 'clear'])->name('admin.viewas.reset');

});



// ===== LISTING IMPORT (ADMIN) =====
Route::middleware(['auth','admin'])->group(function () {
    Route::get('/admin/listings/import', [\App\Http\Controllers\Admin\ListingImportController::class, 'index'])
        ->name('admin.listings.import');

    Route::post('/admin/listings/import', [\App\Http\Controllers\Admin\ListingImportController::class, 'store'])
        ->name('admin.listings.import.store');
});


// ===== LISTING STOCK (ADMIN UI) =====
Route::middleware(['auth','admin'])->group(function () {
    Route::get('/admin/listings/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'agents'])
        ->name('admin.listings.agents');

    Route::get('/admin/listings/agents/{user}', [\App\Http\Controllers\Admin\ListingStockController::class, 'agentShow'])
        ->name('admin.listings.agents.show');

    Route::get('/admin/listings/stock', [\App\Http\Controllers\Admin\CompanyListingStockController::class, 'index'])
        ->middleware('admin_or_bm')->name('admin.listings.stock');


    // Admin: Fix listing agent assignment (primary + multi agents)
    Route::get('/admin/listings/stock/{listing}/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'editAgents'])
        ->name('admin.listings.stock.agents.edit');

    Route::post('/admin/listings/stock/{listing}/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'updateAgents'])
        ->name('admin.listings.stock.agents.update');
});



// Admin impersonation
Route::middleware(['auth'])->group(function () {

    Route::post('/admin/impersonate/{user}', [\App\Http\Controllers\Admin\ImpersonateController::class, 'start'])
        ->middleware('admin')->name('impersonate.start');

    Route::post('/admin/impersonate/stop', [\App\Http\Controllers\Admin\ImpersonateController::class, 'stop'])
        ->name('impersonate.stop');
    // Allow click-through (GET) stop for sidebar UX (session-gated)
});

require __DIR__.'/auth.php';

// ===== TARGETS_MODULE_2026 =====
use App\Http\Controllers\Admin\TargetController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\Tools\PdfSplitterController;

Route::middleware(['auth'])->group(function () {


    // Tools
    Route::get('/tools/commission', [ToolsController::class, 'commission'])->name('tools.commission');
    Route::get('/tools/cma', [ToolsController::class, 'cma'])->name('tools.cma');

    // Tools History (backend)
    Route::get('/tools/history', [ToolsController::class, 'historyIndex'])->name('tools.history.index');
    Route::post('/tools/history', [ToolsController::class, 'historyStore'])->name('tools.history.store');
    Route::get('/tools/history/{id}', [ToolsController::class, 'historyShow'])->name('tools.history.show');
    Route::delete('/tools/history/{id}', [ToolsController::class, 'historyDestroy'])->name('tools.history.destroy');

    // PDF Pack Splitter
    Route::get('/tools/pdf-splitter', [PdfSplitterController::class, 'index'])->name('tools.pdf_splitter.index');
    Route::post('/tools/pdf-splitter/run', [PdfSplitterController::class, 'run'])->name('tools.pdf_splitter.run');
    Route::get('/tools/pdf-splitter/review', [PdfSplitterController::class, 'review'])->name('tools.pdf_splitter.review');
    Route::post('/tools/pdf-splitter/confirm', [PdfSplitterController::class, 'confirm'])->name('tools.pdf_splitter.confirm');
    Route::get('/tools/pdf-splitter/thumb/{page}', [PdfSplitterController::class, 'serveThumb'])->name('tools.pdf_splitter.thumb')->where('page', '[0-9]+');
      // BM: My Agent Dashboard (BM's own numbers)
      Route::get('/bm/my-dashboard', [\App\Http\Controllers\BM\MyDashboardController::class, 'index'])->middleware('branch_manager')->name('bm.my.dashboard');


    // Agent Dashboard (agent-only)
    Route::get('/agent/dashboard', [\App\Http\Controllers\Agent\DashboardController::class, 'index'])->name('agent.dashboard');

    // Agent: My Listings (from imported listing stock)
    Route::get('/agent/listings', [\App\Http\Controllers\Agent\ListingStockController::class, 'index'])->name('agent.listings');
    Route::post('/agent/listings/{listing}/cma', [\App\Http\Controllers\Agent\ListingStockController::class, 'saveCma'])->name('agent.listings.cma');


    Route::get('/admin/targets', [TargetController::class, 'index'])->name('admin.targets');
    Route::post('/admin/targets', [TargetController::class, 'save'])->name('admin.targets.save');
    // Monthly Goals (Company + Branch)
    Route::get('/admin/monthly-goals', [MonthlyGoalController::class, 'index'])
        ->middleware('admin_or_bm')->name('admin.monthly-goals');

    Route::post('/admin/monthly-goals', [MonthlyGoalController::class, 'save'])
        ->middleware('admin_or_bm')->name('admin.monthly-goals.save');


    Route::post('/admin/targets/daily', [TargetController::class, 'saveDaily'])->name('admin.targets.daily.save');

    Route::get('/admin/performance', [\App\Http\Controllers\Admin\PerformanceController::class, 'index'])->middleware('admin_or_bm')->name('admin.performance');
    Route::get('/admin/branch/{branchId}/performance', [\App\Http\Controllers\Admin\BranchPerformanceController::class, 'index'])->middleware('admin_or_bm')->name('admin.branch.performance');
          Route::get('/bm/worksheet-market', [\App\Http\Controllers\BM\WorksheetMarketController::class, 'index'])
          ->middleware('branch_manager')->name('bm.worksheet.market');
      Route::post('/bm/worksheet-market', [\App\Http\Controllers\BM\WorksheetMarketController::class, 'save'])
          ->middleware('branch_manager')->name('bm.worksheet.market.save');

Route::get('/bm/performance', [\App\Http\Controllers\BM\PerformanceController::class, 'index'])->name('bm.performance');

Route::get('/bm/listings', [\App\Http\Controllers\BM\ListingStockController::class, 'index'])->middleware('branch_manager')->name('bm.listings');

    // ===== TV MESSAGES (Admin + BM) =====
    Route::middleware(['admin'])->group(function () {
        Route::get('/admin/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'adminIndex'])->name('admin.tv-messages');
        Route::post('/admin/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'adminStore'])->name('admin.tv-messages.store');
        Route::post('/admin/tv-messages/{tvMessage}', [\App\Http\Controllers\TvMessageController::class, 'adminUpdate'])->name('admin.tv-messages.update');
        Route::post('/admin/tv-messages/{tvMessage}/delete', [\App\Http\Controllers\TvMessageController::class, 'adminDelete'])->name('admin.tv-messages.delete');
    });

    Route::middleware(['branch_manager'])->group(function () {
        Route::get('/bm/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'bmIndex'])->name('bm.tv-messages');
        Route::post('/bm/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'bmStore'])->name('bm.tv-messages.store');
        Route::post('/bm/tv-messages/{tvMessage}', [\App\Http\Controllers\TvMessageController::class, 'bmUpdate'])->name('bm.tv-messages.update');
        Route::post('/bm/tv-messages/{tvMessage}/delete', [\App\Http\Controllers\TvMessageController::class, 'bmDelete'])->name('bm.tv-messages.delete');
    });


    Route::post('/bm/performance', [\App\Http\Controllers\BM\PerformanceController::class, 'save'])->middleware('branch_manager')->name('bm.performance.save');

    Route::get('/bm/agent/{userId}/performance', [\App\Http\Controllers\BM\AgentPerformanceController::class, 'show'])->name('bm.agent.performance');
    Route::get('/admin/agent/{userId}/performance', [\App\Http\Controllers\Admin\AgentPerformanceController::class, 'show'])->middleware('admin_or_bm')->name('admin.agent.performance');

    // Agent Daily Activity (agent menu link)
      // Agent Daily Activity (locked to agents only)
      Route::get('/agent/daily', [\App\Http\Controllers\Agent\DailyActivityController::class, 'index'])->name('agent.daily');
Route::get('/agent/daily/summary', [\App\Http\Controllers\Agent\DailyActivitySummaryController::class, 'index'])->name('agent.daily.summary');
Route::get('/agent/daily/summary/activity/{definition}', [\App\Http\Controllers\Agent\DailyActivitySummaryController::class, 'activity'])->name('agent.daily.summary.activity');


Route::get('/bm/daily/summary', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'index'])->name('bm.daily.summary');
Route::get('/bm/daily/summary/activity/{definition}', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'activity'])->name('bm.daily.summary.activity');
Route::get('/bm/daily/summary/activity/{definition}/agent/{user}', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'agent'])->name('bm.daily.summary.activity.agent');

Route::get('/admin/daily/summary', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'index'])->name('admin.daily.summary');
Route::get('/admin/daily/summary/activity/{definition}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'activity'])->name('admin.daily.summary.activity');
Route::get('/admin/daily/summary/activity/{definition}/branch/{branch}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'branch'])->name('admin.daily.summary.activity.branch');
Route::get('/admin/daily/summary/activity/{definition}/branch/{branch}/agent/{user}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'agent'])->name('admin.daily.summary.activity.branch.agent');


Route::get('/agent/daily/print', [\App\Http\Controllers\Agent\DailyActivityController::class, 'printSheet'])
    ->name('agent.daily.print');
        Route::post('/agent/daily', [\App\Http\Controllers\Agent\DailyActivityController::class, 'store']);
Route::get('/admin/targets/activity-setup', function () {
    return redirect()->route('admin.targets.activity.definitions');
})->name('admin.targets.activity.setup')->middleware('admin_or_bm');
    Route::post('/admin/targets/activity-setup', [TargetController::class, 'activitySetupSave'])->name('admin.targets.activity.setup.save')->middleware('admin_or_bm');
Route::get('/admin/targets/activity-definitions', [TargetController::class, 'activityDefinitions'])->name('admin.targets.activity.definitions')->middleware('admin_or_bm');
    Route::post('/admin/targets/activity-definitions', [TargetController::class, 'activityDefinitionsSave'])->name('admin.targets.activity.definitions.save')->middleware('admin_or_bm');


      Route::post('/admin/targets/activity-columns', [TargetController::class, 'activityColumnCreate'])->name('admin.targets.activity.columns.create')->middleware('admin');
});


Route::post('bm/performance/set-agent-targets', [\App\Http\Controllers\BM\PerformanceController::class, 'setAgentTargets'])
    ->middleware('branch_manager')->name('bm.performance.setAgentTargets');

Route::post('bm/performance/align-agent-to-company', [\App\Http\Controllers\BM\PerformanceController::class, 'alignAgentToCompany'])
    ->middleware('branch_manager')->name('bm.performance.alignAgentToCompany');

Route::post('bm/performance/align-targets', [\App\Http\Controllers\BM\PerformanceController::class, 'alignTargets'])->name('bm.performance.align');

// --- TV (no login, token-protected) ---
Route::get('/tv/branch/{branchId}', [\App\Http\Controllers\TV\BranchTvController::class, 'show'])
    ->middleware('tv')
    ->name('tv.branch');


Route::post('/worksheet/align-company-target', [\App\Http\Controllers\WorksheetController::class, 'alignToCompany'])
    ->name('worksheet.align');

Route::post('/worksheet/apply-branch-default', [\App\Http\Controllers\WorksheetController::class, 'applyBranchDefault'])->name('worksheet.applyBranchDefault');



// Admin: Performance Settings
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/performance-settings', [\App\Http\Controllers\Admin\PerformanceSettingsController::class, 'edit'])
        ->name('admin.performance-settings.edit');

    Route::post('/admin/performance-settings', [\App\Http\Controllers\Admin\PerformanceSettingsController::class, 'update'])
        ->name('admin.performance-settings.update');
});




// Admin: Designations (dropdown list management)
Route::middleware(['auth','verified','admin'])->group(function () {
    Route::get('/admin/designations', [\App\Http\Controllers\Admin\DesignationController::class, 'index'])
        ->name('admin.designations.index');
    Route::post('/admin/designations', [\App\Http\Controllers\Admin\DesignationController::class, 'store'])
        ->name('admin.designations.store');
    Route::post('/admin/designations/{designation}', [\App\Http\Controllers\Admin\DesignationController::class, 'update'])
        ->name('admin.designations.update');
    Route::post('/admin/designations/{designation}/delete', [\App\Http\Controllers\Admin\DesignationController::class, 'delete'])
        ->name('admin.designations.delete');
});


    // ---- Admin: Worksheet Market (per-branch / per-agent market inputs) ----
    Route::get('/admin/worksheet-market', [\App\Http\Controllers\Admin\WorksheetMarketController::class, 'index'])
        ->middleware(['auth','verified','admin'])->name('admin.worksheet-market');
    Route::post('/admin/worksheet-market', [\App\Http\Controllers\Admin\WorksheetMarketController::class, 'store'])
        ->middleware(['auth','verified','admin'])->name('admin.worksheet-market.store');

/*
|--------------------------------------------------------------------------
| Rentals
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    Route::get('/rentals', [\App\Http\Controllers\RentalsController::class, 'index'])
        ->name('rentals.index');

    Route::get('/rentals/create', [\App\Http\Controllers\RentalsController::class, 'create'])
        ->name('rentals.create');

    Route::get('/rentals/{id}/edit', [\App\Http\Controllers\RentalsController::class, 'edit'])
        ->name('rentals.edit');

    Route::post('/rentals', [\App\Http\Controllers\RentalsController::class, 'store'])
        ->name('rentals.store');

    Route::post('/rentals/{id}', [\App\Http\Controllers\RentalsController::class, 'update'])
        ->whereNumber('id')
        ->name('rentals.update');


});

/*
|--------------------------------------------------------------------------
| Rental Permissions
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    Route::get('/rentals/permissions', [\App\Http\Controllers\RentalPermissionsController::class, 'index'])
        ->name('rentals.permissions');

    Route::post('/rentals/permissions', [\App\Http\Controllers\RentalPermissionsController::class, 'update'])
        ->name('rentals.permissions.update');

});

// Internal: Nginx auth_request gate for /ai (returns 200 or 401, no redirects)
Route::get('/internal/ai-auth-check', [\App\Http\Controllers\Internal\AiAuthController::class, 'check'])
    ->name('internal.ai-auth-check');

Route::post('/internal/ai-chat-proxy', [\App\Http\Controllers\Internal\AiChatProxyController::class, 'chat'])
    ->middleware('auth')
    ->name('internal.ai-chat-proxy');

Route::get('/ai-buddy', fn() => redirect()->route('ellie.index'))->middleware('auth')->name('ai.buddy');

// ===== NEXUS OS ROUTES =====
use App\Http\Controllers\Nexus\DashboardController as NexusDashboardController;
use App\Http\Controllers\Nexus\PlaceholderController as NexusPlaceholderController;
use App\Http\Controllers\Nexus\SettingsController as NexusSettingsController;
use App\Http\Controllers\Nexus\RoleManagerController as NexusRoleManagerController;

Route::middleware(['auth', 'verified'])->prefix('nexus')->group(function () {
    Route::get('/', [NexusDashboardController::class, 'index'])->name('nexus.dashboard');

    Route::get('/documents', [NexusPlaceholderController::class, 'show'])->defaults('section', 'documents')->name('nexus.documents');
    Route::get('/compliance', [NexusPlaceholderController::class, 'show'])->defaults('section', 'compliance')->name('nexus.compliance');
    Route::get('/supervision', [NexusPlaceholderController::class, 'show'])->defaults('section', 'supervision')->name('nexus.supervision');
    Route::get('/training', [NexusPlaceholderController::class, 'show'])->defaults('section', 'training')->name('nexus.training');
    Route::get('/communication', [NexusPlaceholderController::class, 'show'])->defaults('section', 'communication')->name('nexus.communication');
    Route::get('/client-portal', [NexusPlaceholderController::class, 'show'])->defaults('section', 'client-portal')->name('nexus.client-portal');
    Route::get('/franchise-admin', [NexusPlaceholderController::class, 'show'])->defaults('section', 'franchise-admin')->name('nexus.franchise-admin');

    // Settings (admin only)
    Route::get('/settings', [NexusSettingsController::class, 'index'])->name('nexus.settings');

    // Role Manager (admin only)
    Route::get('/role-manager', [NexusRoleManagerController::class, 'index'])->name('nexus.role-manager');
    Route::post('/role-manager/permissions', [NexusRoleManagerController::class, 'savePermissions'])
        ->middleware('admin')->name('nexus.role-manager.save');
    Route::post('/role-manager/user-role', [NexusRoleManagerController::class, 'updateUserRole'])
        ->middleware('admin')->name('nexus.role-manager.user-role');
});

Route::get('tools/pdf-splitter/download', [\App\Http\Controllers\Tools\PdfSplitterController::class, 'downloadLastZip'])->name('tools.pdf_splitter.download');

// ===== PRESENTATIONS =====
Route::middleware(['auth'])->prefix('presentations')->name('presentations.')->group(function () {
    Route::get('/',       [\App\Http\Controllers\Presentation\PresentationController::class, 'index'])  ->name('index');
    Route::get('/create', [\App\Http\Controllers\Presentation\PresentationController::class, 'create']) ->name('create');
    Route::post('/',      [\App\Http\Controllers\Presentation\PresentationController::class, 'store'])  ->name('store');

    Route::get('/{presentation}',              [\App\Http\Controllers\Presentation\PresentationController::class, 'show'])     ->name('show');
    Route::get('/{presentation}/analysis',     [\App\Http\Controllers\Presentation\PresentationController::class, 'analysis']) ->name('analysis');
    Route::post('/{presentation}/analysis/run',[\App\Http\Controllers\Presentation\PresentationController::class, 'compute'])  ->name('analysis.run');

    Route::post('/{presentation}/upload', [\App\Http\Controllers\Presentation\PresentationController::class, 'upload'])
        ->name('upload');

    Route::post('/{presentation}/links', [\App\Http\Controllers\Presentation\PresentationController::class, 'storeLink'])
        ->name('links.store');
    Route::delete('/{presentation}/links/{link}', [\App\Http\Controllers\Presentation\PresentationController::class, 'destroyLink'])
        ->name('links.destroy');

    // Snapshot routes — names preserved for existing tests
    Route::post('/{presentation}/snapshots', [\App\Http\Controllers\Presentation\PresentationSnapshotController::class, 'saveSnapshot'])
        ->name('snapshots.save');
    Route::get('/{presentation}/snapshots/{snapshot}', [\App\Http\Controllers\Presentation\PresentationSnapshotController::class, 'showSnapshot'])
        ->name('snapshots.show');
});
