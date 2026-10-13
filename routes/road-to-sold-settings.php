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
    Route::get('/', [\App\Http\Controllers\RoadToSold\SettingsController::class, 'index'])
        ->middleware('permission:road_to_sold.manage_settings,road_to_sold.manage_areas,road_to_sold.manage_targets')
        ->name('index');
    Route::put('/general', [\App\Http\Controllers\RoadToSold\SettingsController::class, 'update'])
        ->middleware('permission:road_to_sold.manage_settings')
        ->name('general.update');
    Route::post('/general/reset', [\App\Http\Controllers\RoadToSold\SettingsController::class, 'reset'])
        ->middleware('permission:road_to_sold.manage_settings')
        ->name('general.reset');

    // Areas - which suburbs each agent works (spec section 5.2).
    Route::middleware('permission:road_to_sold.manage_areas')->prefix('areas')->name('areas.')->group(function () {
        Route::get('/', [\App\Http\Controllers\RoadToSold\AreasController::class, 'index'])->name('index');
        Route::post('/agents/{user}', [\App\Http\Controllers\RoadToSold\AreasController::class, 'sync'])->whereNumber('user')->name('sync');
        Route::delete('/{area}', [\App\Http\Controllers\RoadToSold\AreasController::class, 'archive'])->whereNumber('area')->name('archive');
        Route::post('/{area}/restore', [\App\Http\Controllers\RoadToSold\AreasController::class, 'restore'])->whereNumber('area')->withTrashed()->name('restore');
    });

    // Targets - an agent's number for each weekday, over the agency default (spec section 5.3).
    Route::middleware('permission:road_to_sold.manage_targets,road_to_sold.manage_settings')->prefix('targets')->name('targets.')->group(function () {
        $c = \App\Http\Controllers\RoadToSold\TargetsController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::put('/default', [$c, 'updateDefault'])->name('default');
        Route::put('/agents/{user}', [$c, 'saveAgent'])->whereNumber('user')->name('agent');
        Route::post('/agents/{user}/fill-weekdays', [$c, 'fillWeekdays'])->whereNumber('user')->name('fill-weekdays');
        Route::post('/copy-to-agents', [$c, 'copyToAgents'])->name('copy-agents');
        Route::delete('/agents/{user}', [$c, 'clear'])->whereNumber('user')->name('clear');
        Route::post('/agents/{user}/restore', [$c, 'restore'])->whereNumber('user')->name('restore');
    });
};
