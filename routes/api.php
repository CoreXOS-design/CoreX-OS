<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\CommandCenterApiController;
use App\Http\Controllers\Api\ProspectingApiController;
use App\Http\Controllers\Api\MobilePropertyController;
use App\Http\Controllers\Api\PropertyPullController;
use App\Http\Controllers\FaultReportController;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    $token = $user->createToken('corex-mobile')->plainTextToken;

    return response()->json([
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'branch' => $user->branch?->name ?? null,
            'ffc_status' => $user->ffc_status ?? null,
        ],
    ]);
});

Route::post('/fault-report', [FaultReportController::class, 'capture'])
    ->middleware('throttle:30,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'branch' => $user->branch?->name ?? null,
            'ffc_status' => $user->ffc_status ?? null,
        ]);
    });

    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    });

    Route::post('/prospecting/import', [ProspectingApiController::class, 'import']);
    Route::get('/prospecting/check-search', [ProspectingApiController::class, 'checkSearch']);

    Route::post('/properties/pull-from-portal', [PropertyPullController::class, 'pullFromPortal']);
    Route::get('/properties/{propertyId}/pull-status', [PropertyPullController::class, 'pullStatus']);

    // ── Mobile Properties ────────────────────────────────────────
    Route::prefix('mobile/properties')->group(function () {
        Route::get('/',         [MobilePropertyController::class, 'index']);
        Route::post('/',        [MobilePropertyController::class, 'store']);

        // Static catalogs (must be defined BEFORE /{property} so they
        // aren't treated as a property id by the route binding).
        Route::get('/options',        [MobilePropertyController::class, 'options']);
        Route::get('/spaces/catalog', [MobilePropertyController::class, 'spacesCatalog']);

        Route::get('/{property}',  [MobilePropertyController::class, 'show']);
        Route::put('/{property}',  [MobilePropertyController::class, 'update']);
        Route::post('/{property}/images', [MobilePropertyController::class, 'uploadImage']);

        // Gallery tags (derived live from this property's spaces)
        Route::get('/{property}/gallery/tags', [MobilePropertyController::class, 'galleryTags']);

        // Spaces & features (Bedroom, Bathroom, Kitchen, …)
        Route::get('/{property}/spaces', [MobilePropertyController::class, 'spacesShow']);
        Route::put('/{property}/spaces', [MobilePropertyController::class, 'spacesUpdate']);
    });

    // ── Command Center ────────────────────────────────────────────
    Route::prefix('command-center')->group(function () {
        Route::get('/dashboard', [CommandCenterApiController::class, 'dashboard']);

        Route::get('/calendar', [CommandCenterApiController::class, 'calendarIndex']);
        Route::post('/calendar', [CommandCenterApiController::class, 'calendarStore']);
        Route::post('/calendar/{calendarEvent}/complete', [CommandCenterApiController::class, 'calendarComplete']);
        Route::post('/calendar/{calendarEvent}/dismiss', [CommandCenterApiController::class, 'calendarDismiss']);

        Route::get('/tasks', [CommandCenterApiController::class, 'tasksIndex']);
        Route::post('/tasks', [CommandCenterApiController::class, 'tasksStore']);
        Route::post('/tasks/{task}/complete', [CommandCenterApiController::class, 'tasksComplete']);
        Route::patch('/tasks/{task}/status', [CommandCenterApiController::class, 'tasksUpdateStatus']);

        Route::post('/resolve-task/{task}', [CommandCenterApiController::class, 'resolveTask']);
        Route::post('/resolve-event/{calendarEvent}', [CommandCenterApiController::class, 'resolveEvent']);

        Route::get('/user-settings', [CommandCenterApiController::class, 'settingsIndex']);
        Route::put('/user-settings', [CommandCenterApiController::class, 'settingsUpdate']);
    });
});
