<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Mail\PlatformEsign\AgreementCountersignReminderMail;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Event;
use App\Models\PlatformEsign\Signer;
use App\Models\User;
use App\Services\PlatformEsign\EsignService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Link expiry + reminders for Subscription Agreements (spec §11.14). One pass, driven by the scheduled command.
 * Every decision re-reads the document's CURRENT state, so a reminder can never go out for an agreement that has
 * since been signed, voided, expired, completed or archived. No entered value is ever put in a reminder.
 */
class AgreementReminders
{
    /** Recipient actions that count as progress (merely opening the link does not). */
    private const PROGRESS_EVENTS = ['saved', 'page_initialled', 'signed'];

    public function __construct(private AgreementService $agreements, private EsignService $esign)
    {
    }

    /**
     * @return array{expired:int,reminded:int,countersign_reminded:int,lines:string[]}
     */
    public function run(bool $dry = false, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $out = ['expired' => 0, 'reminded' => 0, 'countersign_reminded' => 0, 'lines' => []];

        // 1. Expire unsigned agreements whose link has run out. An agreement the agency has signed never expires.
        Document::query()->where('source', 'webdoc')->whereIn('status', ['sent', 'in_progress'])
            ->whereNotNull('expires_at')->where('expires_at', '<', $now)->each(function (Document $doc) use ($dry, &$out) {
                $out['lines'][] = ($dry ? 'would expire ' : 'expired ') . $doc->contract_ref;
                $out['expired']++;
                if (!$dry) {
                    $doc->update(['status' => 'expired']);
                    $this->esign->log($doc, 'expired', 'Link expired — no signature before the link ran out');
                }
            });

        $max = AgreementSettings::get('reminder_max');
        if ($max > 0) {
            // 2. Reminders to the agency.
            Document::query()->where('source', 'webdoc')->whereIn('status', ['sent', 'in_progress'])
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))->with('agency')->each(function (Document $doc) use ($dry, $now, $max, &$out) {
                    $signer = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first();
                    if (!$signer || $signer->status === 'signed' || $signer->reminders_sent >= $max) {
                        return;
                    }
                    $gap = $signer->reminders_sent === 0 ? AgreementSettings::get('reminder_days') : AgreementSettings::get('reminder_repeat_days');
                    $since = $this->latest([$signer->invited_at ?? $doc->sent_at, $this->lastProgress($doc), $signer->last_reminded_at]);
                    if (!$since || $since->copy()->addDays($gap)->gt($now)) {
                        return;
                    }
                    $out['lines'][] = ($dry ? 'would remind ' : 'reminded ') . $signer->email . ' (' . $doc->contract_ref . ', reminder ' . ($signer->reminders_sent + 1) . ' of ' . $max . ')';
                    $out['reminded']++;
                    if ($dry) {
                        return;
                    }
                    $before = (int) $signer->reminders_sent;
                    $this->agreements->invite($doc, null, true);
                    if ((int) $signer->fresh()->reminders_sent === $before) {
                        // The mail failed (already logged). Push the next try out by one interval instead of retrying every run.
                        $signer->update(['last_reminded_at' => $now]);
                    }
                });

            // 3. Reminders to RR for agreements waiting on the countersignature.
            $gap = AgreementSettings::get('countersign_reminder_days');
            Document::query()->where('source', 'webdoc')->whereIn('status', ['awaiting_countersign', 'wetink_received'])->with(['agency', 'signers'])
                ->each(function (Document $doc) use ($dry, $now, $max, $gap, &$out) {
                    $rr = Signer::where('document_id', $doc->id)->where('role_key', 'r2')->first();
                    $agency = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first();
                    if (!$rr || $rr->status === 'signed' || $rr->reminders_sent >= $max) {
                        return;
                    }
                    $since = $this->latest([$agency?->signed_at, $doc->updated_at, $rr->last_reminded_at]);
                    if (!$since || $since->copy()->addDays($gap)->gt($now)) {
                        return;
                    }
                    $to = strtolower((string) ($doc->created_by ? User::withoutGlobalScopes()->where('id', $doc->created_by)->value('email') : '') ?: $rr->email);
                    if ($to === '') {
                        return;
                    }
                    $out['lines'][] = ($dry ? 'would remind RR ' : 'reminded RR ') . $to . ' (' . $doc->contract_ref . ', reminder ' . ($rr->reminders_sent + 1) . ' of ' . $max . ')';
                    $out['countersign_reminded']++;
                    if ($dry) {
                        return;
                    }
                    $waiting = (int) max(1, floor($since->diffInDays($now)));
                    try {
                        Mail::mailer('corex')->to($to)->send(new AgreementCountersignReminderMail($doc, $waiting));
                        $rr->update(['reminders_sent' => $rr->reminders_sent + 1, 'last_reminded_at' => $now]);
                        $this->esign->log($doc, 'countersign_reminded', 'Reminded ' . $to . ' to countersign');
                    } catch (\Throwable $e) {
                        Log::error('Platform e-sign countersign reminder failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
                        $rr->update(['last_reminded_at' => $now]);
                        $this->esign->log($doc, 'email_failed', 'Countersign reminder to ' . $to . ' failed');
                    }
                });
        }

        return $out;
    }

    private function lastProgress(Document $doc): ?Carbon
    {
        $t = Event::where('document_id', $doc->id)->whereIn('event', self::PROGRESS_EVENTS)->max('created_at');

        return $t ? Carbon::parse($t) : null;
    }

    /** @param array<int,mixed> $times @return ?Carbon the latest non-null time */
    private function latest(array $times): ?Carbon
    {
        $times = array_filter(array_map(fn ($t) => $t ? Carbon::parse($t) : null, $times));

        return $times ? collect($times)->max() : null;
    }
}
