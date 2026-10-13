<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Johan's standing rule: CoreX never says "valuation" - the word is "evaluation". Road to Sold shipped with the old word in what it seeded and stored.
 *
 * Re-words what is already in the database (idempotent: every step only touches rows that still carry the old value):
 *   (1) the seeded outcome "Spoke - valuation booked" -> "Spoke - evaluation booked", its stable key spoke_valuation_booked -> spoke_evaluation_booked
 *       (the key shows in the drill-down address bar). The LABEL is only changed where the agency has not renamed it itself;
 *   (2) the call log: the same key, and the label snapshot where it is still the seeded wording;
 *   (3) the summary-bar / report metric keys valuations_booked / valuations_done -> evaluations_booked / evaluations_done wherever an agency saved them
 *       (settings step list and the settings audit trail);
 *   (4) the history note Road to Sold wrote on the owner contact, and the title of the calendar appointment it created ("Valuation: <address>").
 * Left as they are (never shown to a user): the call-log column valuation_booked and the form field of the same name.
 */
return new class extends Migration
{
    private const OLD_LABEL = 'Spoke - valuation booked';
    private const NEW_LABEL = 'Spoke - evaluation booked';
    private const OLD_KEY = 'spoke_valuation_booked';
    private const NEW_KEY = 'spoke_evaluation_booked';

    public function up(): void
    {
        // (1) the seeded outcome definition, per agency / track
        foreach (DB::table('rts_outcome_definitions')->where('key', self::OLD_KEY)->get(['id', 'agency_id', 'track', 'label']) as $def) {
            $taken = DB::table('rts_outcome_definitions')->where('agency_id', $def->agency_id)->where('track', $def->track)->where('key', self::NEW_KEY)->exists();
            if ($taken) {
                continue;   // an agency's own outcome already owns the new key - leave this one exactly as it is
            }
            $set = ['key' => self::NEW_KEY];
            if ($def->label === self::OLD_LABEL) {
                $set['label'] = self::NEW_LABEL;
            }
            DB::table('rts_outcome_definitions')->where('id', $def->id)->update($set);
        }

        // (2) the call log
        DB::table('rts_contact_outcomes')->where('outcome_key', self::OLD_KEY)->update(['outcome_key' => self::NEW_KEY]);
        DB::table('rts_contact_outcomes')->where('outcome_label', self::OLD_LABEL)->update(['outcome_label' => self::NEW_LABEL]);

        // (3) saved metric keys
        foreach (DB::table('rts_settings')->where('summary_steps', 'like', '%valuations\_%')->get(['id', 'summary_steps']) as $row) {
            DB::table('rts_settings')->where('id', $row->id)->update(['summary_steps' => $this->swapMetrics((string) $row->summary_steps)]);
        }
        foreach (DB::table('rts_settings_audit')->where(function ($q) {
            $q->where('old_values', 'like', '%valuations\_%')->orWhere('new_values', 'like', '%valuations\_%');
        })->get(['id', 'old_values', 'new_values']) as $row) {
            DB::table('rts_settings_audit')->where('id', $row->id)->update([
                'old_values' => $row->old_values === null ? null : $this->swapMetrics((string) $row->old_values),
                'new_values' => $row->new_values === null ? null : $this->swapMetrics((string) $row->new_values),
            ]);
        }

        // (4) the owner-contact history note and the calendar appointment title
        DB::table('contact_notes')->where('body', 'like', 'Road to Sold - ' . self::OLD_LABEL . '%')->update([
            'body' => DB::raw("CONCAT('Road to Sold - " . self::NEW_LABEL . "', SUBSTRING(body, " . (strlen('Road to Sold - ' . self::OLD_LABEL) + 1) . '))'),
        ]);
        $eventIds = DB::table('rts_contact_outcomes')->whereNotNull('calendar_event_id')->pluck('calendar_event_id');
        foreach ($eventIds->chunk(500) as $chunk) {
            DB::table('calendar_events')->whereIn('id', $chunk->all())->where('title', 'like', 'Valuation: %')->update([
                'title' => DB::raw("CONCAT('Evaluation: ', SUBSTRING(title, " . (strlen('Valuation: ') + 1) . '))'),
            ]);
        }
    }

    private function swapMetrics(string $json): string
    {
        // (?<!e): "evaluations_booked" contains "valuations_booked" - a second run must not touch rows already renamed
        return (string) preg_replace('/(?<!e)valuations_(booked|done)/', 'evaluations_$1', $json);
    }

    public function down(): void
    {
        // forward-only wording change: restoring the old word is never wanted
    }
};
