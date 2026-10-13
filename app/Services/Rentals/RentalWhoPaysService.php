<?php

namespace App\Services\Rentals;

use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalFaultTypeCause;
use App\Models\RentalPayerRuling;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.13 - "Who pays": a LOGGED ruling. ONE place that records what was found and the cause (from the
 * contractor link, the crew link/page or the agent), suggests a default payer from the fault type's list, writes the agent's ruling (append-only,
 * a newer one supersedes) and tells the owner and the tenant. It raises NO charge and writes no invoice or ledger line - and it never touches the
 * work order's own `paid_by` (who settles the contractor's bill).
 */
class RentalWhoPaysService
{
    public const MIN_TEXT = 3;

    // ───────────────────────── reading ─────────────────────────

    public function faultFor(RentalWorkOrder $wo): ?RentalFaultReport
    {
        return $wo->reported_fault_report_id
            ? RentalFaultReport::withoutGlobalScopes()->where('agency_id', $wo->agency_id)->find($wo->reported_fault_report_id)
            : null;
    }

    /** Only a job that came from a fault carries the report-back fields and the "Who pays" block. */
    public function isFaultJob(RentalWorkOrder $wo): bool
    {
        return $this->faultFor($wo) !== null;
    }

    /** The active rows the contractor/agent may pick as the cause: the fault type's causes first, then its self-checks. @return Collection<int,RentalFaultTypeCause> */
    public function causeOptions(RentalWorkOrder $wo): Collection
    {
        $fault = $this->faultFor($wo);
        if (! $fault || ! $fault->rental_fault_type_id) {
            return collect();
        }

        return RentalFaultTypeCause::withoutGlobalScopes()
            ->where('agency_id', $wo->agency_id)->where('rental_fault_type_id', $fault->rental_fault_type_id)
            ->where('is_active', true)->whereNull('deleted_at')
            ->orderByRaw("kind = 'cause' desc")->orderBy('sort_order')->orderBy('id')->get();
    }

    /** The tenant's ticks, each with the fault type's default for a self-check row of the same wording (or null). @return array<int,array{text:string,ticked:bool,default_payer:?string,default_label:?string}> */
    public function selfChecks(RentalFaultReport $fault): array
    {
        $rows = RentalFaultTypeCause::withoutGlobalScopes()
            ->where('agency_id', $fault->agency_id)->where('rental_fault_type_id', $fault->rental_fault_type_id)
            ->where('kind', RentalFaultTypeCause::KIND_SELF_CHECK)->where('is_active', true)->whereNull('deleted_at')->get()
            ->keyBy(fn ($r) => RentalFaultTypeCause::normalise($r->label));

        return array_map(function ($c) use ($rows) {
            $match = $rows->get(RentalFaultTypeCause::normalise((string) $c['text']));

            return [
                'text' => (string) $c['text'], 'ticked' => (bool) ($c['ticked'] ?? false),
                'default_payer' => $match?->default_payer,
                'default_label' => $match && $match->default_payer ? RentalPayerRuling::payerLabel($match->default_payer) : null,
            ];
        }, $fault->tenantSelfChecks());
    }

    /** The picked cause's default: ['payer','label','because'] - or null when nothing was picked / the row has no default. */
    public function suggestion(RentalWorkOrder $wo): ?array
    {
        if (! $wo->cause_id) {
            return null;
        }
        $row = RentalFaultTypeCause::withoutGlobalScopes()->withTrashed()->where('agency_id', $wo->agency_id)->find($wo->cause_id);
        if (! $row || ! $row->default_payer) {
            return null;
        }

        return ['payer' => $row->default_payer, 'label' => RentalPayerRuling::payerLabel($row->default_payer), 'because' => 'The default for "' . $row->label . '"'];
    }

    public function causeText(RentalWorkOrder $wo): string
    {
        return $this->joinCause($wo->cause_label, $wo->cause_note);
    }

    private function joinCause(?string $label, ?string $note): string
    {
        $label = trim((string) $label);
        $note = trim((string) $note);

        return $label !== '' && $note !== '' ? "{$label} - {$note}" : ($label !== '' ? $label : $note);
    }

    public function hasFindings(RentalWorkOrder $wo): bool
    {
        return trim((string) $wo->found_text) !== '' && $this->causeText($wo) !== '';
    }

    public function currentRuling(RentalWorkOrder $wo): ?RentalPayerRuling
    {
        return $wo->payerRulings()->whereNull('superseded_at')->get()->last();
    }

    /** Every ruling, newest first (the history). @return Collection<int,RentalPayerRuling> */
    public function history(RentalWorkOrder $wo): Collection
    {
        return $wo->payerRulings()->with('ruledBy')->reorder()->orderByDesc('id')->get();
    }

    public function rulingForFault(RentalFaultReport $fault): ?RentalPayerRuling
    {
        if (! $fault->rental_work_order_id) {
            return null;
        }
        $wo = RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $fault->agency_id)->find($fault->rental_work_order_id);

        return $wo ? $this->currentRuling($wo) : null;
    }

    // ───────────────────────── report-back: what was found + the cause ─────────────────────────

    /**
     * The ONE writer for "What was found" and "Cause", whoever reports it (contractor link, crew link/page, the agent). The cause is a row of the
     * fault type's list OR free text ("Something else"); free text is required when no row is picked. Returns true when anything changed.
     *
     * @throws \InvalidArgumentException in plain words
     */
    public function recordFindings(RentalWorkOrder $wo, ?string $found, ?int $causeId, ?string $causeNote, string $by, ?User $user = null): bool
    {
        $found = trim((string) $found);
        $causeNote = trim((string) $causeNote);
        if (mb_strlen($found) < self::MIN_TEXT) {
            throw new \InvalidArgumentException('Tell us what was found.');
        }
        if (mb_strlen($found) > 2000 || mb_strlen($causeNote) > 500) {
            throw new \InvalidArgumentException('That is too long - keep "what was found" under 2000 characters and the cause note under 500.');
        }
        $label = null;
        if ($causeId) {
            $row = $this->causeOptions($wo)->firstWhere('id', $causeId);
            if (! $row) {
                throw new \InvalidArgumentException('Pick a cause from the list, or choose "Something else" and describe it.');
            }
            $label = $row->label;
        } elseif (mb_strlen($causeNote) < self::MIN_TEXT) {
            throw new \InvalidArgumentException('Pick a cause, or choose "Something else" and describe it.');
        }

        $changed = trim((string) $wo->found_text) !== $found || (int) $wo->cause_id !== (int) $causeId || (string) $wo->cause_label !== (string) $label || trim((string) $wo->cause_note) !== $causeNote;
        if (! $changed) {
            return false;
        }

        $before = $this->hasFindings($wo) ? 'What was found: "' . $wo->found_text . '". Cause: ' . $this->causeText($wo) . '.' : 'nothing recorded';
        DB::transaction(function () use ($wo, $found, $causeId, $label, $causeNote, $by) {
            $wo->forceFill([
                'found_text' => $found, 'cause_id' => $causeId ?: null, 'cause_label' => $label, 'cause_note' => $causeNote !== '' ? $causeNote : null,
                'reported_back_at' => now(), 'reported_back_by' => mb_substr($by, 0, 191),
            ])->save();
        });
        $line = "{$by} recorded - What was found: \"{$found}\". Cause: " . $this->joinCause($label, $causeNote) . ". (Before: {$before})";
        $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'note', 'note' => $line, 'created_by_user_id' => $user?->id]);
        $this->faultFor($wo)?->recordHistory(RentalFaultReportUpdate::TYPE_FINDINGS_RECORDED, $user, $line);

        return true;
    }

    // ───────────────────────── the ruling ─────────────────────────

    /**
     * Record who pays. Needs What was found + a Cause on file and a reason. Append-only: the previous ruling is superseded, never edited. The owner and
     * the tenant are told once it is saved. Raises no charge.
     *
     * @throws \LogicException|\InvalidArgumentException in plain words
     */
    public function rule(RentalWorkOrder $wo, User $by, string $payer, ?int $tenantPercent, string $reason, ?UploadedFile $evidence = null): RentalPayerRuling
    {
        if (! $this->isFaultJob($wo)) {
            throw new \LogicException('Who pays is only recorded on a work order that came from a fault.');
        }
        if ($wo->status === RentalWorkOrder::STATUS_CANCELLED) {
            throw new \LogicException('This work order is cancelled.');
        }
        if (! $this->hasFindings($wo)) {
            throw new \LogicException('Record what was found and the cause first.');
        }
        if (! isset(RentalPayerRuling::PAYERS[$payer])) {
            throw new \InvalidArgumentException('Pick who pays.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_TEXT) {
            throw new \InvalidArgumentException('Give the reason for the ruling.');
        }
        $owner = $tenant = null;
        if ($payer === RentalPayerRuling::PAYER_SHARED) {
            if ($tenantPercent === null || $tenantPercent < 1 || $tenantPercent > 99) {
                throw new \InvalidArgumentException('For a shared cost, say what percentage the tenant pays (1 to 99).');
            }
            $tenant = $tenantPercent;
            $owner = 100 - $tenantPercent;
        }

        $path = $name = null;
        if ($evidence) {
            $name = mb_substr($evidence->getClientOriginalName(), 0, 191);
            $path = $evidence->storeAs('rental-payer-rulings/' . $wo->id, now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . ($evidence->getClientOriginalExtension() ?: 'dat'), 'local');
        }

        $suggest = $this->suggestion($wo);
        $ruling = DB::transaction(function () use ($wo, $by, $payer, $tenant, $owner, $reason, $path, $name, $suggest) {
            $previous = $this->currentRuling($wo);
            $row = RentalPayerRuling::create([
                'agency_id' => $wo->agency_id, 'rental_work_order_id' => $wo->id, 'rental_fault_report_id' => $wo->reported_fault_report_id,
                'payer' => $payer, 'tenant_percent' => $tenant, 'owner_percent' => $owner, 'reason' => $reason,
                'evidence_path' => $path, 'evidence_name' => $name,
                'found_text' => $wo->found_text, 'cause_text' => $this->causeText($wo), 'suggested_payer' => $suggest['payer'] ?? null,
                'ruled_by_user_id' => $by->id, 'ruled_at' => now(), 'created_at' => now(),
            ]);
            $previous?->forceFill(['superseded_at' => now(), 'superseded_by_id' => $row->id])->save();
            $wo->forceFill(['who_pays' => $payer, 'who_pays_ruling_id' => $row->id])->save();

            return $row;
        });

        $line = "{$by->name} ruled: who pays - " . $ruling->summary() . '. Reason: ' . $reason . ($ruling->evidence_name ? ' (evidence attached)' : '') . '. Nothing was charged.';
        $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'note', 'note' => $line, 'created_by_user_id' => $by->id]);
        $this->faultFor($wo)?->recordHistory(RentalFaultReportUpdate::TYPE_PAYER_RULED, $by, $line);

        $this->tellParties($wo, $ruling);

        return $ruling->fresh();
    }

    // ───────────────────────── telling the owner and the tenant ─────────────────────────

    /**
     * Johan, 14 Oct 2026: the TENANT hears about a ruling only when they have something to pay - "Tenant" (all of it) or "Shared" (their share only). For "Owner" and
     * "Insurance or body corporate" they are told nothing and their portal shows no who-pays line. The owner is always told.
     */
    public function tenantInvolved(?RentalPayerRuling $ruling): bool
    {
        return $ruling !== null && in_array($ruling->payer, [RentalPayerRuling::PAYER_TENANT, RentalPayerRuling::PAYER_SHARED], true);
    }

    public function tellParties(RentalWorkOrder $wo, RentalPayerRuling $ruling): void
    {
        $previous = RentalPayerRuling::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('id', '<', $ruling->id)->orderByDesc('id')->first();
        $replaces = $previous !== null;
        // the tenant is told when THEY pay something now, or when an earlier ruling that asked them to pay no longer does (what changed for them)
        $tenantMail = $this->tenantInvolved($ruling) ? 'ruling' : ($this->tenantInvolved($previous) ? 'released' : null);
        $sentOwner = $sentTenant = 0;
        try {
            if (RentalPortalSetting::notifyLandlordOnDecisionNeededFor($wo->agency_id)) {
                foreach (app(RentalAuthorisationService::class)->ownersFor($wo) as $owner) {
                    if ($owner->email) {
                        Mail::to($owner->email)->send(new \App\Mail\Rentals\RentalPayerRulingMail($wo, $ruling, 'owner', $this->payload($ruling, 'owner'), $owner, $replaces));
                        $sentOwner++;
                    }
                }
            }
            if ($tenantMail !== null) {
                foreach ($wo->lease?->tenantContacts() ?? [] as $tenant) {
                    if ($tenant->email) {
                        Mail::to($tenant->email)->send(new \App\Mail\Rentals\RentalPayerRulingMail($wo, $ruling, 'tenant', $this->payload($ruling, 'tenant', $tenantMail === 'released'), $tenant, $replaces));
                        $sentTenant++;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Who pays: could not email the owner/tenant', ['ruling' => $ruling->id, 'error' => $e->getMessage()]);
        }
        $ruling->forceFill(array_filter(['owner_told_at' => $sentOwner ? now() : null, 'tenant_told_at' => $sentTenant ? now() : null]))->save();
    }

    // ───────────────────────── the words ─────────────────────────

    /** The ruling in plain words for one audience ('owner' | 'tenant'). Factual; the tenant's version never accuses. */
    public function payload(RentalPayerRuling $r, string $audience, bool $tenantReleased = false): array
    {
        $owner = $audience === 'owner';
        $o = (int) $r->owner_percent;
        $t = (int) $r->tenant_percent;
        $headline = match ($r->payer) {
            RentalPayerRuling::PAYER_OWNER => 'You will pay for this.',
            RentalPayerRuling::PAYER_TENANT => $owner ? 'The tenant will pay for this.' : 'You are asked to pay for this.',
            // the tenant reads ONLY their own share - never the owner's side
            RentalPayerRuling::PAYER_SHARED => $owner ? "The cost is shared: you pay {$o}% and the tenant pays {$t}%." : "You are asked to pay {$t}% of the cost.",
            default => 'Your insurer or the body corporate is expected to pay for this.',
        };
        if (! $owner && $tenantReleased) {
            // an earlier ruling asked the tenant to pay; this one no longer does - say only what changed for them (no reason: it may speak of the owner)
            $headline = 'You are no longer asked to pay for this repair.';
        }
        $tz = $r->workOrder?->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');

        return [
            'payer' => $r->payer,
            'headline' => $headline,
            'found' => (string) $r->found_text,
            'cause' => (string) $r->cause_text,
            'reason' => (! $owner && $tenantReleased) ? '' : (string) $r->reason,
            'closing' => (! $owner && $tenantReleased) ? 'Nothing more is needed from you.' : 'Your agency will be in touch about payment.',
            'at' => $r->ruled_at?->copy()->setTimezone($tz)->format('j M Y H:i'),
        ];
    }

    /** What the owner's / tenant's fault screen reads for this fault - or null when no ruling was made. */
    public function portalPayload(RentalFaultReport $fault, string $audience): ?array
    {
        $ruling = $this->rulingForFault($fault);
        // the tenant's portal shows a who-pays line only when THEY have something to pay
        if ($audience === 'tenant' && ! $this->tenantInvolved($ruling)) {
            return null;
        }

        return $ruling ? $this->payload($ruling, $audience) : null;
    }
}
