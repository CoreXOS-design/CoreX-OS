<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\AgentSocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();
        $socialAccounts = AgentSocialAccount::where('user_id', $user->id)->active()->get();

        return view('profile.edit', [
            'user'           => $user,
            'socialAccounts' => $socialAccounts,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Update the user's theme preference.
     */
    public function updateTheme(Request $request)
    {
        $request->validate(['theme' => ['required', 'in:light,dark']]);

        $request->user()->update(['theme' => $request->theme]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return Redirect::route('profile.edit')->with('status', 'theme-updated');
    }

    /**
     * Download the CoreX Chrome extension as a zip file.
     * Zips the public/chrome-extension/portal-capture folder on the fly.
     */
    public function downloadExtension(): BinaryFileResponse
    {
        $sourceDir = public_path('chrome-extension/portal-capture');
        $zipPath   = storage_path('app/corex-extension.zip');

        // Always rebuild so the download reflects the latest files
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create extension zip.');
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getRealPath(), strlen($sourceDir) + 1);
                // Normalise to forward slashes for zip compatibility
                $relativePath = str_replace('\\', '/', $relativePath);
                $zip->addFile($file->getRealPath(), $relativePath);
            }
        }

        $zip->close();

        return response()->download($zipPath, 'corex-extension.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
