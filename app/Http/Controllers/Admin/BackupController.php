<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupPasswordReveal;
use App\Models\PerformanceSetting;
use App\Services\Backup\BackupStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AT-163. System Developer → Backups. Read-only status/health/history dashboard
 * over the off-box restic backup, plus an audited, permission-gated reveal of
 * the repo encryption password and a configurable stale-alarm threshold.
 *
 * Access is permission-gated at the route (permission:view_backups); the reveal
 * is a SEPARATE, principal-only permission (reveal_backup_password). Defensive
 * hasPermission checks below are belt-and-suspenders with the route middleware.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupStatusService $backups)
    {
    }

    public function index()
    {
        abort_unless(Auth::user()?->hasPermission('view_backups'), 403);

        return view('admin.backups.index', [
            'status'       => $this->backups->status(),
            'snapshots'    => $this->backups->snapshots(),
            'runs'         => $this->backups->runs(30),
            'threshold'    => $this->backups->staleThresholdHours(),
            'canReveal'    => (bool) Auth::user()?->hasPermission('reveal_backup_password'),
            'recentReveals'=> BackupPasswordReveal::with('revealedBy')
                                ->orderByDesc('revealed_at')->limit(10)->get(),
        ]);
    }

    /**
     * Audited reveal of the backup encryption password. Every reveal — even by an
     * owner — writes an immutable audit row BEFORE the secret is read, then shows
     * it once via a session flash (disappears on the next request).
     */
    public function reveal(Request $request)
    {
        abort_unless($request->user()?->hasPermission('reveal_backup_password'), 403);

        // Audit FIRST — a reveal is recorded whether or not the read then succeeds.
        BackupPasswordReveal::create([
            'revealed_by'           => $request->user()->id,
            'revealed_by_agency_id' => $request->user()->effectiveAgencyId(),
            'revealed_at'           => now(),
            'ip_address'            => $request->ip(),
            'user_agent'            => substr((string) $request->userAgent(), 0, 512),
        ]);

        $password = $this->backups->revealPassword();

        if ($password === null) {
            return back()->with('backup_reveal_error',
                'Could not read the backup password (the reveal helper is unavailable). The reveal was still recorded in the audit log.');
        }

        return back()->with('revealed_backup_password', $password);
    }

    /** Configure the stale-alarm threshold (global setting). */
    public function updateThreshold(Request $request)
    {
        abort_unless($request->user()?->hasPermission('view_backups'), 403);

        $data = $request->validate([
            'stale_alarm_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        PerformanceSetting::updateOrCreate(
            ['key' => 'backup_stale_alarm_hours'],
            ['value' => (string) $data['stale_alarm_hours']],
        );

        return back()->with('backup_threshold_saved',
            "Stale-alarm threshold set to {$data['stale_alarm_hours']} hours.");
    }
}
