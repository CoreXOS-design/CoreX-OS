<?php

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
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/dashboard', function () {
    return redirect()->route('corex.dashboard');
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
        return redirect()->route('corex.dashboard')->with('success', 'You are now an Admin.');
    });

    // Ellie (AI Assistant)
    Route::get('/ellie', [\App\Http\Controllers\EllieController::class, 'index'])
        ->middleware('permission:access_ellie')->name('ellie.index');

    Route::post('/ellie/send', [\App\Http\Controllers\EllieController::class, 'send'])
        ->middleware('permission:access_ellie')->name('ellie.send');

    // ELLIE_ROUTES_2026
    Route::post('/ellie/rename', [\App\Http\Controllers\EllieController::class, 'rename'])
        ->middleware('permission:access_ellie')->name('ellie.rename');

    Route::post('/ellie/archive', [\App\Http\Controllers\EllieController::class, 'archive'])
        ->middleware('permission:access_ellie')->name('ellie.archive');

    Route::post('/ellie/unarchive', [\App\Http\Controllers\EllieController::class, 'unarchive'])
        ->middleware('permission:access_ellie')->name('ellie.unarchive');

    // Calculators
    Route::get('/calculators', [\App\Http\Controllers\CalculatorController::class, 'index'])->middleware('permission:access_calculators')->name('calculators.index');
    Route::post('/calculators/commission', [\App\Http\Controllers\CalculatorController::class, 'calculateCommission'])->middleware('permission:access_calculators')->name('calculators.commission');
    Route::post('/calculators/bond', [\App\Http\Controllers\CalculatorController::class, 'calculateBond'])->middleware('permission:access_calculators')->name('calculators.bond');
    Route::post('/calculators/transfer-costs', [\App\Http\Controllers\CalculatorController::class, 'calculateTransferCosts'])->middleware('permission:access_calculators')->name('calculators.transferCosts');
    Route::post('/calculators/upload-fee-sheet', [\App\Http\Controllers\CalculatorController::class, 'uploadFeeSheet'])->middleware('permission:access_calculators')->name('calculators.uploadFeeSheet');
    Route::post('/calculators/bond-overpayment', [\App\Http\Controllers\CalculatorController::class, 'calculateBondOverpayment'])->middleware('permission:access_calculators')->name('calculators.bondOverpayment');

    Route::get('/worksheet', [WorksheetController::class, 'index'])->name('worksheet.index');
    Route::post('/worksheet', [WorksheetController::class, 'store'])->name('worksheet.store');

    Route::get('/company-summary', [CompanySummaryController::class, 'index'])->name('company.summary');

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('permission:export_reports')->name('admin.dashboard');

    Route::post('/admin/dashboard/expenses', [AdminDashboardController::class, 'saveExpenses'])
        ->middleware('permission:export_reports')->name('admin.expenses.save');

    Route::get('/admin/branch-assignments', [BranchAssignmentController::class, 'index'])
        ->middleware('permission:access_branch_assignments')->name('admin.branch-assignments');

    Route::post('/admin/branch-assignments', [BranchAssignmentController::class, 'update'])
        ->middleware('permission:access_branch_assignments')->name('admin.branch-assignments.update');

    Route::post('/admin/branches', [BranchAssignmentController::class, 'createBranch'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branches.store');

    Route::post('/admin/branches/{branch}/delete', [BranchAssignmentController::class, 'deleteBranch'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branches.delete');

    Route::post('/admin/branch-settings/{branch}', [BranchAssignmentController::class, 'updateBranchSettings'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branch-settings.update');


    Route::get('/admin/users', [App\Http\Controllers\Admin\UserManagementController::class, 'index'])
        ->middleware('permission:manage_users')->name('admin.users');

    Route::post('/admin/users/{user}/toggle', [App\Http\Controllers\Admin\UserManagementController::class, 'toggle'])
        ->middleware('permission:manage_users')->name('admin.users.toggle');

    Route::post('/admin/users/{user}/delete', [App\Http\Controllers\Admin\UserManagementController::class, 'delete'])
        ->middleware('permission:manage_users')->name('admin.users.delete');

    Route::post('/admin/users/{user}/defaults', [App\Http\Controllers\Admin\UserManagementController::class, 'updateDefaults'])
        ->middleware('permission:manage_users')->name('admin.users.defaults.update');
    Route::post('/admin/users/{user}/role', [App\Http\Controllers\Admin\UserManagementController::class, 'updateRole'])->middleware('permission:manage_users')->name('admin.users.role.update');
    Route::post('/admin/users/{user}/remove-file', [App\Http\Controllers\Admin\UserManagementController::class, 'removeAgentFile'])->middleware('permission:manage_users')->name('admin.users.remove-file');

    Route::get('/admin/listing-targets', [ListingTargetController::class, 'index'])
        ->middleware('permission:manage_targets')->name('admin.listing-targets');

    Route::post('/admin/listing-targets', [ListingTargetController::class, 'store'])
        ->middleware('permission:manage_targets')->name('admin.listing-targets.store');

    // Deals
    Route::get('/admin/deals', [DealController::class, 'index'])->name('admin.deals')->middleware('permission:create_deals');
    // Agent: My Deals (read-only, remarks via log)
    Route::get('/agent/deals', [DealRegisterController::class, 'index'])->name('agent.deals.index')->middleware('permission:view_deals');
    Route::get('/agent/deals/{deal}/log', [DealRegisterController::class, 'log'])->name('agent.deals.log')->middleware('permission:view_deals');
    Route::post('/agent/deals/{deal}/remark', [DealRegisterController::class, 'addRemark'])->name('agent.deals.remark')->middleware('permission:view_deals');


    Route::get('/admin/deals/create', [DealController::class, 'create'])->name('admin.deals.create')->middleware('permission:create_deals');

    Route::post('/admin/deals', [DealController::class, 'store'])->name('admin.deals.store')->middleware('permission:create_deals');

    Route::get('/admin/deals/{deal}/edit', [DealController::class, 'edit'])->name('admin.deals.edit')->middleware('permission:create_deals');
    Route::get('/admin/deals/{deal}/log', [DealController::class, 'log'])->name('admin.deals.log')->middleware('permission:create_deals');
    Route::post('/admin/deals/{deal}/remark', [DealController::class, 'addRemark'])->name('admin.deals.remark')->middleware('permission:create_deals');

    Route::post('/admin/deals/{deal}', [DealController::class, 'update'])->name('admin.deals.update')->middleware('permission:create_deals');
    Route::post('/admin/deals/{deal}/quick', [DealController::class, 'quickUpdate'])->name('admin.deals.quickUpdate')->middleware('permission:create_deals');

    // Deal Settlement (Per-deal Pay screen)
    Route::get('/admin/deals/{deal}/settle', [DealController::class, 'settle'])
        ->middleware('permission:settle_deals')->name('admin.deals.settle');

    Route::post('/admin/deals/{deal}/settle', [DealController::class, 'saveSettlement'])
        ->middleware('permission:settle_deals')->name('admin.deals.settle.save');

    // Deal Settlement Printing
    Route::get('/admin/deals/{deal}/settle/print', [DealController::class, 'printSettlement'])
        ->middleware('permission:settle_deals')->name('admin.deals.settle.print');

    Route::get('/admin/deals/{deal}/settle/print/{user}', [DealController::class, 'printAgentPayslip'])
        ->middleware('permission:settle_deals')->name('admin.deals.settle.print.agent');

    Route::post('/admin/view-as', [ViewAsController::class, 'update'])->name('admin.viewas.update');
    Route::post('/admin/view-as/reset', [ViewAsController::class, 'clear'])->name('admin.viewas.reset');

});

// ===== P24 MARKET INTELLIGENCE =====
Route::prefix('admin/p24')->middleware(['auth', 'permission:manage_p24'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\P24Controller::class, 'index'])->name('admin.p24.index');
    Route::get('/listings', [\App\Http\Controllers\Admin\P24Controller::class, 'listings'])->name('admin.p24.listings');
    Route::post('/import', [\App\Http\Controllers\Admin\P24Controller::class, 'runImport'])->name('admin.p24.import');
});

// ===== KNOWLEDGE BASE =====
Route::prefix('admin/knowledge')->middleware(['auth', 'permission:access_knowledge_base'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\KnowledgeController::class, 'index'])->name('admin.knowledge.index');
    Route::get('/category/{id}', [\App\Http\Controllers\Admin\KnowledgeController::class, 'show'])->name('admin.knowledge.category');
    Route::post('/upload', [\App\Http\Controllers\Admin\KnowledgeController::class, 'upload'])->name('admin.knowledge.upload');
    Route::post('/{id}/toggle-active', [\App\Http\Controllers\Admin\KnowledgeController::class, 'toggleActive'])->name('admin.knowledge.toggleActive');
    Route::post('/{id}/toggle-ellie', [\App\Http\Controllers\Admin\KnowledgeController::class, 'toggleEllie'])->name('admin.knowledge.toggleEllie');
    Route::post('/{id}/reprocess', [\App\Http\Controllers\Admin\KnowledgeController::class, 'reprocess'])->name('admin.knowledge.reprocess');
    Route::delete('/{id}', [\App\Http\Controllers\Admin\KnowledgeController::class, 'destroy'])->name('admin.knowledge.destroy');
    Route::get('/{id}/preview', [\App\Http\Controllers\Admin\KnowledgeController::class, 'preview'])->name('admin.knowledge.preview');

    // Category CRUD
    Route::post('/categories', [\App\Http\Controllers\Admin\KnowledgeController::class, 'storeCategory'])->name('admin.knowledge.storeCategory');
    Route::put('/categories/{id}', [\App\Http\Controllers\Admin\KnowledgeController::class, 'updateCategory'])->name('admin.knowledge.updateCategory');
    Route::delete('/categories/{id}', [\App\Http\Controllers\Admin\KnowledgeController::class, 'deleteCategory'])->name('admin.knowledge.deleteCategory');
    Route::post('/categories/reorder', [\App\Http\Controllers\Admin\KnowledgeController::class, 'reorderCategories'])->name('admin.knowledge.reorderCategories');
});

// ===== LISTING IMPORT =====
Route::middleware(['auth','permission:import_listings'])->group(function () {
    Route::get('/admin/listings/import', [\App\Http\Controllers\Admin\ListingImportController::class, 'index'])
        ->name('admin.listings.import');

    Route::post('/admin/listings/import', [\App\Http\Controllers\Admin\ListingImportController::class, 'store'])
        ->name('admin.listings.import.store');
});


// ===== LISTING STOCK =====
Route::middleware(['auth','permission:view_listings'])->group(function () {
    Route::get('/admin/listings/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'agents'])
        ->name('admin.listings.agents');

    Route::get('/admin/listings/agents/{user}', [\App\Http\Controllers\Admin\ListingStockController::class, 'agentShow'])
        ->name('admin.listings.agents.show');

    Route::get('/admin/listings/stock', [\App\Http\Controllers\Admin\CompanyListingStockController::class, 'index'])
        ->name('admin.listings.stock');


    // Admin: Fix listing agent assignment (primary + multi agents)
    Route::get('/admin/listings/stock/{listing}/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'editAgents'])
        ->name('admin.listings.stock.agents.edit');

    Route::post('/admin/listings/stock/{listing}/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'updateAgents'])
        ->name('admin.listings.stock.agents.update');
});



// Admin impersonation
Route::middleware(['auth'])->group(function () {

    Route::post('/admin/impersonate/stop', [\App\Http\Controllers\Admin\ImpersonateController::class, 'stop'])
        ->name('impersonate.stop');

    Route::post('/admin/impersonate/{user}', [\App\Http\Controllers\Admin\ImpersonateController::class, 'start'])
        ->middleware('permission:impersonate_users')->name('impersonate.start');
    // Allow click-through (GET) stop for sidebar UX (session-gated)
});

require __DIR__.'/auth.php';

// ===== TARGETS_MODULE_2026 =====
use App\Http\Controllers\Admin\TargetController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\Tools\PdfSplitterController;

Route::middleware(['auth'])->group(function () {


    // Tools
    Route::get('/tools/commission', [ToolsController::class, 'commission'])->middleware('permission:access_calculators')->name('tools.commission');
    Route::get('/tools/cma', [ToolsController::class, 'cma'])->middleware('permission:access_calculators')->name('tools.cma');

    // Tools History (backend)
    Route::get('/tools/history', [ToolsController::class, 'historyIndex'])->middleware('permission:access_calculators')->name('tools.history.index');
    Route::post('/tools/history', [ToolsController::class, 'historyStore'])->middleware('permission:access_calculators')->name('tools.history.store');
    Route::get('/tools/history/{id}', [ToolsController::class, 'historyShow'])->middleware('permission:access_calculators')->name('tools.history.show');
    Route::delete('/tools/history/{id}', [ToolsController::class, 'historyDestroy'])->middleware('permission:access_calculators')->name('tools.history.destroy');

    // PDF Pack Splitter
    Route::get('/tools/pdf-splitter', [PdfSplitterController::class, 'index'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.index');
    Route::post('/tools/pdf-splitter/run', [PdfSplitterController::class, 'run'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.run');
    Route::get('/tools/pdf-splitter/review', [PdfSplitterController::class, 'review'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.review');
    Route::post('/tools/pdf-splitter/confirm', [PdfSplitterController::class, 'confirm'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.confirm');
    Route::get('/tools/pdf-splitter/thumb/{page}', [PdfSplitterController::class, 'serveThumb'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.thumb')->where('page', '[0-9]+');
    Route::get('/tools/pdf-splitter/download', [PdfSplitterController::class, 'downloadLastZip'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.download');

    // Splitter Doc Type Admin
    Route::get('/admin/splitter/doc-types', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'index'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.index');
    Route::post('/admin/splitter/doc-types', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'store'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.store');
    Route::put('/admin/splitter/doc-types/{doc_type}', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'update'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.update');
    Route::delete('/admin/splitter/doc-types/{doc_type}', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'destroy'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.destroy');
    Route::post('/admin/splitter/doc-types/bulk-save', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'bulkSave'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.bulk-save');

      // BM: My Agent Dashboard (BM's own numbers)
      Route::get('/bm/my-dashboard', [\App\Http\Controllers\BM\MyDashboardController::class, 'index'])->middleware('permission:view_performance')->name('bm.my.dashboard');


    // Agent Dashboard (agent-only)
    Route::get('/agent/dashboard', [\App\Http\Controllers\Agent\DashboardController::class, 'index'])->middleware('permission:view_dashboard')->name('agent.dashboard');

    // Agent: My Listings (from imported listing stock)
    Route::get('/agent/listings', [\App\Http\Controllers\Agent\ListingStockController::class, 'index'])->middleware('permission:view_listings')->name('agent.listings');
    Route::post('/agent/listings/{listing}/cma', [\App\Http\Controllers\Agent\ListingStockController::class, 'saveCma'])->middleware('permission:view_listings')->name('agent.listings.cma');


    Route::get('/admin/targets', [TargetController::class, 'index'])->middleware('permission:manage_targets')->name('admin.targets');
    Route::post('/admin/targets', [TargetController::class, 'save'])->middleware('permission:manage_targets')->name('admin.targets.save');
    // Monthly Goals (Company + Branch)
    Route::get('/admin/monthly-goals', [MonthlyGoalController::class, 'index'])
        ->middleware('permission:manage_targets')->name('admin.monthly-goals');

    Route::post('/admin/monthly-goals', [MonthlyGoalController::class, 'save'])
        ->middleware('permission:manage_targets')->name('admin.monthly-goals.save');


    Route::post('/admin/targets/daily', [TargetController::class, 'saveDaily'])->middleware('permission:manage_targets')->name('admin.targets.daily.save');

    Route::get('/admin/performance', [\App\Http\Controllers\Admin\PerformanceController::class, 'index'])->middleware('permission:view_performance')->name('admin.performance');
    Route::get('/admin/branch/{branchId}/performance', [\App\Http\Controllers\Admin\BranchPerformanceController::class, 'index'])->middleware('permission:view_performance')->name('admin.branch.performance');
          Route::get('/bm/worksheet-market', [\App\Http\Controllers\BM\WorksheetMarketController::class, 'index'])
          ->middleware('permission:access_worksheet_market')->name('bm.worksheet.market');
      Route::post('/bm/worksheet-market', [\App\Http\Controllers\BM\WorksheetMarketController::class, 'save'])
          ->middleware('permission:access_worksheet_market')->name('bm.worksheet.market.save');

Route::get('/bm/performance', [\App\Http\Controllers\BM\PerformanceController::class, 'index'])->middleware('permission:view_performance')->name('bm.performance');

Route::get('/bm/listings', [\App\Http\Controllers\BM\ListingStockController::class, 'index'])->middleware('permission:access_listing_stock')->name('bm.listings');

    // ===== TV MESSAGES (Admin + BM) =====
    Route::middleware(['permission:manage_tv_messages'])->group(function () {
        Route::get('/admin/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'adminIndex'])->name('admin.tv-messages');
        Route::post('/admin/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'adminStore'])->name('admin.tv-messages.store');
        Route::post('/admin/tv-messages/{tvMessage}', [\App\Http\Controllers\TvMessageController::class, 'adminUpdate'])->name('admin.tv-messages.update');
        Route::post('/admin/tv-messages/{tvMessage}/delete', [\App\Http\Controllers\TvMessageController::class, 'adminDelete'])->name('admin.tv-messages.delete');

        // Admin: TV Code Management (all branches)
        Route::post('/admin/tv-code/generate', [\App\Http\Controllers\Admin\TvCodeController::class, 'generate'])->name('admin.tv-code.generate');
        Route::post('/admin/tv-code/revoke', [\App\Http\Controllers\Admin\TvCodeController::class, 'revoke'])->name('admin.tv-code.revoke');
        Route::post('/admin/tv-code/generate-company', [\App\Http\Controllers\Admin\TvCodeController::class, 'generateCompany'])->name('admin.tv-code.generate-company');
        Route::post('/admin/tv-code/revoke-company', [\App\Http\Controllers\Admin\TvCodeController::class, 'revokeCompany'])->name('admin.tv-code.revoke-company');

        // Agency switcher (super admin)
        Route::post('/agency/switch/clear', [\App\Http\Controllers\Admin\AgencySwitcherController::class, 'clear'])->middleware('permission:access_agencies')->name('agency.switch.clear');
        Route::post('/agency/switch/{agency}', [\App\Http\Controllers\Admin\AgencySwitcherController::class, 'switch'])->middleware('permission:access_agencies')->name('agency.switch');
    });

    Route::middleware(['permission:manage_tv_messages'])->group(function () {
        Route::get('/bm/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'bmIndex'])->name('bm.tv-messages');
        Route::post('/bm/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'bmStore'])->name('bm.tv-messages.store');
        Route::post('/bm/tv-messages/{tvMessage}', [\App\Http\Controllers\TvMessageController::class, 'bmUpdate'])->name('bm.tv-messages.update');
        Route::post('/bm/tv-messages/{tvMessage}/delete', [\App\Http\Controllers\TvMessageController::class, 'bmDelete'])->name('bm.tv-messages.delete');
    });


    Route::post('/bm/performance', [\App\Http\Controllers\BM\PerformanceController::class, 'save'])->middleware('permission:manage_targets')->name('bm.performance.save');

    Route::get('/bm/agent/{userId}/performance', [\App\Http\Controllers\BM\AgentPerformanceController::class, 'show'])->middleware('permission:view_performance')->name('bm.agent.performance');
    Route::get('/admin/agent/{userId}/performance', [\App\Http\Controllers\Admin\AgentPerformanceController::class, 'show'])->middleware('permission:view_performance')->name('admin.agent.performance');

    // Agent Daily Activity (agent menu link)
      // Agent Daily Activity (locked to agents only)
      Route::get('/agent/daily', [\App\Http\Controllers\Agent\DailyActivityController::class, 'index'])->middleware('permission:access_daily_activity')->name('agent.daily');
Route::get('/agent/daily/summary', [\App\Http\Controllers\Agent\DailyActivitySummaryController::class, 'index'])->middleware('permission:view_daily_activity')->name('agent.daily.summary');
Route::get('/agent/daily/summary/activity/{definition}', [\App\Http\Controllers\Agent\DailyActivitySummaryController::class, 'activity'])->middleware('permission:view_daily_activity')->name('agent.daily.summary.activity');


Route::get('/bm/daily/summary', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'index'])->middleware('permission:view_daily_activity')->name('bm.daily.summary');
Route::get('/bm/daily/summary/activity/{definition}', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'activity'])->middleware('permission:view_daily_activity')->name('bm.daily.summary.activity');
Route::get('/bm/daily/summary/activity/{definition}/agent/{user}', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'agent'])->middleware('permission:view_daily_activity')->name('bm.daily.summary.activity.agent');

Route::get('/admin/daily/summary', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'index'])->middleware('permission:view_daily_activity')->name('admin.daily.summary');
Route::get('/admin/daily/summary/activity/{definition}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'activity'])->middleware('permission:view_daily_activity')->name('admin.daily.summary.activity');
Route::get('/admin/daily/summary/activity/{definition}/branch/{branch}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'branch'])->middleware('permission:view_daily_activity')->name('admin.daily.summary.activity.branch');
Route::get('/admin/daily/summary/activity/{definition}/branch/{branch}/agent/{user}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'agent'])->middleware('permission:view_daily_activity')->name('admin.daily.summary.activity.branch.agent');


Route::get('/agent/daily/print', [\App\Http\Controllers\Agent\DailyActivityController::class, 'printSheet'])
    ->middleware('permission:access_daily_activity')->name('agent.daily.print');
        Route::post('/agent/daily', [\App\Http\Controllers\Agent\DailyActivityController::class, 'store'])->middleware('permission:access_daily_activity');
Route::get('/admin/targets/activity-setup', function () {
    return redirect()->route('admin.targets.activity.definitions');
})->name('admin.targets.activity.setup')->middleware('permission:manage_targets');
    Route::post('/admin/targets/activity-setup', [TargetController::class, 'activitySetupSave'])->name('admin.targets.activity.setup.save')->middleware('permission:manage_targets');
Route::get('/admin/targets/activity-definitions', [TargetController::class, 'activityDefinitions'])->name('admin.targets.activity.definitions')->middleware('permission:manage_targets');
    Route::post('/admin/targets/activity-definitions', [TargetController::class, 'activityDefinitionsSave'])->name('admin.targets.activity.definitions.save')->middleware('permission:manage_targets');


      Route::post('/admin/targets/activity-columns', [TargetController::class, 'activityColumnCreate'])->name('admin.targets.activity.columns.create')->middleware('permission:manage_targets');
});


Route::post('bm/performance/set-agent-targets', [\App\Http\Controllers\BM\PerformanceController::class, 'setAgentTargets'])
    ->middleware(['auth', 'permission:manage_targets'])->name('bm.performance.setAgentTargets');

// --- BM: TV Code Management ---
Route::post('/bm/tv-code/generate', [\App\Http\Controllers\BM\TvCodeController::class, 'generate'])
    ->middleware(['auth', 'permission:manage_tv_messages'])->name('bm.tv-code.generate');
Route::post('/bm/tv-code/revoke', [\App\Http\Controllers\BM\TvCodeController::class, 'revoke'])
    ->middleware(['auth', 'permission:manage_tv_messages'])->name('bm.tv-code.revoke');

Route::post('bm/performance/align-agent-to-company', [\App\Http\Controllers\BM\PerformanceController::class, 'alignAgentToCompany'])
    ->middleware(['auth', 'permission:manage_targets'])->name('bm.performance.alignAgentToCompany');

Route::post('bm/performance/align-targets', [\App\Http\Controllers\BM\PerformanceController::class, 'alignTargets'])->middleware(['auth', 'permission:manage_targets'])->name('bm.performance.align');

// --- TV (no login, token-protected — legacy) ---
Route::get('/tv/branch/{branchId}', [\App\Http\Controllers\TV\BranchTvController::class, 'show'])
    ->middleware('tv')
    ->name('tv.branch');

// --- TV (code-based auth — new) ---
Route::get('/tv', [\App\Http\Controllers\TV\TvController::class, 'index'])->name('tv.index');
Route::post('/tv/verify', [\App\Http\Controllers\TV\TvController::class, 'verify'])->name('tv.verify');
Route::get('/tv/display/{code}', [\App\Http\Controllers\TV\TvController::class, 'display'])->name('tv.display');


Route::post('/worksheet/align-company-target', [\App\Http\Controllers\WorksheetController::class, 'alignToCompany'])
    ->name('worksheet.align');

Route::post('/worksheet/apply-branch-default', [\App\Http\Controllers\WorksheetController::class, 'applyBranchDefault'])->name('worksheet.applyBranchDefault');



// Admin: Performance Settings
Route::middleware(['auth', 'permission:manage_performance_settings'])->group(function () {
    Route::get('/admin/performance-settings', [\App\Http\Controllers\Admin\PerformanceSettingsController::class, 'edit'])
        ->name('admin.performance-settings.edit');

    Route::post('/admin/performance-settings', [\App\Http\Controllers\Admin\PerformanceSettingsController::class, 'update'])
        ->name('admin.performance-settings.update');
});

// Admin: P24 Suburb Mappings
Route::middleware(['auth', 'permission:manage_p24'])->group(function () {
    Route::get('/settings/p24-suburbs', [\App\Http\Controllers\Admin\P24SuburbController::class, 'index'])
        ->name('admin.p24-suburbs.index');
    Route::post('/settings/p24-suburbs', [\App\Http\Controllers\Admin\P24SuburbController::class, 'store'])
        ->name('admin.p24-suburbs.store');
    Route::put('/settings/p24-suburbs/{p24Suburb}', [\App\Http\Controllers\Admin\P24SuburbController::class, 'update'])
        ->name('admin.p24-suburbs.update');
    Route::delete('/settings/p24-suburbs/{p24Suburb}', [\App\Http\Controllers\Admin\P24SuburbController::class, 'destroy'])
        ->name('admin.p24-suburbs.destroy');
});




// Admin: Designations (dropdown list management)
Route::middleware(['auth','verified','permission:manage_designations'])->group(function () {
    Route::get('/admin/designations', [\App\Http\Controllers\Admin\DesignationController::class, 'index'])
        ->name('admin.designations.index');
    Route::post('/admin/designations', [\App\Http\Controllers\Admin\DesignationController::class, 'store'])
        ->name('admin.designations.store');
    Route::post('/admin/designations/{designation}', [\App\Http\Controllers\Admin\DesignationController::class, 'update'])
        ->name('admin.designations.update');
    Route::post('/admin/designations/{designation}/delete', [\App\Http\Controllers\Admin\DesignationController::class, 'delete'])
        ->name('admin.designations.delete');
});

// ===== FINANCE ENGINE & AUDIT (Admin only) =====
Route::middleware(['auth','verified','permission:access_finance_engine'])->group(function () {
    Route::get('/admin/finance/definitions', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'definitions'])
        ->name('admin.finance.definitions');
    Route::get('/admin/finance/audit', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'index'])
        ->name('admin.finance.audit.index');
    Route::get('/admin/finance/audit/runs/{run}', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'run'])
        ->name('admin.finance.audit.run');
    Route::get('/admin/finance/audit/deals/{deal}', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'deal'])
        ->name('admin.finance.audit.deal');
    Route::post('/admin/finance/recalculate', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'recalculate'])
        ->name('admin.finance.recalculate');
});


    // ---- Admin: Worksheet Market (per-branch / per-agent market inputs) ----
    Route::get('/admin/worksheet-market', [\App\Http\Controllers\Admin\WorksheetMarketController::class, 'index'])
        ->middleware(['auth','verified','permission:edit_worksheet'])->name('admin.worksheet-market');
    Route::post('/admin/worksheet-market', [\App\Http\Controllers\Admin\WorksheetMarketController::class, 'store'])
        ->middleware(['auth','verified','permission:edit_worksheet'])->name('admin.worksheet-market.store');

/*
|--------------------------------------------------------------------------
| Rentals
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'permission:view_rentals'])->group(function () {

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

Route::middleware(['auth', 'permission:manage_rentals'])->group(function () {

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

// ===== DOCUMENT FILING REGISTER =====
Route::middleware(['auth', 'permission:access_filing_register'])->group(function () {
    Route::get('/filing-register', [\App\Http\Controllers\DocumentFilingController::class, 'index'])->name('filing-register.index');
    Route::post('/filing-register', [\App\Http\Controllers\DocumentFilingController::class, 'store'])->name('filing-register.store');
    Route::put('/filing-register/{id}', [\App\Http\Controllers\DocumentFilingController::class, 'update'])->name('filing-register.update');
    Route::delete('/filing-register/{id}', [\App\Http\Controllers\DocumentFilingController::class, 'destroy'])->name('filing-register.destroy');
});

// ===== NEXUS OS ROUTES =====
use App\Http\Controllers\CoreX\DashboardController as CoreXDashboardController;
use App\Http\Controllers\CoreX\PlaceholderController as CoreXPlaceholderController;
use App\Http\Controllers\CoreX\SettingsController as CoreXSettingsController;
use App\Http\Controllers\CoreX\RoleManagerController as CoreXRoleManagerController;

Route::middleware(['auth', 'verified'])->prefix('corex')->group(function () {
    Route::get('/', [CoreXDashboardController::class, 'index'])->middleware('permission:view_dashboard')->name('corex.dashboard');

    Route::get('/documents', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'documents')->middleware('permission:access_docuperfect')->name('corex.documents');
    Route::get('/compliance', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'compliance')->middleware('permission:access_compliance')->name('corex.compliance');
    Route::get('/supervision', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'supervision')->middleware('permission:access_supervision')->name('corex.supervision');
    Route::get('/training', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'training')->middleware('permission:access_training')->name('corex.training');
    Route::get('/communication', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'communication')->middleware('permission:access_communication')->name('corex.communication');
    Route::get('/client-portal', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'client-portal')->middleware('permission:access_client_portal')->name('corex.client-portal');
    Route::get('/franchise-admin', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'franchise-admin')->middleware('permission:access_franchise_admin')->name('corex.franchise-admin');

    // Settings (admin only)
    Route::get('/settings', [CoreXSettingsController::class, 'index'])->middleware('permission:access_settings')->name('corex.settings');
    Route::post('/settings/generate-token', [CoreXSettingsController::class, 'generateApiToken'])->middleware('permission:access_settings')->name('corex.settings.generate-token');

    // Role Manager (admin only)
    Route::get('/role-manager', [CoreXRoleManagerController::class, 'index'])->middleware('permission:access_role_manager')->name('corex.role-manager');
    Route::post('/role-manager/permissions', [CoreXRoleManagerController::class, 'savePermissions'])
        ->middleware('permission:edit_permissions')->name('corex.role-manager.save');
    Route::post('/role-manager/user-role', [CoreXRoleManagerController::class, 'updateUserRole'])
        ->middleware('permission:change_user_roles')->name('corex.role-manager.user-role');

    // Agency Management (super_admin only)
    Route::middleware('permission:access_agencies')->prefix('settings/agencies')->name('agencies.')->group(function () {
        Route::get('/',              [\App\Http\Controllers\Admin\AgencyController::class, 'index'])->name('index');
        Route::get('/create',        [\App\Http\Controllers\Admin\AgencyController::class, 'create'])->name('create');
        Route::post('/',             [\App\Http\Controllers\Admin\AgencyController::class, 'store'])->name('store');
        Route::get('/{agency}/edit', [\App\Http\Controllers\Admin\AgencyController::class, 'edit'])->name('edit');
        Route::put('/{agency}',      [\App\Http\Controllers\Admin\AgencyController::class, 'update'])->name('update');
    });

    // Properties — listing sync to website
    Route::prefix('properties')->middleware('permission:access_properties')->name('corex.properties.')->group(function () {
        Route::get('/',                [\App\Http\Controllers\CoreX\PropertyController::class, 'index'])->name('index');
        Route::get('/create',          [\App\Http\Controllers\CoreX\PropertyController::class, 'create'])->name('create');
        Route::post('/',               [\App\Http\Controllers\CoreX\PropertyController::class, 'store'])->name('store');
        Route::get('/{property}/edit', [\App\Http\Controllers\CoreX\PropertyController::class, 'edit'])->name('edit');
        Route::get('/{property}/ad',   [\App\Http\Controllers\CoreX\PropertyController::class, 'ad'])->name('ad');
        Route::put('/{property}',      [\App\Http\Controllers\CoreX\PropertyController::class, 'update'])->name('update');
        Route::delete('/{property}',   [\App\Http\Controllers\CoreX\PropertyController::class, 'destroy'])->name('destroy');
    });

    // Ad Template Builder
    Route::prefix('ad-templates')->middleware('permission:access_properties')->name('corex.ad-templates.')->group(function () {
        Route::get('/builder',                    [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'builder'])->name('builder');
        Route::get('/builder/{template}',         [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'builder'])->name('builder.edit');
        Route::post('/',                          [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'store'])->name('store');
        Route::put('/{template}',                 [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'update'])->name('update');
        Route::delete('/{template}',              [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'destroy'])->name('destroy');
    });
});


// ===== COMMERCIAL EVALUATIONS =====
Route::middleware(['auth', 'permission:access_commercial_evaluations'])->prefix('commercial-evaluations')->name('commercial-evaluations.')->group(function () {
    Route::get('/',                                          [\App\Http\Controllers\CommercialEvaluationController::class, 'index'])            ->name('index');
    Route::get('/create',                                   [\App\Http\Controllers\CommercialEvaluationController::class, 'create'])           ->name('create');
    Route::post('/',                                        [\App\Http\Controllers\CommercialEvaluationController::class, 'store'])            ->name('store');
    Route::get('/{evaluation}',                             [\App\Http\Controllers\CommercialEvaluationController::class, 'show'])             ->name('show');
    Route::get('/{evaluation}/edit',                        [\App\Http\Controllers\CommercialEvaluationController::class, 'edit'])             ->name('edit');
    Route::put('/{evaluation}',                             [\App\Http\Controllers\CommercialEvaluationController::class, 'update'])           ->name('update');
    Route::delete('/{evaluation}',                          [\App\Http\Controllers\CommercialEvaluationController::class, 'destroy'])          ->name('destroy');
    Route::post('/{evaluation}/evaluate',                   [\App\Http\Controllers\CommercialEvaluationController::class, 'evaluate'])         ->name('evaluate');
    Route::get('/{evaluation}/pdf',                         [\App\Http\Controllers\CommercialEvaluationController::class, 'downloadPdf'])      ->name('pdf');
    Route::post('/{evaluation}/financials',                 [\App\Http\Controllers\CommercialEvaluationController::class, 'storeFinancials'])  ->name('financials.store');
    Route::post('/{evaluation}/comparables',                [\App\Http\Controllers\CommercialEvaluationController::class, 'storeComparable']) ->name('comparables.store');
    Route::delete('/{evaluation}/comparables/{comparable}', [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyComparable'])->name('comparables.destroy');
    Route::post('/{evaluation}/assets',                     [\App\Http\Controllers\CommercialEvaluationController::class, 'storeAsset'])       ->name('assets.store');
    Route::delete('/{evaluation}/assets/{asset}',           [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyAsset'])     ->name('assets.destroy');
    Route::post('/{evaluation}/units',                      [\App\Http\Controllers\CommercialEvaluationController::class, 'storeUnit'])        ->name('units.store');
    Route::delete('/{evaluation}/units/{unit}',             [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyUnit'])      ->name('units.destroy');
    Route::post('/{evaluation}/crops',                      [\App\Http\Controllers\CommercialEvaluationController::class, 'storeCrop'])        ->name('crops.store');
    Route::delete('/{evaluation}/crops/{crop}',             [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyCrop'])      ->name('crops.destroy');
    Route::post('/{evaluation}/livestock',                  [\App\Http\Controllers\CommercialEvaluationController::class, 'storeLivestock'])   ->name('livestock.store');
    Route::delete('/{evaluation}/livestock/{livestock}',    [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyLivestock']) ->name('livestock.destroy');
});

// ===== PRESENTATION VERSION HISTORY (P17) =====
Route::middleware(['auth', 'permission:access_presentations'])->group(function () {
    Route::get('/presentations/versions', [\App\Http\Controllers\Presentation\PresentationVersionController::class, 'index'])
        ->name('presentations.versions.index');
});

Route::middleware(['auth', 'permission:access_presentations'])->group(function () {
    Route::get('/my/presentations/versions', [\App\Http\Controllers\Presentation\PresentationVersionController::class, 'mine'])
        ->name('presentations.versions.mine');
});

// ===== PRESENTATIONS =====
Route::middleware(['auth', 'permission:access_presentations'])->prefix('presentations')->name('presentations.')->group(function () {
    Route::get('/',       [\App\Http\Controllers\Presentation\PresentationController::class, 'index'])  ->name('index');
    Route::get('/create', [\App\Http\Controllers\Presentation\PresentationController::class, 'create']) ->name('create');
    Route::post('/',      [\App\Http\Controllers\Presentation\PresentationController::class, 'store'])  ->name('store');

    Route::get('/{presentation}',              [\App\Http\Controllers\Presentation\PresentationController::class, 'show'])     ->name('show');
    Route::get('/{presentation}/edit',         [\App\Http\Controllers\Presentation\PresentationController::class, 'edit'])     ->name('edit');
    Route::patch('/{presentation}',            [\App\Http\Controllers\Presentation\PresentationController::class, 'update'])   ->name('update');
    Route::get('/{presentation}/analysis',     [\App\Http\Controllers\Presentation\PresentationController::class, 'analysis']) ->name('analysis');
    Route::post('/{presentation}/analysis/run',[\App\Http\Controllers\Presentation\PresentationController::class, 'runAnalysis'])  ->name('analysis.run');
    Route::patch('/{presentation}/analysis-selections', [\App\Http\Controllers\Presentation\PresentationController::class, 'updateAnalysisSelections'])
        ->name('analysis-selections.update');

    Route::post('/{presentation}/upload', [\App\Http\Controllers\Presentation\PresentationController::class, 'upload'])
        ->name('upload');
    Route::patch('/{presentation}/uploads/{upload}/type', [\App\Http\Controllers\Presentation\PresentationController::class, 'updateUploadType'])
        ->name('uploads.update-type');
    Route::patch('/{presentation}/uploads/{upload}/override', [\App\Http\Controllers\Presentation\PresentationController::class, 'saveUploadOverride'])
        ->name('uploads.override');
    Route::delete('/{presentation}/uploads/{upload}/override', [\App\Http\Controllers\Presentation\PresentationController::class, 'clearUploadOverride'])
        ->name('uploads.override.clear');
    Route::post('/{presentation}/uploads/{upload}/re-extract', [\App\Http\Controllers\Presentation\PresentationController::class, 'reExtractUpload'])
        ->name('uploads.re-extract');
    Route::delete('/{presentation}/uploads/{upload}', [\App\Http\Controllers\Presentation\PresentationController::class, 'destroyUpload'])
        ->name('uploads.destroy');

    Route::post('/{presentation}/links', [\App\Http\Controllers\Presentation\PresentationController::class, 'storeLink'])
        ->name('links.store');
    Route::patch('/{presentation}/links/{link}/type', [\App\Http\Controllers\Presentation\PresentationController::class, 'updateLinkType'])
        ->name('links.update-type');
    Route::patch('/{presentation}/links/{link}/override', [\App\Http\Controllers\Presentation\PresentationController::class, 'saveLinkOverride'])
        ->name('links.override');
    Route::delete('/{presentation}/links/{link}/override', [\App\Http\Controllers\Presentation\PresentationController::class, 'clearLinkOverride'])
        ->name('links.override.clear');
    Route::delete('/{presentation}/links/{link}', [\App\Http\Controllers\Presentation\PresentationController::class, 'destroyLink'])
        ->name('links.destroy');
    Route::post('/{presentation}/links/{link}/re-extract', [\App\Http\Controllers\Presentation\PresentationController::class, 'reExtractLink'])
        ->name('links.re-extract');

    // Snapshot routes — names preserved for existing tests
    Route::post('/{presentation}/snapshots', [\App\Http\Controllers\Presentation\PresentationSnapshotController::class, 'saveSnapshot'])
        ->name('snapshots.save');
    Route::get('/{presentation}/snapshots/{snapshot}', [\App\Http\Controllers\Presentation\PresentationSnapshotController::class, 'showSnapshot'])
        ->name('snapshots.show');

    // Blueprint compiler + live simulation
    Route::post('/{presentation}/compile',  [\App\Http\Controllers\Presentation\PresentationController::class, 'compile'])
        ->name('compile');
    Route::post('/{presentation}/simulate', [\App\Http\Controllers\Presentation\PresentationController::class, 'simulate'])
        ->name('simulate');

    // URL snapshot ingestion (P6/P7/P8)
    Route::post('/{presentation}/url-snapshots', [\App\Http\Controllers\Presentation\PresentationController::class, 'storeUrlSnapshot'])
        ->name('url-snapshots.store');

    // Holding cost inputs (P15)
    Route::patch('/{presentation}/holding-cost', [\App\Http\Controllers\Presentation\PresentationController::class, 'updateHoldingCost'])
        ->name('holding-cost.update');

    // Multi-step price trajectory simulation (C1)
    Route::post('/{presentation}/simulate-trajectory', [\App\Http\Controllers\Presentation\PresentationController::class, 'simulateTrajectory'])
        ->name('simulate-trajectory');

    // Optimal price band scan (C2)
    Route::post('/{presentation}/price-band', [\App\Http\Controllers\Presentation\PresentationController::class, 'priceBand'])
        ->name('price-band');

    // Competitive threat ranking (C3)
    Route::post('/{presentation}/competitive-threats', [\App\Http\Controllers\Presentation\PresentationController::class, 'competitiveThreats'])
        ->name('competitive-threats');

    // Pricing Simulator (replaces Brain Simulation)
    Route::get('/{presentation}/pricing-simulator', [\App\Http\Controllers\Presentation\PresentationController::class, 'pricingSimulator'])
        ->name('pricing-simulator');
    Route::post('/{presentation}/pricing-simulator/compute', [\App\Http\Controllers\Presentation\PresentationController::class, 'computePricingSimulator'])
        ->name('pricing-simulator.compute');
    Route::post('/{presentation}/pricing-simulator/save', [\App\Http\Controllers\Presentation\PresentationController::class, 'savePricingSimulator'])
        ->name('pricing-simulator.save');
    Route::get('/{presentation}/pricing-simulator/present', [\App\Http\Controllers\Presentation\PresentationController::class, 'pricingSimulatorPresent'])
        ->name('pricing-simulator.present');

    // Seller Live Probability Screen
    Route::get('/{presentation}/seller-live', [\App\Http\Controllers\Presentation\PresentationController::class, 'sellerLive'])
        ->name('seller-live');
    Route::post('/{presentation}/seller-live/capture', [\App\Http\Controllers\Presentation\PresentationController::class, 'captureSellerLive'])
        ->name('seller-live.capture');

    // Legacy Brain route → redirect to Pricing Simulator
    Route::get('/{presentation}/brain', [\App\Http\Controllers\Presentation\PresentationController::class, 'brain'])
        ->name('brain');

    // PDF pack download (P18) — feature-flagged via config('features.presentation_pdf_v1')
    Route::get('/{presentation}/versions/{version}/pdf', [\App\Http\Controllers\Presentation\PresentationPdfController::class, 'download'])
        ->name('versions.pdf');
    Route::get('/{presentation}/versions/{version}/complete-pack', [\App\Http\Controllers\Presentation\PresentationPdfController::class, 'downloadCompletePack'])
        ->name('versions.complete-pack');

    // Portal captures (extension-based ingestion)
    Route::get('/{presentation}/portal-captures', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'index'])
        ->name('portal-captures.index');
    Route::post('/{presentation}/portal-captures/reclassify', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'reclassify'])
        ->name('portal-captures.reclassify');
    Route::post('/{presentation}/portal-captures/{capture}/attach', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'attach'])
        ->name('portal-captures.attach');
    Route::delete('/{presentation}/portal-captures/{capture}', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'destroy'])
        ->name('portal-captures.destroy');

    // Live snapshot polling (B1 — zero-refresh updates)
    Route::get('/{presentation}/live-snapshot', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'liveSnapshot'])
        ->name('live-snapshot');
});

// ===== DOCUPERFECT =====
Route::prefix('docuperfect')->middleware(['auth', 'permission:access_docuperfect'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Docuperfect\DashboardController::class, 'index'])->name('docuperfect.dashboard');
    Route::get('/create', [\App\Http\Controllers\Docuperfect\DashboardController::class, 'create'])->name('docuperfect.create');

    // Templates (admin/BM)
    Route::get('/templates', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'index'])->name('docuperfect.templates.index');
    Route::post('/templates/upload', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'upload'])->name('docuperfect.templates.upload');
    Route::get('/templates/{id}/edit', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'edit'])->name('docuperfect.templates.edit');
    Route::post('/templates/{id}/fields', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'saveFields'])->name('docuperfect.templates.saveFields');
    Route::post('/templates/{id}/pages', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'uploadPageImages'])->name('docuperfect.templates.uploadPages');
    Route::post('/templates/{id}/archive', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'archive'])->name('docuperfect.templates.archive');
    Route::post('/templates/{id}/restore', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'restore'])->name('docuperfect.templates.restore');
    Route::post('/templates/{id}/copy', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'copy'])->name('docuperfect.templates.copy');
    Route::delete('/templates/{id}', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'destroy'])->name('docuperfect.templates.destroy');

    // Documents — bare /docuperfect/documents redirects to dashboard (pack_instance keeps existing view)
    Route::get('/documents', function (\Illuminate\Http\Request $request) {
        if (!$request->query('pack_instance')) {
            return redirect()->route('docuperfect.dashboard');
        }
        return app(\App\Http\Controllers\Docuperfect\DocumentController::class)->index($request);
    })->name('docuperfect.documents.index');
    Route::get('/documents/create/{templateId}', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'create'])->name('docuperfect.documents.create');
    Route::post('/documents/create/{templateId}', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'store'])->name('docuperfect.documents.store');
    Route::get('/documents/{id}/edit', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'edit'])->name('docuperfect.documents.edit');
    Route::post('/documents/{id}/fields', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'saveFields'])->name('docuperfect.documents.saveFields');
    Route::post('/documents/{id}/rename', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'rename'])->name('docuperfect.documents.rename');
    Route::post('/documents/{id}/archive', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'archive'])->name('docuperfect.documents.archive');
    Route::post('/documents/{id}/restore', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'restore'])->name('docuperfect.documents.restore');
    Route::delete('/documents/{id}', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'destroy'])->name('docuperfect.documents.destroy');
    Route::post('/documents/{id}/send-to-rentals', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'sendToRentals'])->name('docuperfect.documents.sendToRentals');
    Route::post('/documents/{id}/reject', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'reject'])->name('docuperfect.documents.reject');
    Route::get('/api/pack-instance/{instanceId}/combined-pdf-data', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'combinedPdfData'])->name('docuperfect.api.combinedPdfData');

    // Clauses
    Route::get('/clauses', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'index'])->name('docuperfect.clauses.index');
    Route::post('/clauses', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'store'])->name('docuperfect.clauses.store');
    Route::put('/clauses/{id}', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'update'])->name('docuperfect.clauses.update');
    Route::post('/clauses/{id}/copy', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'copy'])->name('docuperfect.clauses.copy');
    Route::delete('/clauses/{id}', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'destroy'])->name('docuperfect.clauses.destroy');
    Route::get('/api/clauses', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'listJson'])->name('docuperfect.clauses.json');

    // Page images (authenticated)
    Route::get('/templates/{id}/page/{page}', [\App\Http\Controllers\Docuperfect\PageImageController::class, 'show'])->name('docuperfect.page.image');

    // Document Types settings (admin)
    Route::get('/settings/types', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'index'])->name('docuperfect.settings.types');
    Route::post('/settings/types', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'store'])->name('docuperfect.settings.types.store');
    Route::put('/settings/types/{id}', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'update'])->name('docuperfect.settings.types.update');
    Route::delete('/settings/types/{id}', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'destroy'])->name('docuperfect.settings.types.destroy');
    Route::post('/settings/types/reorder', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'reorder'])->name('docuperfect.settings.types.reorder');

    // Named Fields settings (admin)
    Route::get('/settings/named-fields', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'index'])->name('docuperfect.settings.namedFields');
    Route::post('/settings/named-fields', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'store'])->name('docuperfect.settings.namedFields.store');
    Route::put('/settings/named-fields/{id}', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'update'])->name('docuperfect.settings.namedFields.update');
    Route::delete('/settings/named-fields/{id}', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'destroy'])->name('docuperfect.settings.namedFields.destroy');
    Route::post('/settings/named-fields/reorder', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'reorder'])->name('docuperfect.settings.namedFields.reorder');

    // Document Packs
    Route::get('/packs', [\App\Http\Controllers\Docuperfect\PackController::class, 'index'])->name('docuperfect.packs.index');
    Route::get('/packs/create', [\App\Http\Controllers\Docuperfect\PackController::class, 'create'])->name('docuperfect.packs.create');
    Route::post('/packs', [\App\Http\Controllers\Docuperfect\PackController::class, 'store'])->name('docuperfect.packs.store');
    Route::get('/packs/{id}/edit', [\App\Http\Controllers\Docuperfect\PackController::class, 'edit'])->name('docuperfect.packs.edit');
    Route::put('/packs/{id}', [\App\Http\Controllers\Docuperfect\PackController::class, 'update'])->name('docuperfect.packs.update');
    Route::delete('/packs/{id}', [\App\Http\Controllers\Docuperfect\PackController::class, 'destroy'])->name('docuperfect.packs.destroy');
    Route::get('/packs/{id}/launch', [\App\Http\Controllers\Docuperfect\PackController::class, 'showLaunch'])->name('docuperfect.packs.showLaunch');
    Route::post('/packs/{id}/launch', [\App\Http\Controllers\Docuperfect\PackController::class, 'executeLaunch'])->name('docuperfect.packs.launch');
    Route::get('/attachments/{id}/download', [\App\Http\Controllers\Docuperfect\PackController::class, 'downloadAttachment'])->name('docuperfect.attachments.download');

    // Pack Instance Values API
    Route::get('/api/pack-instance-values/{instanceId}', [\App\Http\Controllers\Docuperfect\PackInstanceValueController::class, 'show'])->name('docuperfect.api.packInstanceValues');
    Route::post('/api/pack-instance-values', [\App\Http\Controllers\Docuperfect\PackInstanceValueController::class, 'save'])->name('docuperfect.api.packInstanceValuesSave');

    // ===== RENTAL DOCUMENTS (redirect to new Rental Division) =====
    Route::get('/rental', function () {
        return redirect()->route('rental.signatures');
    })->name('docuperfect.rental');

    // Rental Upload & Send (standalone signing flow)
    Route::get('/rental/upload-and-send', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'showUploadAndSend'])->name('docuperfect.rental.uploadAndSend');
    Route::post('/rental/upload-and-send', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'processUploadAndSend'])->name('docuperfect.rental.uploadAndSend.store');

    // ===== SIGNATURES =====

    // Agent approval gate
    Route::get('/documents/{document}/signatures/review', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'review'])->name('docuperfect.signatures.review');
    Route::post('/documents/{document}/signatures/approve-and-advance', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'approveAndAdvance'])->name('docuperfect.signatures.approveAndAdvance');

    // Dashboard polling
    Route::get('/rental/status-check', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'statusCheck'])->name('docuperfect.rental.statusCheck');

    // Pre-signed document upload
    Route::post('/documents/{document}/signatures/upload-presigned', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'uploadPresigned'])->name('docuperfect.signatures.uploadPresigned');

    // Signature setup
    Route::get('/documents/{document}/signatures/setup', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'setup'])->name('docuperfect.signatures.setup');
    Route::post('/documents/{document}/signatures/parties', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveParties'])->name('docuperfect.signatures.saveParties');
    Route::post('/documents/{document}/signatures/markers', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveMarkers'])->name('docuperfect.signatures.saveMarkers');
    Route::put('/documents/{document}/signatures/markers', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'updateMarkers'])->name('docuperfect.signatures.updateMarkers');

    // Internal signing
    Route::get('/documents/{document}/sign', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sign'])->name('docuperfect.signatures.sign');
    Route::post('/documents/{document}/sign/{marker}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'captureSignature'])->name('docuperfect.signatures.capture');
    Route::post('/documents/{document}/sign-complete', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'signComplete'])->name('docuperfect.signatures.signComplete');

    // Send + reminders
    Route::get('/documents/{document}/send-confirmation', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sendConfirmation'])->name('docuperfect.signatures.sendConfirmation');
    Route::post('/documents/{document}/send-for-signature', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sendForSignature'])->name('docuperfect.signatures.send');
    Route::post('/documents/{document}/send-reminder/{signatureRequest}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sendReminder'])->name('docuperfect.signatures.sendReminder');

    // Audit & download
    Route::get('/documents/{document}/signatures/audit', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'audit'])->name('docuperfect.signatures.audit');
    Route::get('/documents/{document}/signatures/download', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'download'])->name('docuperfect.signatures.download');

    // Wet ink inspection
    Route::get('/documents/{document}/signatures/inspect/{signingRequest}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'wetInkReview'])->name('docuperfect.signatures.wetInkReview');
    Route::post('/documents/{document}/signatures/inspect/{signingRequest}/decision', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'wetInkDecision'])->name('docuperfect.signatures.wetInkDecision');
    Route::get('/documents/{document}/signatures/inspect/{signingRequest}/file/{fileIndex}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'wetInkFile'])->name('docuperfect.signatures.wetInkFile');
    Route::post('/documents/{document}/signatures/inspect/{signingRequest}/upload-on-behalf', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'uploadOnBehalf'])->name('docuperfect.signatures.uploadOnBehalf');

    // Supersede & Reject
    Route::post('/documents/{document}/supersede', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'supersede'])->name('docuperfect.signatures.supersede');
    Route::post('/documents/{document}/reject', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'reject'])->name('docuperfect.signatures.reject');

    // Flattened page images (authenticated)
    Route::get('/signatures/{templateId}/flattened-page/{page}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'flattenedPageImage'])->name('docuperfect.signatures.flattenedPage');

    // Lease records
    Route::get('/leases', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'leases'])->name('docuperfect.leases.index');

    // Lease lifecycle
    Route::post('/leases/{lease}/renew', [\App\Http\Controllers\Docuperfect\LeaseController::class, 'renewLease'])->name('docuperfect.leases.renew');
    Route::post('/leases/{lease}/terminate', [\App\Http\Controllers\Docuperfect\LeaseController::class, 'terminateLease'])->name('docuperfect.leases.terminate');
    Route::get('/leases/{lease}/history', [\App\Http\Controllers\Docuperfect\LeaseController::class, 'leaseHistory'])->name('docuperfect.leases.history');

    // ===== SALES DOCUMENTS =====
    Route::get('/sales', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'index'])->name('docuperfect.sales');
    Route::get('/sales/send', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'showSendForm'])->name('docuperfect.sales.send');
    Route::post('/sales/send', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'sendToClient'])->name('docuperfect.sales.send.store');
    Route::post('/sales/recipient/{recipient}/mark-returned', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'markAsReturned'])->name('docuperfect.sales.mark-returned');
    Route::post('/sales/recipient/{recipient}/resend', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'resend'])->name('docuperfect.sales.resend');
    Route::post('/sales/recipient/{recipient}/remind', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'sendManualReminder'])->name('docuperfect.sales.remind');
    Route::post('/sales/{send}/approve/{recipient}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'approveAndSendNext'])->name('docuperfect.sales.approve');
    Route::get('/sales/{send}/download', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'downloadOriginal'])->name('docuperfect.sales.download');
    Route::post('/sales/documents/{document}/upload-signed', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'uploadSignedDocument'])->name('docuperfect.sales.uploadSigned');
    Route::post('/sales/{send}/cancel', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'cancel'])->name('docuperfect.sales.cancel');
    Route::get('/sales/{send}/review/{recipient}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'reviewUpload'])->name('docuperfect.sales.review');
    Route::get('/sales/{send}/recipient/{recipient}/file/{index}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'serveReturnedFile'])->name('docuperfect.sales.recipientFile');
    Route::post('/sales/{send}/upload-on-behalf/{recipient}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'uploadOnBehalf'])->name('docuperfect.sales.uploadOnBehalf');
});

// ===== RENTAL DIVISION =====
Route::prefix('rental')->middleware(['auth', 'permission:view_rentals'])->name('rental.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'dashboard'])->name('dashboard');
    Route::get('/signatures', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'signatures'])->name('signatures');
    Route::post('/signatures/{document}/assign-metadata', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'assignMetadata'])->name('signatures.assign-metadata');
    Route::post('/signatures/{document}/set-expiry', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'setExpiry'])->name('signatures.set-expiry');
    Route::get('/active-leases', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'activeLeases'])->name('active-leases');
    Route::get('/expired-leases', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'expiredLeases'])->name('expired-leases');
    Route::get('/settings', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'settings'])->name('settings');

    // Settings sub-routes
    Route::prefix('settings')->name('settings.')->group(function () {
        // Properties
        Route::get('/properties', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'index'])->name('properties.index');
        Route::get('/properties/create', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'create'])->name('properties.create');
        Route::post('/properties', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'store'])->name('properties.store');
        Route::get('/properties/{property}/edit', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'edit'])->name('properties.edit');
        Route::put('/properties/{property}', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'update'])->name('properties.update');
        Route::post('/properties/{property}/toggle', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'toggleActive'])->name('properties.toggle');
        Route::get('/properties/search', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'search'])->name('properties.search');

        // Document Types
        Route::get('/document-types', [\App\Http\Controllers\Rental\RentalDocumentTypeController::class, 'index'])->name('document-types.index');
        Route::post('/document-types', [\App\Http\Controllers\Rental\RentalDocumentTypeController::class, 'store'])->name('document-types.store');
        Route::put('/document-types/{type}', [\App\Http\Controllers\Rental\RentalDocumentTypeController::class, 'update'])->name('document-types.update');
        Route::post('/document-types/{type}/toggle', [\App\Http\Controllers\Rental\RentalDocumentTypeController::class, 'toggleActive'])->name('document-types.toggle');

        // Reminders
        Route::get('/reminders', [\App\Http\Controllers\Rental\RentalReminderSettingsController::class, 'index'])->name('reminders.index');
        Route::put('/reminders', [\App\Http\Controllers\Rental\RentalReminderSettingsController::class, 'update'])->name('reminders.update');
    });
});

// ===== SALES DOCUMENT RETURN (public, no auth, token-based) =====
Route::get('/sales-documents/return/{token}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'showUploadPage'])->name('sales-documents.upload');
Route::post('/sales-documents/return/{token}/verify', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'verifySalesIdentity'])->name('sales-documents.verify');
Route::get('/sales-documents/return/{token}/download', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'downloadForRecipient'])->name('sales-documents.download');
Route::post('/sales-documents/return/{token}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'handleUpload'])->name('sales-documents.upload.store');

// ===== EXTERNAL SIGNING (no auth, token-based) =====
Route::prefix('sign')->group(function () {
    Route::get('/{token}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'show'])->name('signatures.external');
    Route::post('/{token}/verify', [\App\Http\Controllers\Docuperfect\SigningController::class, 'verify'])->name('signatures.external.verify');
    Route::post('/{token}/choose-method', [\App\Http\Controllers\Docuperfect\SigningController::class, 'chooseMethod'])->name('signatures.external.chooseMethod');
    Route::post('/{token}/capture/{marker}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'capture'])->name('signatures.external.capture');
    Route::post('/{token}/complete', [\App\Http\Controllers\Docuperfect\SigningController::class, 'complete'])->name('signatures.external.complete');
    Route::get('/{token}/completed', [\App\Http\Controllers\Docuperfect\SigningController::class, 'completed'])->name('signatures.external.completed');
    Route::post('/{token}/upload', [\App\Http\Controllers\Docuperfect\SigningController::class, 'uploadWetInk'])->name('signatures.external.upload');
    Route::get('/{token}/download', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadForSigning'])->name('signatures.external.download');
    Route::post('/{token}/decline', [\App\Http\Controllers\Docuperfect\SigningController::class, 'decline'])->name('signatures.external.decline');
    Route::get('/{token}/flattened-page/{page}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'flattenedPageImage'])->name('signatures.external.flattenedPage');
});

// ===== SIGNED DOCUMENT DOWNLOAD (no auth, token-based) =====
Route::get('/documents/download/{token}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadPage'])->name('signatures.download.page');
Route::post('/documents/download/{token}/verify', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadVerify'])->name('signatures.download.verify');
Route::get('/documents/download/{token}/file', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadSignedFile'])->name('signatures.download.file');

// ===== DOCUMENT LIBRARY =====
Route::middleware(['auth', 'permission:access_document_library'])->prefix('documents')->name('documents.')->group(function () {
    Route::get('/library', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'index'])
        ->name('library.index');
    Route::post('/library/upload', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'upload'])
        ->name('library.upload');
    Route::get('/library/{item}/download', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'download'])
        ->name('library.download');
    Route::post('/library/attach', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'attach'])
        ->name('library.attach');

    // Document type management
    Route::post('/library/types', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'storeType'])
        ->name('library.types.store');
    Route::put('/library/types/{documentType}', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'updateType'])
        ->name('library.types.update');
    Route::delete('/library/types/{documentType}', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'destroyType'])
        ->name('library.types.destroy');
});

// Portal capture ingest endpoint (outside presentation prefix — extension posts here)
// Uses auth.portal_capture: session auth OR bearer token (for Chrome extension)
Route::middleware(['auth.portal_capture'])->post('/portal-captures/ingest', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'ingest'])
    ->name('portal-captures.ingest');
