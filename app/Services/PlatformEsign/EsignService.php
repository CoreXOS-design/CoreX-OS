<?php

namespace App\Services\PlatformEsign;

use App\Mail\PlatformEsign\InviteMail;
use App\Mail\PlatformEsign\SignedMail;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\PlatformEsign\Attachment;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Event;
use App\Models\PlatformEsign\FieldValue;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\Template;
use App\Models\PlatformEsign\TemplateField;
use App\Services\Platform\AgencyTimelineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Platform E-Sign engine (spec §3A): CoreX's own contracts. Templates (typed wording or an uploaded PDF with
 * fields placed on it), send, sequential signing by named roles, sealed PDF + audit trail.
 * Platform-owned — nothing here touches properties, listings, deals or the contact book.
 */
class EsignService
{
    public const DISK = 'local';

    public const CONSENT = 'I confirm that I am authorised to sign this document, that I have read it in full, and that typing my name and submitting this form is my electronic signature.';

    public function __construct(private MergeFields $merge, private SealService $seal, private AgencyTimelineService $timelines)
    {
    }

    // ── Templates ──────────────────────────────────────────────────────────

    /** @param array{name:string,kind:string,source:string,body?:?string,roles:array} $data */
    public function createTemplate(array $data, ?UploadedFile $pdf, ?int $userId): Template
    {
        return DB::transaction(function () use ($data, $pdf, $userId) {
            $tpl = Template::create([
                'name' => $data['name'], 'kind' => $data['kind'], 'source' => $data['source'],
                'body' => $data['source'] === 'web' ? ($data['body'] ?? '') : null,
                'roles_json' => $this->normaliseRoles($data['roles']), 'created_by' => $userId, 'is_active' => true, 'version' => 1,
            ]);
            if ($data['source'] === 'pdf') {
                $this->attachPdf($tpl, $pdf);
            }

            return $tpl;
        });
    }

    public function updateTemplate(Template $tpl, array $data, ?UploadedFile $pdf): Template
    {
        return DB::transaction(function () use ($tpl, $data, $pdf) {
            $tpl->fill([
                'name' => $data['name'], 'kind' => $data['kind'], 'is_active' => (bool) ($data['is_active'] ?? false),
                'roles_json' => $this->normaliseRoles($data['roles']),
            ]);
            if ($tpl->source === 'web') {
                $tpl->body = $data['body'] ?? '';
            }
            if ($tpl->source === 'pdf' && $pdf) {
                $this->attachPdf($tpl, $pdf);
                // New file → old placements no longer line up.
                $tpl->fields()->delete();
            }
            $tpl->version = $tpl->version + 1;
            $tpl->save();

            return $tpl;
        });
    }

    /** @return array<int,array{key:string,label:string,order:int}> */
    private function normaliseRoles(array $roles): array
    {
        $out = [];
        $i = 0;
        foreach ($roles as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $i++;
            $out[] = ['key' => 'r' . $i, 'label' => Str::limit($label, 80, ''), 'order' => $i];
        }
        if (!$out) {
            throw new \DomainException('A template needs at least one signer role.');
        }

        return $out;
    }

    /** Store the source PDF and rasterise its pages for the field editor and the sealed copy. */
    private function attachPdf(Template $tpl, ?UploadedFile $pdf): void
    {
        if (!$pdf) {
            throw new \DomainException('Upload the PDF this template is built on.');
        }
        $dir = 'platform-esign/templates/' . $tpl->id;
        Storage::disk(self::DISK)->deleteDirectory($dir);
        $path = $pdf->storeAs($dir, 'source.pdf', self::DISK);
        $count = $this->rasterise($path, $dir . '/pages');
        $tpl->fill(['pdf_path' => $path, 'page_count' => $count])->save();
    }

    /** @return int page count */
    private function rasterise(string $pdfPath, string $pagesDir): int
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory($pagesDir);
        $abs = $disk->path($pdfPath);
        $out = $disk->path($pagesDir) . '/page';
        $bin = config('splitter.pdftoppm_path', 'pdftoppm');
        $res = Process::timeout(120)->run([$bin, '-r', '110', '-png', $abs, $out]);
        if (!$res->successful()) {
            Log::error('Platform e-sign: pdftoppm failed', ['err' => $res->errorOutput()]);
            throw new \DomainException('That PDF could not be read. Export it again as a standard PDF and retry.');
        }
        $n = 0;
        foreach (glob($disk->path($pagesDir) . '/page-*.png') as $f) {
            // pdftoppm pads (page-01.png); normalise to page-<index>.png (0-based) for stable addressing.
            if (preg_match('/page-(\d+)\.png$/', $f, $m)) {
                $idx = (int) $m[1] - 1;
                rename($f, $disk->path($pagesDir) . '/p' . $idx . '.png');
                $n++;
            }
        }
        if ($n === 0 || $n > 60) {
            throw new \DomainException($n === 0 ? 'That PDF has no readable pages.' : 'That PDF is longer than 60 pages.');
        }

        return $n;
    }

    public function pagePath(string $baseDir, int $index): string
    {
        return $baseDir . '/pages/p' . $index . '.png';
    }

    /** Replace a template's placed fields. @param array<int,array> $fields */
    public function saveFields(Template $tpl, array $fields): void
    {
        $roleKeys = collect($tpl->roles())->pluck('key')->all();
        DB::transaction(function () use ($tpl, $fields, $roleKeys) {
            $tpl->fields()->forceDelete();
            foreach (array_values($fields) as $i => $f) {
                if (!in_array($f['role_key'] ?? null, $roleKeys, true) || !isset(TemplateField::TYPES[$f['type'] ?? ''])) {
                    continue;
                }
                $page = (int) $f['page_index'];
                if ($page < 0 || $page >= $tpl->page_count) {
                    continue;
                }
                TemplateField::create([
                    'template_id' => $tpl->id, 'page_index' => $page,
                    'x' => $this->pct($f['x']), 'y' => $this->pct($f['y']),
                    'w' => max(1, $this->pct($f['w'])), 'h' => max(1, $this->pct($f['h'])),
                    'type' => $f['type'], 'role_key' => $f['role_key'],
                    'label' => isset($f['label']) ? Str::limit(trim((string) $f['label']), 120, '') : null,
                    'required' => !isset($f['required']) || (bool) $f['required'], 'sort_order' => $i,
                ]);
            }
            $tpl->increment('version');
        });
    }

    private function pct($v): float
    {
        return max(0, min(100, round((float) $v, 4)));
    }

    // ── Send ───────────────────────────────────────────────────────────────

    /**
     * @param array{title?:?string,agency_id?:?int,sequential?:bool,expiry_days?:int,signers:array<int,array{role_key:string,name:string,email:string,id_number?:?string}>} $data
     * @param UploadedFile[] $attachments
     * @throws \DomainException
     */
    public function send(Template $tpl, array $data, array $attachments, ?int $userId): Document
    {
        if (!$tpl->is_active) {
            throw new \DomainException('This template is not active.');
        }
        $agency = !empty($data['agency_id']) ? Agency::withoutGlobalScopes()->find($data['agency_id']) : null;
        $roles = collect($tpl->roles())->keyBy('key');

        $bySigner = collect($data['signers'])->keyBy('role_key');
        foreach ($roles as $key => $role) {
            $s = $bySigner[$key] ?? null;
            if (!$s || trim((string) ($s['name'] ?? '')) === '' || trim((string) ($s['email'] ?? '')) === '') {
                throw new \DomainException('Enter a name and email for ' . $role['label'] . '.');
            }
        }

        $html = null;
        $fields = null;
        if ($tpl->isPdf()) {
            $fields = $tpl->fields()->get()->map(fn ($f) => [
                'id' => $f->id, 'page_index' => $f->page_index, 'x' => $f->x, 'y' => $f->y, 'w' => $f->w, 'h' => $f->h,
                'type' => $f->type, 'role_key' => $f->role_key, 'label' => $f->label, 'required' => (bool) $f->required,
            ])->all();
            foreach ($roles as $key => $role) {
                if (!collect($fields)->contains(fn ($f) => $f['role_key'] === $key && $f['type'] === 'signature')) {
                    throw new \DomainException('Place a signature field for ' . $role['label'] . ' on the template first.');
                }
            }
        } else {
            $html = $this->merge->render((string) $tpl->body, $this->merge->values($agency));
        }

        return DB::transaction(function () use ($tpl, $data, $agency, $html, $fields, $roles, $bySigner, $attachments, $userId) {
            $doc = Document::create([
                'template_id' => $tpl->id, 'template_version' => $tpl->version, 'agency_id' => $agency?->id,
                'title' => trim((string) ($data['title'] ?? '')) ?: $tpl->name . ($agency ? ' — ' . $agency->name : ''),
                'status' => 'sent', 'source' => $tpl->source, 'body_html_snapshot' => $html, 'fields_json' => $fields,
                'sequential' => (bool) ($data['sequential'] ?? true),
                'expires_at' => now()->addDays((int) ($data['expiry_days'] ?? 14))->endOfDay(),
                'sent_at' => now(), 'created_by' => $userId,
            ]);

            if ($tpl->isPdf()) {
                $dir = 'platform-esign/documents/' . $doc->id;
                Storage::disk(self::DISK)->makeDirectory($dir . '/pages');
                Storage::disk(self::DISK)->copy($tpl->pdf_path, $dir . '/source.pdf');
                for ($i = 0; $i < $tpl->page_count; $i++) {
                    Storage::disk(self::DISK)->copy($this->pagePath('platform-esign/templates/' . $tpl->id, $i), $this->pagePath($dir, $i));
                }
                $doc->update(['pdf_path' => $dir . '/source.pdf', 'page_count' => $tpl->page_count]);
            }

            foreach ($roles as $key => $role) {
                $s = $bySigner[$key];
                Signer::create([
                    'document_id' => $doc->id, 'role_key' => $key, 'role_label' => $role['label'],
                    'sign_order' => $doc->sequential ? $role['order'] : 1,
                    'name' => trim($s['name']), 'email' => strtolower(trim($s['email'])),
                    'id_number' => !empty($s['id_number']) ? trim($s['id_number']) : null,
                    'token' => Str::random(48), 'status' => 'pending',
                ]);
            }
            $this->log($doc, 'created', 'Created from "' . $tpl->name . '" v' . $tpl->version, null, $userId);

            foreach ($attachments as $file) {
                $path = $file->storeAs('platform-esign/documents/' . $doc->id . '/attachments', Str::random(16) . '.pdf', self::DISK);
                Attachment::create(['document_id' => $doc->id, 'original_name' => $file->getClientOriginalName(),
                    'stored_path' => $path, 'sha256' => hash_file('sha256', $file->getRealPath())]);
            }

            // Timeline integration: a subscription agreement sent for an agency whose timeline has no agreement yet
            // becomes that timeline's agreement automatically (the owner can relink or unlink on the timeline).
            if ($agency && $tpl->kind === 'subscription_agreement') {
                $timeline = AgencyTimeline::where('agency_id', $agency->id)->first();
                if ($timeline && !$timeline->agreement_document_id) {
                    $this->timelines->linkAgreement($timeline, $doc->id, $userId);
                    $this->log($doc, 'timeline_linked', 'Linked as the agreement on ' . $agency->name . "'s timeline", null, $userId);
                }
            }

            return $doc;
        });
    }

    /** Email every signer whose turn it is. Safe to call again (skips signers already invited unless $force). */
    public function inviteDue(Document $doc, ?int $userId = null, bool $force = false): void
    {
        $doc->load('signers');
        $order = $doc->signers->where('status', '!=', 'signed')->min('sign_order');
        foreach ($doc->signers as $s) {
            if ($s->status === 'signed' || $s->sign_order !== $order) {
                continue;
            }
            if ($s->invited_at && !$force) {
                continue;
            }
            try {
                Mail::mailer('corex')->to($s->email)->send(new InviteMail($doc, $s));
                $s->update(['status' => $s->status === 'viewed' ? 'viewed' : 'sent', 'invited_at' => now()]);
                $this->log($doc, $force ? 'reminded' : 'invited', 'Emailed ' . $s->name . ' <' . $s->email . '>', $s, $userId);
            } catch (\Throwable $e) {
                Log::error('Platform e-sign invite failed', ['document_id' => $doc->id, 'signer_id' => $s->id, 'error' => $e->getMessage()]);
                $this->log($doc, 'email_failed', 'Email to ' . $s->email . ' failed — use Resend', $s, $userId);
            }
        }
    }

    /** New link + fresh expiry for the signer(s) whose turn it is; old links die. */
    public function resend(Document $doc, ?int $userId, int $expiryDays = 14): void
    {
        if (!in_array($doc->status, ['sent', 'in_progress', 'expired'], true)) {
            throw new \DomainException('Only an unsigned, un-voided document can be re-sent.');
        }
        $doc->load('signers');
        foreach ($doc->signers->where('status', '!=', 'signed') as $s) {
            $s->update(['token' => Str::random(48)]);
        }
        $doc->update(['status' => $doc->signers->contains('status', 'signed') ? 'in_progress' : 'sent',
            'expires_at' => now()->addDays($expiryDays)->endOfDay()]);
        $this->inviteDue($doc->fresh(), $userId, true);
    }

    public function void(Document $doc, string $reason, ?int $userId): void
    {
        if (in_array($doc->status, ['completed', 'voided'], true)) {
            throw new \DomainException('A signed or already-voided document cannot be voided.');
        }
        $doc->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => Str::limit($reason, 490, '')]);
        foreach ($doc->signers as $s) {
            $s->update(['token' => Str::random(48)]); // old links can never resolve again
        }
        $this->log($doc, 'voided', $reason, null, $userId);
    }

    // ── Signing (public) ───────────────────────────────────────────────────

    public function resolve(string $token): ?Signer
    {
        $signer = Signer::where('token', $token)->first();
        if (!$signer) {
            return null;
        }
        $doc = $signer->document;
        if ($doc && $doc->isOpen() && $doc->expires_at && $doc->expires_at->isPast()) {
            $doc->update(['status' => 'expired']);
        }

        return $signer;
    }

    /** Why this signer cannot sign right now, or null when they can. */
    public function blockedReason(Signer $signer): ?string
    {
        $doc = $signer->document;
        if ($doc->trashed()) {
            return 'This document is no longer available.';
        }
        if ($signer->status === 'signed') {
            return 'You have already signed this document.';
        }
        if ($doc->status === 'completed') {
            return 'This document has been fully signed.';
        }
        if (in_array($doc->status, ['voided', 'declined', 'expired'], true)) {
            return ['voided' => 'This document was cancelled by the sender.', 'declined' => 'This document was declined.',
                'expired' => 'This signing link has expired. Ask the sender for a new one.'][$doc->status];
        }
        if ($doc->status === 'draft') {
            return 'This document has not been sent yet.';
        }
        if ($doc->sequential && $doc->signers()->where('sign_order', '<', $signer->sign_order)->where('status', '!=', 'signed')->exists()) {
            return 'It is not your turn yet — you will be emailed when the earlier signer has signed.';
        }

        return null;
    }

    public function recordView(Signer $signer, ?string $ip): void
    {
        if (!$signer->first_viewed_at) {
            $signer->update(['first_viewed_at' => now(), 'status' => $signer->status === 'signed' ? 'signed' : 'viewed']);
        }
        $this->log($signer->document, 'viewed', null, $signer, null, $ip);
    }

    /**
     * @param array{typed_name:string,id_number?:?string,signature?:?string,fields?:array<int|string,?string>} $input
     * @throws \DomainException
     */
    public function sign(string $token, array $input, ?string $ip, ?string $userAgent): Document
    {
        $result = DB::transaction(function () use ($token, $input, $ip, $userAgent) {
            $signer = Signer::where('token', $token)->lockForUpdate()->first();
            if (!$signer) {
                throw new \DomainException('This signing link is no longer active.');
            }
            $doc = Document::whereKey($signer->document_id)->lockForUpdate()->first();
            $signer->setRelation('document', $doc);
            if ($reason = $this->blockedReason($signer)) {
                throw new \DomainException($reason);
            }

            $idNumber = trim((string) ($input['id_number'] ?? '')) ?: $signer->id_number;
            if (!$idNumber) {
                throw new \DomainException('Enter your ID or passport number.');
            }

            $fieldIds = [];
            if ($doc->isPdf()) {
                foreach ($doc->fields_json ?? [] as $f) {
                    if ($f['role_key'] !== $signer->role_key || !in_array($f['type'], ['text', 'date'], true)) {
                        continue;
                    }
                    $val = trim((string) ($input['fields'][$f['id']] ?? ''));
                    if ($f['type'] === 'date' && $val === '') {
                        $val = now()->format('j F Y');
                    }
                    if ($val === '' && $f['required']) {
                        throw new \DomainException('Fill in "' . ($f['label'] ?: ucfirst($f['type'])) . '" on page ' . ($f['page_index'] + 1) . '.');
                    }
                    $fieldIds[$f['id']] = Str::limit($val, 500, '');
                }
            }
            foreach ($fieldIds as $fid => $val) {
                FieldValue::updateOrCreate(['signer_id' => $signer->id, 'field_id' => $fid], ['document_id' => $doc->id, 'value' => $val]);
            }

            $signer->fill([
                'status' => 'signed', 'signed_at' => now(), 'typed_name' => trim($input['typed_name']),
                'id_number' => $idNumber, 'signature_image' => $this->cleanSignature($input['signature'] ?? null),
                'signed_ip' => $ip, 'signed_user_agent' => Str::limit((string) $userAgent, 480, ''),
                'consent_text_snapshot' => self::CONSENT,
            ])->save();
            $this->log($doc, 'signed', $signer->role_label . ' signed by ' . $signer->typed_name, $signer, null, $ip);

            $remaining = $doc->signers()->where('status', '!=', 'signed')->count();
            $doc->update(['status' => $remaining ? 'in_progress' : 'completed', 'completed_at' => $remaining ? null : now()]);

            return [$doc->fresh(), $remaining === 0];
        });

        [$doc, $done] = $result;
        if ($done) {
            $this->complete($doc);
        } else {
            $this->inviteDue($doc);
        }

        return $doc;
    }

    public function decline(string $token, string $reason, ?string $ip): Document
    {
        return DB::transaction(function () use ($token, $reason, $ip) {
            $signer = Signer::where('token', $token)->lockForUpdate()->first();
            $doc = $signer ? Document::whereKey($signer->document_id)->lockForUpdate()->first() : null;
            if (!$signer || !$doc) {
                throw new \DomainException('This signing link is no longer active.');
            }
            $signer->setRelation('document', $doc);
            if ($r = $this->blockedReason($signer)) {
                throw new \DomainException($r);
            }
            $signer->update(['status' => 'declined']);
            $doc->update(['status' => 'declined', 'declined_at' => now(), 'decline_reason' => Str::limit($reason, 490, '')]);
            $this->log($doc, 'declined', $signer->role_label . ' declined: ' . $reason, $signer, null, $ip);

            return $doc;
        });
    }

    /** Seal, store the PDF, email copies, tell the timeline. */
    private function complete(Document $doc): void
    {
        try {
            $pdf = $this->seal->build($doc->fresh(['signers', 'events', 'agency', 'values']));
            $path = 'platform-esign/documents/' . $doc->id . '/signed-' . $doc->id . '.pdf';
            Storage::disk(self::DISK)->put($path, $pdf);
            $doc->update(['sealed_pdf_path' => $path, 'document_hash' => hash('sha256', $pdf)]);
            $this->log($doc, 'sealed', 'Sealed PDF created (SHA-256 ' . substr(hash('sha256', $pdf), 0, 16) . '…)');
        } catch (\Throwable $e) {
            Log::error('Platform e-sign seal failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $this->log($doc, 'seal_failed', 'Sealing failed — use Re-seal');
        }
        $this->mailSigned($doc->fresh(['signers', 'agency']));
        // Timeline integration: if this is the agency's linked agreement, its "sign agreement" step ticks.
        AgencyTimeline::where('agreement_document_id', $doc->id)->get()->each(fn ($t) => $this->timelines->syncAgreement($t));
    }

    /** Re-run sealing for a completed document whose PDF failed. */
    public function reseal(Document $doc): void
    {
        if ($doc->status !== 'completed') {
            throw new \DomainException('Only a fully signed document can be sealed.');
        }
        $this->complete($doc);
    }

    private function mailSigned(Document $doc): void
    {
        $to = $doc->signers->pluck('email')->all();
        if ($doc->created_by) {
            $sender = \App\Models\User::withoutGlobalScopes()->where('id', $doc->created_by)->value('email');
            if ($sender) {
                $to[] = $sender;
            }
        }
        foreach (array_unique(array_map('strtolower', $to)) as $addr) {
            try {
                Mail::mailer('corex')->to($addr)->send(new SignedMail($doc));
            } catch (\Throwable $e) {
                Log::error('Platform e-sign signed copy failed', ['document_id' => $doc->id, 'to' => $addr, 'error' => $e->getMessage()]);
            }
        }
        $this->log($doc, 'signed_copy_sent', 'Signed copy emailed');
    }

    /** Only a real PNG data-URI of sane size is kept; anything else is dropped (typed name still signs). */
    private function cleanSignature(?string $uri): ?string
    {
        if (!$uri || !str_starts_with($uri, 'data:image/png;base64,') || strlen($uri) > 400_000) {
            return null;
        }
        $bin = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);

        return ($bin !== false && @getimagesizefromstring($bin) !== false) ? $uri : null;
    }

    // ── Audit ──────────────────────────────────────────────────────────────

    public function log(Document $doc, string $event, ?string $detail, ?Signer $signer = null, ?int $userId = null, ?string $ip = null): void
    {
        Event::create([
            'document_id' => $doc->id, 'signer_id' => $signer?->id, 'event' => $event,
            'detail' => $detail ? Str::limit($detail, 490, '') : null, 'actor_user_id' => $userId, 'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
