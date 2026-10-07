<?php

namespace App\Services\Rentals;

use App\Exceptions\Rentals\LeaseAgreementConfirmationRefused;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/leases.md §15.9 (Build L3c) — the ONE place a value printed in the lease agreement is allowed to reach
 * the lease record: the agent's explicit "Confirm lease details".
 *
 * The four guarantees (§15.9): the lease changes only here; every changed value was visible beside its old one on the
 * confirm screen before the button; every changed value is logged old → new with the agent's name; a difference that
 * cannot be accepted (a different person) blocks instead of being absorbed. The document itself is never changed.
 *
 * One transaction. It re-reads the agreement and refuses unless the printed values are exactly the ones the agent was
 * shown (the fingerprint of §15.8.5) — if someone edited the document meanwhile, the screen reopens with what is
 * different now. Nothing is written on a refusal.
 */
class LeaseAgreementConfirmService
{
    public function __construct(private readonly LeaseAgreementCheck $check) {}

    /**
     * §15.9 "Permissions" — whoever holds the approval (the agent who sent the agreement) or a user with
     * `leases.create`, and in either case only inside their own agency and the lease scope they may see
     * (own / branch / agency). Used by the screen, the post, the API and the middleware alike, so one rule decides.
     */
    public function mayConfirm(User $user, Lease $lease): bool
    {
        if (! $user->isOwnerRole() && (int) $lease->agency_id !== (int) ($user->effectiveAgencyId() ?? 0)) {
            return false;
        }

        $sentBy = $lease->signature_template_id
            ? SignatureTemplate::withoutGlobalScopes()->whereKey($lease->signature_template_id)->value('created_by')
            : null;
        if ($sentBy && (int) $sentBy === (int) $user->id) {
            return true;
        }

        return $user->hasPermission('leases.create')
            && Lease::query()->visibleTo($user)->whereKey($lease->id)->exists();
    }

    /**
     * @param  array<string,mixed>  $entered  key => what the agent typed for a value the agreement does not show clearly
     * @return array{changed: array<int,array<string,mixed>>, entered: array<int,array<string,mixed>>, fingerprint: string}
     *
     * @throws LeaseAgreementConfirmationRefused
     */
    public function confirm(Lease $lease, User $actor, string $fingerprint, array $entered = []): array
    {
        $typed = [];
        foreach ($entered as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $typed[(string) $key] = trim((string) $value);
            }
        }

        return DB::transaction(function () use ($lease, $actor, $fingerprint, $typed) {
            /** @var Lease|null $locked */
            $locked = Lease::withoutGlobalScopes()->whereKey($lease->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new LeaseAgreementConfirmationRefused(LeaseAgreementConfirmationRefused::NOT_APPLICABLE, 'That lease is no longer available.');
            }

            $verdict = $this->check->verdict($locked, $typed);
            if (! $verdict['applicable']) {
                throw new LeaseAgreementConfirmationRefused(LeaseAgreementConfirmationRefused::NOT_APPLICABLE, 'This lease has no agreement to check its details against.');
            }
            if (! hash_equals((string) $verdict['fingerprint'], $fingerprint)) {
                throw new LeaseAgreementConfirmationRefused(LeaseAgreementConfirmationRefused::STALE, 'The agreement was changed again while you were looking at it. Check the new differences below.');
            }
            if ($verdict['blocked']) {
                $names = collect($verdict['differences'])->where('state', LeaseAgreementCheck::STATE_BLOCKED)->pluck('label')->implode(', ');
                throw new LeaseAgreementConfirmationRefused(LeaseAgreementConfirmationRefused::BLOCKED, "The agreement names a different person ({$names}). A change of tenant is a new lease — correct the contact and re-check, or go back to the agreement.");
            }
            if ($verdict['cannot_verify'] !== []) {
                $labels = collect($verdict['cannot_verify'])->pluck('label')->implode(', ');
                throw new LeaseAgreementConfirmationRefused(LeaseAgreementConfirmationRefused::INCOMPLETE, "Type what the agreement says for: {$labels}.");
            }

            [$leaseUpdates, $termsUpdates, $extraUpdates, $changed, $enteredOnly] = $this->plan($verdict['rows']);
            $this->assertValid($locked, $leaseUpdates, $termsUpdates);

            if ($leaseUpdates !== []) {
                if (array_key_exists('end_date', $leaseUpdates)) {
                    $leaseUpdates['is_month_to_month'] = false; // the agreement prints an end date
                }
                $locked->forceFill($leaseUpdates);
            }
            $locked->forceFill([
                'agreement_confirmed_fingerprint' => $fingerprint,
                'agreement_confirmed_at' => now(),
                'agreement_confirmed_by_user_id' => $actor->id,
            ])->save();

            if ($termsUpdates !== [] || $extraUpdates !== []) {
                $terms = LeaseAgreementTerms::forLease($locked);
                foreach ($termsUpdates as $column => $value) {
                    $terms->{$column} = $value;
                }
                if ($extraUpdates !== []) {
                    $terms->extra = array_merge((array) ($terms->extra ?? []), $extraUpdates);
                }
                $terms->source = LeaseAgreementTerms::SOURCE_CONFIRMED;
                $terms->save();
            }

            foreach ($changed as $change) {
                $this->event($locked, $actor, $change, $fingerprint, $verdict['document_id']);
            }
            foreach ($enteredOnly as $change) {
                $this->event($locked, $actor, $change, $fingerprint, $verdict['document_id']);
            }

            return ['changed' => $changed, 'entered' => $enteredOnly, 'fingerprint' => $fingerprint];
        });
    }

    /**
     * What each row asks for. A value the agent TYPED that already agrees with the lease changes nothing but is still
     * logged ("entered by <agent> while confirming") — it was never read from the document.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,mixed>, 3: array<int,array<string,mixed>>, 4: array<int,array<string,mixed>>}
     */
    private function plan(array $rows): array
    {
        $lease = $terms = $extra = $changed = $enteredOnly = [];

        foreach ($rows as $row) {
            $differs = $row['state'] === LeaseAgreementCheck::STATE_DIFFERS && $row['acceptable'] && $row['target'] !== null;

            if ($differs) {
                $value = $row['agreement_value'];
                match ($row['target']['type']) {
                    'lease' => $lease[$row['target']['column']] = $value,
                    'terms' => $terms[$row['target']['column']] = $value,
                    default => $extra[$row['key']] = $value,
                };
                $changed[] = $row;
            } elseif (! empty($row['entered']) && $row['state'] === LeaseAgreementCheck::STATE_AGREE) {
                $enteredOnly[] = $row;
            }
        }

        return [$lease, $terms, $extra, $changed, $enteredOnly];
    }

    /**
     * An accepted set of values must still make a valid lease (BUILD_STANDARD §4): an end date after the start,
     * rent and deposit not negative, the earliest-notice date not before the start.
     *
     * @param  array<string,mixed>  $leaseUpdates
     * @param  array<string,mixed>  $termsUpdates
     */
    private function assertValid(Lease $lease, array $leaseUpdates, array $termsUpdates): void
    {
        $start = $leaseUpdates['start_date'] ?? $lease->start_date?->toDateString();
        $end = array_key_exists('end_date', $leaseUpdates) ? $leaseUpdates['end_date'] : ($lease->is_month_to_month ? null : $lease->end_date?->toDateString());

        if ($start && $end && $end <= $start) {
            throw new LeaseAgreementConfirmationRefused(LeaseAgreementConfirmationRefused::INVALID, 'The dates in the agreement do not make a valid lease: the end date must be after the start date. Correct the agreement first.');
        }
        foreach (['rental_amount' => 'rent', 'deposit_amount' => 'deposit'] as $column => $what) {
            if (isset($leaseUpdates[$column]) && (float) $leaseUpdates[$column] < 0) {
                throw new LeaseAgreementConfirmationRefused(LeaseAgreementConfirmationRefused::INVALID, "The {$what} in the agreement is negative.");
            }
        }
        if ($start && isset($termsUpdates['earliest_termination_date']) && $termsUpdates['earliest_termination_date'] < $start) {
            throw new LeaseAgreementConfirmationRefused(LeaseAgreementConfirmationRefused::INVALID, 'The earliest date notice may expire in the agreement is before the lease starts.');
        }
    }

    /** @param  array<string,mixed>  $row */
    private function event(Lease $lease, User $actor, array $row, string $fingerprint, ?int $documentId): void
    {
        $label = (string) $row['label'];
        $old = $row['lease'] ?? null;
        $new = $row['agreement'] ?? null;
        $typed = ! empty($row['entered']);

        $description = $row['state'] === LeaseAgreementCheck::STATE_DIFFERS
            ? "{$label} changed to match the agreement: " . ($old ?? 'not on record') . ' → ' . ($new ?? '—')
                . " (confirmed by {$actor->name}" . ($typed ? ', typed from the document' : '') . ')'
            : "{$label} confirmed as {$new} — entered by {$actor->name} while confirming";

        LeaseEvent::create([
            'lease_id' => $lease->id,
            'event_type' => LeaseEvent::TYPE_AGREEMENT_DIFFERENCES_CONFIRMED,
            'description' => mb_substr($description, 0, 500),
            'actor_user_id' => $actor->id,
            'metadata' => [
                'key' => $row['key'], 'label' => $label, 'old' => $old, 'new' => $new,
                'entered_by_agent' => $typed, 'fingerprint' => $fingerprint, 'document_id' => $documentId,
            ],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
