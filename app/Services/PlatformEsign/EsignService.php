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
        if ($data['source'] === 'web') {
            $this->assertKnownMergeFields((string) ($data['body'] ?? ''));
        }

        return DB::transaction(function () use ($data, $pdf, $userId) {
            $tpl = Template::create([
                'name' => $data['name'], 'kind' => $data['kind'], 'source' => $data['source'],
                'body' => $data['source'] === 'web' ? ($data['body'] ?? '') : null,
                'roles_json' => $this->normaliseRoles($data['roles']), 'created_by' => $userId, 'is_active' => true, 'version' => 1,
            ]);
            if ($data['source'] === 'pdf') {
                // attachPdf cleans up its own files if the upload cannot be read; a failed create leaves nothing behind.
                $this->attachPdf($tpl, $pdf);
            }

            return $tpl;
        });
    }

    public function updateTemplate(Template $tpl, array $data, ?UploadedFile $pdf): Template
    {
        if ($tpl->source === 'web') {
            $this->assertKnownMergeFields((string) ($data['body'] ?? ''));
        }
        $attached = null;
        try {
            $tpl = DB::transaction(function () use ($tpl, $data, $pdf, &$attached) {
                $existing = $tpl->roles();
                $roles = $this->normaliseRoles($data['roles'], $existing, $tpl);
                $replacing = $tpl->source === 'pdf' && $pdf;
                if (!$replacing) {
                    // A role with placed fields cannot be removed — those fields would be orphaned or, worse, handed to another party.
                    $this->assertNoPlacedFieldsOrphaned($tpl, $existing, $roles);
                }
                $tpl->fill([
                    'name' => $data['name'], 'kind' => $data['kind'], 'is_active' => (bool) ($data['is_active'] ?? false),
                    'roles_json' => $roles,
                ]);
                if ($tpl->source === 'web') {
                    $tpl->body = $data['body'] ?? '';
                }
                if ($replacing) {
                    $attached = $this->attachPdf($tpl, $pdf);
                    // New file → old placements no longer line up (soft delete — the rows stay on record).
                    $tpl->fields()->delete();
                }
                $tpl->version = $tpl->version + 1;
                $tpl->save();

                return $tpl;
            });
        } catch (\Throwable $e) {
            if ($attached) {
                $this->purgeDir($attached['dir']); // the new files were never committed; the old ones are untouched
            }
            throw $e;
        }
        if ($attached) {
            $this->archiveSupersededTemplateFiles($tpl, $attached['old'], $attached['dir']);
        }

        return $tpl;
    }

    /** @throws \DomainException on any {{ … }} that is not a known merge field (typo, wrong case, made-up name). */
    private function assertKnownMergeFields(string $body): void
    {
        $bad = array_values(array_diff($this->merge->used($body), MergeFields::FIELDS));
        if ($bad) {
            throw new \DomainException('Unknown merge field {{' . $bad[0] . '}} — field names are lower-case with underscores, e.g. {{ agency_name }}.');
        }
    }

    /**
     * Role identity is STABLE: a row carrying the key of an existing role keeps it (whatever its new position or label), only genuinely
     * new rows get a new key, and a key is never reused — not even one whose role was removed. Placed fields point at role keys, so
     * renumbering by position (the old behaviour) silently handed signature spots to the wrong party.
     *
     * @param array<int,array{key:string,label:string,order:int}> $existing
     * @return array<int,array{key:string,label:string,order:int}>
     */
    private function normaliseRoles(array $roles, array $existing = [], ?Template $tpl = null): array
    {
        $existingKeys = array_column($existing, 'key');
        $labelToKey = [];
        foreach ($existing as $r) {
            $labelToKey[mb_strtolower(trim((string) $r['label']))] ??= $r['key'];
        }
        $max = 0;
        $allKeys = $existingKeys;
        if ($tpl && $tpl->exists) {
            $allKeys = array_merge($allKeys, $tpl->fields()->withTrashed()->pluck('role_key')->all());
        }
        foreach ($allKeys as $k) {
            if (preg_match('/^r(\d+)$/', (string) $k, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        $out = [];
        $used = [];
        $i = 0;
        foreach ($roles as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $i++;
            $key = trim((string) ($r['key'] ?? ''));
            if ($key === '' && !array_key_exists('key', $r)) {
                // A form that does not carry keys (stale page): keep an existing role of the same name rather than minting a new one.
                $key = $labelToKey[mb_strtolower($label)] ?? '';
            }
            if ($key === '' || !in_array($key, $existingKeys, true) || isset($used[$key])) {
                $key = 'r' . (++$max);
            }
            $used[$key] = true;
            $out[] = ['key' => $key, 'label' => Str::limit($label, 80, ''), 'order' => $i];
        }
        if (!$out) {
            throw new \DomainException('A template needs at least one signer role.');
        }

        return $out;
    }

    /** @throws \DomainException when a removed role still has fields placed on the PDF. */
    private function assertNoPlacedFieldsOrphaned(Template $tpl, array $existing, array $new): void
    {
        if (!$tpl->isPdf()) {
            return;
        }
        $removed = array_diff(array_column($existing, 'key'), array_column($new, 'key'));
        foreach ($removed as $key) {
            $n = $tpl->fields()->where('role_key', $key)->count();
            if ($n > 0) {
                $label = collect($existing)->firstWhere('key', $key)['label'] ?? $key;

                throw new \DomainException('"' . $label . '" still has ' . $n . ' field' . ($n === 1 ? '' : 's') . ' placed on the PDF. Delete those fields in the field editor first, then remove the signer.');
            }
        }
    }

    /**
     * Store the source PDF and rasterise its pages for the field editor and the sealed copy.
     * The new file goes into its OWN versioned folder; the previous file is untouched until the caller has committed.
     *
     * @return array{dir:string,old:?string} the new folder and the previous pdf_path (to archive after commit)
     */
    private function attachPdf(Template $tpl, ?UploadedFile $pdf): array
    {
        if (!$pdf) {
            throw new \DomainException('Upload the PDF this template is built on.');
        }
        $old = $tpl->pdf_path;
        $dir = 'platform-esign/templates/' . $tpl->id . '/v' . now()->format('YmdHis') . Str::lower(Str::random(6));
        try {
            $path = $pdf->storeAs($dir, 'source.pdf', self::DISK);
            if (!$path) {
                throw new \DomainException('That PDF could not be stored. Try again.');
            }
            $count = $this->rasterise($path, $dir . '/pages');
        } catch (\Throwable $e) {
            $this->purgeDir($dir);
            throw $e;
        }
        $tpl->fill(['pdf_path' => $path, 'page_count' => $count])->save();

        return ['dir' => $dir, 'old' => $old];
    }

    /** Remove a folder this class created itself and never committed (never a record of anything). */
    private function purgeDir(string $dir): void
    {
        try {
            Storage::disk(self::DISK)->deleteDirectory($dir);
        } catch (\Throwable $e) {
            Log::warning('Platform e-sign: could not clean an uncommitted folder', ['dir' => $dir, 'error' => $e->getMessage()]);
        }
    }

    /** After a replacement PDF is committed the previous files are MOVED to a superseded folder, never deleted. */
    private function archiveSupersededTemplateFiles(Template $tpl, ?string $oldPdfPath, string $newDir): void
    {
        if (!$oldPdfPath) {
            return;
        }
        try {
            $disk = Storage::disk(self::DISK);
            $base = dirname($oldPdfPath);
            if ($base === $newDir || !$disk->exists($oldPdfPath)) {
                return;
            }
            $archive = 'platform-esign/templates/' . $tpl->id . '/superseded/' . now()->format('YmdHis') . '-' . Str::lower(Str::random(4));
            $disk->makeDirectory($archive);
            if (preg_match('#^platform-esign/templates/\d+$#', $base)) {
                // Legacy flat layout: source.pdf and pages/ sit directly in the template folder (which now also holds the new version).
                $disk->move($oldPdfPath, $archive . '/source.pdf');
                if ($disk->exists($base . '/pages')) {
                    \Illuminate\Support\Facades\File::moveDirectory($disk->path($base . '/pages'), $disk->path($archive . '/pages'));
                }
            } else {
                \Illuminate\Support\Facades\File::deleteDirectory($disk->path($archive));
                \Illuminate\Support\Facades\File::moveDirectory($disk->path($base), $disk->path($archive));
            }
        } catch (\Throwable $e) {
            Log::warning('Platform e-sign: could not archive the superseded template files', ['template_id' => $tpl->id, 'error' => $e->getMessage()]);
        }
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

    /**
     * Save a template's placed fields — a DIFF, never a wipe: a posted field carrying the id of an existing field updates it in place,
     * a field without one is created, and an existing field that is no longer posted is archived (soft-deleted), never destroyed.
     *
     * @param array<int,array> $fields
     */
    public function saveFields(Template $tpl, array $fields): void
    {
        $roleKeys = collect($tpl->roles())->pluck('key')->all();
        DB::transaction(function () use ($tpl, $fields, $roleKeys) {
            $existing = $tpl->fields()->get()->keyBy('id');
            $kept = [];
            foreach (array_values($fields) as $i => $f) {
                if (!in_array($f['role_key'] ?? null, $roleKeys, true) || !isset(TemplateField::TYPES[$f['type'] ?? ''])) {
                    continue;
                }
                $page = (int) $f['page_index'];
                if ($page < 0 || $page >= $tpl->page_count) {
                    continue;
                }
                $attrs = [
                    'page_index' => $page,
                    'x' => $this->pct($f['x']), 'y' => $this->pct($f['y']),
                    'w' => max(1, $this->pct($f['w'])), 'h' => max(1, $this->pct($f['h'])),
                    'type' => $f['type'], 'role_key' => $f['role_key'],
                    'label' => isset($f['label']) ? Str::limit(trim((string) $f['label']), 120, '') : null,
                    'required' => !isset($f['required']) || (bool) $f['required'], 'sort_order' => $i,
                ];
                $id = isset($f['id']) ? (int) $f['id'] : 0;
                if ($id && $existing->has($id) && !isset($kept[$id])) {
                    $existing[$id]->update($attrs);
                    $kept[$id] = true;
                } else {
                    $new = TemplateField::create($attrs + ['template_id' => $tpl->id]);
                    $kept[$new->id] = true;
                }
            }
            $tpl->fields()->whereNotIn('id', array_keys($kept))->delete(); // soft delete
            $tpl->increment('version');
        });
    }

    private function pct($v): float
    {
        return max(0, min(100, round((float) $v, 4)));
    }

    // ── Send ───────────────────────────────────────────────────────────────

    /** @throws \DomainException */
    private function assertExpiryDays(int $days): void
    {
        if ($days < 1 || $days > 90) {
            throw new \DomainException('Choose a link expiry between 1 and 90 days.');
        }
    }

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
        $days = (int) ($data['expiry_days'] ?? 14);
        $this->assertExpiryDays($days);
        $agency = !empty($data['agency_id']) ? Agency::withoutGlobalScopes()->find($data['agency_id']) : null;
        $roles = collect($tpl->roles())->keyBy('key');
        if ($roles->isEmpty()) {
            throw new \DomainException('This template has no signer roles. Edit the template and add at least one.');
        }

        $bySigner = collect($data['signers'])->keyBy('role_key');
        // Every signer entered must be for a role this template (still) has, and every role must have a signer.
        foreach ($bySigner as $key => $s) {
            if (!$roles->has($key)) {
                throw new \DomainException('A signer was entered for a role that is not on this template. Reload the page and try again.');
            }
        }
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
            foreach ($fields as $f) {
                if (!$roles->has($f['role_key'])) {
                    throw new \DomainException('This template has fields placed for a signer who is no longer on it. Open the field editor and move or delete them before sending.');
                }
            }
            foreach ($roles as $key => $role) {
                if (!collect($fields)->contains(fn ($f) => $f['role_key'] === $key && $f['type'] === 'signature')) {
                    throw new \DomainException('Place a signature field for ' . $role['label'] . ' on the template first.');
                }
            }
        } else {
            $html = $this->merge->render((string) $tpl->body, $this->merge->values($agency));
        }

        $docDir = null;
        try {
            return DB::transaction(function () use ($tpl, $data, $agency, $html, $fields, $roles, $bySigner, $attachments, $userId, $days, &$docDir) {
                $doc = Document::create([
                    'template_id' => $tpl->id, 'template_version' => $tpl->version, 'agency_id' => $agency?->id,
                    'title' => trim((string) ($data['title'] ?? '')) ?: $tpl->name . ($agency ? ' — ' . $agency->name : ''),
                    'status' => 'sent', 'source' => $tpl->source, 'body_html_snapshot' => $html, 'fields_json' => $fields,
                    'sequential' => (bool) ($data['sequential'] ?? true),
                    'expires_at' => now()->addDays($days)->endOfDay(),
                    'sent_at' => now(), 'created_by' => $userId,
                ]);

                if ($tpl->isPdf()) {
                    $dir = $docDir = 'platform-esign/documents/' . $doc->id;
                    $src = dirname($tpl->pdf_path); // the template's current (versioned) folder
                    Storage::disk(self::DISK)->makeDirectory($dir . '/pages');
                    Storage::disk(self::DISK)->copy($tpl->pdf_path, $dir . '/source.pdf');
                    for ($i = 0; $i < $tpl->page_count; $i++) {
                        Storage::disk(self::DISK)->copy($this->pagePath($src, $i), $this->pagePath($dir, $i));
                    }
                    $doc->update(['pdf_path' => $dir . '/source.pdf', 'page_count' => $tpl->page_count]);
                }
                // Evidence anchor: a fingerprint of exactly what the signers are about to be shown (wording / source PDF / placed fields).
                $doc->update(['content_hash' => $this->computeContentHash($doc->fresh())]);

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
                $this->log($doc, 'created', 'Created from "' . $tpl->name . '" v' . $tpl->version . ' (content SHA-256 ' . $doc->content_hash . ')', null, $userId);

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
        } catch (\Throwable $e) {
            if ($docDir) {
                $this->purgeDir($docDir); // the document never committed; its copied files must not linger
            }
            throw $e;
        }
    }

    /**
     * SHA-256 of what the signers see: the merged wording, the source PDF's bytes and the placed fields.
     * Numbers are normalised so the value is identical whether it is computed from the in-memory array or read back from JSON.
     */
    public function computeContentHash(Document $doc): string
    {
        $disk = Storage::disk(self::DISK);
        $pdf = ($doc->pdf_path && $disk->exists($doc->pdf_path)) ? hash_file('sha256', $disk->path($doc->pdf_path)) : '';
        $fields = collect($doc->fields_json ?? [])->sortBy('id')->map(fn ($f) => implode('|', [
            (int) $f['id'], (int) $f['page_index'], sprintf('%.4F', $f['x']), sprintf('%.4F', $f['y']), sprintf('%.4F', $f['w']), sprintf('%.4F', $f['h']),
            $f['type'], $f['role_key'], (string) ($f['label'] ?? ''), !empty($f['required']) ? 1 : 0,
        ]))->values()->all();

        return hash('sha256', json_encode(['html' => (string) $doc->body_html_snapshot, 'pdf' => $pdf, 'fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** null = no fingerprint on record (sent before it existed) so there is nothing to compare; false = what was sent has changed. */
    public function contentIntact(Document $doc): ?bool
    {
        if (!$doc->content_hash) {
            return null;
        }

        return hash_equals($doc->content_hash, $this->computeContentHash($doc));
    }

    /**
     * Does the sealed PDF on disk still match the fingerprint recorded when it was sealed? A mismatch is logged on the audit trail
     * (and to the log channel) and the file must not be served. Documents sealed before the fingerprint was recorded pass.
     */
    public function sealedIntact(Document $doc, ?int $userId = null): bool
    {
        $disk = Storage::disk(self::DISK);
        if (!$doc->sealed_pdf_path || !$disk->exists($doc->sealed_pdf_path)) {
            return false;
        }
        if (!$doc->document_hash) {
            return true;
        }
        if (hash_equals($doc->document_hash, hash_file('sha256', $disk->path($doc->sealed_pdf_path)))) {
            return true;
        }
        Log::critical('Platform e-sign: sealed PDF does not match its recorded SHA-256', ['document_id' => $doc->id, 'path' => $doc->sealed_pdf_path]);
        $this->log($doc, 'seal_hash_mismatch', 'The sealed PDF on file no longer matches the SHA-256 recorded when it was sealed — download refused', null, $userId);

        return false;
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

    /**
     * Lock the document the way sign() does — signers first (by id), then the document — and hand back the locked, freshly-read row.
     * Callers re-check status on that row, never on the model they were handed.
     */
    private function lockDocument(Document $doc): Document
    {
        Signer::where('document_id', $doc->id)->orderBy('id')->lockForUpdate()->get(['id']);

        return Document::withTrashed()->whereKey($doc->id)->lockForUpdate()->firstOrFail();
    }

    /** New link + fresh expiry for the signer(s) whose turn it is; old links die. */
    public function resend(Document $doc, ?int $userId, int $expiryDays = 14): void
    {
        $this->assertExpiryDays($expiryDays);
        DB::transaction(function () use ($doc, $expiryDays) {
            $locked = $this->lockDocument($doc);
            if (!in_array($locked->status, ['sent', 'in_progress', 'expired'], true)) {
                throw new \DomainException('Only an unsigned, un-voided document can be re-sent.');
            }
            $signers = $locked->signers()->get();
            foreach ($signers->where('status', '!=', 'signed') as $s) {
                $s->update(['token' => Str::random(48)]);
            }
            $locked->update(['status' => $signers->contains('status', 'signed') ? 'in_progress' : 'sent',
                'expires_at' => now()->addDays($expiryDays)->endOfDay()]);
        });
        $this->inviteDue($doc->fresh(), $userId, true);
    }

    public function void(Document $doc, string $reason, ?int $userId): void
    {
        DB::transaction(function () use ($doc, $reason, $userId) {
            $locked = $this->lockDocument($doc);
            if (in_array($locked->status, ['completed', 'voided'], true)) {
                throw new \DomainException('A signed or already-voided document cannot be voided.');
            }
            $locked->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => Str::limit($reason, 490, '')]);
            foreach ($locked->signers()->get() as $s) {
                $s->update(['token' => Str::random(48)]); // old links can never resolve again
            }
            $this->log($locked, 'voided', $reason, null, $userId);
        });
        $doc->refresh();
        $this->unlinkFromTimelines($doc, 'voided', $userId);
    }

    /**
     * A voided or declined agreement must not stay pinned as an agency's agreement — it would stop a replacement being auto-linked.
     * Uses the timeline service's own unlink (which logs on the timeline). Never lets a timeline problem fail the caller.
     */
    private function unlinkFromTimelines(Document $doc, string $why, ?int $userId): void
    {
        try {
            $timelines = AgencyTimeline::where('agreement_document_id', $doc->id)->get();
            foreach ($timelines as $t) {
                $this->timelines->linkAgreement($t, null, $userId);
                $this->log($doc, 'timeline_unlinked', 'Unlinked from the agency timeline (document ' . $why . ')', null, $userId);
            }
        } catch (\Throwable $e) {
            Log::error('Platform e-sign: could not unlink the agreement from the timeline', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
        }
    }

    // ── Signing (public) ───────────────────────────────────────────────────

    /** Flip a document whose signing window has passed to 'expired'. Web documents have their own expiry rules and are never touched here. */
    public function expireIfDue(Document $doc): void
    {
        if (!$doc->isWebdoc() && in_array($doc->status, ['sent', 'in_progress'], true) && $doc->expires_at && $doc->expires_at->isPast()) {
            $doc->update(['status' => 'expired']);
        }
    }

    public function resolve(string $token): ?Signer
    {
        $signer = Signer::where('token', $token)->first();
        if (!$signer) {
            return null;
        }
        if ($signer->document) {
            $this->expireIfDue($signer->document);
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
        // Expiry is checked HERE, not only when a link is opened: a signing request must never succeed past the window.
        if (!$doc->isWebdoc() && $doc->expires_at && $doc->expires_at->isPast()) {
            return 'This signing link has expired. Ask the sender for a new one.';
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
        // One 'viewed' line per signer per hour — a reloading signer must not bloat the audit trail (and the sealed certificate).
        $recent = Event::where('document_id', $signer->document_id)->where('signer_id', $signer->id)->where('event', 'viewed')
            ->where('created_at', '>=', now()->subHour())->exists();
        if (!$recent) {
            $this->log($signer->document, 'viewed', null, $signer, null, $ip);
        }
    }

    /**
     * @param array{typed_name:string,id_number?:?string,signature?:?string,fields?:array<int|string,?string>} $input
     * @throws \DomainException
     */
    public function sign(string $token, array $input, ?string $ip, ?string $userAgent): Document
    {
        try {
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
                $signature = $this->cleanSignature($input['signature'] ?? null); // validated BEFORE anything is stored or sealed
                foreach ($fieldIds as $fid => $val) {
                    FieldValue::updateOrCreate(['signer_id' => $signer->id, 'field_id' => $fid], ['document_id' => $doc->id, 'value' => $val]);
                }

                $signer->fill([
                    'status' => 'signed', 'signed_at' => now(), 'typed_name' => trim($input['typed_name']),
                    'id_number' => $idNumber, 'signature_image' => $signature,
                    'signed_ip' => $ip, 'signed_user_agent' => Str::limit((string) $userAgent, 480, ''),
                    'consent_text_snapshot' => self::CONSENT,
                ])->save();
                $this->log($doc, 'signed', $signer->role_label . ' signed by ' . $signer->typed_name, $signer, null, $ip);

                $remaining = $doc->signers()->where('status', '!=', 'signed')->count();
                $doc->update(['status' => $remaining ? 'in_progress' : 'completed', 'completed_at' => $remaining ? null : now()]);

                return [$doc->fresh(), $remaining === 0];
            });
        } catch (\DomainException $e) {
            // The window may have passed since the link was last opened: record it (the rolled-back transaction could not).
            $s = Signer::where('token', $token)->first();
            if ($s?->document) {
                $this->expireIfDue($s->document);
            }
            throw $e;
        }

        [$doc, $done] = $result;
        // The signature is committed. Nothing that happens next (sealing, email, timeline) may turn into an error page for the signer.
        try {
            if ($done) {
                $this->complete($doc);
            } else {
                $this->inviteDue($doc);
            }
        } catch (\Throwable $e) {
            Log::error('Platform e-sign: post-signing step failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $this->log($doc, $done ? 'seal_failed' : 'email_failed', 'A step after signing failed — use Re-seal / Resend', null, null, $ip);
        }

        return $doc;
    }

    public function decline(string $token, string $reason, ?string $ip): Document
    {
        $doc = DB::transaction(function () use ($token, $reason, $ip) {
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
        $this->unlinkFromTimelines($doc, 'declined', null);

        return $doc;
    }

    /**
     * Seal, store the PDF, email copies, tell the timeline.
     * $notify = false seals without emailing anyone (a forced re-seal over a copy the signers already hold).
     * Never throws: the signatures are already committed, so a failure here leaves the document 'completed' with a `seal_failed`
     * event and the owner's Re-seal button — never an error page for the last signer.
     */
    public function complete(Document $doc, bool $notify = true): void
    {
        $this->sealDocument($doc);
        if ($notify) {
            try {
                $this->mailSigned($doc->fresh(['signers', 'agency']));
            } catch (\Throwable $e) {
                Log::error('Platform e-sign: signed-copy emails failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            }
        }
        // Timeline integration: if this is the agency's linked agreement, its "sign agreement" step ticks.
        try {
            AgencyTimeline::where('agreement_document_id', $doc->id)->get()->each(fn ($t) => $this->timelines->syncAgreement($t));
        } catch (\Throwable $e) {
            Log::error('Platform e-sign: timeline sync after completion failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $this->log($doc, 'timeline_sync_failed', 'The agency timeline could not be updated — it will catch up when the timeline is next opened');
        }
    }

    /** Build and store the sealed PDF. @return bool sealed */
    private function sealDocument(Document $doc): bool
    {
        try {
            $fresh = $doc->fresh(['signers', 'events', 'agency', 'values']);
            if ($fresh->content_hash && $this->contentIntact($fresh) === false) {
                $this->log($doc, 'content_hash_mismatch', 'What was sent no longer matches the fingerprint recorded at send — sealing refused');
                throw new \DomainException('Content fingerprint mismatch.');
            }
            $pdf = $this->seal->build($fresh);
            $path = 'platform-esign/documents/' . $doc->id . '/signed-' . $doc->id . '.pdf';
            Storage::disk(self::DISK)->put($path, $pdf);
            $hash = hash('sha256', $pdf);
            $doc->update(['sealed_pdf_path' => $path, 'document_hash' => $hash]);
            $this->log($doc, 'sealed', 'Sealed PDF created (SHA-256 ' . $hash . ')');

            return true;
        } catch (\Throwable $e) {
            Log::error('Platform e-sign seal failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $this->log($doc, 'seal_failed', 'Sealing failed — use Re-seal');

            return false;
        }
    }

    /**
     * Re-run sealing for a completed document whose PDF failed.
     * An already-sealed document is REFUSED: its PDF has been emailed to every signer, and a rebuild would overwrite that evidence with
     * a file carrying a different hash (the PDF embeds a timestamp) and a different record. Only the platform owner may force it, and
     * then the previous file is kept as a superseded version, the full hash of both is on the audit trail, and nobody is re-emailed.
     *
     * @throws \DomainException
     */
    public function reseal(Document $doc, bool $force = false, ?int $userId = null): void
    {
        if ($doc->status !== 'completed') {
            throw new \DomainException('Only a fully signed document can be sealed.');
        }
        $disk = Storage::disk(self::DISK);
        if (!$doc->sealed_pdf_path || !$disk->exists($doc->sealed_pdf_path)) {
            $this->complete($doc); // never sealed (or the file is gone): this is the recovery path, signers get their copy
            $this->log($doc, 'resealed', 'Sealed copy rebuilt after a failed or missing seal', null, $userId);

            return;
        }
        if (!$force) {
            $this->log($doc, 'reseal_refused', 'Re-seal refused: already sealed (SHA-256 ' . $doc->document_hash . ')', null, $userId);

            throw new \DomainException('This document is already sealed and its signed copy has been sent to the signers, so it cannot be re-sealed. Download the existing copy instead.');
        }
        $owner = $userId ? \App\Models\User::withoutGlobalScopes()->find($userId) : null;
        if (!$owner || !$owner->isOwnerRole()) {
            throw new \DomainException('Only the platform owner can force a re-seal.');
        }
        $keep = 'platform-esign/documents/' . $doc->id . '/signed-' . $doc->id . '-superseded-' . now()->format('YmdHis') . '.pdf';
        $disk->copy($doc->sealed_pdf_path, $keep);
        $this->log($doc, 'reseal_forced', 'Owner forced a re-seal. Previous copy kept as ' . basename($keep) . ' (SHA-256 ' . $doc->document_hash . ')', null, $userId);
        $this->complete($doc, false); // signers already hold the earlier copy — no re-email
    }

    private function mailSigned(Document $doc): void
    {
        if ($doc->isWebdoc()) {
            $this->mailAgreementCompleted($doc);

            return;
        }
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

    /**
     * The Subscription Agreement is fully signed: tell both sides, with a secure link and NEVER an attachment — the agreement holds the
     * agency's bank details, and "from here it stays inside CoreX" (spec §11.15). The agency's link is its own token link; RR's opens the
     * owner-gated document screen. $only = 'agency' | 'rr' sends just that side (link re-issue).
     */
    public function mailAgreementCompleted(Document $doc, ?string $only = null): void
    {
        $doc->load('signers', 'agency'); // always the CURRENT signer rows: the agency link in the mail is the token issued at completion
        $agency = $doc->signers->firstWhere('role_key', 'r1');
        $rr = [];
        if ($only !== 'agency') {
            $rr = [$doc->signers->firstWhere('role_key', 'r2')?->email];
            if ($doc->created_by) {
                $rr[] = \App\Models\User::withoutGlobalScopes()->where('id', $doc->created_by)->value('email');
            }
            $rr = array_values(array_unique(array_filter(array_map(fn ($e) => $e ? strtolower($e) : null, $rr))));
        }
        $sent = [];
        if ($only !== 'rr' && $agency && $agency->email) {
            $sent[] = [strtolower($agency->email), new \App\Mail\PlatformEsign\AgreementSignedMail($doc, 'agency', route('platform-esign.agreement.show', $agency->token))];
        }
        foreach ($rr as $addr) {
            $sent[] = [$addr, new \App\Mail\PlatformEsign\AgreementSignedMail($doc, 'rr', route('platform-esign.documents.show', $doc->id))];
        }
        foreach ($sent as [$addr, $mail]) {
            try {
                Mail::mailer('corex')->to($addr)->send($mail);
            } catch (\Throwable $e) {
                Log::error('Platform e-sign agreement completion notice failed', ['document_id' => $doc->id, 'to' => $addr, 'error' => $e->getMessage()]);
            }
        }
        $this->log($doc, 'signed_copy_sent', 'Completion notice with a secure link emailed (no attachment)');
    }

    /** Largest drawn signature we accept, in pixels. The pad is ~1720 x 280; anything bigger is not a signature pad's output. */
    private const SIGNATURE_MAX_W = 2000;
    private const SIGNATURE_MAX_H = 1000;

    /** Is this a real PNG data-URI of sane size and pixel dimensions? (Also used by the sealer to skip any legacy oversized image.) */
    public static function signatureUsable(?string $uri): bool
    {
        if (!$uri || !str_starts_with($uri, 'data:image/png;base64,') || strlen($uri) > 400_000) {
            return false;
        }
        $bin = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
        $info = $bin === false ? false : @getimagesizefromstring($bin);

        return (bool) $info && ($info[2] ?? null) === IMAGETYPE_PNG && $info[0] >= 1 && $info[1] >= 1
            && $info[0] <= self::SIGNATURE_MAX_W && $info[1] <= self::SIGNATURE_MAX_H;
    }

    /**
     * The drawn signature, validated BEFORE anything stores or seals it: a real PNG data-URI, at most 400 KB, with sane pixel
     * dimensions (a tiny file can declare a huge canvas that the PDF renderer would try to allocate in the signer's request).
     * Empty = no drawn signature (the typed name still signs). Anything else that is not valid is refused with a clear message.
     *
     * @throws \DomainException
     */
    private function cleanSignature(?string $uri): ?string
    {
        $uri = trim((string) $uri);
        if ($uri === '') {
            return null;
        }
        if (!self::signatureUsable($uri)) {
            throw new \DomainException('Your drawn signature could not be used. Clear the box and draw it again, or leave it empty to sign with your typed name.');
        }

        return $uri;
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
