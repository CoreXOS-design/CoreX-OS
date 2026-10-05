<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalNoticeMail;
use App\Models\Document;
use App\Models\Lease;
use App\Models\RentalNotice;
use App\Models\RentalNoticeTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rental-portal-access.md §8 — AT-445. Agent picks a lease,
 * picks a template, enters the figures/dates, previews, sends by email to
 * the tenant and/or landlord — logged on the tenancy with the sent
 * document attached. No arrears calculation (Stage 8 is not being built);
 * the agent types the arrears figure manually for now.
 */
class RentalNoticeService
{
    /** Substitutes {{token}} placeholders in the template body with the agent-entered figures. */
    public function render(RentalNoticeTemplate $template, array $figures): string
    {
        $html = $template->body_html;
        foreach ($figures as $key => $value) {
            $html = str_replace('{{' . $key . '}}', e((string) $value), $html);
        }

        return $html;
    }

    public function preview(RentalNoticeTemplate $template, array $figures): string
    {
        return $this->render($template, $figures);
    }

    public function send(Lease $lease, RentalNoticeTemplate $template, array $figures, bool $toTenant, bool $toLandlord, User $by): RentalNotice
    {
        $renderedHtml = $this->render($template, $figures);

        $notice = RentalNotice::create([
            'agency_id' => $lease->agency_id,
            'lease_id' => $lease->id,
            'rental_notice_template_id' => $template->id,
            'notice_type' => $template->notice_type,
            'figures' => $figures,
            'sent_to_tenant' => $toTenant,
            'sent_to_landlord' => $toLandlord,
            'sent_at' => now(),
            'sent_by_user_id' => $by->id,
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

        if ($toTenant) {
            foreach ($lease->tenantContacts() as $tenantContact) {
                if ($tenantContact->email) {
                    Mail::to($tenantContact->email)->send(new RentalNoticeMail($notice, $tenantContact->first_name ?? '', $pdfContents, $filename));
                }
            }
        }
        if ($toLandlord) {
            foreach ($lease->landlordContacts() as $landlordContact) {
                if ($landlordContact->email) {
                    Mail::to($landlordContact->email)->send(new RentalNoticeMail($notice, $landlordContact->first_name ?? '', $pdfContents, $filename));
                }
            }
        }

        return $notice;
    }
}
