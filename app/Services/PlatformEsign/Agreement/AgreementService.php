<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Mail\PlatformEsign\AgreementInviteMail;
use App\Mail\PlatformEsign\AgreementReceivedMail;
use App\Models\Agency;
use App\Models\DevSetting;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\PlatformCompany;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Initial;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\WetinkFile;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use App\Services\PlatformEsign\EsignService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
    public const ACCESS_KEY = 'platform_esign.agreement_access_months';

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

    /** How long the agency's link keeps opening its completed agreement (months from completion or re-issue). */
    public static function accessMonths(): int
    {
        return max(1, min(120, (int) DevSetting::get(self::ACCESS_KEY, 12)));
    }

    // ── Send ───────────────────────────────────────────────────────────────

    /**
     * @param array{name:string,email:string,cell?:?string,agency_id?:?int,note?:?string,variation_text?:?string,variation_amount?:?string,plan?:?string,take_on_month?:?string} $d
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
        // Take-on month (spec §11.19): RR sets it; the agreement start date and first billing date are derived from it in ONE place.
        $takeOn = trim((string) ($d['take_on_month'] ?? ''));
        if ($takeOn !== '' && !AgreementTakeOn::valid($takeOn)) {
            throw new \DomainException('Choose a take-on month that is this month or later.');
        }
        // Optional owner-only override for a negotiated case: the recipient then sees that plan fixed whatever the number of agents.
        $forcedPlan = in_array($d['plan'] ?? '', ['team', 'agency'], true) ? $d['plan'] : '';
        $rr = array_filter(['variation_text' => $varText, 'variation_amount' => $varAmount, 'plan_forced' => $forcedPlan, 'take_on_month' => $takeOn, 'single_entry' => '1'], fn ($v) => $v !== '');
        if ($takeOn !== '') {
            $values = $values + AgreementTakeOn::values($takeOn);
        }

        $doc = DB::transaction(function () use ($version, $tpl, $agency, $name, $email, $d, $values, $rr, $sender, $userId, $forcedPlan, $takeOn) {
            $doc = Document::create([
                'template_id' => $tpl->id, 'template_version' => (int) $tpl->version, 'wording_version_id' => $version->id,
                'agency_id' => $agency?->id, 'title' => 'CoreX Subscription Agreement — ' . ($agency?->name ?: $name),
                'status' => 'sent', 'source' => 'webdoc', 'sequential' => true,
                'expires_at' => now()->addDays(self::expiryDays())->endOfDay(), 'sent_at' => now(), 'created_by' => $userId,
                // Pin the company letterhead/party details as they are at send time (spec platform-company-profile §7a).
                'company_snapshot' => PlatformCompany::current()->snapshot(),
                'recipient_note' => Str::limit(trim((string) ($d['note'] ?? '')), 490, '') ?: null,
                'form_data' => $values, 'rr_data' => $rr, 'form_rev' => 0,
            ]);
            $doc->update(['contract_ref' => sprintf('CX%06d', $doc->id)]);

            Signer::create(['document_id' => $doc->id, 'role_key' => 'r1', 'role_label' => 'Agency', 'sign_order' => 1,
                'name' => $name, 'email' => $email, 'token' => Str::random(48), 'status' => 'pending']);
            // RR's signer row is for the countersign done inside CoreX by an owner — its token is never emailed or served.
            Signer::create(['document_id' => $doc->id, 'role_key' => 'r2', 'role_label' => AgreementCompany::for($doc)->legalName(), 'sign_order' => 2,
                'name' => $sender->name, 'email' => strtolower((string) $sender->email), 'token' => Str::random(48), 'status' => 'pending']);

            $this->esign->log($doc, 'created', 'Subscription Agreement ' . $version->label() . ' prepared for ' . $name, null, $userId);
            if ($takeOn !== '') {
                $t = AgreementTakeOn::derive($takeOn);
                $this->esign->log($doc, 'take_on_set', 'Take-on month ' . AgreementTakeOn::label($takeOn) . ' set by ' . $sender->name . ' — agreement starts ' . Carbon::parse($t['start_date'])->format('j F Y') . ', billing starts ' . Carbon::parse($t['billing_start'])->format('j F Y'), null, $userId);
            }
            if ($forcedPlan !== '') {
                $this->esign->log($doc, 'plan_forced', 'Plan fixed by ' . $sender->name . ' to CoreX ' . ucfirst($forcedPlan) . ' (negotiated) — the recipient sees it fixed', null, $userId);
            }

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

    /**
     * A COMPLETED agreement: new link, fresh access window, the agency is emailed the new link (no attachment — the old link stops
     * working). Nothing about the signed agreement changes (spec §11.15).
     */
    public function reissueAccess(Document $doc, ?int $userId): void
    {
        if ($doc->status !== 'completed' || $doc->trashed()) {
            throw new \DomainException('Only a fully signed agreement has an access link to re-issue.');
        }
        $signer = $this->agencySigner($doc);
        $signer->update(['token' => Str::random(48), 'invited_at' => now()]);
        $doc->update(['expires_at' => now()->addMonths(self::accessMonths())->endOfDay()]);
        $this->esign->log($doc, 'access_reissued', 'New link issued to ' . $signer->name . ' <' . $signer->email . '>, valid for ' . self::accessMonths() . ' months', $signer, $userId);
        $this->esign->mailAgreementCompleted($doc->fresh(['signers', 'agency']), 'agency');
    }

    /** Why the agency's link to its COMPLETED agreement no longer opens, or null while the access window is open. */
    public function completedAccessBlocked(Document $doc): ?string
    {
        if ($doc->trashed()) {
            return 'This agreement is no longer available.';
        }
        if ($doc->expires_at && $doc->expires_at->isPast()) {
            return 'This link has expired. Reply to the email we sent you and we will send a fresh link — your signed agreement is kept safe.';
        }

        return null;
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
        $calc = $this->calc($values, $rr, $v->rates_json ?? []);
        // Section 3 completes itself: every rendering shows the plan and branches the entries select, whatever an older save stored.
        $values = $this->withDerived($values, $calc);
        $values = $this->withMirrors($this->withTakeOn($values, $rr, $calc), $rr);

        return $over + [
            'values' => $values, 'rr' => $rr, 'rates' => $v->rates_json ?? [], 'ref' => (string) $doc->contract_ref,
            'calc' => $calc,
            'initials' => ['agency' => (string) $a->initials, 'rr' => (string) $r->initials],
            'sigs' => [
                'agency' => (string) ($a->signature_image ?: ($values['sigA'] ?? '')),
                'mandate' => (string) ($a->signature2_image ?: ($values['sigM'] ?? '')),
                'rr' => (string) ($r->signature_image ?: ($rr['sigR'] ?? '')),
            ],
            'sign_date' => $a->signed_at, 'mask' => false, 'errors' => [], 'doc_id' => $doc->id,
            'company' => AgreementCompany::for($doc), // the letterhead/party details this document was sent with
        ];
    }

    /** The single fee calculation (AgreementPricing::derive): plan from the agents, extra branches from the branches. */
    public function calc(array $values, array $rr, array $rates): array
    {
        return AgreementPricing::derive($values, $rr, $rates);
    }

    /**
     * With a take-on month RR set (spec §11.19) the document's start date, first collection date, collection day (the 1st) and the
     * mandate Amount (the monthly fee calculated from the agents/branches) are RR's — documents sent without one keep what they have.
     */
    private function withTakeOn(array $values, array $rr, array $calc): array
    {
        $m = (string) ($rr['take_on_month'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
            return $values;
        }
        $values = array_merge($values, AgreementTakeOn::values($m), ['m_day' => AgreementTakeOn::COLLECTION_DAY]);
        $values['m_amount'] = $calc['plan'] === '' ? '' : rtrim(rtrim(number_format((float) $calc['total'], 2, '.', ''), '0'), '.');

        return $values;
    }

    /** Single entry (spec §11.20): the agreement's copy of a value mirrors the place it is typed — shown on every rendering, never typed twice. */
    private function withMirrors(array $values, array $rr): array
    {
        if (empty($rr['single_entry'])) {
            return $values;
        }
        foreach (AgreementFields::MIRRORS as $target => $source) {
            $values[$target] = (string) ($values[$source] ?? '');
        }

        return $values;
    }

    /** Keys the recipient's request may never write: the derived ones, plus the take-on dates when RR set a take-on month. @return string[] */
    private function lockedKeys(array $rr): array
    {
        return array_merge(self::DERIVED_KEYS, !empty($rr['take_on_month']) ? array_keys(AgreementTakeOn::FIELDS) : [], !empty($rr['single_entry']) ? array_keys(AgreementFields::MIRRORS) : []);
    }

    /** Recipient keys the entries decide — never taken from the recipient's request. */
    public const DERIVED_KEYS = ['plan', 'extra_branches', 'branches_start'];

    /** @param array<string,mixed> $values @param array $calc result of calc() @return array<string,mixed> */
    private function withDerived(array $values, array $calc): array
    {
        $values['plan'] = (string) $calc['plan'];
        $values['extra_branches'] = $calc['plan'] === '' ? '' : (string) $calc['extra_branches'];
        $values['branches_start'] = (string) ($values['branches'] ?? '');

        return $values;
    }

    /**
     * Stores the derived entries, and keeps the mandate Amount equal to the monthly total while the recipient has not
     * typed a different amount of their own.
     *
     * @param array<string,mixed> $before values as last saved @param array<string,mixed> $after values after this change
     * @return array<string,mixed>
     */
    private function settle(array $before, array $after, array $rr, array $rates): array
    {
        $old = $this->calc($before, $rr, $rates);
        $new = $this->calc($after, $rr, $rates);
        $after = $this->withDerived($after, $new);
        $after = $this->withMirrors($this->withTakeOn($after, $rr, $new), $rr);
        $cur = trim((string) ($after['m_amount'] ?? ''));
        $untouched = $cur === '' || ($old['plan'] !== '' && abs((float) str_replace(' ', '', $cur) - $old['total']) < 0.005);
        if ($untouched && $new['plan'] !== '') {
            $after['m_amount'] = rtrim(rtrim(number_format($new['total'], 2, '.', ''), '0'), '.');
        }

        return $after;
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
            'awaiting_countersign' => 'You have signed this agreement. ' . AgreementCompany::for($doc)->legalName() . ' will countersign it and email you the signed copy.',
            'wetink_received' => 'We have received your hand-signed copy. ' . AgreementCompany::for($doc)->legalName() . ' will countersign it and email you the signed copy. You can replace the copy below until then.',
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
            $clean = array_diff_key(AgreementFields::clean($input, 'r'), array_flip($this->lockedKeys((array) $locked->rr_data)));
            $rates = $locked->wording->rates_json ?? [];
            if (!$clean) {
                return ['rev' => (int) $locked->form_rev, 'calc' => $this->calc((array) $locked->form_data, (array) $locked->rr_data, $rates)];
            }
            $data = $this->settle((array) $locked->form_data, array_merge((array) $locked->form_data, $clean), (array) $locked->rr_data, $rates);
            $locked->form_data = $data;
            $locked->form_rev = (int) $locked->form_rev + 1;
            if ($locked->status === 'sent') {
                $locked->status = 'in_progress';
            }
            $locked->save();

            $keys = array_map(fn ($k) => in_array($k, ['sigA', 'sigM'], true) ? 'signature' : $k, array_keys($clean));
            $this->esign->log($locked, 'saved', 'Saved ' . count($keys) . ' field' . (count($keys) === 1 ? '' : 's') . ': ' . implode(', ', array_slice($keys, 0, 8)) . (count($keys) > 8 ? '…' : ''), $signer);

            return ['rev' => (int) $locked->form_rev, 'calc' => $this->calc($data, (array) $locked->rr_data, $rates)];
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
            $rates = $locked->wording->rates_json ?? [];
            $incoming = array_diff_key(AgreementFields::clean($values, 'r'), array_flip($this->lockedKeys((array) $locked->rr_data)));
            $data = $this->settle((array) $locked->form_data, array_merge((array) $locked->form_data, $incoming), (array) $locked->rr_data, $rates);
            $errors = AgreementFields::validateRecipient($data, $rates, !empty($locked->rr_data['single_entry']) ? array_keys(AgreementFields::MIRRORS) : []);

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
            if ($locked->status !== 'awaiting_countersign') {
                throw new \DomainException('This agreement is not waiting for a countersignature on the electronic copy.');
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
            $locked->expires_at = now()->addMonths(self::accessMonths())->endOfDay(); // from here on: the agency's access window
            $locked->save();
            $sg->forceFill([
                'name' => $rr['rr_name'], 'status' => 'signed', 'signed_at' => now(), 'typed_name' => $rr['rr_name'], 'initials' => $ini,
                'signature_image' => $rr['sigR'], 'signed_ip' => $ip, 'signed_user_agent' => Str::limit((string) $ua, 480, ''),
                'consent_text_snapshot' => EsignService::CONSENT, 'email' => strtolower((string) ($user->email ?: $sg->email)),
            ])->save();
            foreach (range(1, $total) as $p) {
                Initial::firstOrCreate(['signer_id' => $sg->id, 'page_no' => $p], ['document_id' => $locked->id, 'initials' => $ini, 'ip' => $ip, 'created_at' => now()]);
            }
            $this->esign->log($locked, 'countersigned', AgreementCompany::for($locked)->legalName() . ' countersigned by ' . $rr['rr_name'] . ' — all ' . $total . ' pages initialled', $sg, $user->id, $ip);
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
        if ($this->isWetInk($doc)) {
            return $this->wetInkAttestation($doc);
        }
        $layout = $this->layout->ensure($doc->wording);
        [$bin, $pages] = $this->pdf->renderWithTotal($doc->wording, $layout, 'pdf', $this->context($doc), [
            'doc' => $doc, 'consent' => EsignService::CONSENT, 'pages_initialled' => $layout['total'],
        ]);
        if ($pages < $layout['total'] + 1 || $pages > $layout['total'] + 4) {
            $this->esign->log($doc, 'page_count_mismatch', 'Planned ' . $layout['total'] . ' contract pages; the PDF has ' . $pages . ' pages in all');
        }

        return $bin;
    }

    // ── Wet-ink option (spec §11.8, phase c) ───────────────────────────────

    public const WET_MAX_KB = 10240;
    public const WET_MAX_FILES = 12;
    public const WET_MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /** A document the agency signed by hand: it has uploaded files and no electronic signature. */
    public function isWetInk(Document $doc): bool
    {
        return !$this->agencySigner($doc)->signature_image && $doc->wetinkFiles()->whereNull('superseded_at')->exists();
    }

    private function wetGuard(Document $doc, Signer $signer): void
    {
        $reason = $this->blockedReason($doc, $signer);
        if ($reason && $doc->status !== 'wetink_received') {
            throw new \DomainException($reason);
        }
    }

    /** The PDF to sign by hand: the values typed so far, blank initial and signature lines (audited). */
    public function wetCopy(Document $doc, Signer $signer, ?string $ip): string
    {
        $doc->loadMissing('wording');
        $this->wetGuard($doc, $signer);
        $layout = $this->layout->ensure($doc->wording);
        $ctx = $this->context($doc, ['initials' => [], 'sigs' => [], 'sign_date' => null]);
        [$bin] = $this->pdf->renderWithTotal($doc->wording, $layout, 'wet', $ctx);
        $this->esign->log($doc, 'wetcopy_downloaded', 'Printable copy downloaded to sign by hand', $signer, null, $ip);

        return $bin;
    }

    /**
     * The agency uploads its hand-signed copy (one batch = one or more files). A new batch supersedes the previous one;
     * nothing is ever deleted.
     *
     * @param UploadedFile[] $files
     * @throws \DomainException
     */
    public function uploadWetInk(Document $doc, Signer $signer, array $files, ?string $ip): int
    {
        $this->wetGuard($doc, $signer);
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile));
        if (!$files) {
            throw new \DomainException('Choose the signed pages to upload (PDF, JPG or PNG).');
        }
        if (count($files) > self::WET_MAX_FILES) {
            throw new \DomainException('Upload at most ' . self::WET_MAX_FILES . ' files at a time — combine the pages into one PDF if you can.');
        }
        $checked = [];
        foreach ($files as $f) {
            if (!$f->isValid()) {
                throw new \DomainException('“' . $f->getClientOriginalName() . '” could not be uploaded. Try again.');
            }
            if ($f->getSize() > self::WET_MAX_KB * 1024) {
                throw new \DomainException('“' . $f->getClientOriginalName() . '” is larger than ' . (self::WET_MAX_KB / 1024) . ' MB.');
            }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($f->getRealPath());
            if (!isset(self::WET_MIMES[$mime])) {
                throw new \DomainException('“' . $f->getClientOriginalName() . '” is not a PDF, JPG or PNG file.');
            }
            $checked[] = [$f, $mime];
        }

        DB::transaction(function () use ($doc, $signer, $checked, $ip) {
            $locked = Document::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if ($locked->wetinkFiles()->count() + count($checked) > 60) {
                throw new \DomainException('Too many files have been uploaded for this agreement. Ask the sender for help.');
            }
            $batch = (int) $locked->wetinkFiles()->max('batch') + 1;
            $old = $locked->wetinkFiles()->whereNull('superseded_at')->get();
            foreach ($old as $o) {
                $o->update(['superseded_at' => now()]);
            }
            if ($old->isNotEmpty()) {
                $this->esign->log($locked, 'wetink_superseded', $old->count() . ' earlier file' . ($old->count() === 1 ? '' : 's') . ' superseded by a new upload', $signer, null, $ip);
            }
            $bytes = 0;
            foreach ($checked as [$f, $mime]) {
                $path = $f->storeAs('platform-esign/documents/' . $locked->id . '/wetink', Str::random(24) . '.' . self::WET_MIMES[$mime], EsignService::DISK);
                WetinkFile::create(['document_id' => $locked->id, 'batch' => $batch, 'original_name' => Str::limit(basename($f->getClientOriginalName()), 200, ''),
                    'stored_path' => $path, 'mime' => $mime, 'size' => (int) $f->getSize(), 'sha256' => hash_file('sha256', $f->getRealPath()), 'uploaded_ip' => $ip]);
                $bytes += (int) $f->getSize();
            }
            $locked->update(['status' => 'wetink_received']);
            $sg = Signer::whereKey($signer->id)->first();
            $sg->forceFill(['status' => 'signed', 'signed_at' => now(), 'typed_name' => ((array) $locked->form_data)['sig_name'] ?? $sg->name, 'signed_ip' => $ip])->save();
            $this->esign->log($locked, 'wetink_uploaded', 'Signed copy uploaded: ' . count($checked) . ' file' . (count($checked) === 1 ? '' : 's') . ', ' . number_format($bytes / 1024, 0) . ' KB', $signer, null, $ip);
        });

        $fresh = $doc->fresh(['signers', 'agency']);
        $sender = $fresh->created_by ? User::withoutGlobalScopes()->where('id', $fresh->created_by)->value('email') : null;
        if ($sender) {
            try {
                Mail::mailer('corex')->to($sender)->send(new AgreementReceivedMail($fresh, true));
            } catch (\Throwable $e) {
                Log::error('Platform e-sign wet-ink received mail failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            }
        }

        return count($checked);
    }

    /**
     * RR countersigns a hand-signed agreement electronically on the attestation page (no page initials — RR did not sign the scan).
     *
     * @return array<string,string> errors (empty = countersigned and sealed)
     */
    public function countersignWetInk(Document $doc, User $user, array $rrInput, ?string $ip, ?string $ua): array
    {
        $errors = [];
        DB::transaction(function () use ($doc, $user, $rrInput, $ip, $ua, &$errors) {
            $locked = Document::whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'wetink_received' || !$locked->wetinkFiles()->whereNull('superseded_at')->exists()) {
                throw new \DomainException('This agreement is not waiting for a countersignature on a hand-signed copy.');
            }
            $clean = array_diff_key(AgreementFields::clean($rrInput, 'rr'), array_flip(['variation_text', 'variation_amount']));
            $rr = array_merge((array) $locked->rr_data, $clean);
            foreach (['rr_name' => 'Name', 'rr_capacity' => 'Capacity', 'rr_place' => 'Place', 'rr_date' => 'Date', 'sigR' => 'Signature'] as $k => $label) {
                if (trim((string) ($rr[$k] ?? '')) === '') {
                    $errors[$k] = $label . ' is required.';
                }
            }
            if ($errors) {
                return;
            }
            $sg = Signer::where('document_id', $locked->id)->where('role_key', 'r2')->lockForUpdate()->firstOrFail();
            $locked->rr_data = $rr;
            $locked->status = 'completed';
            $locked->completed_at = now();
            $locked->expires_at = now()->addMonths(self::accessMonths())->endOfDay(); // from here on: the agency's access window
            $locked->save();
            $sg->forceFill(['name' => $rr['rr_name'], 'status' => 'signed', 'signed_at' => now(), 'typed_name' => $rr['rr_name'], 'signature_image' => $rr['sigR'],
                'signed_ip' => $ip, 'signed_user_agent' => Str::limit((string) $ua, 480, ''), 'consent_text_snapshot' => EsignService::CONSENT,
                'email' => strtolower((string) ($user->email ?: $sg->email))])->save();
            $this->esign->log($locked, 'countersigned', AgreementCompany::for($locked)->legalName() . ' countersigned the hand-signed copy by ' . $rr['rr_name'], $sg, $user->id, $ip);
        });
        if ($errors) {
            return $errors;
        }
        $this->esign->complete($doc->fresh());

        return [];
    }

    private function wetInkAttestation(Document $doc): string
    {
        $files = $doc->wetinkFiles()->whereNull('superseded_at')->get();
        $v = $doc->wording;
        $co = AgreementCompany::for($doc);
        $data = [
            'doc' => $doc, 'files' => $files, 'rr' => (array) $doc->rr_data, 'versionLabel' => $v->label(), 'consent' => EsignService::CONSENT,
            'logo' => $co->logoDataUri(), 'logoBox' => $co->logoBoxPt(), 'brand' => $co->brand(), 'letterhead' => $co->letterhead(),
        ];
        $first = \Barryvdh\DomPDF\Facade\Pdf::loadView('platform-esign.pdf.agreement-attestation', $data + ['total' => '00'])->setPaper('a4')->output();
        $n = AgreementPdf::countPdfPages($first);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('platform-esign.pdf.agreement-attestation', $data + ['total' => $n])->setPaper('a4')->output();
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
