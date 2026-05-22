<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Presentation;

/**
 * Phase 6 — DTO returned by PresentationDeliveryService::prepareDeliveryBatch().
 *
 * Holds a fully-rendered preview of what each recipient will receive — used
 * by the modal's Step 2 preview pane. No DB writes happen at preview time;
 * sendBatch() takes this same DTO and does the actual create+send.
 */
final class DeliveryBatch
{
    /**
     * @param array<int, array{
     *   contact_id: ?int,
     *   name: string,
     *   first_name: string,
     *   email: ?string,
     *   phone: ?string,
     *   channel: string,
     *   mode: string,
     *   subject: ?string,
     *   body: string,
     *   validation_error: ?string,
     * }> $recipients
     */
    public function __construct(
        public readonly Presentation $presentation,
        public readonly array        $recipients,
        public readonly int          $createdByUserId,
        public readonly ?\DateTimeInterface $expiresAt = null,
    ) {}

    public function isValid(): bool
    {
        foreach ($this->recipients as $r) {
            if (!empty($r['validation_error'])) return false;
        }
        return !empty($this->recipients);
    }

    public function validationErrors(): array
    {
        $errors = [];
        foreach ($this->recipients as $i => $r) {
            if (!empty($r['validation_error'])) {
                $errors[$i] = $r['validation_error'];
            }
        }
        return $errors;
    }
}
