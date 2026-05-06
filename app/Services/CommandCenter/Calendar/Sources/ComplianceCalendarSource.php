<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lights up 10 compliance-domain event classes:
 *   ffc_expiry, pi_insurance_expiry, tax_clearance_expiry,
 *   fica_renewal_due, rmcp_review_due, screening_due,
 *   training_expiry, compliance_provision_expiry,
 *   compliance_override_expiry, agent_document_expiry.
 *
 * Reconciliation-only (no Eloquent observers). Called nightly by
 * ReconcileCalendarEvents at 03:00.
 */
class ComplianceCalendarSource implements CalendarSourceContract
{
    public function name(): string
    {
        return 'ComplianceCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->ffcExpiry())
            ->merge($this->piInsuranceExpiry())
            ->merge($this->taxClearanceExpiry())
            ->merge($this->ficaRenewalDue())
            ->merge($this->rmcpReviewDue())
            ->merge($this->screeningDue())
            ->merge($this->trainingExpiry())
            ->merge($this->complianceProvisionExpiry())
            ->merge($this->complianceOverrideExpiry())
            ->merge($this->agentDocumentExpiry());
    }

    // ── User-level expiries (FFC / PI / Tax) ──

    private function ffcExpiry(): Collection
    {
        return DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotNull('ffc_expiry_date')
            ->select('id', 'name', 'ffc_expiry_date', 'agency_id', 'branch_id')
            ->get()
            ->map(fn ($u) => [
                'event_type'  => 'compliance',
                'category'    => 'ffc_expiry',
                'title'       => "FFC expires — {$u->name}",
                'event_date'  => Carbon::parse($u->ffc_expiry_date)->startOfDay(),
                'source_type' => \App\Models\User::class,
                'source_id'   => $u->id,
                'user_id'     => $u->id,
                'agency_id'   => $u->agency_id,
                'branch_id'   => $u->branch_id,
            ]);
    }

    private function piInsuranceExpiry(): Collection
    {
        return DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotNull('pi_insurance_expiry')
            ->select('id', 'name', 'pi_insurance_expiry', 'agency_id', 'branch_id')
            ->get()
            ->map(fn ($u) => [
                'event_type'  => 'compliance',
                'category'    => 'pi_insurance_expiry',
                'title'       => "PI insurance expires — {$u->name}",
                'event_date'  => Carbon::parse($u->pi_insurance_expiry)->startOfDay(),
                'source_type' => \App\Models\User::class,
                'source_id'   => $u->id,
                'user_id'     => $u->id,
                'agency_id'   => $u->agency_id,
                'branch_id'   => $u->branch_id,
            ]);
    }

    private function taxClearanceExpiry(): Collection
    {
        return DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotNull('tax_clearance_expiry')
            ->select('id', 'name', 'tax_clearance_expiry', 'agency_id', 'branch_id')
            ->get()
            ->map(fn ($u) => [
                'event_type'  => 'compliance',
                'category'    => 'tax_clearance_expiry',
                'title'       => "Tax clearance expires — {$u->name}",
                'event_date'  => Carbon::parse($u->tax_clearance_expiry)->startOfDay(),
                'source_type' => \App\Models\User::class,
                'source_id'   => $u->id,
                'user_id'     => $u->id,
                'agency_id'   => $u->agency_id,
                'branch_id'   => $u->branch_id,
            ]);
    }

    // ── FICA renewal ──

    private function ficaRenewalDue(): Collection
    {
        return DB::table('fica_submissions')
            ->whereNull('fica_submissions.deleted_at')
            ->whereNotNull('fica_submissions.fica_expires_at')
            ->where('fica_submissions.status', 'approved')
            ->leftJoin('contacts', 'contacts.id', '=', 'fica_submissions.contact_id')
            ->select(
                'fica_submissions.id',
                'fica_submissions.contact_id',
                'fica_submissions.fica_expires_at',
                'fica_submissions.agency_id',
                'fica_submissions.branch_id',
                DB::raw("CONCAT(COALESCE(contacts.first_name, ''), ' ', COALESCE(contacts.last_name, '')) AS contact_name"),
            )
            ->get()
            ->map(fn ($f) => [
                'event_type'  => 'compliance',
                'category'    => 'fica_renewal_due',
                'title'       => "FICA renewal due — " . trim($f->contact_name ?: "Contact #{$f->contact_id}"),
                'event_date'  => Carbon::parse($f->fica_expires_at)->startOfDay(),
                'source_type' => \App\Models\FicaSubmission::class,
                'source_id'   => $f->id,
                'user_id'     => null,
                'agency_id'   => $f->agency_id,
                'branch_id'   => $f->branch_id,
                'contact_id'  => $f->contact_id,
            ]);
    }

    // ── RMCP review ──

    private function rmcpReviewDue(): Collection
    {
        return DB::table('rmcp_versions')
            ->whereNull('deleted_at')
            ->whereNotNull('next_review_due')
            ->select('id', 'next_review_due', 'agency_id')
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'compliance',
                'category'    => 'rmcp_review_due',
                'title'       => 'RMCP review due',
                'event_date'  => Carbon::parse($r->next_review_due)->startOfDay(),
                'source_type' => \App\Models\Compliance\RmcpVersion::class,
                'source_id'   => $r->id,
                'user_id'     => null,
                'agency_id'   => $r->agency_id,
                'branch_id'   => null,
            ]);
    }

    // ── Employee screening ──

    private function screeningDue(): Collection
    {
        return DB::table('employee_screenings')
            ->whereNull('employee_screenings.deleted_at')
            ->whereNotNull('employee_screenings.next_due_on')
            ->leftJoin('users', 'users.id', '=', 'employee_screenings.user_id')
            ->select(
                'employee_screenings.id',
                'employee_screenings.next_due_on',
                'employee_screenings.user_id',
                'employee_screenings.agency_id',
                'employee_screenings.branch_id',
                'users.name AS user_name',
            )
            ->get()
            ->map(fn ($s) => [
                'event_type'  => 'compliance',
                'category'    => 'screening_due',
                'title'       => $s->user_name
                    ? "Background screening due — {$s->user_name}"
                    : 'Background screening due',
                'event_date'  => Carbon::parse($s->next_due_on)->startOfDay(),
                'source_type' => \App\Models\Compliance\EmployeeScreening::class,
                'source_id'   => $s->id,
                'user_id'     => $s->user_id,
                'agency_id'   => $s->agency_id,
                'branch_id'   => $s->branch_id,
            ]);
    }

    // ── Training expiry ──

    private function trainingExpiry(): Collection
    {
        return DB::table('training_completions')
            ->whereNotNull('training_completions.expires_at')
            ->leftJoin('users', 'users.id', '=', 'training_completions.user_id')
            ->select(
                'training_completions.id',
                'training_completions.expires_at',
                'training_completions.user_id',
                'users.name AS user_name',
                'users.agency_id',
                'users.branch_id',
            )
            ->get()
            ->map(fn ($t) => [
                'event_type'  => 'compliance',
                'category'    => 'training_expiry',
                'title'       => "Training expires — " . ($t->user_name ?? 'Unknown'),
                'event_date'  => Carbon::parse($t->expires_at)->startOfDay(),
                'source_type' => \App\Models\TrainingCompletion::class,
                'source_id'   => $t->id,
                'user_id'     => $t->user_id,
                'agency_id'   => $t->agency_id,
                'branch_id'   => $t->branch_id,
            ]);
    }

    // ── Agency compliance provisions ──

    private function complianceProvisionExpiry(): Collection
    {
        return DB::table('agency_compliance_provisions')
            ->whereNull('agency_compliance_provisions.deleted_at')
            ->whereNotNull('agency_compliance_provisions.effective_until')
            ->leftJoin('agency_document_type_configs', 'agency_document_type_configs.id', '=', 'agency_compliance_provisions.document_type_config_id')
            ->select(
                'agency_compliance_provisions.id',
                'agency_compliance_provisions.effective_until',
                'agency_compliance_provisions.agency_id',
                'agency_compliance_provisions.provision_type',
                'agency_document_type_configs.name AS doc_type_name',
            )
            ->get()
            ->map(fn ($p) => [
                'event_type'  => 'compliance',
                'category'    => 'compliance_provision_expiry',
                'title'       => "Compliance provision expires — " . ($p->doc_type_name ?? $p->provision_type ?? 'Unknown'),
                'event_date'  => Carbon::parse($p->effective_until)->startOfDay(),
                'source_type' => \App\Models\Compliance\AgencyComplianceProvision::class,
                'source_id'   => $p->id,
                'user_id'     => null,
                'agency_id'   => $p->agency_id,
                'branch_id'   => null,
            ]);
    }

    // ── User compliance overrides ──

    private function complianceOverrideExpiry(): Collection
    {
        return DB::table('user_compliance_overrides')
            ->whereNull('user_compliance_overrides.deleted_at')
            ->whereNotNull('user_compliance_overrides.expires_at')
            ->leftJoin('users', 'users.id', '=', 'user_compliance_overrides.user_id')
            ->select(
                'user_compliance_overrides.id',
                'user_compliance_overrides.expires_at',
                'user_compliance_overrides.user_id',
                'users.name AS user_name',
                'users.agency_id',
                'users.branch_id',
            )
            ->get()
            ->map(fn ($o) => [
                'event_type'  => 'compliance',
                'category'    => 'compliance_override_expiry',
                'title'       => "Compliance override expires — " . ($o->user_name ?? 'Unknown'),
                'event_date'  => Carbon::parse($o->expires_at)->startOfDay(),
                'source_type' => \App\Models\Compliance\UserComplianceOverride::class,
                'source_id'   => $o->id,
                'user_id'     => $o->user_id,
                'agency_id'   => $o->agency_id,
                'branch_id'   => $o->branch_id,
            ]);
    }

    // ── Generic agent documents ──

    private function agentDocumentExpiry(): Collection
    {
        // Join user_documents.document_type (slug) to agency_document_type_configs.slug
        // Only include types where has_expiry=true (or no config row exists — preserve orphans)
        return DB::table('user_documents')
            ->whereNull('user_documents.deleted_at')
            ->whereNotNull('user_documents.expiry_date')
            ->leftJoin('users', 'users.id', '=', 'user_documents.user_id')
            ->leftJoin('agency_document_type_configs', function ($join) {
                $join->on('agency_document_type_configs.slug', '=', 'user_documents.document_type')
                     ->on('agency_document_type_configs.agency_id', '=', 'user_documents.agency_id')
                     ->whereNull('agency_document_type_configs.deleted_at');
            })
            ->where(function ($q) {
                $q->where('agency_document_type_configs.has_expiry', true)
                  ->orWhereNull('agency_document_type_configs.id');
            })
            ->select(
                'user_documents.id',
                'user_documents.expiry_date',
                'user_documents.user_id',
                'user_documents.document_type',
                'users.name AS user_name',
                'users.agency_id',
                'users.branch_id',
                'agency_document_type_configs.name AS doc_type_label',
            )
            ->get()
            ->map(fn ($d) => [
                'event_type'  => 'compliance',
                'category'    => 'agent_document_expiry',
                'title'       => trim(
                    ($d->doc_type_label ?: ucfirst(str_replace('_', ' ', $d->document_type ?? 'Document')))
                    . " expires — " . ($d->user_name ?? 'Unknown')
                ),
                'event_date'  => Carbon::parse($d->expiry_date)->startOfDay(),
                'source_type' => \App\Models\UserDocument::class,
                'source_id'   => $d->id,
                'user_id'     => $d->user_id,
                'agency_id'   => $d->agency_id,
                'branch_id'   => $d->branch_id,
            ]);
    }
}
