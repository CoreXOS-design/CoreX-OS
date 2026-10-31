<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdStudioSetting;
use App\Services\AdStudio\AdBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * AT-527 - the Ad Studio brand settings (spec .ai/specs/training-videos.md 9.21.5). Platform level, System Owner only. Every field has a default in
 * config/ad-studio.php; "Reset to default" on a field = save it empty.
 */
class VideoAdBrandController extends Controller
{
    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    public function edit()
    {
        $this->assertOwner();
        $brand = AdBrand::current();

        return view('admin.video-ads.brand', ['brand' => $brand, 'defaults' => AdBrand::defaults(), 'logoUrl' => AdBrand::logoUrl($brand),
            'socialLabels' => (array) config('ad-studio.social_labels')]);
    }

    public function update(Request $request)
    {
        $this->assertOwner();
        $hex = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $data = $request->validate([
            'website' => ['nullable', 'url', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
            'join_line' => ['nullable', 'string', 'max:60'],
            'cta_line' => ['nullable', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:140'],
            'colours.primary' => $hex, 'colours.secondary' => $hex, 'colours.accent' => $hex, 'colours.text' => $hex,
            'social.*' => ['nullable', 'string', 'max:200'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'use_default_logo' => ['nullable', 'boolean'],
        ], [
            'colours.*.regex' => 'Colours are written like #33c4e0.',
        ]);

        $defaults = AdBrand::defaults();
        foreach (['website', 'email', 'phone', 'join_line', 'cta_line', 'tagline'] as $k) {
            $v = trim((string) ($data[$k] ?? ''));
            $v === '' ? AdStudioSetting::forget($k) : AdStudioSetting::put($k, $v);
        }
        $colours = [];
        foreach ((array) ($data['colours'] ?? []) as $k => $v) {
            $colours[$k] = trim((string) $v) !== '' ? strtolower((string) $v) : $defaults['colours'][$k];
        }
        $colours === [] ? AdStudioSetting::forget('colours') : AdStudioSetting::put('colours', $colours);

        // Socials are saved as typed, blank stays blank (empty until Johan fills them).
        $social = [];
        foreach (array_keys((array) config('ad-studio.social_labels')) as $k) {
            $social[$k] = trim((string) ($data['social'][$k] ?? ''));
        }
        AdStudioSetting::put('social', $social);

        if (! empty($data['use_default_logo'])) {
            AdStudioSetting::forget('logo');
        } elseif ($request->hasFile('logo')) {
            $file = $request->file('logo');
            if ($file->getClientOriginalExtension() !== 'svg') {
                $size = @getimagesize($file->getRealPath());
                if (! $size || $size[0] < 600) {
                    return back()->withErrors(['logo' => 'The logo must be at least 600 pixels wide.'])->withInput();
                }
            }
            $path = $file->storeAs('ad-studio', 'logo-' . now()->format('YmdHis') . '.' . strtolower($file->getClientOriginalExtension()), 'public');
            AdStudioSetting::put('logo', $path);
        }

        return redirect()->route('admin.video-ads.brand')->with('status', 'Brand settings saved.');
    }
}
