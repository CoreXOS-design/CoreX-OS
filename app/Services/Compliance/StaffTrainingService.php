<?php

namespace App\Services\Compliance;

use App\Models\Compliance\ComplianceDocumentAudit;
use App\Models\Compliance\PolicyAcknowledgement;
use App\Models\Compliance\PolicyVersion;
use App\Models\Compliance\RmcpAcknowledgement;
use App\Models\Compliance\RmcpVersion;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Build staff training from this version" - one service for an RMCP version AND a policy version
 * (compliance.md). It generalises AT-506's RmcpReSignService.
 *
 * What a document's staff training IS: the My Portal > Training item. That item is not a row of its own - it is
 * read from the document's ACTIVE version (User::rmcpAcknowledgementStatus / outstandingPolicyAcknowledgements), so every
 * active staff member who has not signed the active version already has it. Sending the training therefore does three
 * things, and nothing else:
 *   1. supersedes the acknowledgements staff signed on EARLIER versions of this same document (kept, never deleted - they
 *      are the signed record of what each person agreed to);
 *   2. tells each active staff member, in-app, to read it section by section and sign at the end;
 *   3. records when it was sent and to how many people, so the dashboards can print "Last sent ... - N of M signed".
 *
 * Callers: the "Build staff training from this version" button (mode MODE_BUTTON - asks only the people who have not
 * signed this version yet) and version approval (mode MODE_APPROVAL - asks everyone, exactly as RMCP approval always has).
 * A failure in here never undoes an approval; every step logs and carries on.
 */
class StaffTrainingService
{
    public const MODE_BUTTON   = 'button';
    public const MODE_APPROVAL = 'approval';

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public static function kindOf(Model $version): string
    {
        return $version instanceof PolicyVersion
            ? ComplianceDocumentAudit::KIND_POLICY
            : ComplianceDocumentAudit::KIND_RMCP;
    }

    /** The people who are expected to sign: the agency's active users (the same set both dashboards list). */
    public function audience(Model $version): Collection
    {
        return User::withoutGlobalScopes()
            ->where('agency_id', $version->agency_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get();
    }

    /** @return array<int,int> user ids holding a valid, completed acknowledgement of THIS version */
    public function signedUserIds(Model $version): array
    {
        $query = $version instanceof PolicyVersion
            ? DB::table('policy_acknowledgements')->where('policy_version_id', $version->id)
            : DB::table('rmcp_acknowledgements')->where('rmcp_version_id', $version->id);

        return $query->where('agency_id', $version->agency_id)
            ->where('status', 'completed')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->whereNull('deleted_at')
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array{total:int, signed:int, asked:int} what the confirm dialog and the dashboards quote */
    public function counts(Model $version): array
    {
        $staff  = $this->audience($version);
        $signed = array_intersect($staff->pluck('id')->map(fn ($id) => (int) $id)->all(), $this->signedUserIds($version));

        return [
            'total'  => $staff->count(),
            'signed' => count($signed),
            'asked'  => $staff->count() - count($signed),
        ];
    }

    /**
     * @return array{superseded:int, notified:int, asked:int, total:int}
     */
    public function send(Model $version, ?User $by = null, string $mode = self::MODE_BUTTON): array
    {
        $kind       = self::kindOf($version);
        $superseded = $this->supersedeEarlier($version);
        $staff      = $this->audience($version);
        $signedIds  = $mode === self::MODE_BUTTON ? $this->signedUserIds($version) : [];
        $asked      = $staff->reject(fn (User $u) => in_array((int) $u->id, $signedIds, true))->values();
        $notified   = 0;

        foreach ($asked as $user) {
            try {
                $sent = $this->dispatcher->fire(
                    $user,
                    $kind === ComplianceDocumentAudit::KIND_POLICY ? 'policy.version_approved' : 'rmcp.version_approved',
                    $version,
                    $this->notificationArgs($version, $kind, $mode)
                );
                if ($sent) {
                    $notified++;
                }
            } catch (\Throwable $e) {
                Log::warning('Staff training: notification failed for one staff member', [
                    'kind' => $kind, 'version_id' => $version->id, 'user_id' => $user->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $version->forceFill([
                'staff_training_sent_at'    => now(),
                'staff_training_sent_by'    => $by?->id,
                'staff_training_sent_count' => $asked->count(),
            ])->save();
            ComplianceDocumentAudit::record($kind, (int) $version->agency_id, (int) $version->id, 'training_sent', $by, [
                'mode' => $mode, 'asked' => $asked->count(), 'notified' => $notified, 'superseded' => $superseded, 'staff' => $staff->count(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Staff training: could not record the send', ['kind' => $kind, 'version_id' => $version->id, 'error' => $e->getMessage()]);
        }

        Log::info('Staff training sent', [
            'kind' => $kind, 'version_id' => $version->id, 'agency_id' => $version->agency_id, 'mode' => $mode,
            'superseded' => $superseded, 'asked' => $asked->count(), 'notified' => $notified, 'by' => $by?->id,
        ]);

        return ['superseded' => $superseded, 'notified' => $notified, 'asked' => $asked->count(), 'total' => $staff->count()];
    }

    /** Acknowledgements staff signed on earlier versions of this same document stop counting (kept for the record). */
    private function supersedeEarlier(Model $version): int
    {
        try {
            if ($version instanceof PolicyVersion) {
                return DB::table('policy_acknowledgements')
                    ->where('agency_id', $version->agency_id)
                    ->where('policy_id', $version->policy_id)
                    ->where('policy_version_id', '<>', $version->id)
                    ->whereIn('status', [PolicyAcknowledgement::STATUS_IN_PROGRESS, PolicyAcknowledgement::STATUS_COMPLETED])
                    ->whereNull('deleted_at')
                    ->update(['status' => PolicyAcknowledgement::STATUS_SUPERSEDED, 'updated_at' => now()]);
            }

            return DB::table('rmcp_acknowledgements')
                ->where('agency_id', $version->agency_id)
                ->where('rmcp_version_id', '<>', $version->id)
                ->whereIn('status', [RmcpAcknowledgement::STATUS_IN_PROGRESS, RmcpAcknowledgement::STATUS_COMPLETED])
                ->whereNull('deleted_at')
                ->update(['status' => RmcpAcknowledgement::STATUS_SUPERSEDED, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Staff training: could not mark earlier acknowledgements superseded', [
                'version_id' => $version->id, 'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function notificationArgs(Model $version, string $kind, string $mode): array
    {
        // Approval: one notification per person per version - the approval moment is a stable fact. Button: the person
        // pressing it means "ask again now", so the moment is now (the gateway would otherwise drop a repeat).
        $moment = $mode === self::MODE_APPROVAL ? ($version->approved_at ?? now()) : now();
        // Each deliberate press is its own occurrence (AT-492 dedupe_key), or the gateway's cooldown would swallow it as a repeat.
        $extra = $mode === self::MODE_APPROVAL ? [] : ['dedupe_key' => 'training-send-' . now()->format('YmdHis') . '-' . \Illuminate\Support\Str::random(5)];

        if ($kind === ComplianceDocumentAudit::KIND_POLICY) {
            $name = $version->policy?->name ?: $version->title;

            return [
                'title'            => 'Policy to read and sign: ' . $name,
                'body'             => $name . ' (version ' . $version->version_number . ') is ready. Please read it section by section and sign at the end - My Portal, Training.',
                'subject_label'    => $name . ' v' . $version->version_number,
                'action_url'       => '/corex/my-portal#training',
                'severity'         => 'warning',
                'threshold_hit_at' => $moment,
            ] + $extra;
        }

        return [
            'title'            => 'New RMCP version — please acknowledge',
            'body'             => 'Version ' . $version->version_number . ' of your agency\'s Risk Management and Compliance Programme is now in force. Please read it and sign the acknowledgement.',
            'subject_label'    => 'RMCP v' . $version->version_number,
            'action_url'       => '/corex/my-portal#training',
            'severity'         => 'warning',
            'threshold_hit_at' => $moment,
        ] + $extra;
    }
}
