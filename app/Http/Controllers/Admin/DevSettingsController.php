<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DevSetting;
use Illuminate\Http\Request;

class DevSettingsController extends Controller
{
    /**
     * Gate password required to flip the "Enable demo mode" toggle either way.
     * Demo mode is an authentication bypass, so changing it needs confirmation.
     */
    private const DEMO_TOGGLE_PASSWORD = 'Demo@on&off@$';

    /**
     * Sections of the Dev Settings hub. ?s=<key> drives the right pane, same
     * contract as the main settings hub (CoreX\SettingsController::index).
     */
    private const SECTIONS = ['compliance', 'demo'];

    public function index(Request $request)
    {
        $section = (string) $request->get('s', '');
        if (!in_array($section, self::SECTIONS, true)) {
            $section = 'compliance';
        }

        return view('admin.dev-settings.index', [
            'activeSection'            => $section,
            'complianceChecksDisabled' => DevSetting::bool('compliance_checks_disabled'),
            'demoModeEnabled'          => DevSetting::bool('demo_mode_enabled'),
            'isProduction'             => app()->environment('production'),
        ]);
    }

    /**
     * Dedicated demo-sidebar curation page (linked from Dev Settings, under
     * the demo-mode toggle). The curator builds its checklist client-side
     * from the live sidebar and pre-checks the saved hidden keys
     * (g:<group> | p:<path>).
     */
    public function demoSidebar()
    {
        return view('admin.dev-settings.demo-sidebar', [
            'demoHiddenNav' => DevSetting::demoHiddenSidebar(),
        ]);
    }

    public function update(Request $request)
    {
        DevSetting::set(
            'compliance_checks_disabled',
            $request->boolean('compliance_checks_disabled') ? '1' : '0'
        );

        // Demo mode is an auth bypass — flipping it (on OR off) requires the
        // gate password. If demo mode isn't changing, no password is needed.
        $currentDemo   = DevSetting::bool('demo_mode_enabled');
        $requestedDemo = $request->boolean('demo_mode_enabled');

        if ($currentDemo !== $requestedDemo) {
            $supplied = (string) $request->input('demo_toggle_password', '');

            if (!hash_equals(self::DEMO_TOGGLE_PASSWORD, $supplied)) {
                // Land back on the Demo pane — otherwise the hub opens on
                // Compliance and the password error sits on a pane nobody sees.
                return redirect()->route('admin.dev-settings.index', ['s' => 'demo'])
                    ->withErrors(['demo_toggle_password' => 'Incorrect password — demo mode was left unchanged.'])
                    ->with('warning', 'Other settings were saved, but demo mode requires the correct password to change.');
            }

            DevSetting::set('demo_mode_enabled', $requestedDemo ? '1' : '0');

            return redirect()->route('admin.dev-settings.index', ['s' => 'demo'])
                ->with('success', 'Dev settings updated.');
        }

        return redirect()->route('admin.dev-settings.index')
            ->with('success', 'Dev settings updated.');
    }

    /**
     * Persist which sidebar items are hidden for demo-agency members.
     * Keys are opaque strings produced by the curator: g:<groupKey> for an
     * entire expandable section, p:<pathname> for a single page / sub-page.
     */
    public function updateDemoSidebar(Request $request)
    {
        $validated = $request->validate([
            'keys'   => 'nullable|array',
            'keys.*' => 'string|max:255',
        ]);

        $keys = array_values(array_unique($validated['keys'] ?? []));

        DevSetting::set('demo_hidden_sidebar', json_encode($keys));

        return redirect()->route('admin.dev-settings.demo-sidebar')
            ->with('success', 'Demo sidebar visibility updated.');
    }
}
