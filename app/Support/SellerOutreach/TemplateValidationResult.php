<?php

declare(strict_types=1);

namespace App\Support\SellerOutreach;

/**
 * Result of validating a template body before save.
 */
final class TemplateValidationResult
{
    /** @param array<string,string> $errors keys = error codes, values = display messages */
    public function __construct(
        public readonly array $errors,
    ) {}

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }
}
