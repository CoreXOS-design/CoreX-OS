<?php

namespace App\Support\Navigation;

/**
 * STANDING RULE (Andre + Johan, 2026-10-07): every sidebar item must be switchable
 * on/off under Settings → Features. This class is the one place that decides which
 * sidebar links are allowed to have NO toggle, and why. It rides on
 * SidebarNavAuditor (which already parses the sidebar) — it is not a second parser.
 *
 * A link is "covered" when it sits inside at least one @feature('key') wrap
 * (SidebarNavAuditor::featureStacks). Anything else must be in one of the two
 * explicit exemption lists below — and both lists are justified, not just named:
 *
 *   CORE_ROUTES  — a core pillar page that is never toggleable. Each entry names the
 *                  registry key that makes it core; the guard test asserts that key
 *                  really has `'core' => true`, so an exemption can't quietly outlive
 *                  its reason.
 *   owner-only   — a link inside an `@if($isOwner)` block, or in one of PLATFORM_GROUPS: the CoreX
 *                  system owner's System Developer section. They are not an agency's features; an
 *                  agency never sees them, so there is nothing for an agency to switch.
 *
 * Adding a sidebar item? Give it a row in config/corex-features.php and wrap it in
 * @feature('<key>'). Do NOT add it here unless it is genuinely core or owner-only.
 */
final class SidebarFeatureCoverage
{
    /**
     * Route-name glob => core registry key that justifies the exemption.
     *
     * @var array<string,string>
     */
    public const CORE_ROUTES = [
        'command-center.today'              => 'dashboard',
        'command-center.calendar*'          => 'dashboard',
        'command-center.tasks*'             => 'dashboard',
        'command-center.user-settings*'     => 'dashboard',
        'agent.portal'                      => 'my-portal',
        'corex.properties.index'            => 'properties',
        'corex.map.index'                   => 'properties',
        'corex.contacts.index'               => 'contacts',
        'deals-v2.*'                        => 'deals',
        'admin.settings.deal-*'             => 'deals',
        'admin.settings.document-distribution*' => 'deals',
        'corex.settings'                    => 'settings',
        'admin.company-settings*'           => 'company-settings',
        'corex.role-manager*'               => 'role-manager',
        // Release notes for the CoreX platform itself — shown to everyone, not a module an agency runs.
        'corex.whats-new.index'              => 'dashboard',
    ];

    /**
     * Sidebar group keys that are System-Owner-only (the System Developer section and
     * its Hidden drawer). `deals-v2` / `deal-register-settings` are the retired Deals
     * menu (`@if(false && …)`) — their pages are core `deals` routes, kept reachable by URL.
     *
     * @var list<string>
     */
    public const PLATFORM_GROUPS = [
        'agency', 'api-server', 'integration', 'importer', 'hidden',
        'deals-v2', 'deal-register-settings',
    ];

    /**
     * Sidebar links that sit outside every @feature wrap and are not exempt.
     *
     * @param  array<int,array<string,mixed>>  $navEntries  SidebarNavAuditor::run()['navEntries']
     * @return list<array<string,mixed>>
     */
    public static function uncovered(array $navEntries): array
    {
        $out = [];
        foreach ($navEntries as $e) {
            if (! empty($e['is_toggle']) || ! empty($e['features'])) {
                continue;
            }
            if (self::exemption($e) !== null) {
                continue;
            }
            $out[] = $e;
        }

        return $out;
    }

    /**
     * Why this link is allowed to have no toggle, or null when it is not.
     *
     * @param  array<string,mixed>  $entry
     */
    public static function exemption(array $entry): ?string
    {
        if (! empty($entry['owner_only'])) {
            return 'platform-owner:block';
        }

        if (in_array($entry['group'] ?? null, self::PLATFORM_GROUPS, true)) {
            return 'platform-owner:' . $entry['group'];
        }

        $route = $entry['route_name'] ?? null;
        if ($route !== null) {
            foreach (self::CORE_ROUTES as $glob => $coreKey) {
                if (SidebarNavAuditor::strIs($glob, $route)) {
                    return 'core:' . $coreKey;
                }
            }
        }

        return null;
    }
}
