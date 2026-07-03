<?php

namespace App\Mail\Matches;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The single daily Core Matches digest for one agent.
 *
 * Carries every NEW property match surfaced for that agent's contacts since the
 * last digest, grouped by contact. Replaces the per-match
 * NewPropertyMatchNotification email so an agent receives at most ONE match
 * email per day — never one per property. Assembled by SendMatchDigests.
 *
 * @param array<int,array{
 *     contact_id:int,
 *     name:string,
 *     items:array<int,array{property_id:int,address:string,price:int,score:int,listing_type:?string}>
 * }> $groups
 */
class MatchDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $greeting;
    public string $dateLine;
    public int $matchCount;
    public int $contactCount;

    public function __construct(
        public User $user,
        public array $groups,
    ) {
        $this->greeting     = $user->first_name ?? $user->name ?? 'there';
        $this->dateLine     = now()->format('l, d F Y');
        $this->contactCount = count($groups);
        $this->matchCount   = array_sum(array_map(fn ($g) => count($g['items']), $groups));
    }

    public function envelope(): Envelope
    {
        $n = $this->matchCount;
        $subject = $n === 1
            ? 'New property match for your buyers'
            : "{$n} new property matches for your buyers";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.matches.digest');
    }
}
