<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalNoticeMail;
use App\Models\Agency;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseBreach;
use App\Models\LeaseEvent;
use App\Models\RentalNotice;
use App\Models\RentalNoticeTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-portal-access.md §8 + .ai/specs/rental-notices.md. An agency's own uploaded notice template (breach notice, notice to
 * vacate, or any other the agency adds) is filled from the lease, turned into a PDF, filed as a Document and logged on the tenancy.
 *
 * The placeholders a template uses are filled from the lease and the breach (autoFigures()); typed `name=value` figures win over those.
 * A placeholder that is still empty at the end is named in the preview and sending is refused - a notice never goes out with a hole.
 * Sending a breach / vacate notice is LeaseBreachService (the agent's own mailbox, the other party told); the older free-form "Send notice"
 * screen sends through send() below.
 */
class RentalNoticeService
{
    private const TOKEN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /** Placeholders filled from the lease and the breach, with what each is (shown beside the template editor and the upload form). */
    public const PLACEHOLDERS = [
        'agency_name' => 'Your agency\'s name', 'agency_address' => 'Your agency\'s address', 'agency_phone' => 'Your agency\'s phone',
        'agency_email' => 'Your agency\'s email', 'agent_name' => 'The agent sending the notice', 'today' => 'Today\'s date',
        'property_address' => 'The property\'s address', 'tenant_name' => 'The tenant(s)', 'landlord_name' => 'The landlord(s)',
        'lease_ref' => 'The lease reference', 'lease_start_date' => 'The lease start date', 'lease_end_date' => 'The lease end date',
        'monthly_rent' => 'The lease\'s monthly rent',
        'agency_phone_secondary' => 'Your agency\'s second phone', 'agency_reg_no' => 'Your agency\'s company registration number',
        'agency_ffc_no' => 'Your agency\'s Fidelity Fund Certificate number', 'agency_vat_no' => 'Your agency\'s VAT number',
        'tenant_email' => 'The tenant\'s email address(es)',
        'payment_proof_email' => 'Where the tenant sends proof of payment (Settings -> Leases "Rental payment proof email"; your agency email when blank)',
        'amount' => 'The outstanding amount (payment breach)', 'clause' => 'The lease clause(s) quoted (the template\'s default clause block, edited per notice)', 'reason' => 'The reason / what the breach is',
        'breach_date' => 'The date the breach notice was issued', 'remedy_days' => 'Days allowed to remedy', 'remedy_deadline' => 'The last day to remedy the breach',
        'breach_summary' => 'The tenant\'s breach notices on file (date and reason), for a notice to vacate',
        'vacate_date' => 'The date the tenant must be out by (notice to vacate)', 'vacate_time' => 'The time of day the tenant must be out by (notice to vacate)',
    ];

    /**
     * What the lease and the breach know, keyed by placeholder name (plain text; the renderer escapes).
     *
     * @return array<string,string>
     */
    public function autoFigures(Lease $lease, ?LeaseBreach $breach = null, ?User $agent = null): array
    {
        $lease->loadMissing(['property', 'agency', 'tenants.contact']);
        $agency = $lease->agency;

        $figures = [
            'agency_name' => Agency::displayNameFor($agency, ''),
            'agency_address' => trim((string) ($agency?->address ?? '')),
            'agency_phone' => trim((string) ($agency?->phone ?? '')),
            'agency_email' => trim((string) ($agency?->email ?? '')),
            'agent_name' => (string) ($agent?->name ?? ''),
            'today' => now()->format('j F Y'),
            'property_address' => (string) ($lease->property?->buildDisplayAddress() ?: ''),
            'tenant_name' => $lease->tenantContacts()->map(fn ($c) => trim((string) $c->full_name))->filter()->implode(' and '),
            'landlord_name' => $lease->landlordContacts()->map(fn ($c) => trim((string) $c->full_name))->filter()->implode(' and '),
            'lease_ref' => 'Lease #' . $lease->id,
            'lease_start_date' => $lease->start_date?->format('j F Y') ?? '',
            'lease_end_date' => $lease->end_date?->format('j F Y') ?? '',
            'monthly_rent' => $lease->rental_amount ? self::money((float) $lease->rental_amount) : '',
            // the letterhead details come from the agency record - never typed into a template
            'agency_phone_secondary' => trim((string) ($agency?->phone_secondary ?? '')),
            'agency_reg_no' => trim((string) ($agency?->reg_no ?? '')),
            'agency_ffc_no' => trim((string) ($agency?->ffc_no ?? '')),
            'agency_vat_no' => trim((string) ($agency?->vat_no ?? '')),
            'tenant_email' => $lease->tenantContacts()->map(fn ($c) => trim((string) $c->email))->filter()->unique()->implode(', '),
            // the agency's own setting (its agency email when blank) - never a constant
            'payment_proof_email' => \App\Models\LeaseSetting::paymentProofEmailFor((int) $lease->agency_id),
        ];
        if ($breach) {
            $figures['breach_date'] = $breach->issued_on->format('j F Y');
            $figures['remedy_days'] = $breach->remedy_business_days ? (string) $breach->remedy_business_days : '';
            $figures['remedy_deadline'] = $breach->remedy_deadline?->format('j F Y') ?? '';
            $figures['amount'] = $breach->amount !== null ? self::money((float) $breach->amount) : '';
            $figures['clause'] = (string) ($breach->clause ?? '');
            $figures['reason'] = (string) ($breach->reason ?? '');
            if ($breach->vacate_date) {
                $figures['vacate_date'] = $breach->vacate_date->format('j F Y');
                $figures['vacate_time'] = LeaseBreach::vacateTimeText($breach->vacate_time);
            }
        }
        $figures['breach_summary'] = $this->breachSummary($lease);

        return array_filter($figures, fn ($v) => $v !== '');
    }

    /**
     * The tenant's breach notices still standing on this lease (not remedied, not archived) as "date - what it was", one after another.
     * A notice to vacate quotes it as the breaches behind the cancellation; the agent can retype it on the form.
     */
    public function breachSummary(Lease $lease): string
    {
        if (! $lease->exists) {
            return '';
        }

        return $lease->breaches()->where('breach_party', Lease::NOTICE_BY_TENANT)->where('status', '!=', LeaseBreach::STATUS_REMEDIED)->orderBy('issued_on')->orderBy('id')->get()
            ->map(fn (LeaseBreach $b) => $b->issued_on->format('j F Y') . ' - ' . ($b->kind === LeaseBreach::KIND_PAYMENT
                ? 'failure to pay rent (' . self::money((float) $b->amount) . ' outstanding)'
                : trim((string) $b->reason)))
            ->implode('; ');
    }

    /** South African money: "R 1 234.50". */
    public static function money(float $amount): string
    {
        return 'R ' . number_format($amount, 2, '.', ' ');
    }

    /** Fills {{token}} placeholders: typed figures win over what the lease and the breach know. Values are escaped. */
    public function render(RentalNoticeTemplate $template, array $figures, ?Lease $lease = null, ?LeaseBreach $breach = null, ?User $agent = null): string
    {
        $values = $lease ? $this->autoFigures($lease, $breach, $agent) : [];
        // the template's own saved clause block is the starting text of {{clause}} (the agent quotes the real clause on a one-off breach)
        if (empty($values['clause']) && trim((string) $template->default_clause) !== '') {
            $values['clause'] = trim((string) $template->default_clause);
        }
        foreach ($figures as $key => $value) {
            if (trim((string) $value) !== '') {
                $values[$key] = (string) $value;
            }
        }

        // the template's own HTML is cleaned of anything active before it is shown unescaped (it was already cleaned on save; templates saved
        // before that are cleaned here), then the placeholder values - which are escaped - are put in, line breaks kept (a clause block is several lines)
        return (string) preg_replace_callback(self::TOKEN, fn ($m) => array_key_exists($m[1], $values) ? nl2br(e($values[$m[1]]), false) : $m[0], \App\Support\Html\NoticeHtmlCleaner::clean((string) $template->body_html));
    }

    /** Placeholders still unfilled in rendered HTML. @return list<string> */
    public function unfilled(string $html): array
    {
        preg_match_all(self::TOKEN, $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function preview(RentalNoticeTemplate $template, array $figures, ?Lease $lease = null, ?LeaseBreach $breach = null, ?User $agent = null): string
    {
        return $this->render($template, $figures, $lease, $breach, $agent);
    }

    /**
     * Fill the template, make the PDF, file it as a Document and log it. Sends nothing - the caller decides who is told and how.
     *
     * @param bool $visibleToTenant / $visibleToLandlord who sees it in their portal (RentalPortalDocumentService reads these)
     * @return array{0:RentalNotice,1:string,2:string} [the notice, the PDF bytes, the file name]
     *
     * @throws ValidationException a placeholder still unfilled
     */
    public function generate(Lease $lease, RentalNoticeTemplate $template, array $figures, bool $visibleToTenant, bool $visibleToLandlord, User $by, ?LeaseBreach $breach = null): array
    {
        $renderedHtml = $this->render($template, $figures, $lease, $breach, $by);
        $missing = $this->unfilled($renderedHtml);
        if ($missing !== []) {
            throw ValidationException::withMessages(['figures_raw' => 'Fill in: ' . implode(', ', $missing) . ' (one per line as name=value).']);
        }

        $notice = RentalNotice::create([
            'agency_id' => $lease->agency_id,
            'lease_id' => $lease->id,
            'rental_notice_template_id' => $template->id,
            'notice_type' => $template->notice_type,
            'figures' => $figures,
            'sent_to_tenant' => $visibleToTenant,
            'sent_to_landlord' => $visibleToLandlord,
            'sent_at' => now(),
            'sent_by_user_id' => $by->id,
            'lease_breach_id' => $breach?->id,
        ]);

        $pdfService = app(RentalDocumentPdfService::class);
        $pdf = $pdfService->noticePdf($notice, $renderedHtml);
        $filename = $pdfService->noticeFilename($notice);
        $pdfContents = $pdf->output();
        $storagePath = "rental-notices/{$lease->id}/" . uniqid() . '-' . $filename;
        Storage::disk('local')->put($storagePath, $pdfContents);

        $document = Document::create([
            'agency_id' => $lease->agency_id,
            'original_name' => $filename,
            'storage_path' => $storagePath,
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size' => strlen($pdfContents),
            'source_type' => 'rental_notice',
            'source_id' => $notice->id,
            'uploaded_by' => $by->id,
        ]);
        $notice->forceFill(['document_id' => $document->id])->save();

        return [$notice, $pdfContents, $filename];
    }

    /** The older free-form "Send notice" screen: the agency's template, emailed to the tenant and/or the landlord. */
    public function send(Lease $lease, RentalNoticeTemplate $template, array $figures, bool $toTenant, bool $toLandlord, User $by): RentalNotice
    {
        [$notice, $pdfContents, $filename] = $this->generate($lease, $template, $figures, $toTenant, $toLandlord, $by);

        $names = [];
        if ($toTenant) {
            foreach ($lease->tenantContacts() as $tenantContact) {
                $names[] = trim((string) $tenantContact->full_name) ?: 'tenant';
                if ($tenantContact->email) {
                    Mail::to($tenantContact->email)->send(new RentalNoticeMail($notice, $tenantContact->first_name ?? '', $pdfContents, $filename));
                }
            }
        }
        if ($toLandlord) {
            foreach ($lease->landlordContacts() as $landlordContact) {
                $names[] = trim((string) $landlordContact->full_name) ?: 'landlord';
                if ($landlordContact->email) {
                    Mail::to($landlordContact->email)->send(new RentalNoticeMail($notice, $landlordContact->first_name ?? '', $pdfContents, $filename));
                }
            }
        }

        LeaseEvent::create([
            'lease_id' => $lease->id, 'event_type' => LeaseEvent::TYPE_NOTICE_SENT, 'actor_user_id' => $by->id, 'occurred_at' => now(), 'created_at' => now(),
            'description' => RentalNoticeTemplate::labelFor($template->notice_type) . ' sent to ' . ($names ? implode(', ', array_unique($names)) : 'no one') . ' ("' . $template->name . '")',
            'metadata' => ['rental_notice_id' => $notice->id, 'to_tenant' => $toTenant, 'to_landlord' => $toLandlord],
        ]);

        return $notice;
    }
}
