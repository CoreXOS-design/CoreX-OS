<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\PerformanceSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The authenticated, guided agency-setup wizard.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §5, §6.
 *
 * Runs under normal auth + agency.required + permission:agency_setup.run. Each
 * step writes LIVE through the exact same SettingsController methods the normal
 * settings page uses (config/agency-onboarding-copy.php → `savers`), so the two
 * can never drift. A saver's ValidationException bubbles up so the step re-renders
 * with the error; a 403 (the Admin lacks that section's permission) is absorbed —
 * the control is simply not written, matching what the settings page would allow.
 *
 * The token's job ended at login; here the setup is resolved from the
 * authenticated Admin's own agency (firstOrCreate, so the "Re-open from settings"
 * path works even for agencies created before this feature existed).
 */
class AgencySetupWizardController extends Controller
{
    /** GET /corex/agency-setup — resume at the current step. */
    public function index()
    {
        $setup  = $this->resolveOrCreateSetup();
        $agency = $this->agency();
        $active = AgencyOnboardingSetup::activeSteps($agency);

        // Resume pointer → the step at current_step, clamped to the nearest
        // ACTIVE step (a gated-off step must never be the resume target).
        $pointerKey = AgencyOnboardingSetup::STEPS[($setup->current_step ?? 1) - 1] ?? AgencyOnboardingSetup::STEPS[0];
        $stepKey = $this->nearestActiveStep($pointerKey, $active);

        return redirect()->route('corex.agency-setup.step', ['step' => $stepKey]);
    }

    /** GET /corex/agency-setup/step/{step} — render one step. */
    public function show(string $step)
    {
        $this->assertStep($step);
        $setup  = $this->resolveOrCreateSetup();
        $agency = $this->agency();

        // A legitimately-gated step (e.g. `matches` with Core Matches switched
        // off in the capabilities step) is not a 404 — it is skipped. Redirect
        // forward to the nearest active step. assertStep still 404s truly-unknown
        // keys above.
        $active = AgencyOnboardingSetup::activeSteps($agency);
        if (!in_array($step, $active, true)) {
            return redirect()->route('corex.agency-setup.step', ['step' => $this->nearestActiveStep($step, $active)]);
        }

        $config = config("agency-onboarding-copy.$step");

        return view('agency-setup.wizard', array_merge([
            'setup'    => $setup,
            'agency'   => $agency,
            'stepKey'  => $step,
            'config'   => $config,
            'values'   => $this->currentValues($config, $agency),
            'progress' => $this->progress($setup, $step),
            'nav'      => $this->nav($step),
        ], $this->stepData($step, $agency)));
    }

    /**
     * Extra view data for steps whose inline partial needs live models
     * (commission settings, dashboard reminders, etc.). These are read from the
     * SAME stores the settings page reads, so the wizard shows current values.
     */
    /**
     * Inline collection editors (add/remove lists that render OUTSIDE the main
     * form, since they need their own sub-forms). Each maps to the canonical
     * CRUD so the wizard never rebuilds a parallel store.
     */
    private const COLLECTIONS = [
        'contact_source' => ['step' => 'contacts', 'model' => \App\Models\ContactSource::class, 'label' => 'Contact sources', 'placeholder' => 'e.g. Walk-in'],
        'branch'         => ['step' => 'branches', 'label' => 'Branches', 'placeholder' => 'e.g. Seabreeze Bay'],
    ];

    private function stepData(string $step, Agency $agency): array
    {
        return match ($step) {
            'commission' => [
                'commission' => \App\Models\CommissionSetting::forAgency($agency->id),
            ],
            'branches' => [
                'branches' => \App\Models\Branch::orderBy('name')->get(),
            ],
            // Contact TYPES are the six fixed signing roles (Owner, Other, Seller,
            // Buyer, Lessor, Lessee) — not configurable, so the wizard doesn't
            // surface them. Only lead sources are editable here.
            'contacts' => [
                'contactSources' => \App\Models\ContactSource::orderBy('sort_order')->orderBy('name')->get(),
            ],
            'notifications' => [
                'dashboard' => \App\Models\CommandCenter\AgencyDashboardSetting::firstOrNew(['agency_id' => $agency->id]),
            ],
            // Explainer step — show the agency's REAL roles and how many people
            // hold each, so the example is their agency and not a mock-up. Owner
            // roles are excluded: they are the CoreX platform team, not staff.
            'roles' => [
                'roleRows' => collect(\App\Models\Role::allRoles($agency->id))
                    ->reject(fn ($r) => $r->is_owner)
                    ->map(fn ($r) => [
                        'name'  => $r->name,
                        'label' => $r->label ?: \Illuminate\Support\Str::headline($r->name),
                        'count' => \App\Models\User::withoutGlobalScopes()
                            ->where('agency_id', $agency->id)
                            ->where('role', $r->name)
                            ->count(),
                    ])
                    ->values()->all(),
                'permissionCount' => collect(config('corex-permissions.permissions', []))->count(),
                'splitOn'         => (bool) $agency->split_branches_enabled,
            ],
            'compliance' => [
                'whistleblow' => [
                    'officer_email' => $agency->whistleblow_compliance_officer_email ?? null,
                    'approver_ids'  => (array) ($agency->whistleblow_approver_user_ids ?? []),
                ],
                'agencyMembers' => \App\Models\User::withoutGlobalScopes()
                    ->where('agency_id', $agency->id)
                    ->whereIn('role', ['admin', 'branch_manager', 'agent'])
                    ->orderBy('name')->get(['id', 'name', 'email']),
            ],
            default => [],
        };
    }

    /** POST /corex/agency-setup/step/{step} — save via canonical paths, advance. */
    public function save(Request $request, string $step)
    {
        $this->assertStep($step);
        $setup  = $this->resolveOrCreateSetup();
        $config = config("agency-onboarding-copy.$step");

        foreach (($config['savers'] ?? []) as $saver) {
            try {
                // Some canonical savers take the Agency as a second argument
                // (e.g. CompanySettingsController@update). Declared per-saver.
                $args = [$request];
                if (!empty($saver['pass_agency'])) {
                    $args[] = $this->agency();
                }
                app($saver['controller'])->{$saver['method']}(...$args);
            } catch (ValidationException $e) {
                // Bubble so the step re-renders with the field error(s).
                throw $e;
            } catch (HttpException $e) {
                if ($e->getStatusCode() === 403) {
                    // Admin lacks this section's permission — absorb, don't write,
                    // don't break the flow (spec §8 / BUILD_STANDARD §3).
                    Log::info('Agency setup wizard: saver skipped (no permission).', [
                        'step' => $step, 'method' => $saver['method'], 'user' => Auth::id(),
                    ]);
                    continue;
                }
                throw $e;
            }
        }

        $setup->markStepComplete($step);

        return $this->advance($setup, $step, 'Saved.');
    }

    /** POST /corex/agency-setup/step/{step}/skip — advance without writing. */
    public function skip(string $step)
    {
        $this->assertStep($step);
        $setup = $this->resolveOrCreateSetup();
        // A skipped step still advances the pointer, but is NOT marked complete
        // (so progress % honestly reflects what was configured).
        return $this->advance($setup, $step, null, skipped: true);
    }

    /** POST /corex/agency-setup/collection/{collection} — add a list item inline. */
    public function addCollectionItem(Request $request, string $collection)
    {
        $def = self::COLLECTIONS[$collection] ?? abort(404);
        $this->resolveOrCreateSetup(); // ensure agency context + setup exist

        try {
            if ($collection === 'contact_source') {
                app(\App\Http\Controllers\CoreX\ContactSourceController::class)->store($request);
            } elseif ($collection === 'branch') {
                app(\App\Http\Controllers\Admin\BranchAssignmentController::class)->createBranch($request);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }
        }

        return redirect()->route('corex.agency-setup.step', ['step' => $def['step']])
            ->with('success', 'Added.');
    }

    /** DELETE /corex/agency-setup/collection/{collection}/{id} — remove a list item. */
    public function removeCollectionItem(Request $request, string $collection, int $id)
    {
        $def = self::COLLECTIONS[$collection] ?? abort(404);
        $this->resolveOrCreateSetup();

        try {
            if ($collection === 'contact_source') {
                $src = \App\Models\ContactSource::findOrFail($id);
                app(\App\Http\Controllers\CoreX\ContactSourceController::class)->destroy($src);
            } elseif ($collection === 'branch') {
                $branch = \App\Models\Branch::findOrFail($id);
                // deleteBranch refuses (and flashes an error bag) while users are
                // still attached — that guard must reach the wizard, not be
                // swallowed. It flashes to session immediately, so it survives
                // our own redirect below.
                app(\App\Http\Controllers\Admin\BranchAssignmentController::class)->deleteBranch($request, $branch);
            }
        } catch (HttpException $e) {
            if (!in_array($e->getStatusCode(), [403, 404], true)) {
                throw $e;
            }
        }

        $redirect = redirect()->route('corex.agency-setup.step', ['step' => $def['step']]);

        // Don't stamp a misleading "Removed." over a refusal (e.g. branch still
        // has users assigned).
        return session()->get('errors') ? $redirect : $redirect->with('success', 'Removed.');
    }

    /** POST /corex/agency-setup/finish — mark complete, exit to dashboard. */
    public function finish()
    {
        $setup = $this->resolveOrCreateSetup();
        $setup->markStepComplete('access');
        if (!$setup->completed_at) {
            $setup->completed_at = now();
            $setup->save();
        }
        return redirect()->route('dashboard')
            ->with('success', 'Your agency setup is complete. Welcome to CoreX!');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function advance(AgencyOnboardingSetup $setup, string $step, ?string $flash, bool $skipped = false)
    {
        // Advance over ACTIVE steps only — a gated-off step is stepped past
        // (switchboard spec §7). The capabilities step may itself have just
        // toggled a feature off, so recompute against the fresh state.
        $steps = AgencyOnboardingSetup::activeSteps($this->agency());
        $i = array_search($step, $steps, true);

        // The just-saved step is no longer active (edge: a step that gated
        // itself off). Fall back to the first active step.
        if ($i === false) {
            return redirect()->route('corex.agency-setup.step', ['step' => $steps[0] ?? AgencyOnboardingSetup::STEPS[0]]);
        }

        $isLast = $i === (count($steps) - 1);
        if ($isLast) {
            // Last active step's Save routes straight to finish.
            return $this->finish();
        }

        $next = $steps[$i + 1];

        if ($skipped) {
            // Move the resume pointer forward past the skipped step, to the next
            // active step's 1-based position in the FULL step list.
            $globalPos = (int) array_search($next, AgencyOnboardingSetup::STEPS, true) + 1;
            $setup->current_step = max($setup->current_step, $globalPos);
            $setup->save();
        }

        $redirect = redirect()->route('corex.agency-setup.step', ['step' => $next]);
        return $flash ? $redirect->with('success', $flash) : $redirect;
    }

    /**
     * Resolve a step key to the nearest ACTIVE step: itself if active, else the
     * first active step after it in the full list, else the last active before
     * it, else the first active step. Never returns a gated-off key.
     */
    private function nearestActiveStep(string $step, array $active): string
    {
        if (in_array($step, $active, true)) {
            return $step;
        }
        $all = AgencyOnboardingSetup::STEPS;
        $i = array_search($step, $all, true);
        if ($i === false) {
            return $active[0] ?? $all[0];
        }
        for ($j = $i + 1; $j < count($all); $j++) {
            if (in_array($all[$j], $active, true)) {
                return $all[$j];
            }
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            if (in_array($all[$j], $active, true)) {
                return $all[$j];
            }
        }
        return $active[0] ?? $all[0];
    }

    private function resolveOrCreateSetup(): AgencyOnboardingSetup
    {
        $agency = $this->agency();

        $setup = AgencyOnboardingSetup::where('agency_id', $agency->id)->first();
        if ($setup) {
            return $setup;
        }

        // No record yet (agency predates this feature, or owner opening a fresh
        // agency's guide). Create one so the wizard is always reachable.
        $setup = new AgencyOnboardingSetup();
        $setup->agency_id       = $agency->id;
        $setup->token           = AgencyOnboardingSetup::generateToken();
        $setup->slug            = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $setup->created_by      = Auth::id();
        $setup->admin_user_id   = Auth::user()->role === 'admin' ? Auth::id() : null;
        $setup->current_step    = 1;
        $setup->completed_steps = [];
        $setup->expires_at      = now()->addDays(30);
        $setup->save();

        return $setup;
    }

    private function agency(): Agency
    {
        $agencyId = Auth::user()?->effectiveAgencyId();
        abort_unless($agencyId, 404, 'No agency in scope.');
        return Agency::findOrFail($agencyId);
    }

    private function assertStep(string $step): void
    {
        abort_unless(in_array($step, AgencyOnboardingSetup::STEPS, true), 404);
    }

    /** Resolve each control's current value from its declared store. */
    private function currentValues(array $config, Agency $agency): array
    {
        $values = [];
        foreach (($config['controls'] ?? []) as $control) {
            $key = $control['key'];
            $values[$key] = match ($control['source'] ?? 'agency') {
                'perf'  => PerformanceSetting::get($key, $control['default'] ?? null),
                default => $agency->{$key} ?? ($control['default'] ?? null),
            };
        }
        return $values;
    }

    private function progress(AgencyOnboardingSetup $setup, string $step): array
    {
        // "Step X of N" where N is the ACTIVE-step count for this agency
        // (switchboard spec §8) — gated-off steps are neither shown nor counted.
        $steps = AgencyOnboardingSetup::activeSteps($this->agency());
        $pos = array_search($step, $steps, true);
        return [
            'current' => $pos === false ? 1 : (int) ($pos + 1),
            'total'   => count($steps),
            'percent' => $setup->progressPercent($this->agency()),
        ];
    }

    private function nav(string $step): array
    {
        // prev/next computed over ACTIVE steps, so Back/Next step over a
        // gated-off step invisibly (switchboard spec §7).
        $steps = AgencyOnboardingSetup::activeSteps($this->agency());
        $i = array_search($step, $steps, true);
        if ($i === false) {
            return ['prev' => null, 'next' => null, 'isLast' => true, 'index' => 0];
        }
        return [
            'prev'   => $i > 0 ? $steps[$i - 1] : null,
            'next'   => $i < count($steps) - 1 ? $steps[$i + 1] : null,
            'isLast' => $i === count($steps) - 1,
            'index'  => $i,
        ];
    }
}
