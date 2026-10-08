<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Exceptions\Docuperfect\EsignAcknowledgementRequired;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Template;
use App\Models\TemplateEsignAcknowledgement;
use App\Models\User;

/**
 * E-sign eligibility is the template's own setting. For the document types flagged
 * `document_types.esign_warning_required` (sale agreements, offers to purchase, deeds) turning
 * that setting ON additionally needs a recorded acknowledgement of the legal warning. This
 * class is the single place that rule lives.
 *
 * Which types are flagged is DATA (the flag on the document type), never a code list — when the
 * law changes the flag is switched off and nothing here changes.
 *
 * Spec: .ai/specs/ESIGN-CANON.md §7.
 */
class EsignAcknowledgementService
{
    /**
     * Does a template of this document type (or, when it has none, of this name) carry the legal
     * warning? An unclassified template is classified by its NAME only to find which document type
     * it is — the decision still comes from that type's flag.
     */
    public function typeRequiresAcknowledgement(?int $documentTypeId, ?string $templateName = null): bool
    {
        $type = $documentTypeId ? DocumentType::query()->find($documentTypeId) : null;

        if ($type === null && $templateName !== null && $templateName !== '') {
            $slug = app(DocumentTypeClassifier::class)->classify($templateName);
            $type = $slug !== null ? DocumentType::query()->where('slug', $slug)->first() : null;
        }

        return (bool) ($type?->esign_warning_required);
    }

    /** @return array{title:string,paragraphs:array<int,string>,confirm_label:string,enable_button:string,cancel_button:string,version:int} */
    public function warning(): array
    {
        return [
            'version'       => (int) config('esign-acknowledgement.version', 1),
            'title'         => (string) config('esign-acknowledgement.title'),
            'paragraphs'    => array_values((array) config('esign-acknowledgement.paragraphs', [])),
            'confirm_label' => (string) config('esign-acknowledgement.confirm_label'),
            'enable_button' => (string) config('esign-acknowledgement.enable_button'),
            'cancel_button' => (string) config('esign-acknowledgement.cancel_button'),
        ];
    }

    /** The wording as one block of text, stored with the audit row. */
    public function warningText(): string
    {
        $w = $this->warning();

        return $w['title'] . "\n\n" . implode("\n\n", $w['paragraphs']) . "\n\n[" . $w['confirm_label'] . ']';
    }

    /** Ids of the document types that carry the warning (for the editors' client-side prompt). */
    public function flaggedTypeIds(): array
    {
        return DocumentType::query()->where('esign_warning_required', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Work out the e-sign attributes to write for a requested e-sign setting.
     *
     * @return array{attributes:array<string,mixed>,newly_acknowledged:bool,was_acknowledged:bool}
     *
     * @throws EsignAcknowledgementRequired  e-sign requested ON for a flagged type, not acknowledged
     */
    public function resolveRequest(
        ?Template $existing,
        bool $wantEsign,
        ?int $documentTypeId,
        ?string $templateName,
        bool $acknowledged,
        ?User $user,
    ): array {
        $wasAcknowledged = $existing !== null && $existing->esign_acknowledged_at !== null;
        $clear = [
            'esign_acknowledged_by_user_id' => null,
            'esign_acknowledged_by_name'    => null,
            'esign_acknowledged_at'         => null,
        ];

        if (! $wantEsign) {
            return ['attributes' => ['is_esign' => false] + $clear, 'newly_acknowledged' => false, 'was_acknowledged' => $wasAcknowledged];
        }

        if (! $this->typeRequiresAcknowledgement($documentTypeId, $templateName)) {
            return ['attributes' => ['is_esign' => true] + $clear, 'newly_acknowledged' => false, 'was_acknowledged' => $wasAcknowledged];
        }

        // Already switched on and acknowledged by someone: that decision stands, no second prompt.
        if ($existing !== null && $existing->is_esign && $wasAcknowledged) {
            return ['attributes' => ['is_esign' => true], 'newly_acknowledged' => false, 'was_acknowledged' => true];
        }

        if (! $acknowledged || $user === null) {
            throw new EsignAcknowledgementRequired((string) config('esign-acknowledgement.required_message'), $this->warning());
        }

        return [
            'attributes' => [
                'is_esign'                      => true,
                'esign_acknowledged_by_user_id' => $user->id,
                'esign_acknowledged_by_name'    => (string) $user->name,
                'esign_acknowledged_at'         => now(),
            ],
            'newly_acknowledged' => true,
            'was_acknowledged'   => $wasAcknowledged,
        ];
    }

    /** Audit row: $user switched e-signing on for $template, having acknowledged the warning. */
    public function recordEnabled(Template $template, ?User $user): void
    {
        $this->record($template, $user, TemplateEsignAcknowledgement::ACTION_ENABLED, true);
    }

    /** Audit row: e-signing was switched off on a template that had been acknowledged. */
    public function recordDisabled(Template $template, ?User $user): void
    {
        $this->record($template, $user, TemplateEsignAcknowledgement::ACTION_DISABLED, false);
    }

    private function record(Template $template, ?User $user, string $action, bool $withWording): void
    {
        TemplateEsignAcknowledgement::create([
            'agency_id'          => $template->agency_id ?? $user?->effectiveAgencyId(),
            'template_id'        => $template->id,
            'template_name'      => (string) $template->name,
            'document_type_slug' => $template->documentType?->slug,
            'action'             => $action,
            'user_id'            => $user?->id,
            'user_name'          => $user?->name,
            'wording_version'    => $withWording ? (int) config('esign-acknowledgement.version', 1) : null,
            'wording_snapshot'   => $withWording ? $this->warningText() : null,
            'request_context'    => [
                'route'      => request()->route()?->getName(),
                'ip'         => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ],
        ]);
    }
}
