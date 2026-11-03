<?php

declare(strict_types=1);

namespace App\Listeners\RoadToSold;

use App\Events\Docuperfect\OtpSigned;
use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Models\Docuperfect\Document as EsignDocument;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Document;
use App\Models\DocumentType;
use App\Support\Events\SafeRaise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * road-to-sold.md step 11: fan SignatureEnvelopeFinalized (every completed, approved and filed envelope) out to OtpSigned for
 * the ones that are an offer to purchase. The finalised event itself carries no document type, so it is read here from the
 * envelope's document / template - the same two places SignatureService reads it everywhere else.
 *
 * Once per envelope: finalisation can be recorded again when a queued job is retried, so "OtpSigned already logged for this
 * envelope" ends it. Never throws to the caller (the signing is legally done before this runs).
 */
final class RaiseOtpSignedFromEnvelope
{
    public const OTP_SLUG = 'otp';

    public function handle(SignatureEnvelopeFinalized $event): void
    {
        try {
            $envelope = SignatureTemplate::withoutGlobalScopes()->find($event->signatureTemplateId);
            if (! $envelope || ! $envelope->document_id) {
                return;
            }
            $document = EsignDocument::withoutGlobalScopes()->find($envelope->document_id);
            if (! $document || ! $this->isOtp($document)) {
                return;
            }
            $agencyId = $envelope->agency_id ?: $document->agency_id;
            if (! $agencyId) {
                return;
            }
            $alreadyRaised = DB::table('domain_event_log')
                ->where('event_name', OtpSigned::class)
                ->where('subject_type', SignatureTemplate::class)
                ->where('subject_id', $envelope->id)
                ->exists();
            if ($alreadyRaised) {
                return;
            }

            $senderId = $envelope->requests()->whereNotNull('sent_by')->value('sent_by') ?: $envelope->created_by;
            $filed = Document::withoutGlobalScopes()->where('source_type', 'esign')->where('source_id', $envelope->id)->orderBy('id')->first();
            $propertyId = $document->property_id ?: null;
            $branchId = $document->branch_id
                ?? ($propertyId ? DB::table('properties')->where('id', $propertyId)->value('branch_id') : null);

            SafeRaise::dispatch('OtpSigned', fn () => new OtpSigned(
                signatureTemplateId: (int) $envelope->id,
                documentId: (int) $document->id,
                propertyId: $propertyId !== null ? (int) $propertyId : null,
                dealId: $filed?->deal_id !== null ? (int) $filed->deal_id : null,
                agencyId: (int) $agencyId,
                branchId: $branchId !== null ? (int) $branchId : null,
                userId: $senderId !== null ? (int) $senderId : null,
                traceId: $event->traceId,
            ));
        } catch (\Throwable $e) {
            Log::warning('OtpSigned fan-out caught exception, signing unaffected', [
                'signature_template_id' => $event->signatureTemplateId,
                'message'               => $e->getMessage(),
            ]);
        }
    }

    /** The template's document type (slug `otp`), else the plain type word the e-sign engine falls back to everywhere. */
    private function isOtp(EsignDocument $document): bool
    {
        $template = $document->template_id ? Template::withoutGlobalScopes()->find($document->template_id) : null;
        if ($template && $template->document_type_id) {
            $slug = DocumentType::withoutGlobalScopes()->whereKey($template->document_type_id)->value('slug');
            if ($slug !== null) {
                return $slug === self::OTP_SLUG;
            }
        }

        return strtolower((string) ($template?->template_type ?? $document->document_type ?? '')) === self::OTP_SLUG;
    }
}
