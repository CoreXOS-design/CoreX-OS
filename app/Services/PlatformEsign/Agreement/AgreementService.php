<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Mail\PlatformEsign\AgreementInviteMail;
use App\Mail\PlatformEsign\AgreementReceivedMail;
use App\Models\Agency;
use App\Models\DevSetting;
use App\Models\Platform\AgencyTimeline;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Initial;
use App\Models\PlatformEsign\Signer;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use App\Services\PlatformEsign\EsignService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Workflow of the CoreX Subscription Agreement web document (spec §11): send → recipient fills/initials/signs → RR countersigns
 * → sealed PDF + timeline hook. Reuses the module's Document/Signer/Event rows, mail path, audit log and completion.
 * Entered values are NEVER written to logs, events or email bodies (spec §11.10).
 */
class AgreementService
{
    public const EXPIRY_KEY = 'platform_esign.agreement_expiry_days';
    public const REMINDER_KEY = 'platform_esign.agreement_reminder_days';

    public function __construct(
        private AgreementContent $content,
        private AgreementLayout $layout,
        private AgreementPdf $pdf,
        private EsignService $esign,
        private AgencyTimelineService $timelines,
    ) {
    }

    public static function expiryDays(): int
    {
        return max(1, min(180, (int) DevSetting::get(self::EXPIRY_KEY, 30)));
    }

    public static function reminderDays(): int
    {
        return max(1, min(60, (int) DevSetting::get(self::REMINDER_KEY, 3)));
    }

    // ── Send ───────────────────────────────────────────────────────────────

    /**
     * @param array{name:string,email:string,cell?:?string,agency_id?:?int,note?:?string,variation_text?:?string,variation_amount?:?string} $d
     * @throws \DomainException
     */
    public function send(array $d, int $userId): Document
    {
        $version = $this->content->ensureSeeded($userId);
        $this->layout->ensure($version); // calibrate now, owner-side, so the recipient never waits on it
        $tpl = $version->template;
        $sender = User::withoutGlobalScopes()->find($userId);
        if (!$sender) {
            throw new \DomainException('The sending user could not be found.');
        }
        $agency = !empty($d['agency_id']) ? Agency::withoutGlobalScopes()->find($d['agency_id']) : null;

        $varText = trim((string) ($d['variation_text'] ?? ''));
        $varAmount = trim(str_replace([' ', ','], ['', '.'], (string) ($d['variation_amount'] ?? '')));
        if ($varAmount !== '' && !preg_match('/^\d{1,9}(\.\d{1,2})?$/', $varAmount)) {
            throw new \DomainException('The agreed discount must be an amount in rand.');
        }
        if ((float) $varAmount > 0 && $varText === '') {
            throw new \DomainException('Describe the agreed variation next to the discount amount.');
        }

        $name = trim($d['name']);
        $email = strtolower(trim($d['email']));
        $cell = trim((string) ($d['cell'] ?? ''));
        $values = AgreementFields::prefillFromAgency($agency) + array_filter([
            'sig_name' => $name, 'billing_name' => $name, 'billing_email' => $email, 'billing_cell' => $cell,
        ], fn ($v) => $v !== '');
        $rr = array_filter(['variation_text' => $varText, 'variation_amount' => $varAmount], fn ($v) => $v !== '');

        $doc = DB::transaction(function () use ($version, $tpl, $agency, $name, $email, $d, $values, $rr, $sender, $userId) {
            $doc = Document::create([
                'template_id' => $tpl->id, 'template_version' => (int) $tpl->version, 'wording_version_id' => $version->id,
                'agency_id' => $agency?->id, 'title' => 'CoreX Subscription Agreement — ' . ($agency?->name ?: $name),
                'status' => 'sent', 'source' => 'webdoc', 'sequential' => true,
                'expires_at' => now()->addDays(self::expiryDays())->endOfDay(), 'sent_at' => now(), 'created_by' => $userId,
                'recipient_note' => Str::limit(trim((string) ($d['note'] ?? '')), 490, '') ?: null,
                'form_data' => $values, 'rr_data' => $rr, 'form_rev' => 0,
            ]);
            $doc->update(['contract_ref' => sprintf('CX%06d', $doc->id)]);

            Signer::create(['document_id' => $doc->id, 'role_key' => 'r1', 'role_label' => 'Agency', 'sign_order' => 1,
                'name' => $name, 'email' => $email, 'token' => Str::random(48), 'status' => 'pending']);
            // RR's signer row is for the countersign done inside CoreX by an owner — its token is never emailed or served.
            Signer::create(['document_id' => $doc->id, 'role_key' => 'r2', 'role_label' => 'RR Technologies', 'sign_order' => 2,
                'name' => $sender->name, 'email' => strtolower((string) $sender->email), 'token' => Str::random(48), 'status' => 'pending']);

            $this->esign->log($doc, 'created', 'Subscription Agreement ' . $version->label() . ' prepared for ' . $name, null, $userId);

            if ($agency && $tpl->kind === 'subscription_agreement') {
                $timeline = AgencyTimeline::where('agency_id', $agency->id)->first();
                if ($timeline && !$timeline->agreement_document_id) {
                    $this->timelines->linkAgreement($timeline, $doc->id, $userId);
                    $this->esign->log($doc, 'timeline_linked', 'Linked as the agreement on ' . $agency->name . "'s timeline", null, $userId);
                }
            }

            return $doc;
        });

        $this->invite($doc, $userId);

        return $doc->fresh(['signers']);
    }

    public function invite(Document $doc, ?int $userId = null, bool $reminder = false): void
    {
        $signer = $this->agencySigner($doc);
        try {
            Mail::mailer('corex')->to($signer->email)->send(new AgreementInviteMail($doc->loadMissing('agency'), $signer, $reminder));
            $signer->update(['status' => in_array($signer->status, ['viewed'], true) ? 'viewed' : 'sent', 'invited_at' => $signer->invited_at ?? now(),
                'last_reminded_at' => $reminder ? now() : $signer->last_reminded_at, 'reminders_sent' => $signer->reminders_sent + ($reminder ? 1 : 0)]);
            $this->esign->log($doc, $reminder ? 'reminded' : 'invited', 'Emailed ' . $signer->name . ' <' . $signer->email . '>', $signer, $userId);
        } catch (\Throwable $e) {
            Log::error('Platform e-sign agreement invite failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $this->esign->log($doc, 'email_failed', 'Email to ' . $signer->email . ' failed — copy the link or use Resend', $signer, $userId);
        }
    }

    /** New link + fresh expiry; the entered values are kept. */
    public function resend(Document $doc, ?int $userId): void
    {
        if (!in_array($doc->status, ['sent', 'in_progress', 'expired'], true)) {
            throw new \DomainException('Only an unsigned, un-voided agreement can be re-sent.');
        }
        $signer = $this->agencySigner($doc);
        $signer->update(['token' => Str::random(48), 'invited_at' => now(), 'reminders_sent' => 0, 'last_reminded_at' => null]);
        $doc->update(['status' => ($doc->status === 'expired' && $doc->form_rev === 0) ? 'sent' : ($doc->form_rev > 0 ? 'in_progress' : 'sent'),
            'expires_at' => now()->addDays(self::expiryDays())->endOfDay()]);
        $this->invite($doc->fresh(), $userId);
    }

    // ── Context for rendering ──────────────────────────────────────────────

    public function agencySigner(Document $doc): Signer
    {
        return Signer::where('document_id', $doc->id)->where('role_key', 'r1')->firstOrFail();
    }

    public function rrSigner(Document $doc): Signer
    {
        return Signer::where('document_id', $doc->id)->where('role_key', 'r2')->firstOrFail();
    }

    public function context(Document $doc, array $over = []): array
    {
        $doc->loadMissing('wording');
        $v = $doc->wording;
        $a = $this->agencySigner($doc);
        $r = $this->rrSigner($doc);
        $values = (array) ($doc->form_data ?? []);
        $rr = (array) ($doc->rr_data ?? []);

        return $over + [
            'values' => $values, 'rr' => $rr, 'rates' => $v->rates_json ?? [], 'ref' => (string) $doc->contract_ref,
            'calc' => $this->calc($values, $rr, $v->rates_json ?? []),
            'initials' => ['agency' => (string) $a->initials, 'rr' => (string) $r->initials],
            'sigs' => [
                'agency' => (string) ($a->signature_image ?: ($values['sigA'] ?? '')),
                'mandate' => (string) ($a->signature2_image ?: ($values['sigM'] ?? '')),
                'rr' => (string) ($r->signature_image ?: ($rr['sigR'] ?? '')),
            ],
            'sign_date' => $a->signed_at, 'mask' => false, 'errors' => [], 'doc_id' => $doc->id,
        ];
    }

    public function calc(array $values, array $rr, array $rates): array
    {
        return AgreementPricing::compute(
            (string) ($values['plan'] ?? ''), (int) ($values['agents'] ?? 0), (int) ($values['extra_branches'] ?? 0),
            (float) ($rr['variation_amount'] ?? 0), $rates,
        );
    }

    public function totalPages(Document $doc): int
    {
        $doc->loadMissing('wording');

        return (int) $this->layout->ensure($doc->wording)['total'];
    }

    // ── Recipient: guard, autosave, initials, submit ───────────────────────

    /** Why the recipient cannot edit right now, or null. */
    public function blockedReason(Document $doc, Signer $signer): ?string
    {
        if ($doc->trashed()) {
            return 'This agreement is no longer available.';
        }
        if (in_array($doc->status, ['sent', 'in_progress'], true) && $doc->expires_at && $doc->expires_at->isPast()) {
            $doc->update(['status' => 'expired']);
            $this->esign->log($doc, 'expired', 'Link expired');
        }

        return match ($doc->status) {
            'voided' => 'This agreement was cancelled by the sender.',
            'expired' => 'This link has expired. Reply to the email we sent you and we will send a fresh link — everything you entered has been kept.',
            'completed' => 'This agreement has been fully signed.',
            'declined' => 'This agreement was declined.',
            'awaiting_countersign', 'wetink_received' => 'You have signed this agreement. RR Technologies will countersign it and email you the signed copy.',
            default => null,
        };
    }

    /**
     * Autosave (spec §11.7). $rev is the revision the browser last saw; a different value means another tab saved first.
     *
     * @return array{rev:int,calc:array}
     * @throws AgreementConflict|\DomainException
     */
    public function save(Document $doc, Signer $signer, array $input, int $rev): array
    {
        return DB::transaction(function () use ($doc, $signer, $input, $rev) {
            $locked = Document::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if ($reason = $this->blockedReason($locked, $signer)) {
                throw new \DomainException($reason);
            }
            if ($rev !== (int) $locked->form_rev) {
                throw new AgreementConflict((int) $locked->form_rev, (array) $locked->form_data);
            }
            $clean = AgreementFields::clean($input, 'r');
            if (!$clean) {
                return ['rev' => (int) $locked->form_rev, 'calc' => $this->calc((array) $locked->form_data, (array) $locked->rr_data, $locked->wording->rates_json ?? [])];
            }
            $data = array_merge((array) $locked->form_data, $clean);
            $locked->form_data = $data;
            $locked->form_rev = (int) $locked->form_rev + 1;
            if ($locked->status === 'sent') {
                $locked->status = 'in_progress';
            }
            $locked->save();

            $keys = array_map(fn ($k) => in_array($k, ['sigA', 'sigM'], true) ? 'signature' : $k, array_keys($clean));
            $this->esign->log($locked, 'saved', 'Saved ' . count($keys) . ' field' . (count($keys) === 1 ? '' : 's') . ': ' . implode(', ', array_slice($keys, 0, 8)) . (count($keys) > 8 ? '…' : ''), $signer);

            return ['rev' => (int) $locked->form_rev, 'calc' => $this->calc($data, (array) $locked->rr_data, $locked->wording->rates_json ?? [])];
        });
    }

    public function setInitials(Document $doc, Signer $signer, string $initials): string
    {
        $clean = mb_strtoupper(preg_replace('/[^\p{L}]/u', '', $initials));
        $clean = mb_substr($clean, 0, 5);
        if ($clean === '') {
            throw new \DomainException('Enter your initials (letters only).');
        }
        if ($signer->initials !== $clean && Initial::where('signer_id', $signer->id)->exists()) {
            throw new \DomainException('Your initials are fixed once you have initialled a page.');
        }
        $signer->update(['initials' => $clean]);

        return $clean;
    }

    /** One explicit tap = one page initialled (spec §11.7). @return int pages initialled so far */
    public function initialPage(Document $doc, Signer $signer, int $page, ?string $ip): int
    {
        if ($reason = $this->blockedReason($doc, $signer)) {
            throw new \DomainException($reason);
        }
        $total = $this->totalPages($doc);
        if ($page < 1 || $page > $total) {
            throw new \DomainException('That page does not exist.');
        }
        if (!$signer->initials) {
            throw new \DomainException('Set your initials first.');
        }
        $row = Initial::firstOrCreate(['signer_id' => $signer->id, 'page_no' => $page], ['document_id' => $doc->id, 'initials' => $signer->initials, 'ip' => $ip, 'created_at' => now()]);
        if ($row->wasRecentlyCreated) {
            $this->esign->log($doc, 'page_initialled', 'Page ' . $page . ' of ' . $total . ' initialled', $signer, null, $ip);
        }

        return Initial::where('signer_id', $signer->id)->count();
    }

    /**
     * Final submit by the recipient. Validates everything, stores both signatures, moves the document to awaiting countersign.
     *
     * @param array{id_number?:?string,consent?:mixed} $input
     * @return array<string,string> errors (empty = submitted)
     */
    public function submit(Document $doc, Signer $signer, array $values, array $input, ?string $ip, ?string $ua): array
    {
        $errors = [];
        DB::transaction(function () use ($doc, $signer, $values, $input, $ip, $ua, &$errors) {
            $locked = Document::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            $sg = Signer::whereKey($signer->id)->lockForUpdate()->firstOrFail();
            if ($reason = $this->blockedReason($locked, $sg)) {
                throw new \DomainException($reason);
            }
            $data = array_merge((array) $locked->form_data, AgreementFields::clean($values, 'r'));
            $rates = $locked->wording->rates_json ?? [];
            $errors = AgreementFields::validateRecipient($data, $rates);

            $idNumber = trim((string) ($input['id_number'] ?? '')) ?: (string) $sg->id_number;
            if ($idNumber === '') {
                $errors['id_number'] = 'Enter your ID or passport number.';
            } elseif (!preg_match('/^[A-Za-z0-9 \-]{5,40}$/', $idNumber)) {
                $errors['id_number'] = 'Enter a valid ID or passport number.';
            }
            if (empty($input['consent'])) {
                $errors['consent'] = 'Tick the box to confirm you agree to sign electronically.';
            }
            if (!$sg->initials) {
                $errors['initials'] = 'Set your initials, then initial every page.';
            }
            $total = $this->totalPages($locked);
            $done = Initial::where('signer_id', $sg->id)->count();
            if ($done < $total) {
                $errors['pages'] = 'Initial every page — ' . $done . ' of ' . $total . ' done.';
            }
            if ($errors) {
                $locked->form_data = $data;
                $locked->save();

                return;
            }

            $calc = $this->calc($data, (array) $locked->rr_data, $rates);
            $data['m_total_at_signing'] = (string) $calc['total'];
            $locked->form_data = $data;
            $locked->status = 'awaiting_countersign';
            $locked->save();
            $sg->forceFill([
                'status' => 'signed', 'signed_at' => now(), 'typed_name' => $data['sig_name'], 'id_number' => $idNumber,
                'signature_image' => $data['sigA'], 'signature2_image' => $data['sigM'], 'signed_ip' => $ip,
                'signed_user_agent' => Str::limit((string) $ua, 480, ''), 'consent_text_snapshot' => EsignService::CONSENT,
            ])->save();
            $this->esign->log($locked, 'signed', 'Agency signed by ' . $data['sig_name'] . ' — all ' . $total . ' pages initialled', $sg, null, $ip);
        });
        if ($errors) {
            return $errors;
        }

        $fresh = $doc->fresh(['signers', 'agency']);
        $sender = $fresh->created_by ? User::withoutGlobalScopes()->where('id', $fresh->created_by)->value('email') : null;
        if ($sender) {
            try {
                Mail::mailer('corex')->to($sender)->send(new AgreementReceivedMail($fresh));
            } catch (\Throwable $e) {
                Log::error('Platform e-sign agreement received mail failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            }
        }

        return [];
    }

    // ── RR countersign ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $rrInput RR-side fields (name, capacity, place, date, signature)
     * @param int[] $pages the pages RR initialled
     * @return array<string,string> errors (empty = countersigned and sealed)
     */
    public function countersign(Document $doc, User $user, array $rrInput, string $initials, array $pages, ?string $ip, ?string $ua): array
    {
        $errors = [];
        DB::transaction(function () use ($doc, $user, $rrInput, $initials, $pages, $ip, $ua, &$errors) {
            $locked = Document::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'awaiting_countersign' && $locked->status !== 'wetink_received') {
                throw new \DomainException('This agreement is not waiting for a countersignature.');
            }
            // RR can only write RR-side fields, and never the variation fields (set at send, spec §11.7).
            $clean = array_diff_key(AgreementFields::clean($rrInput, 'rr'), array_flip(['variation_text', 'variation_amount']));
            $rr = array_merge((array) $locked->rr_data, $clean);
            foreach (['rr_name' => 'Name', 'rr_capacity' => 'Capacity', 'rr_place' => 'Place', 'rr_date' => 'Date', 'sigR' => 'Signature'] as $k => $label) {
                if (trim((string) ($rr[$k] ?? '')) === '') {
                    $errors[$k] = $label . ' is required.';
                }
            }
            $ini = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $initials), 0, 5));
            if ($ini === '') {
                $errors['initials'] = 'Enter your initials.';
            }
            $total = $this->totalPages($locked);
            $pages = array_values(array_unique(array_map('intval', $pages)));
            if (count(array_filter($pages, fn ($p) => $p >= 1 && $p <= $total)) < $total) {
                $errors['pages'] = 'Initial every page — ' . count($pages) . ' of ' . $total . ' done.';
            }
            if ($errors) {
                return;
            }

            $sg = Signer::where('document_id', $locked->id)->where('role_key', 'r2')->lockForUpdate()->firstOrFail();
            $locked->rr_data = $rr;
            $locked->status = 'completed';
            $locked->completed_at = now();
            $locked->save();
            $sg->forceFill([
                'name' => $rr['rr_name'], 'status' => 'signed', 'signed_at' => now(), 'typed_name' => $rr['rr_name'], 'initials' => $ini,
                'signature_image' => $rr['sigR'], 'signed_ip' => $ip, 'signed_user_agent' => Str::limit((string) $ua, 480, ''),
                'consent_text_snapshot' => EsignService::CONSENT, 'email' => strtolower((string) ($user->email ?: $sg->email)),
            ])->save();
            foreach (range(1, $total) as $p) {
                Initial::firstOrCreate(['signer_id' => $sg->id, 'page_no' => $p], ['document_id' => $locked->id, 'initials' => $ini, 'ip' => $ip, 'created_at' => now()]);
            }
            $this->esign->log($locked, 'countersigned', 'RR Technologies countersigned by ' . $rr['rr_name'] . ' — all ' . $total . ' pages initialled', $sg, $user->id, $ip);
        });
        if ($errors) {
            return $errors;
        }

        $this->esign->complete($doc->fresh());

        return [];
    }

    // ── Sealing ────────────────────────────────────────────────────────────

    public function sealedPdf(Document $doc): string
    {
        $doc->loadMissing(['signers', 'events', 'agency', 'wording']);
        $layout = $this->layout->ensure($doc->wording);
        [$bin, $pages] = $this->pdf->renderWithTotal($doc->wording, $layout, 'pdf', $this->context($doc), [
            'doc' => $doc, 'consent' => EsignService::CONSENT, 'pages_initialled' => $layout['total'],
        ]);
        if ($pages < $layout['total'] + 1 || $pages > $layout['total'] + 4) {
            $this->esign->log($doc, 'page_count_mismatch', 'Planned ' . $layout['total'] . ' contract pages; the PDF has ' . $pages . ' pages in all');
        }

        return $bin;
    }

    // ── Sensitive values ───────────────────────────────────────────────────

    /** Audited reveal of one sensitive value (spec §11.10). */
    public function reveal(Document $doc, User $user, string $key, ?string $ip): string
    {
        if (!in_array($key, AgreementFields::SENSITIVE, true)) {
            throw new \DomainException('That value cannot be revealed.');
        }
        $this->esign->log($doc, 'bank_revealed', 'Revealed ' . (AgreementFields::schema()[$key]['label'] ?? $key), null, $user->id, $ip);

        return (string) (((array) $doc->form_data)[$key] ?? '');
    }
}
