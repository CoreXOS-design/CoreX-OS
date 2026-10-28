<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Release;

use App\Events\Docuperfect\TemplateReleased;
use App\Events\Docuperfect\TemplateReleaseApproved;
use App\Events\Docuperfect\TemplateReleaseDeclined;
use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Models\Docuperfect\TemplateReleaseEvent;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use App\Services\Docuperfect\TemplateAgencyCopier;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/esign-template-transfer.md §13 — the path of a finished document: verify (owner) -> release to an agency (owner, lands NOT live)
 * -> approve / decline (the agency's authorisers) -> live; withdraw (owner, before a decision). Every step writes the release log.
 *
 * A release is the existing package pipeline (TemplateAgencyCopier: export -> validate -> import, a NEW agency-owned template, never an
 * overwrite) plus the release record and the "not live" mark, in ONE transaction: a failure leaves no template, no row, no file.
 * Documents already sent are never touched (spec §5): approval of a newer version archives the older live copy but every sent document
 * keeps its own frozen content.
 */
final class TemplateReleaseService
{
    public const MIN_REASON = 3;
    public const MAX_REASON = 1000;

    public function __construct(
        private readonly TemplateAgencyCopier $copier,
        private readonly TemplateReadinessReport $readiness,
        private readonly LeaseAgreementTemplateGuard $leaseGuard,
    ) {
    }

    // ───────────────────────────── verify (owner) ─────────────────────────────

    /** Mark a workshop template as verified: allowed only with no failing readiness check and the owner's "I have looked" tick. */
    public function verify(Template $template, User $actor, bool $iHaveLooked): Template
    {
        $this->assertOwner($actor);
        if (! $iHaveLooked) {
            throw new TemplateReleaseException('Tick "I have looked at the demo view and the document" to verify it.');
        }
        if ($template->archived_at !== null || $template->deleted_at !== null) {
            throw new TemplateReleaseException('An archived template cannot be verified. Restore it first.');
        }
        if ($template->isHeldBack()) {
            throw new TemplateReleaseException('A released copy waiting for approval cannot be verified.');
        }
        $result = $this->readiness->forTemplate($template);
        if ($result['verdict'] === TemplateReadinessReport::VERDICT_NOT_READY) {
            $fails = array_map(fn ($c) => $c['id'] . ' ' . $c['title'], array_values(array_filter($result['checks'], fn ($c) => $c['status'] === TemplateReadinessReport::FAIL)));
            throw new TemplateReleaseException('This template has things that must be fixed before it can be verified.', 422, $fails);
        }

        DB::transaction(function () use ($template, $actor, $result) {
            $sha = $template->contentSha();
            DB::table('docuperfect_templates')->where('id', $template->id)->update([
                'workshop_verified_at' => now(), 'workshop_verified_by' => $actor->id, 'workshop_verified_sha' => $sha,
            ]);
            $this->log('verified', null, $template, $actor, null, ['verdict' => $result['verdict'], 'sha' => $sha]);
        });

        return $template->fresh();
    }

    // ───────────────────────────── release (owner) ─────────────────────────────

    public function release(Template $source, Agency $target, User $actor): TemplateRelease
    {
        $this->assertOwner($actor);
        $source = $source->fresh() ?? $source;

        if ($source->archived_at !== null || $source->deleted_at !== null) {
            throw new TemplateReleaseException('An archived template cannot be released. Restore it first.');
        }
        if ($source->isHeldBack()) {
            throw new TemplateReleaseException('A released copy waiting for approval cannot be released again.');
        }
        if ($source->agency_id !== null && (int) $source->agency_id === (int) $target->id) {
            throw new TemplateReleaseException('Choose the client agency to release to — this template already belongs to ' . $target->name . '.');
        }
        if ($source->workshop_verified_at === null) {
            throw new TemplateReleaseException('Verify the template first, then release it.');
        }
        if (! $source->isVerifiedAndCurrent()) {
            throw new TemplateReleaseException('The template was changed after it was verified. Check it again and verify it again before releasing.');
        }
        $waiting = TemplateRelease::withoutGlobalScopes()->where('source_template_id', $source->id)->where('agency_id', $target->id)
            ->where('state', TemplateRelease::STATE_AWAITING)->exists();
        if ($waiting) {
            throw new TemplateReleaseException('This document is already waiting for approval at ' . $target->name . '.');
        }
        $result = $this->readiness->forTemplate($source);
        if ($result['verdict'] === TemplateReadinessReport::VERDICT_NOT_READY) {
            throw new TemplateReleaseException('This template has things that must be fixed before it can be released.');
        }

        try {
            $release = DB::transaction(function () use ($source, $target, $actor, $result) {
                // Version: soft-deleted rows count too (create -> soft-delete -> recreate must not collide with the unique key).
                $last = TemplateRelease::withoutGlobalScopes()->withTrashed()->where('source_template_id', $source->id)->where('agency_id', $target->id)
                    ->lockForUpdate()->orderByDesc('version')->first();
                $version = $last ? $last->version + 1 : 1;
                $previous = TemplateRelease::withoutGlobalScopes()->where('source_template_id', $source->id)->where('agency_id', $target->id)
                    ->whereIn('state', [TemplateRelease::STATE_LIVE])->orderByDesc('version')->first();

                $copied = $this->copier->copy($source, $target, $actor);
                /** @var Template $copy */
                $copy = $copied['template'];
                DB::table('docuperfect_templates')->where('id', $copy->id)->update(['release_status' => Template::RELEASE_AWAITING]);

                $release = TemplateRelease::withoutGlobalScopes()->create([
                    'source_template_id' => $source->id,
                    'template_id' => $copy->id,
                    'agency_id' => $target->id,
                    'version' => $version,
                    'state' => TemplateRelease::STATE_AWAITING,
                    'readiness_verdict' => $result['verdict'],
                    'readiness_snapshot' => ['verdict' => $result['verdict'], 'counts' => $result['counts'], 'checks' => $result['checks']],
                    'source_content_sha' => $source->contentSha(),
                    'lease_link' => $this->captureLeaseLink($source),
                    'released_by' => $actor->id,
                    'released_at' => now(),
                    'previous_release_id' => $previous?->id,
                ]);
                $this->log('released', $release, $copy, $actor, null, ['version' => $version, 'source_template_id' => $source->id, 'renamed' => (bool) ($copied['renamed'] ?? false)]);

                return $release;
            });
        } catch (TemplateTransferException $e) {
            throw new TemplateReleaseException(trim($e->getMessage() . ' ' . implode(' ', $e->details)), 422, $e->details);
        }

        $this->afterCommit(fn () => event(new TemplateReleased((int) $release->id, (int) $release->template_id, (int) $release->agency_id, (int) $actor->id)));

        return $release->fresh();
    }

    // ───────────────────────────── approve / decline (the agency) ─────────────────────────────

    /** @return array{release: TemplateRelease, lease_message: ?string} */
    public function approve(TemplateRelease $release, User $user, bool $iHaveLooked, ?string $note = null): array
    {
        $this->assertAuthoriser($user, (int) $release->agency_id);

        // Idempotent: a second click (or a double submit) finds it already live and changes nothing.
        $fresh = TemplateRelease::withoutGlobalScopes()->find($release->id);
        if ($fresh && $fresh->state === TemplateRelease::STATE_LIVE) {
            return ['release' => $fresh, 'lease_message' => null];
        }
        if (! $fresh || $fresh->state !== TemplateRelease::STATE_AWAITING) {
            throw new TemplateReleaseException('This document is no longer waiting for approval.');
        }
        if (! $iHaveLooked) {
            throw new TemplateReleaseException('Tick "I have looked at the document and the demo" to approve it.');
        }
        $copy = Template::withoutGlobalScopes()->find($fresh->template_id);
        if (! $copy || $copy->archived_at !== null) {
            throw new TemplateReleaseException('This document is no longer available. Ask for it to be released again.');
        }
        $result = $this->readiness->forTemplate($copy);
        if ($result['verdict'] === TemplateReadinessReport::VERDICT_NOT_READY) {
            throw new TemplateReleaseException('This document has a problem that was found just now and cannot be approved. Ask for it to be released again.');
        }

        $message = null;
        $release = DB::transaction(function () use ($fresh, $copy, $user, $note, &$message) {
            $locked = TemplateRelease::withoutGlobalScopes()->lockForUpdate()->find($fresh->id);
            if ($locked->state !== TemplateRelease::STATE_AWAITING) {
                return $locked;   // lost a race to a double submit: nothing more to do
            }
            $previous = $locked->previous_release_id ? TemplateRelease::withoutGlobalScopes()->find($locked->previous_release_id) : null;

            DB::table('docuperfect_templates')->where('id', $copy->id)->update(['release_status' => Template::RELEASE_LIVE]);
            $copy = $copy->fresh();   // now live: the lease guard below must see it that way
            $locked->forceFill([
                'state' => TemplateRelease::STATE_LIVE, 'decided_by' => $user->id, 'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, self::MAX_REASON) : null,
            ])->save();
            $this->log('approved', $locked, $copy, $user, $locked->decision_note, ['version' => $locked->version]);

            // The older live version of the same document is replaced: hidden from new documents (archived), never edited; sent documents keep their own copy.
            $older = TemplateRelease::withoutGlobalScopes()->where('source_template_id', $locked->source_template_id)->where('agency_id', $locked->agency_id)
                ->where('state', TemplateRelease::STATE_LIVE)->where('id', '!=', $locked->id)->get();
            foreach ($older as $old) {
                $old->forceFill(['state' => TemplateRelease::STATE_SUPERSEDED])->save();
                DB::table('docuperfect_templates')->where('id', $old->template_id)->update(['release_status' => Template::RELEASE_SUPERSEDED, 'archived_at' => now()]);
                $this->log('superseded', $old, null, $user, null, ['replaced_by_release_id' => $locked->id, 'template_id' => $old->template_id]);
            }

            $message = $this->applyLeaseLink($locked, $copy, $previous, $user);

            return $locked;
        });

        $this->afterCommit(fn () => event(new TemplateReleaseApproved((int) $release->id, (int) $release->template_id, (int) $release->agency_id, (int) $user->id)));

        return ['release' => $release->fresh(), 'lease_message' => $message];
    }

    public function decline(TemplateRelease $release, User $user, string $reason): TemplateRelease
    {
        $this->assertAuthoriser($user, (int) $release->agency_id);
        $reason = trim($reason);
        $len = mb_strlen($reason);
        if ($len < self::MIN_REASON) {
            throw new TemplateReleaseException('Say why you are declining it (at least ' . self::MIN_REASON . ' characters) so the person who prepared it can fix it.');
        }
        if ($len > self::MAX_REASON) {
            throw new TemplateReleaseException('Please keep the reason under ' . self::MAX_REASON . ' characters.');
        }

        $release = DB::transaction(function () use ($release, $user, $reason) {
            $locked = TemplateRelease::withoutGlobalScopes()->lockForUpdate()->find($release->id);
            if (! $locked || $locked->state !== TemplateRelease::STATE_AWAITING) {
                throw new TemplateReleaseException('This document is no longer waiting for approval.');
            }
            DB::table('docuperfect_templates')->where('id', $locked->template_id)->update(['release_status' => Template::RELEASE_DECLINED]);
            $locked->forceFill(['state' => TemplateRelease::STATE_DECLINED, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason])->save();
            $this->log('declined', $locked, null, $user, $reason, ['version' => $locked->version, 'template_id' => $locked->template_id]);

            return $locked;
        });

        $this->afterCommit(fn () => event(new TemplateReleaseDeclined((int) $release->id, (int) $release->template_id, (int) $release->agency_id, (int) $user->id)));

        return $release->fresh();
    }

    // ───────────────────────────── withdraw (owner) ─────────────────────────────

    /** The archive path of a release: before any decision the owner can take it back. The copy is archived (and stays hidden); "Release again" makes the next version. */
    public function withdraw(TemplateRelease $release, User $actor, ?string $note = null): TemplateRelease
    {
        $this->assertOwner($actor);

        return DB::transaction(function () use ($release, $actor, $note) {
            $locked = TemplateRelease::withoutGlobalScopes()->lockForUpdate()->find($release->id);
            if (! $locked || $locked->state !== TemplateRelease::STATE_AWAITING) {
                throw new TemplateReleaseException('Only a document that is still waiting for approval can be withdrawn.');
            }
            DB::table('docuperfect_templates')->where('id', $locked->template_id)->update(['archived_at' => now()]);
            $locked->forceFill(['state' => TemplateRelease::STATE_WITHDRAWN, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, self::MAX_REASON) : null])->save();
            $this->log('withdrawn', $locked, null, $actor, $locked->decision_note, ['template_id' => $locked->template_id]);

            return $locked->fresh();
        });
    }

    // ───────────────────────────── lease link ─────────────────────────────

    /** The source template's active lease-agreement link, as plain data (kept on the release; applied only at approval). */
    private function captureLeaseLink(Template $source): ?array
    {
        $row = RentalLeaseTemplate::withoutGlobalScopes()->where('docuperfect_template_id', $source->id)->where('is_active', true)->orderByDesc('id')->first();
        if (! $row) {
            return null;
        }

        return ['category' => (string) $row->category, 'name' => (string) $row->name, 'is_default' => (bool) $row->is_default, 'field_map' => is_array($row->field_map) ? $row->field_map : []];
    }

    /** Applied at approval (spec §13.8). Returns a plain sentence for the approval screen, or null when the release carries no lease link. */
    private function applyLeaseLink(TemplateRelease $release, Template $copy, ?TemplateRelease $previous, User $user): ?string
    {
        $link = $release->lease_link;
        if (! is_array($link) || empty($link['category'])) {
            return null;
        }
        $agencyId = (int) $release->agency_id;
        $map = is_array($link['field_map'] ?? null) ? $link['field_map'] : [];
        $existing = RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $agencyId)->where('category', $link['category'])->where('is_active', true)->get();

        $repoint = $previous ? $existing->firstWhere('docuperfect_template_id', $previous->template_id) : null;
        if (! $repoint && $existing->isNotEmpty()) {
            return 'Your agency already uses another lease agreement. Link this one in Settings → Rental lease agreements if you want to use it.';
        }

        $problems = $this->leaseGuard->problemsFor($copy, $agencyId, $map);
        if ($problems !== []) {
            return 'The document is live, but it could not be linked as your lease agreement: ' . implode(' ', $problems);
        }

        if ($repoint) {
            $repoint->forceFill(['docuperfect_template_id' => $copy->id, 'field_map' => $map ?: $repoint->field_map])->save();
            $row = $repoint;
            $what = 'moved to the new version';
        } else {
            $row = RentalLeaseTemplate::withoutGlobalScopes()->create([
                'agency_id' => $agencyId, 'name' => (string) ($link['name'] ?? $copy->name), 'docuperfect_template_id' => $copy->id,
                'category' => (string) $link['category'], 'is_active' => true, 'field_map' => $map, 'is_default' => (bool) ($link['is_default'] ?? true),
            ]);
            $what = 'set as your lease agreement';
        }
        try {
            $this->leaseGuard->recordCheck($row, $agencyId);
        } catch (\Throwable) {
            // the check result is informational; the link itself is already saved
        }
        $this->log('lease_linked', $release, $copy, $user, null, ['rental_lease_template_id' => $row->id, 'category' => $link['category']]);

        return 'Your lease agreement was ' . $what . '.';
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function assertOwner(User $actor): void
    {
        if (! $actor->isOwnerRole() || ! $actor->hasPermission('templates.release')) {
            throw new TemplateReleaseException('Only a CoreX administrator can verify and release documents.', 403);
        }
    }

    private function assertAuthoriser(User $user, int $agencyId): void
    {
        if (! AgencyDocumentAuthorisers::canAuthorise($user, $agencyId)) {
            throw new TemplateReleaseException('You are not one of the people your agency has chosen to authorise documents.', 403);
        }
    }

    private function log(string $event, ?TemplateRelease $release, ?Template $template, ?User $actor, ?string $note, array $detail = []): void
    {
        TemplateReleaseEvent::create([
            'release_id' => $release?->id,
            'template_id' => $template?->id ?? ($detail['template_id'] ?? $release?->template_id),
            'agency_id' => $release?->agency_id ?? $template?->agency_id,
            'event' => $event,
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'note' => $note,
            'detail' => $detail,
        ]);
    }

    /** Domain events fire after the transaction that made the change has finished (the importer does the same). */
    private function afterCommit(callable $fn): void
    {
        $fn();
    }
}
