<?php

namespace App\Services\Rentals;

/**
 * .ai/specs/rental-work-orders.md section 17.41 (AT-465) - the crew page's NEXT bar: ONE sentence saying what the office needs
 * from the crew right now, and at most one button that scrolls to the right place on the page.
 *
 * Reads only the crew payload CrewJobService::payload() already built (plain values), so it can say nothing the crew page does
 * not already show. The order is the crew's flow: arrive -> report what you found and what you need -> send -> (office decides)
 * -> do the work -> mark it completed.
 */
final class CrewNextBar
{
    /** @return array{tone: string, text: string, button: ?array{label: string, target: string}} */
    public static function for(array $job): array
    {
        $pricing = $job['blocks']['pricing'] ?? [];
        $drafts = (int) ($pricing['draft_count'] ?? 0);
        $waiting = (int) ($pricing['sent_count'] ?? 0);
        $asked = ! empty($pricing['request']);

        if (! empty($job['crew_completed']) || ! ($job['is_open'] ?? true)) {
            return ['tone' => 'ok', 'text' => 'Completed - thank you.', 'button' => null];
        }

        if (! empty($job['on_hold'])) {
            return ['tone' => 'danger', 'text' => 'This job is on hold. Please do not attend until you hear from us.', 'button' => null];
        }

        // Lines on the list that have not gone anywhere yet always come first - the crew has work to finish on the page.
        if ($drafts > 0 && empty($job['authorised'])) {
            return [
                'tone' => 'warn',
                'text' => 'Your list has ' . $drafts . ' ' . ($drafts === 1 ? 'line' : 'lines') . '. Tell the office what you found, then press Send to office.',
                'button' => ['label' => 'Go to Send to office', 'target' => '#cj-send'],
            ];
        }

        if (! empty($job['authorised'])) {
            $when = ! empty($job['scheduled_at']) ? 'Approved: booked ' . $job['scheduled_at'] . '. ' : 'Approved. ';

            return [
                'tone' => 'ok',
                'text' => $when . 'Do the work, take photos, then Mark work completed.',
                'button' => ['label' => 'Mark work completed', 'target' => '#cj-complete'],
            ];
        }

        if ($waiting > 0) {
            return ['tone' => 'info', 'text' => 'Sent - waiting for the office.', 'button' => null];
        }

        if ($asked) {
            return [
                'tone' => 'warn',
                'text' => 'The office asked you to price this job: add your parts and hours, then press Send to office.',
                'button' => ['label' => 'Add parts and hours', 'target' => '#cj-pricing'],
            ];
        }

        return [
            'tone' => 'info',
            'text' => 'Look at the job, then tell the office what you found and what is needed: add your parts and hours, then press Send to office.',
            'button' => ['label' => 'Add parts and hours', 'target' => '#cj-pricing'],
        ];
    }
}
