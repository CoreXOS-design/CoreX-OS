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
        $setup = $this->resolveOrCreateSetup();
        $stepKey = AgencyOnboardingSetup::STEPS[($setup->current_step ?? 1) - 1] ?? AgencyOnboardingSetup::STEPS[0];
        return redirect()->route('corex.agency-setup.step', ['step' => $stepKey]);
    }

    /** GET /corex/agency-setup/step/{step} — render one step. */
    public function show(string $step)
    {
        $this->assertStep($step);
        $setup  = $this->resolveOrCreateSetup();
        $agency = $this->agency();
        $config = config("agency-onboarding-copy.$step");

        return view('agency-setup.wizard', [
            'setup'    => $setup,
            'agency'   => $agency,
            'stepKey'  => $step,
            'config'   => $config,
            'values'   => $this->currentValues($config, $agency),
            'progress' => $this->progress($setup, $step),
            'nav'      => $this->nav($step),
        ]);
    }

    /** POST /corex/agency-setup/step/{step} — save via canonical paths, advance. */
    public function save(Request $request, string $step)
    {
        $this->assertStep($step);
        $setup  = $this->resolveOrCreateSetup();
        $config = config("agency-onboarding-copy.$step");

        foreach (($config['savers'] ?? []) as $saver) {
            try {
                app($saver['controller'])->{$saver['method']}($request);
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
        $steps = AgencyOnboardingSetup::STEPS;
        $i = array_search($step, $steps, true);
        $isLast = $i === (count($steps) - 1);

        if ($isLast) {
            // Last step's Save routes straight to finish.
            return $this->finish();
        }

        if ($skipped && $step !== end($steps)) {
            // Move the resume pointer forward past a skipped step without
            // marking it complete.
            $setup->current_step = max($setup->current_step, $i + 2);
            $setup->save();
        }

        $next = $steps[$i + 1];
        $redirect = redirect()->route('corex.agency-setup.step', ['step' => $next]);
        return $flash ? $redirect->with('success', $flash) : $redirect;
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
        $steps = AgencyOnboardingSetup::STEPS;
        return [
            'current' => (int) (array_search($step, $steps, true) + 1),
            'total'   => count($steps),
            'percent' => $setup->progressPercent(),
        ];
    }

    private function nav(string $step): array
    {
        $steps = AgencyOnboardingSetup::STEPS;
        $i = array_search($step, $steps, true);
        return [
            'prev'   => $i > 0 ? $steps[$i - 1] : null,
            'next'   => $i < count($steps) - 1 ? $steps[$i + 1] : null,
            'isLast' => $i === count($steps) - 1,
            'index'  => $i,
        ];
    }
}
