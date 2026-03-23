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

// Public shared pages (no auth required)
Route::get('/shared/match/{token}', [\App\Http\Controllers\SharedMatchController::class, 'show'])->name('shared.match');
Route::get('/shared/match/{token}/view/{property}', [\App\Http\Controllers\SharedMatchController::class, 'recordView'])->name('shared.match.view');

Route::get('/dashboard', function () {
    return redirect()->route('corex.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {

    Route::get('/evaluation', function () {
        return view('evaluation.index');
    })->middleware('permission:access_evaluation')->name('evaluation.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/theme', [ProfileController::class, 'updateTheme'])->name('profile.theme');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/corex/extension/download', [ProfileController::class, 'downloadExtension'])->name('corex.extension.download');

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

    Route::get('/worksheet', [WorksheetController::class, 'index'])->middleware('permission:view_worksheet')->name('worksheet.index');
    Route::post('/worksheet', [WorksheetController::class, 'store'])->middleware('permission:view_worksheet')->name('worksheet.store');

    Route::get('/company-summary', [CompanySummaryController::class, 'index'])->middleware('permission:view_dashboard')->name('company.summary');

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

    Route::post('/admin/branches/{branch}/restore', [BranchAssignmentController::class, 'restoreBranch'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branches.restore')->withTrashed();

    Route::post('/admin/branch-settings/{branch}', [BranchAssignmentController::class, 'updateBranchSettings'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branch-settings.update');


    Route::get('/admin/users', [App\Http\Controllers\Admin\UserManagementController::class, 'index'])
        ->middleware('permission:manage_users')->name('admin.users');
    Route::get('/admin/users/create', [App\Http\Controllers\Admin\UserManagementController::class, 'create'])
        ->middleware('permission:manage_users')->name('admin.users.create');
    Route::post('/admin/users', [App\Http\Controllers\Admin\UserManagementController::class, 'store'])
        ->middleware('permission:manage_users')->name('admin.users.store');
    Route::get('/admin/users/{user}/edit', [App\Http\Controllers\Admin\UserManagementController::class, 'edit'])
        ->middleware('permission:manage_users')->name('admin.users.edit');
    Route::put('/admin/users/{user}', [App\Http\Controllers\Admin\UserManagementController::class, 'update'])
        ->middleware('permission:manage_users')->name('admin.users.update');

    Route::post('/admin/users/{user}/toggle', [App\Http\Controllers\Admin\UserManagementController::class, 'toggle'])
        ->middleware('permission:manage_users')->name('admin.users.toggle');

    Route::post('/admin/users/{user}/delete', [App\Http\Controllers\Admin\UserManagementController::class, 'delete'])
        ->middleware('permission:manage_users')->name('admin.users.delete');

    Route::post('/admin/users/{user}/defaults', [App\Http\Controllers\Admin\UserManagementController::class, 'updateDefaults'])
        ->middleware('permission:manage_users')->name('admin.users.defaults.update');
    Route::post('/admin/users/{user}/role', [App\Http\Controllers\Admin\UserManagementController::class, 'updateRole'])->middleware('permission:manage_users')->name('admin.users.role.update');
    Route::post('/admin/users/{user}/resend-invite', [App\Http\Controllers\Admin\UserManagementController::class, 'resendInvite'])->middleware('permission:manage_users')->name('admin.users.resend-invite');
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

    // Carry forward targets from previous month (manual trigger)
    Route::post('/admin/targets/carry-forward', function () {
        \Illuminate\Support\Facades\Artisan::call('targets:carry-forward');
        $output = \Illuminate\Support\Facades\Artisan::output();
        return back()->with('status', 'Targets carried forward from previous month. ' . strip_tags(trim($output)));
    })->middleware('permission:manage_targets')->name('admin.targets.carry-forward');

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
        Route::post('/admin/tv-messages/{tvMessage}/restore', [\App\Http\Controllers\TvMessageController::class, 'adminRestore'])->name('admin.tv-messages.restore')->withTrashed();

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
    Route::post('/filing-register/{filing}/restore', [\App\Http\Controllers\DocumentFilingController::class, 'restore'])->name('filing-register.restore')->withTrashed();
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
    Route::post('/settings/marketing-enabled', [CoreXSettingsController::class, 'updateMarketingEnabled'])->middleware('permission:access_settings')->name('corex.settings.marketing-enabled');
    Route::post('/settings/matches-enabled', [CoreXSettingsController::class, 'updateMatchesEnabled'])->middleware('permission:access_settings')->name('corex.settings.matches-enabled');
    Route::post('/settings/matches-wa-message', [CoreXSettingsController::class, 'updateMatchesWaMessage'])->middleware('permission:access_settings')->name('corex.settings.matches-wa-message');
    Route::post('/settings/matches-show-on-properties', [CoreXSettingsController::class, 'updateMatchesShowOnProperties'])->middleware('permission:access_settings')->name('corex.settings.matches-show-on-properties');
    Route::put('/settings/agency', [CoreXSettingsController::class, 'updateAgency'])->middleware('permission:access_settings')->name('corex.settings.agency.update');
    Route::get('/settings/preview-header', [CoreXSettingsController::class, 'previewHeader'])->middleware('permission:access_settings')->name('corex.settings.preview-header');
    Route::get('/settings/preview-signature', [CoreXSettingsController::class, 'previewSignature'])->middleware('permission:access_settings')->name('corex.settings.preview-signature');

    // Role Manager
    Route::get('/role-manager', [CoreXRoleManagerController::class, 'index'])->middleware('permission:access_role_manager')->name('corex.role-manager');
    Route::post('/role-manager/permissions', [CoreXRoleManagerController::class, 'savePermissions'])
        ->middleware('permission:edit_permissions')->name('corex.role-manager.save');
    Route::post('/role-manager/user-role', [CoreXRoleManagerController::class, 'updateUserRole'])
        ->middleware('permission:change_user_roles')->name('corex.role-manager.user-role');
    // Role CRUD
    Route::post('/role-manager/roles', [CoreXRoleManagerController::class, 'storeRole'])
        ->middleware('permission:edit_permissions')->name('corex.role-manager.roles.store');
    Route::put('/role-manager/roles/{role}', [CoreXRoleManagerController::class, 'updateRole'])
        ->middleware('permission:edit_permissions')->name('corex.role-manager.roles.update');
    Route::delete('/role-manager/roles/{role}', [CoreXRoleManagerController::class, 'destroyRole'])
        ->middleware('permission:edit_permissions')->name('corex.role-manager.roles.destroy');
    Route::post('/role-manager/copy-permissions', [CoreXRoleManagerController::class, 'copyPermissions'])
        ->middleware('permission:edit_permissions')->name('corex.role-manager.copy');

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
        Route::get('/',                        [\App\Http\Controllers\CoreX\PropertyController::class, 'index'])->name('index');
        Route::get('/create',                  [\App\Http\Controllers\CoreX\PropertyController::class, 'create'])->name('create');
        Route::post('/',                       [\App\Http\Controllers\CoreX\PropertyController::class, 'store'])->name('store');
        Route::get('/contacts/search',         [\App\Http\Controllers\CoreX\PropertyContactController::class, 'searchGlobal'])->name('contacts.search-global');
        Route::get('/{property}',              [\App\Http\Controllers\CoreX\PropertyController::class, 'show'])->name('show');
        Route::get('/{property}/edit',         [\App\Http\Controllers\CoreX\PropertyController::class, 'edit'])->name('edit');
        Route::get('/{property}/ad',           [\App\Http\Controllers\CoreX\PropertyController::class, 'ad'])->name('ad');
        Route::get('/{property}/preview',      [\App\Http\Controllers\CoreX\PropertyController::class, 'livePreview'])->name('preview');
        Route::put('/{property}',              [\App\Http\Controllers\CoreX\PropertyController::class, 'update'])->name('update');
        Route::delete('/{property}',           [\App\Http\Controllers\CoreX\PropertyController::class, 'destroy'])->name('destroy');
        Route::post('/{property}/restore',     [\App\Http\Controllers\CoreX\PropertyController::class, 'restore'])->name('restore')->withTrashed();
        Route::post('/{property}/delete-image',[\App\Http\Controllers\CoreX\PropertyController::class, 'deleteImage'])->name('deleteImage');
        Route::post('/{property}/reorder-images',[\App\Http\Controllers\CoreX\PropertyController::class, 'reorderImages'])->name('reorderImages');
        // Notes
        Route::post('/{property}/notes',                [\App\Http\Controllers\CoreX\PropertyNoteController::class, 'store'])->name('notes.store');
        Route::delete('/{property}/notes/{note}',       [\App\Http\Controllers\CoreX\PropertyNoteController::class, 'destroy'])->name('notes.destroy');
        // Files (Drive)
        Route::post('/{property}/files',                [\App\Http\Controllers\CoreX\PropertyFileController::class, 'store'])->name('files.store');
        Route::delete('/{property}/files/{file}',       [\App\Http\Controllers\CoreX\PropertyFileController::class, 'destroy'])->name('files.destroy');
        // Contacts
        Route::get('/{property}/contacts/search',       [\App\Http\Controllers\CoreX\PropertyContactController::class, 'search'])->name('contacts.search');
        Route::post('/{property}/contacts/link',        [\App\Http\Controllers\CoreX\PropertyContactController::class, 'link'])->name('contacts.link');
        Route::post('/{property}/contacts/create-link', [\App\Http\Controllers\CoreX\PropertyContactController::class, 'createAndLink'])->name('contacts.createAndLink');
        Route::delete('/{property}/contacts/{contact}', [\App\Http\Controllers\CoreX\PropertyContactController::class, 'unlink'])->name('contacts.unlink');
        // Marketing
        Route::get('/{property}/marketing',              [\App\Http\Controllers\PropertyMarketingController::class, 'index'])->name('marketing.index');
        Route::post('/{property}/marketing/generate-copy', [\App\Http\Controllers\PropertyMarketingController::class, 'generateCopy'])->name('marketing.generateCopy');
        Route::post('/{property}/marketing/publish',     [\App\Http\Controllers\PropertyMarketingController::class, 'publish'])->name('marketing.publish');
    });

    // Ad Template Builder
    Route::prefix('ad-templates')->middleware('permission:access_properties')->name('corex.ad-templates.')->group(function () {
        Route::get('/builder',                    [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'builder'])->name('builder');
        Route::get('/builder/{template}',         [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'builder'])->name('builder.edit');
        Route::post('/',                          [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'store'])->name('store');
        Route::put('/{template}',                 [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'update'])->name('update');
        Route::delete('/{template}',              [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'destroy'])->name('destroy');
    });

    // Core Matches (top-level index)
    Route::get('/core-matches', [\App\Http\Controllers\CoreX\ContactMatchController::class, 'index'])
        ->middleware('permission:access_contacts')
        ->name('corex.core-matches.index');

    // Contacts
    Route::prefix('contacts')->middleware('permission:access_contacts')->name('corex.contacts.')->group(function () {
        Route::get('/',                   [\App\Http\Controllers\CoreX\ContactController::class, 'index'])->name('index');
        Route::post('/',                  [\App\Http\Controllers\CoreX\ContactController::class, 'store'])->name('store');
        Route::post('/check-duplicate',   [\App\Http\Controllers\CoreX\ContactController::class, 'checkDuplicate'])->name('check-duplicate');
        Route::post('/import',            [\App\Http\Controllers\CoreX\ContactImportController::class, 'import'])->name('import');
        Route::delete('/destroy-all',     [\App\Http\Controllers\CoreX\ContactController::class, 'destroyAll'])->name('destroy-all');
        Route::get('/{contact}',          [\App\Http\Controllers\CoreX\ContactController::class, 'show'])->name('show');
        Route::put('/{contact}',          [\App\Http\Controllers\CoreX\ContactController::class, 'update'])->name('update');
        Route::delete('/{contact}',       [\App\Http\Controllers\CoreX\ContactController::class, 'destroy'])->name('destroy');
        Route::post('/{contact}/tags',    [\App\Http\Controllers\CoreX\ContactController::class, 'syncTags'])->name('tags.sync');
        Route::post('/{contact}/touch',   [\App\Http\Controllers\CoreX\ContactController::class, 'touch'])->name('touch');
        Route::post('/{contact}/increment', [\App\Http\Controllers\CoreX\ContactController::class, 'incrementChannel'])->name('increment');

        // Notes
        Route::post('/{contact}/notes',          [\App\Http\Controllers\CoreX\ContactNoteController::class, 'store'])->name('notes.store');
        Route::delete('/{contact}/notes/{note}', [\App\Http\Controllers\CoreX\ContactNoteController::class, 'destroy'])->name('notes.destroy');

        // Documents (Drive)
        Route::post('/{contact}/documents',                    [\App\Http\Controllers\CoreX\ContactDocumentController::class, 'store'])->name('documents.store');
        Route::get('/{contact}/documents/{document}/download', [\App\Http\Controllers\CoreX\ContactDocumentController::class, 'download'])->name('documents.download');
        Route::delete('/{contact}/documents/{document}',       [\App\Http\Controllers\CoreX\ContactDocumentController::class, 'destroy'])->name('documents.destroy');
        // Properties
        Route::get('/{contact}/properties/search',    [\App\Http\Controllers\CoreX\ContactPropertyController::class, 'search'])->name('properties.search');
        Route::post('/{contact}/properties/link',     [\App\Http\Controllers\CoreX\ContactPropertyController::class, 'link'])->name('properties.link');
        Route::delete('/{contact}/properties/{property}', [\App\Http\Controllers\CoreX\ContactPropertyController::class, 'unlink'])->name('properties.unlink');
        // Core Matches
        Route::post('/{contact}/matches',                              [\App\Http\Controllers\CoreX\ContactMatchController::class, 'store'])->name('matches.store');
        Route::get('/{contact}/matches/{match}/results',               [\App\Http\Controllers\CoreX\ContactMatchController::class, 'results'])->name('matches.results');
        Route::post('/{contact}/matches/{match}/hide/{property}',      [\App\Http\Controllers\CoreX\ContactMatchController::class, 'toggleHide'])->name('matches.toggleHide');
        Route::delete('/{contact}/matches/{match}',                    [\App\Http\Controllers\CoreX\ContactMatchController::class, 'destroy'])->name('matches.destroy');
    });

    // Contact Types (settings)
    Route::prefix('settings/contact-types')->middleware('permission:access_settings')->name('corex.settings.contact-types.')->group(function () {
        Route::post('/',              [\App\Http\Controllers\CoreX\ContactTypeController::class, 'store'])->name('store');
        Route::put('/{contactType}',  [\App\Http\Controllers\CoreX\ContactTypeController::class, 'update'])->name('update');
        Route::delete('/{contactType}', [\App\Http\Controllers\CoreX\ContactTypeController::class, 'destroy'])->name('destroy');
    });

    // Contact Sources (settings)
    Route::prefix('settings/contact-sources')->middleware('permission:access_settings')->name('corex.settings.contact-sources.')->group(function () {
        Route::post('/',                  [\App\Http\Controllers\CoreX\ContactSourceController::class, 'store'])->name('store');
        Route::put('/{contactSource}',    [\App\Http\Controllers\CoreX\ContactSourceController::class, 'update'])->name('update');
        Route::delete('/{contactSource}', [\App\Http\Controllers\CoreX\ContactSourceController::class, 'destroy'])->name('destroy');
    });

    // Contact Tags (settings)
    Route::prefix('settings/contact-tags')->middleware('permission:access_settings')->name('corex.settings.contact-tags.')->group(function () {
        Route::post('/',              [\App\Http\Controllers\CoreX\ContactTagController::class, 'store'])->name('store');
        Route::put('/{contactTag}',   [\App\Http\Controllers\CoreX\ContactTagController::class, 'update'])->name('update');
        Route::delete('/{contactTag}', [\App\Http\Controllers\CoreX\ContactTagController::class, 'destroy'])->name('destroy');
    });

    // Property Setting Items (settings)
    Route::prefix('settings/property-items')->middleware('permission:access_settings')->name('corex.settings.property-items.')->group(function () {
        Route::post('/',                    [\App\Http\Controllers\CoreX\SettingsController::class, 'storePropertySettingItem'])->name('store');
        Route::put('/{item}',              [\App\Http\Controllers\CoreX\SettingsController::class, 'updatePropertySettingItem'])->name('update');
        Route::post('/{item}/toggle',      [\App\Http\Controllers\CoreX\SettingsController::class, 'togglePropertySettingItem'])->name('toggle');
        Route::post('/reorder',               [\App\Http\Controllers\CoreX\SettingsController::class, 'reorderPropertySettingItems'])->name('reorder');
        Route::post('/batch-toggle/{group}', [\App\Http\Controllers\CoreX\SettingsController::class, 'batchToggleDefaultItems'])->name('batch-toggle');
        Route::delete('/{item}',           [\App\Http\Controllers\CoreX\SettingsController::class, 'destroyPropertySettingItem'])->name('destroy');
    });

    // Marketing: post insights sync + social account disconnect
    Route::post('/marketing/posts/{post}/sync-insights', [\App\Http\Controllers\PropertyMarketingController::class, 'syncInsights'])->middleware('permission:access_properties')->name('corex.marketing.sync-insights');
    Route::post('/marketing/social/disconnect', [\App\Http\Controllers\PropertyMarketingController::class, 'disconnectAccount'])->middleware('permission:access_properties')->name('corex.marketing.social.disconnect');
    Route::post('/marketing/upload-template-image', [\App\Http\Controllers\PropertyMarketingController::class, 'uploadTemplateImage'])->middleware('permission:access_properties')->name('corex.marketing.upload-template-image');

    // Social OAuth
    Route::get('/social/oauth/redirect', [\App\Http\Controllers\PropertyMarketingController::class, 'oauthRedirect'])->middleware('permission:access_properties')->name('corex.social.oauth.redirect');
    Route::get('/social/oauth/callback', [\App\Http\Controllers\PropertyMarketingController::class, 'oauthCallback'])->middleware('permission:access_properties')->name('corex.social.oauth.callback');
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
    Route::post('/{evaluation}/restore',                    [\App\Http\Controllers\CommercialEvaluationController::class, 'restore'])          ->name('restore')->withTrashed();
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

    // Restore soft-deleted presentation
    Route::post('/{presentation}/restore', [\App\Http\Controllers\Presentation\PresentationController::class, 'restore'])
        ->name('restore')->withTrashed();
});

// ===== DOCUPERFECT =====
Route::prefix('docuperfect')->middleware(['auth', 'permission:access_docuperfect'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Docuperfect\DashboardController::class, 'index'])->name('docuperfect.dashboard');
    Route::get('/create', [\App\Http\Controllers\Docuperfect\DashboardController::class, 'create'])->name('docuperfect.create');

    // Templates (admin/BM)
    Route::get('/templates', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'index'])->name('docuperfect.templates.index');
    Route::post('/templates/upload', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'upload'])->name('docuperfect.templates.upload');
    // CDS Document Engine (DB-backed drafts)
    Route::get('/templates/cds/builder/{draft}', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsBuilder'])->name('docuperfect.cds.builder');
    Route::post('/templates/cds/mappings', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsSaveMappings'])->name('docuperfect.cds.mappings');
    Route::post('/templates/cds/draft/save', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsSaveDraft'])->name('docuperfect.cds.draft.save');
    Route::post('/templates/cds/generate', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsGenerate'])->name('docuperfect.cds.generate');
    Route::delete('/templates/cds/draft/{draft}', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsDestroyDraft'])->name('docuperfect.cds.draft.destroy');
    Route::get('/templates/{id}/edit', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'edit'])->name('docuperfect.templates.edit');
    Route::get('/templates/{id}/web-preview', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'webPreview'])->name('docuperfect.templates.webPreview');
    Route::post('/templates/{id}/fields', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'saveFields'])->name('docuperfect.templates.saveFields');
    Route::post('/templates/{id}/pages', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'uploadPageImages'])->name('docuperfect.templates.uploadPages');
    Route::post('/templates/{id}/archive', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'archive'])->name('docuperfect.templates.archive');
    Route::post('/templates/{id}/restore', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'restore'])->name('docuperfect.templates.restore');
    Route::post('/templates/{id}/copy', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'copy'])->name('docuperfect.templates.copy');
    Route::delete('/templates/{id}', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'destroy'])->name('docuperfect.templates.destroy');

    // Template Wizard Config
    Route::get('/templates/{id}/wizard-config', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'wizardConfig'])->name('docuperfect.templates.wizardConfig');
    Route::post('/templates/{id}/wizard-config', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'saveWizardConfig'])->name('docuperfect.templates.wizardConfig.save');

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
    Route::post('/clauses/{clause}/restore', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'restore'])->name('docuperfect.clauses.restore')->withTrashed();
    Route::get('/api/clauses', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'listJson'])->name('docuperfect.clauses.json');

    // Page images (authenticated)
    Route::get('/templates/{id}/page/{page}', [\App\Http\Controllers\Docuperfect\PageImageController::class, 'show'])->name('docuperfect.page.image');

    // Document-level page images (flattened web templates)
    Route::get('/documents/{id}/page/{page}', [\App\Http\Controllers\Docuperfect\PageImageController::class, 'showDocumentPage'])->name('docuperfect.documents.pageImage');

    // Document Types settings (admin)
    Route::get('/settings/types', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'index'])->name('docuperfect.settings.types');
    Route::post('/settings/types', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'store'])->name('docuperfect.settings.types.store');
    Route::put('/settings/types/{id}', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'update'])->name('docuperfect.settings.types.update');
    Route::delete('/settings/types/{id}', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'destroy'])->name('docuperfect.settings.types.destroy');
    Route::post('/settings/types/{type}/restore', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'restore'])->name('docuperfect.settings.types.restore')->withTrashed();
    Route::post('/settings/types/reorder', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'reorder'])->name('docuperfect.settings.types.reorder');

    // Named Fields settings (admin)
    Route::get('/settings/named-fields', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'index'])->name('docuperfect.settings.namedFields');
    Route::post('/settings/named-fields', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'store'])->name('docuperfect.settings.namedFields.store');
    Route::put('/settings/named-fields/{id}', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'update'])->name('docuperfect.settings.namedFields.update');
    Route::delete('/settings/named-fields/{id}', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'destroy'])->name('docuperfect.settings.namedFields.destroy');
    Route::post('/settings/named-fields/{field}/restore', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'restore'])->name('docuperfect.settings.namedFields.restore')->withTrashed();
    Route::post('/settings/named-fields/reorder', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'reorder'])->name('docuperfect.settings.namedFields.reorder');

    // Field Groups
    Route::get('/field-groups', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'index'])->name('docuperfect.field-groups.index');
    Route::post('/field-groups', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'store'])->name('docuperfect.field-groups.store');
    Route::put('/field-groups/{group}', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'update'])->name('docuperfect.field-groups.update');
    Route::delete('/field-groups/{group}', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'destroy'])->name('docuperfect.field-groups.destroy');
    Route::get('/field-groups/json', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'json'])->name('docuperfect.field-groups.json');

    // Document Packs
    Route::get('/packs', [\App\Http\Controllers\Docuperfect\PackController::class, 'index'])->name('docuperfect.packs.index');
    Route::get('/packs/create', [\App\Http\Controllers\Docuperfect\PackController::class, 'create'])->name('docuperfect.packs.create');
    Route::post('/packs', [\App\Http\Controllers\Docuperfect\PackController::class, 'store'])->name('docuperfect.packs.store');
    Route::get('/packs/{id}/edit', [\App\Http\Controllers\Docuperfect\PackController::class, 'edit'])->name('docuperfect.packs.edit');
    Route::put('/packs/{id}', [\App\Http\Controllers\Docuperfect\PackController::class, 'update'])->name('docuperfect.packs.update');
    Route::delete('/packs/{id}', [\App\Http\Controllers\Docuperfect\PackController::class, 'destroy'])->name('docuperfect.packs.destroy');
    Route::post('/packs/{pack}/restore', [\App\Http\Controllers\Docuperfect\PackController::class, 'restore'])->name('docuperfect.packs.restore')->withTrashed();
    Route::get('/packs/{id}/launch', [\App\Http\Controllers\Docuperfect\PackController::class, 'showLaunch'])->name('docuperfect.packs.showLaunch');
    Route::post('/packs/{id}/launch', [\App\Http\Controllers\Docuperfect\PackController::class, 'executeLaunch'])->name('docuperfect.packs.launch');
    Route::get('/attachments/{id}/download', [\App\Http\Controllers\Docuperfect\PackController::class, 'downloadAttachment'])->name('docuperfect.attachments.download');

    // Web Packs
    Route::get('/web-packs', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'index'])->name('docuperfect.web-packs.index');
    Route::get('/web-packs/create', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'create'])->name('docuperfect.web-packs.create');
    Route::post('/web-packs', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'store'])->name('docuperfect.web-packs.store');
    Route::get('/web-packs/{id}/edit', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'edit'])->name('docuperfect.web-packs.edit');
    Route::put('/web-packs/{id}', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'update'])->name('docuperfect.web-packs.update');
    Route::delete('/web-packs/{id}', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'destroy'])->name('docuperfect.web-packs.destroy');

    // Pack Instance Values API
    Route::get('/api/pack-instance-values/{instanceId}', [\App\Http\Controllers\Docuperfect\PackInstanceValueController::class, 'show'])->name('docuperfect.api.packInstanceValues');
    Route::post('/api/pack-instance-values', [\App\Http\Controllers\Docuperfect\PackInstanceValueController::class, 'save'])->name('docuperfect.api.packInstanceValuesSave');

    // ===== E-SIGN WIZARD =====
    Route::get('/esign/my-documents', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'myDocuments'])->name('docuperfect.esign.myDocuments');
    Route::get('/esign/test-render/{templateId}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'testRender'])->name('docuperfect.esign.testRender');
    Route::get('/esign/create', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'create'])->name('docuperfect.esign.create');
    Route::post('/esign/store', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'store'])->name('docuperfect.esign.store');
    Route::get('/esign/{flow}/step/{step}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'showStep'])->name('docuperfect.esign.step');
    Route::post('/esign/{flow}/step/{step}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'saveStep'])->name('docuperfect.esign.saveStep');
    Route::post('/esign/{flow}/draft', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'saveDraft'])->name('docuperfect.esign.saveDraft');
    Route::delete('/esign/{flow}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'destroy'])->name('docuperfect.esign.destroy');
    Route::post('/esign/{flow}/autosave-fields', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'autosaveFields'])->name('docuperfect.esign.autosaveFields');
    Route::post('/esign/{flow}/prepare-signing', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'prepareSigning'])->name('docuperfect.esign.prepareSigning');
    Route::post('/esign/{flow}/prepare-download', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'prepareDownload'])->name('docuperfect.esign.prepareDownload');
    Route::post('/esign/{flow}/prepare-wet-ink', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'prepareWetInk'])->name('docuperfect.esign.prepareWetInk');
    Route::get('/esign/{flow}/signing-complete', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'signingComplete'])->name('docuperfect.esign.signingComplete');
    Route::get('/esign/{flow}/wet-ink-confirmation', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'wetInkConfirmation'])->name('docuperfect.esign.wetInkConfirmation');
    Route::get('/esign/download/{document}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'downloadDocument'])->name('docuperfect.esign.downloadDocument');
    Route::get('/esign/api/properties', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'searchProperties'])->name('docuperfect.esign.api.properties');
    Route::get('/esign/api/contacts', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'searchContacts'])->name('docuperfect.esign.api.contacts');
    Route::get('/esign/api/template/{templateId}/pages', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'templatePages'])->name('docuperfect.esign.api.templatePages');

    // Pack chaining
    Route::post('/esign/pack-chain/init', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'initPackChain'])->name('docuperfect.esign.initPackChain');
    Route::post('/esign/{flow}/next-pack-doc', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'nextPackDocument'])->name('docuperfect.esign.nextPackDoc');
    Route::get('/esign/{flow}/pack-status', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'packStatus'])->name('docuperfect.esign.packStatus');
    Route::post('/esign/{flow}/duplicate-fica', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'duplicateFicaPerParty'])->name('docuperfect.esign.duplicateFica');
    Route::post('/esign/documents/{signatureTemplate}/cancel', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'cancelDocument'])->name('docuperfect.esign.cancelDocument');

    // ===== DOCUMENT IMPORTER =====
    Route::get('/import', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'index'])->name('docuperfect.import.index');
    Route::post('/import/parse', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'parse'])->name('docuperfect.import.parse');
    Route::post('/import/cds', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'generateCdsTemplate'])->name('docuperfect.import.cds');
    Route::get('/import/review', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'review'])->name('docuperfect.import.review');
    Route::post('/import/generate', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'generate'])->name('docuperfect.import.generate');
    Route::post('/import/review/mappings', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'saveMappings'])->name('docuperfect.import.review.mappings');
    Route::post('/import/draft/save', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'saveDraft'])->name('docuperfect.import.draft.save');
    Route::delete('/import/draft/{id}', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'destroyDraft'])->name('docuperfect.import.draft.destroy');

    // Signing party management (agency-level)
    Route::get('/import/parties', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'getParties'])->name('docuperfect.import.parties.index');
    Route::post('/import/parties', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'storeParty'])->name('docuperfect.import.parties.store');
    Route::put('/import/parties/{id}', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'updateParty'])->name('docuperfect.import.parties.update');
    Route::delete('/import/parties/{id}', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'destroyParty'])->name('docuperfect.import.parties.destroy');
    Route::post('/import/parties/reorder', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'reorderParties'])->name('docuperfect.import.parties.reorder');
    Route::post('/import/template/{id}/edit', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'editFromTemplate'])->name('docuperfect.import.template.edit');

    // ===== RENDERER TEST =====
    Route::get('/renderer-test', function () {
        return view('docuperfect.renderer-test');
    })->name('docuperfect.renderer-test');

    // ===== CDS PARSER TEST =====
    Route::get('/parser-test', function () {
        return view('docuperfect.parser-test');
    })->name('docuperfect.parser-test');

    Route::post('/parser-test', function (\Illuminate\Http\Request $request) {
        $request->validate(['document' => 'required|file|mimes:docx']);

        $file = $request->file('document');
        $fullPath = $file->getPathname();

        $parser = new \App\Services\Docuperfect\CdsParserService();
        $cds = $parser->parse($fullPath);

        $renderer = new \App\Services\Docuperfect\CdsRendererService();
        $html = $renderer->render($cds);

        return view('docuperfect.parser-test-result', [
            'cds' => $cds,
            'html' => $html,
            'sectionCount' => count($cds['sections'] ?? []),
            'title' => $cds['title'] ?? 'Unknown',
        ]);
    })->name('docuperfect.parser-test.upload');

    // ===== WEB TEMPLATE PREVIEWS =====
    Route::get('/web-preview/letting-mandate-v5', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'lettingMandateV5'])->name('docuperfect.webPreview.lettingMandateV5');
    Route::get('/web-preview/rental-application-v8', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'rentalApplicationV8'])->name('docuperfect.webPreview.rentalApplicationV8');
    Route::get('/web-preview/letting-mandatory-disclosure-v7', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'lettingMandatoryDisclosureV7'])->name('docuperfect.webPreview.lettingMandatoryDisclosureV7');
    Route::get('/web-preview/letting-marketing-permission-v7', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'lettingMarketingPermissionV7'])->name('docuperfect.webPreview.lettingMarketingPermissionV7');
    Route::get('/web-preview/lease-agreement-popi-v8', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'leaseAgreementPopiV8'])->name('docuperfect.webPreview.leaseAgreementPopiV8');
    Route::get('/web-preview/commercial-lease-agreement-v5', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'commercialLeaseAgreementV5'])->name('docuperfect.webPreview.commercialLeaseAgreementV5');

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
    Route::get('/documents/{document}/signatures/authorise-signing', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'authoriseSigning'])->name('docuperfect.signatures.authoriseSigning');
    Route::post('/documents/{document}/signatures/return-to-candidate', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'returnToCandidate'])->name('docuperfect.signatures.returnToCandidate');

    // Dashboard polling
    Route::get('/rental/status-check', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'statusCheck'])->name('docuperfect.rental.statusCheck');

    // Pre-signed document upload
    Route::post('/documents/{document}/signatures/upload-presigned', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'uploadPresigned'])->name('docuperfect.signatures.uploadPresigned');

    // Signature setup
    Route::get('/documents/{document}/signatures/setup', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'setup'])->name('docuperfect.signatures.setup');
    Route::post('/documents/{document}/signatures/parties', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveParties'])->name('docuperfect.signatures.saveParties');
    Route::post('/documents/{document}/signatures/markers', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveMarkers'])->name('docuperfect.signatures.saveMarkers');
    Route::put('/documents/{document}/signatures/markers', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'updateMarkers'])->name('docuperfect.signatures.updateMarkers');

    // Dynamic signature zones
    Route::get('/documents/{document}/signatures/zones', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'getZones'])->name('docuperfect.signatures.zones');
    Route::post('/documents/{document}/signatures/zones', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'storeZone'])->name('docuperfect.signatures.storeZone');
    Route::post('/documents/{document}/signatures/zones/batch', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'batchStoreZones'])->name('docuperfect.signatures.batchStoreZones');
    Route::put('/documents/{document}/signatures/zones/{zone}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'updateZone'])->name('docuperfect.signatures.updateZone');
    Route::delete('/documents/{document}/signatures/zones/{zone}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'deleteZone'])->name('docuperfect.signatures.deleteZone');

    // Internal signing
    Route::get('/documents/{document}/sign', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sign'])->name('docuperfect.signatures.sign');
    Route::post('/documents/{document}/sign/{marker}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'captureSignature'])->name('docuperfect.signatures.capture');
    Route::post('/documents/{document}/save-agent-fields', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveAgentFields'])->name('docuperfect.signatures.saveAgentFields');
    Route::post('/documents/{document}/save-agent-web-fields', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveAgentWebFields'])->name('docuperfect.signatures.saveAgentWebFields');
    Route::post('/documents/{document}/sign-complete', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'signComplete'])->name('docuperfect.signatures.signComplete');
    Route::post('/documents/{document}/web-sign-complete', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'webSignComplete'])->name('docuperfect.signatures.webSignComplete');

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

    // Deferred signing
    Route::post('/documents/{document}/signatures/resume-deferred', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'resumeDeferred'])->name('docuperfect.signatures.resumeDeferred');

    // Property document dashboard
    Route::get('/property/{propertyId}/documents', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'propertyDocuments'])->name('docuperfect.property.documents');

    // Section signing (agent/internal)
    Route::post('/documents/{document}/sections/accept', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'acceptSection'])->name('docuperfect.signatures.acceptSection');
    Route::get('/documents/{document}/sections/progress', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'getSectionProgress'])->name('docuperfect.signatures.sectionProgress');

    // Amendment review (agent/internal)
    Route::get('/documents/{document}/amendments', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'amendments'])->name('docuperfect.signatures.amendments');
    Route::post('/documents/{document}/amendments/{amendment}/action', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'amendmentAction'])->name('docuperfect.signatures.amendmentAction');

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
    Route::get('/{token}/gateway', [\App\Http\Controllers\Docuperfect\SigningController::class, 'gateway'])->name('signatures.external.gateway');
    Route::post('/{token}/verify', [\App\Http\Controllers\Docuperfect\SigningController::class, 'verify'])->name('signatures.external.verify');
    Route::get('/{token}/consent', [\App\Http\Controllers\Docuperfect\SigningController::class, 'showConsent'])->name('signatures.external.showConsent');
    Route::post('/{token}/consent', [\App\Http\Controllers\Docuperfect\SigningController::class, 'captureConsent'])->name('signatures.external.consent');
    Route::get('/{token}/already-signed', [\App\Http\Controllers\Docuperfect\SigningController::class, 'alreadySigned'])->name('signatures.external.alreadySigned');
    Route::post('/{token}/choose-method', [\App\Http\Controllers\Docuperfect\SigningController::class, 'chooseMethod'])->name('signatures.external.chooseMethod');
    Route::post('/{token}/capture/{marker}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'capture'])->name('signatures.external.capture');
    Route::post('/{token}/save-fields', [\App\Http\Controllers\Docuperfect\SigningController::class, 'saveFields'])->name('signatures.external.saveFields');
    Route::post('/{token}/save-web-fields', [\App\Http\Controllers\Docuperfect\SigningController::class, 'saveWebFields'])->name('signatures.external.saveWebFields');
    Route::post('/{token}/complete-web', [\App\Http\Controllers\Docuperfect\SigningController::class, 'completeWeb'])->name('signatures.external.completeWeb');
    Route::post('/{token}/complete', [\App\Http\Controllers\Docuperfect\SigningController::class, 'complete'])->name('signatures.external.complete');
    Route::get('/{token}/completed', [\App\Http\Controllers\Docuperfect\SigningController::class, 'completed'])->name('signatures.external.completed');
    Route::post('/{token}/upload', [\App\Http\Controllers\Docuperfect\SigningController::class, 'uploadWetInk'])->name('signatures.external.upload');
    Route::get('/{token}/download', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadForSigning'])->name('signatures.external.download');
    Route::get('/{token}/print', [\App\Http\Controllers\Docuperfect\SigningController::class, 'printView'])->name('signatures.external.print');
    Route::get('/{token}/wet-ink-portal', [\App\Http\Controllers\Docuperfect\SigningController::class, 'wetInkPortal'])->name('signatures.external.wetInkPortal');
    Route::post('/{token}/decline', [\App\Http\Controllers\Docuperfect\SigningController::class, 'decline'])->name('signatures.external.decline');
    Route::get('/{token}/flattened-page/{page}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'flattenedPageImage'])->name('signatures.external.flattenedPage');

    // Section signing (external)
    Route::post('/{token}/sections/accept', [\App\Http\Controllers\Docuperfect\SigningController::class, 'acceptSection'])->name('signatures.external.acceptSection');
    Route::post('/{token}/sections/reject', [\App\Http\Controllers\Docuperfect\SigningController::class, 'rejectSection'])->name('signatures.external.rejectSection');
    Route::get('/{token}/sections/progress', [\App\Http\Controllers\Docuperfect\SigningController::class, 'getSectionProgress'])->name('signatures.external.sectionProgress');

    // Amendment review (external — re-signing)
    Route::get('/{token}/amendment-review', [\App\Http\Controllers\Docuperfect\SigningController::class, 'amendmentReview'])->name('signatures.external.amendment-review');
    Route::post('/{token}/amendment/{amendment}/accept', [\App\Http\Controllers\Docuperfect\SigningController::class, 'acceptAmendment'])->name('signatures.external.acceptAmendment');
    Route::post('/{token}/amendment/{amendment}/reject', [\App\Http\Controllers\Docuperfect\SigningController::class, 'rejectAmendment'])->name('signatures.external.rejectAmendment');
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

// ===== PROSPECTING =====
Route::middleware(['auth', 'permission:access_prospecting'])->prefix('prospecting')->name('prospecting.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ProspectingController::class, 'index'])->name('index');
    Route::get('/thumbnail/{listing}', [\App\Http\Controllers\ProspectingController::class, 'thumbnail'])->name('thumbnail');
    Route::post('/{listing}/claim', [\App\Http\Controllers\ProspectingController::class, 'claim'])->name('claim');
    Route::post('/{listing}/feedback', [\App\Http\Controllers\ProspectingController::class, 'feedback'])->name('feedback');
    Route::post('/{listing}/release', [\App\Http\Controllers\ProspectingController::class, 'release'])->name('release');
    Route::get('/{listing}', [\App\Http\Controllers\ProspectingController::class, 'show'])->name('show');
});

// Portal capture ingest endpoint (outside presentation prefix — extension posts here)
// Uses auth.portal_capture: session auth OR bearer token (for Chrome extension)
Route::middleware(['auth.portal_capture'])->post('/portal-captures/ingest', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'ingest'])
    ->name('portal-captures.ingest');

