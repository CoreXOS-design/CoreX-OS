<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lights up 2 document-domain event classes:
 *   signature_expiry  — signature_requests.token_expires_at (active)
 *   sales_doc_expiry  — sales_document_recipients.token_expires_at (active)
 *
 * Layered on top of existing email reminder infrastructure. Adds calendar
 * visibility for the agent who sent the document.
 *
 * Schema notes:
 *   - signature_requests has sent_by (user_id) but no agency/branch — resolve via users join
 *   - sales_document_recipients has no agent ref — resolve via sales_document_sends.sent_by → users
 */
class DocumentCalendarSource implements CalendarSourceContract
{
    /** signature_requests statuses meaning "still awaiting signature". */
    private const ACTIVE_SIGNATURE_STATUSES = ['waiting', 'pending', 'viewed'];

    /** sales_document_recipients statuses meaning "still awaiting action". */
    private const ACTIVE_SALES_STATUSES = ['sent'];

    public function name(): string
    {
        return 'DocumentCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->signatureExpiry())
            ->merge($this->salesDocExpiry());
    }

    private function signatureExpiry(): Collection
    {
        return DB::table('signature_requests as sr')
            ->whereNull('sr.deleted_at')
            ->whereNotNull('sr.token_expires_at')
            ->whereIn('sr.status', self::ACTIVE_SIGNATURE_STATUSES)
            ->leftJoin('users as u', 'u.id', '=', 'sr.sent_by')
            ->select(
                'sr.id',
                'sr.token_expires_at',
                'sr.signer_name',
                'sr.sent_by',
                'u.agency_id',
                'u.branch_id',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'document',
                'category'    => 'signature_expiry',
                'title'       => 'Signature expires — ' . ($r->signer_name ?: "request #{$r->id}"),
                'event_date'  => Carbon::parse($r->token_expires_at),
                'source_type' => \App\Models\Docuperfect\SignatureRequest::class,
                'source_id'   => $r->id,
                'user_id'     => $r->sent_by,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
            ]);
    }

    private function salesDocExpiry(): Collection
    {
        return DB::table('sales_document_recipients as sdr')
            ->whereNull('sdr.deleted_at')
            ->whereNotNull('sdr.token_expires_at')
            ->whereIn('sdr.status', self::ACTIVE_SALES_STATUSES)
            ->leftJoin('sales_document_sends as sds', 'sds.id', '=', 'sdr.sales_document_send_id')
            ->leftJoin('users as u', 'u.id', '=', 'sds.sent_by')
            ->select(
                'sdr.id',
                'sdr.token_expires_at',
                'sdr.recipient_name',
                'sds.sent_by',
                'u.agency_id',
                'u.branch_id',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'document',
                'category'    => 'sales_doc_expiry',
                'title'       => 'Sales document expires — ' . ($r->recipient_name ?: "recipient #{$r->id}"),
                'event_date'  => Carbon::parse($r->token_expires_at),
                'source_type' => \App\Models\SalesDocumentRecipient::class,
                'source_id'   => $r->id,
                'user_id'     => $r->sent_by,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
            ]);
    }
}
