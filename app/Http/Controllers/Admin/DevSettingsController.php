<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\MobileAppConfigController;
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
    private const SECTIONS = ['compliance', 'demo', 'queue_worker_emails', 'queue_backlog_emails', 'mobile_releases'];

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
            'queueWorkerAlertEmails'   => DevSetting::queueWorkerAlertEmails(),
            'queueBacklogAlertEmails'  => DevSetting::queueBacklogAlertEmails(),
            'mobileReleases'           => $this->mobileReleaseValues(),
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

        if (($error = $this->saveQueueWorkerAlertEmails($request)) !== null) {
            return redirect()->route('admin.dev-settings.index', ['s' => 'queue_worker_emails'])
                ->withErrors(['queue_alert_emails' => $error])
                ->with('warning', 'Other settings were saved, but the queue worker email list was not — fix the error below.');
        }

        if (($error = $this->saveQueueBacklogAlertEmails($request)) !== null) {
            return redirect()->route('admin.dev-settings.index', ['s' => 'queue_backlog_emails'])
                ->withErrors(['queue_backlog_alert_emails' => $error])
                ->with('warning', 'Other settings were saved, but the queue backlog email list was not — fix the error below.');
        }

        if (($error = $this->saveMobileReleases($request)) !== null) {
            return redirect()->route('admin.dev-settings.index', ['s' => 'mobile_releases'])
                ->withErrors(['mobile_releases' => $error])
                ->with('warning', 'Other settings were saved, but the mobile release dials were not — fix the error below.');
        }

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

    /**
     * Recipients for the queue-worker-down alert (Server Health monitoring).
     * Blank rows (the empty slot the UI always leaves for adding one more) are
     * dropped before validation so an untouched form doesn't fail it.
     *
     * @return string|null An error message if any entered address is invalid, else null (saved).
     */
    private function saveQueueWorkerAlertEmails(Request $request): ?string
    {
        $emails = array_values(array_filter(
            (array) $request->input('queue_alert_emails', []),
            fn ($e) => trim((string) $e) !== ''
        ));

        $validator = validator(
            ['queue_alert_emails' => $emails],
            ['queue_alert_emails' => 'array', 'queue_alert_emails.*' => 'email:filter|max:255'],
        );

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        $unique = array_values(array_unique(array_map('strtolower', $emails)));

        DevSetting::set('queue_worker_alert_emails', json_encode($unique));

        return null;
    }

    /**
     * Recipients for the queue-backlog critical alert (corex:queue-healthcheck).
     * Blank rows (the empty slot the UI always leaves for adding one more) are
     * dropped before validation so an untouched form doesn't fail it.
     *
     * @return string|null An error message if any entered address is invalid, else null (saved).
     */
    private function saveQueueBacklogAlertEmails(Request $request): ?string
    {
        $emails = array_values(array_filter(
            (array) $request->input('queue_backlog_alert_emails', []),
            fn ($e) => trim((string) $e) !== ''
        ));

        $validator = validator(
            ['queue_backlog_alert_emails' => $emails],
            ['queue_backlog_alert_emails' => 'array', 'queue_backlog_alert_emails.*' => 'email:filter|max:255'],
        );

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        $unique = array_values(array_unique(array_map('strtolower', $emails)));

        DevSetting::set('queue_backlog_alert_emails', json_encode($unique));

        return null;
    }

    /**
     * Current live values for the mobile release dials, per platform, plus the
     * effective update url (which for Android includes the derivable Play
     * default). The screen shows the EFFECTIVE url because that is what the
     * endpoint actually applies its "nowhere to send them" rule against.
     */
    private function mobileReleaseValues(): array
    {
        $out = [];

        foreach (MobileAppConfigController::PLATFORMS as $platform) {
            $out[$platform] = [
                'latest_build'   => (int) DevSetting::get("mobile_latest_build_{$platform}", '0'),
                'latest_version' => (string) DevSetting::get(
                    "mobile_latest_version_{$platform}",
                    (string) DevSetting::get('mobile_latest_version', '')
                ),
                'min_build'      => (int) DevSetting::get("mobile_min_build_{$platform}", '0'),
                'update_url'     => (string) DevSetting::get("mobile_update_url_{$platform}", ''),
                'effective_url'  => MobileAppConfigController::effectiveUpdateUrl($platform),
            ];
        }

        $out['update_message']           = (string) DevSetting::get('mobile_update_message', '');
        $out['update_available_message'] = (string) DevSetting::get('mobile_update_available_message', '');

        return $out;
    }

    /**
     * Save the mobile release dials.
     *
     * Mirrors the endpoint's own hard rule rather than duplicating its
     * behaviour: a platform with no update url has BOTH dials forced to 0
     * server-side, so arming one here would look like it worked and quietly do
     * nothing. Refusing with an explanation teaches the operator why; silently
     * zeroing teaches them the screen is broken.
     *
     * @return string|null  error message, or null on success
     */
    private function saveMobileReleases(Request $request): ?string
    {
        if (! $request->has('mobile_releases_present')) {
            return null; // the form did not render this section
        }

        $pending = [];

        foreach (MobileAppConfigController::PLATFORMS as $platform) {
            $latestBuild = (int) $request->input("mobile_latest_build_{$platform}", 0);
            $minBuild    = (int) $request->input("mobile_min_build_{$platform}", 0);
            $url         = trim((string) $request->input("mobile_update_url_{$platform}", ''));
            $version     = trim((string) $request->input("mobile_latest_version_{$platform}", ''));

            if ($latestBuild < 0 || $minBuild < 0) {
                return 'Build numbers cannot be negative. Use 0 to switch a dial off.';
            }

            // The effective url is what the endpoint checks — Android has a
            // derivable Play default, so a blank field there is still fine.
            $effective = $url !== ''
                ? $url
                : ($platform === 'android' ? MobileAppConfigController::DEFAULT_ANDROID_URL : '');

            if ($effective === '' && ($latestBuild > 0 || $minBuild > 0)) {
                return ucfirst($platform).' has no update link, so neither dial can be armed — '
                    .'the app would show an "Update now" button that opens nothing. '
                    .'Set the '.ucfirst($platform).' update URL first, or leave both builds at 0.';
            }

            if ($minBuild > 0 && $latestBuild > 0 && $minBuild > $latestBuild) {
                return ucfirst($platform).': the minimum build ('.$minBuild.') is higher than the latest '
                    .'announced build ('.$latestBuild.'), so every agent would be blocked and sent to '
                    .'a release that does not satisfy the block. Raise the latest build first.';
            }

            $pending["mobile_latest_build_{$platform}"]   = (string) $latestBuild;
            $pending["mobile_min_build_{$platform}"]      = (string) $minBuild;
            $pending["mobile_update_url_{$platform}"]     = $url;
            $pending["mobile_latest_version_{$platform}"] = $version;
        }

        $pending['mobile_update_message']           = trim((string) $request->input('mobile_update_message', ''));
        $pending['mobile_update_available_message'] = trim((string) $request->input('mobile_update_available_message', ''));

        // Nothing is written until every platform validates — a half-applied
        // release gate is exactly the state nobody can reason about at 6pm.
        foreach ($pending as $key => $value) {
            DevSetting::set($key, $value);
        }

        return null;
    }
}
