<?php

use Illuminate\Support\Facades\Route;

/*
 * AT-454 - the Road to Sold settings routes (spec section 5), as a closure so the same screens can be
 * mounted twice: web.php mounts them as `road-to-sold.settings.*` (track sales); Road to Rented mounts
 * this file under `road-to-rented.settings.*` and the screens serve the rentals track
 * (App\Services\RoadToSold\Support\Routing reads the track from the route-name root).
 * The hub is open to anyone who can manage settings, areas or targets; each tab re-checks its own key.
 */
return function () {
    // Everything here needs the Road to Sold (call list) feature EXCEPT Budgets and the monthly targets: those are the agency's
    // planning numbers, not the prospecting list, so they stay reachable when the feature is off (Johan, 23 Oct 2026).
    $rts = 'feature:road-to-sold';

    Route::get('/', [\App\Http\Controllers\RoadToSold\SettingsController::class, 'index'])
        ->middleware([$rts, 'permission:road_to_sold.manage_settings,road_to_sold.manage_areas,road_to_sold.manage_targets,road_to_sold.manage_recipe'])
        ->name('index');
    Route::put('/general', [\App\Http\Controllers\RoadToSold\SettingsController::class, 'update'])
        ->middleware([$rts, 'permission:road_to_sold.manage_settings'])
        ->name('general.update');
    Route::post('/general/reset', [\App\Http\Controllers\RoadToSold\SettingsController::class, 'reset'])
        ->middleware([$rts, 'permission:road_to_sold.manage_settings'])
        ->name('general.reset');

    // Areas - which suburbs each agent works (spec section 5.2).
    Route::middleware([$rts, 'permission:road_to_sold.manage_areas'])->prefix('areas')->name('areas.')->group(function () use ($rts) {
        Route::get('/', [\App\Http\Controllers\RoadToSold\AreasController::class, 'index'])->name('index');
        Route::post('/agents/{user}', [\App\Http\Controllers\RoadToSold\AreasController::class, 'sync'])->whereNumber('user')->name('sync');
        Route::post('/hide', [\App\Http\Controllers\RoadToSold\AreasController::class, 'hide'])->middleware('permission:road_to_sold.manage_settings')->name('hide');
        Route::post('/hidden/{hidden}/show', [\App\Http\Controllers\RoadToSold\AreasController::class, 'showAgain'])->whereNumber('hidden')->middleware('permission:road_to_sold.manage_settings')->name('show-again');
        Route::delete('/{area}', [\App\Http\Controllers\RoadToSold\AreasController::class, 'archive'])->whereNumber('area')->name('archive');
        Route::post('/{area}/restore', [\App\Http\Controllers\RoadToSold\AreasController::class, 'restore'])->whereNumber('area')->withTrashed()->name('restore');
    });

    // Targets - an agent's number for each weekday, over the agency default (spec section 5.3).
    Route::middleware('permission:road_to_sold.manage_targets,road_to_sold.manage_settings')->prefix('targets')->name('targets.')->group(function () use ($rts) {
        $c = \App\Http\Controllers\RoadToSold\TargetsController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::put('/default', [$c, 'updateDefault'])->middleware($rts)->name('default');
        Route::put('/agents/{user}', [$c, 'saveAgent'])->whereNumber('user')->middleware($rts)->name('agent');
        Route::post('/agents/{user}/fill-weekdays', [$c, 'fillWeekdays'])->whereNumber('user')->middleware($rts)->name('fill-weekdays');
        Route::post('/copy-to-agents', [$c, 'copyToAgents'])->middleware($rts)->name('copy-agents');
        Route::delete('/agents/{user}', [$c, 'clear'])->whereNumber('user')->middleware($rts)->name('clear');
        Route::post('/agents/{user}/restore', [$c, 'restore'])->whereNumber('user')->middleware($rts)->name('restore');

        // Monthly targets (points / deals / value / listings per agent per month) - agency-tracker-rehome.md 5.7.
        Route::put('/monthly/agents/{user}', [$c, 'saveMonthly'])->whereNumber('user')->name('monthly.save');
        Route::delete('/monthly/agents/{user}', [$c, 'archiveMonthly'])->whereNumber('user')->name('monthly.archive');
        Route::post('/monthly/agents/{user}/restore', [$c, 'restoreMonthly'])->whereNumber('user')->name('monthly.restore');
        Route::put('/monthly/default', [$c, 'updateMonthlyDefault'])->name('monthly.default');
        Route::post('/monthly/apply-default', [$c, 'applyMonthlyDefault'])->name('monthly.apply-default');
    });

    // Budgets & requirements - what the agency, each branch and each agent needs per month (agency-tracker-rehome.md 4.3).
    Route::middleware('permission:road_to_sold.manage_targets,road_to_sold.manage_settings')->prefix('budgets')->name('budgets.')->group(function () {
        $c = \App\Http\Controllers\RoadToSold\BudgetsController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::put('/agency', [$c, 'saveAgency'])->name('agency');
        Route::put('/branches/{branch}', [$c, 'saveBranch'])->whereNumber('branch')->name('branch');
        Route::put('/agents/{user}', [$c, 'saveAgent'])->whereNumber('user')->name('agent');
        Route::delete('/agents/{user}', [$c, 'archiveAgent'])->whereNumber('user')->name('agent.archive');
        Route::post('/agents/{user}/restore', [$c, 'restoreAgent'])->whereNumber('user')->name('agent.restore');
        Route::post('/copy-previous', [$c, 'copyPrevious'])->name('copy-previous');
    });

    // Task targets - an agent's own target for each task on their level, over the level's (v2 phase 5, spec 5.2 / 10.4).
    Route::middleware([$rts, 'permission:road_to_sold.manage_targets'])->prefix('task-targets')->name('task-targets.')->group(function () {
        $c = \App\Http\Controllers\RoadToSold\TaskTargetsController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::put('/agents/{user}/tasks/{task}', [$c, 'save'])->whereNumber('user')->whereNumber('task')->name('save');
        Route::delete('/agents/{user}/tasks/{task}', [$c, 'clear'])->whereNumber('user')->whereNumber('task')->name('clear');
        Route::post('/agents/{user}/tasks/{task}/restore', [$c, 'restore'])->whereNumber('user')->whereNumber('task')->name('restore');
    });

    // Training & flags - the thresholds (saved through the General tab's writer) and the training an agency points at for each funnel ratio (v2 phase 5, spec 9.3).
    Route::middleware([$rts, 'permission:road_to_sold.manage_settings'])->prefix('flags')->name('flags.')->group(function () {
        $c = \App\Http\Controllers\RoadToSold\FlagSettingsController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/pointers', [$c, 'save'])->name('pointer.save');
        Route::delete('/pointers/{ratio}', [$c, 'archive'])->name('pointer.archive');
        Route::post('/pointers/{ratio}/restore', [$c, 'restore'])->name('pointer.restore');
    });

    // Recipe - the 13 steps, the tasks inside them and the levels an agent works through (road-to-sold.md section 5, 10.4, 13.1).
    Route::middleware([$rts, 'permission:road_to_sold.manage_recipe'])->prefix('recipe')->name('recipe.')->group(function () use ($rts) {
        $c = \App\Http\Controllers\RoadToSold\RecipeController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/start', [$c, 'start'])->name('start');

        Route::get('/tasks/create', [$c, 'taskCreate'])->name('tasks.create');
        Route::post('/tasks', [$c, 'taskStore'])->name('tasks.store');
        Route::get('/tasks/{task}/edit', [$c, 'taskEdit'])->whereNumber('task')->name('tasks.edit');
        Route::put('/tasks/{task}', [$c, 'taskUpdate'])->whereNumber('task')->name('tasks.update');
        Route::delete('/tasks/{task}', [$c, 'taskArchive'])->whereNumber('task')->name('tasks.archive');
        Route::post('/tasks/{task}/restore', [$c, 'taskRestore'])->whereNumber('task')->withTrashed()->name('tasks.restore');

        Route::put('/steps/{step}', [$c, 'stepUpdate'])->whereNumber('step')->name('steps.update');
        Route::delete('/steps/{step}', [$c, 'stepArchive'])->whereNumber('step')->name('steps.archive');
        Route::post('/steps/{step}/restore', [$c, 'stepRestore'])->whereNumber('step')->withTrashed()->name('steps.restore');

        Route::get('/levels/create', [$c, 'levelCreate'])->name('levels.create');
        Route::post('/levels', [$c, 'levelStore'])->name('levels.store');
        Route::get('/levels/{level}/edit', [$c, 'levelEdit'])->whereNumber('level')->name('levels.edit');
        Route::put('/levels/{level}', [$c, 'levelUpdate'])->whereNumber('level')->name('levels.update');
        Route::delete('/levels/{level}', [$c, 'levelArchive'])->whereNumber('level')->name('levels.archive');
        Route::post('/levels/{level}/restore', [$c, 'levelRestore'])->whereNumber('level')->withTrashed()->name('levels.restore');
    });
};
