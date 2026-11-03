<?php

namespace App\Services\AdStudio;

/**
 * AT-527 - one feature's ad script (type "ad"), resources/training/ads/<key>.php: the first "Ad" text Claude writes and Johan approves, which the script editor
 * pre-fills from. Plain data. Spec .ai/specs/training-videos.md 9.21.4.
 */
final class AdScript
{
    /** @param array<string,mixed> $data */
    public function __construct(public readonly array $data, public readonly string $path = '')
    {
    }

    public function key(): string { return (string) ($this->data['key'] ?? ''); }
    public function title(): string { return (string) ($this->data['title'] ?? ''); }
    /** The training script this ad films (its url / login / steps). */
    public function screen(): string { return (string) ($this->data['screen'] ?? $this->key()); }
    public function approved(): bool { return (bool) ($this->data['approved'] ?? false); }
    /** @return array<string,string> variant => text with the accent word between asterisks */
    public function questions(): array { return (array) ($this->data['questions'] ?? []); }
    /** @return array<int,array<string,mixed>> */
    public function shots(): array { return array_values((array) ($this->data['shots'] ?? [])); }
    /** @return array<string,mixed> */
    public function defaults(): array { return (array) ($this->data['defaults'] ?? []); }
    /** @return array<string,mixed> */
    public function post(): array { return (array) ($this->data['post'] ?? []); }
}
