<?php

namespace App\Services\Platform;

use App\Models\Platform\PlatformCompany;
use App\Models\Platform\PlatformCompanyAudit;
use App\Models\Platform\PlatformCompanyLogo;
use App\Models\User;
use App\Support\Platform\SafeHtml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves the platform company record: normalise → (controller validates) → transaction { lock, version check, diff,
 * update, audit }. A failed save leaves nothing behind. Spec: .ai/specs/platform-company-profile.md §5.
 */
class PlatformCompanyService
{
    /** Human labels — used in the audit summary and the History screen. */
    public const LABELS = [
        'legal_name' => 'Legal name', 'trading_name' => 'Trading name / brand', 'registration_number' => 'Registration number',
        'vat_registered' => 'VAT registered', 'vat_number' => 'VAT number', 'directors' => 'Directors',
        'physical_address' => 'Physical address', 'postal_address' => 'Postal address',
        'email_general' => 'General / admin email', 'email_support' => 'Support email', 'email_accounts' => 'Accounts email',
        'send_from_address' => 'Sending address (emails are sent from)', 'send_from_name' => 'Sender name (emails are sent as)',
        'phones' => 'Telephone numbers', 'websites' => 'Websites', 'strap_line' => 'Letterhead strap line',
        'letterhead_footer' => 'Letterhead footer', 'email_signature_html' => 'Email signature', 'bank_details' => 'Bank details',
        'logo_id' => 'Logo',
    ];

    private const SECTION_KEYS = [
        'legal_name', 'trading_name', 'registration_number', 'vat_registered', 'vat_number', 'directors', 'physical_address',
        'postal_address', 'email_general', 'email_support', 'email_accounts', 'send_from_address', 'send_from_name', 'phones', 'websites', 'strap_line',
        'letterhead_footer', 'email_signature_html', 'bank_details',
    ];

    public const BANK_FIELDS = ['bank_name', 'account_holder', 'account_number', 'branch_code', 'account_type', 'reference_note'];

    /**
     * Trim / tidy raw request input (input-space rule: whitespace, blank optional rows, case). Only keys present in $in
     * are returned, so a form that does not render a field can never blank it. Safe to call on unvalidated input (preview).
     */
    public function normalise(array $in): array
    {
        $out = [];
        $str = fn ($v) => ($t = trim((string) $v)) === '' ? null : $t;

        foreach (['legal_name', 'trading_name', 'registration_number', 'strap_line', 'send_from_name'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = $str($in[$k]);
            }
        }
        if (array_key_exists('vat_registered', $in)) {
            $out['vat_registered'] = filter_var($in['vat_registered'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('vat_number', $in)) {
            $v = $str(preg_replace('/\s+/', '', (string) $in['vat_number']));
            $out['vat_number'] = $v;
        }
        foreach (['physical_address', 'postal_address', 'letterhead_footer'] as $k) {
            if (array_key_exists($k, $in)) {
                $lines = array_map('trim', preg_split('/\r\n|\r|\n/', (string) $in[$k]) ?: []);
                $out[$k] = $k === 'letterhead_footer' ? $str(implode("\n", $lines)) : $str(implode("\n", array_filter($lines, fn ($l) => $l !== '')));
            }
        }
        foreach (['email_general', 'email_support', 'email_accounts', 'send_from_address'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = ($v = $str($in[$k])) === null ? null : mb_strtolower($v);
            }
        }
        if (array_key_exists('directors', $in)) {
            $out['directors'] = collect((array) $in['directors'])
                ->map(fn ($d) => ['name' => trim((string) ($d['name'] ?? '')), 'title' => trim((string) ($d['title'] ?? ''))])
                ->filter(fn ($d) => $d['name'] !== '')->values()->all();
        }
        if (array_key_exists('phones', $in)) {
            $out['phones'] = collect((array) $in['phones'])
                ->map(fn ($p) => ['label' => trim((string) ($p['label'] ?? '')), 'number' => trim((string) ($p['number'] ?? ''))])
                ->filter(fn ($p) => $p['number'] !== '')->values()->all();
        }
        if (array_key_exists('websites', $in)) {
            $out['websites'] = collect((array) $in['websites'])
                ->map(fn ($w) => trim((string) $w))->filter(fn ($w) => $w !== '')->values()->all();
        }
        if (array_key_exists('email_signature_html', $in)) {
            $clean = SafeHtml::clean((string) $in['email_signature_html']);
            $out['email_signature_html'] = $clean === '' ? null : $clean;
        }
        if (array_key_exists('bank_details', $in)) {
            $bank = [];
            foreach (self::BANK_FIELDS as $f) {
                $v = trim((string) (((array) $in['bank_details'])[$f] ?? ''));
                if ($f === 'account_number' || $f === 'branch_code') {
                    $v = preg_replace('/[\s\-]+/', '', $v);
                }
                if ($v !== '') {
                    $bank[$f] = $v;
                }
            }
            $out['bank_details'] = $bank ?: null;
        }

        return $out;
    }

    /**
     * Save $data (already normalised + validated) against $expectedVersion.
     *
     * @return array<string,array{from:mixed,to:mixed}> what changed (empty = nothing to save)
     */
    public function save(array $data, int $expectedVersion, User $by): array
    {
        $data = array_intersect_key($data, array_flip(self::SECTION_KEYS));

        return DB::transaction(function () use ($data, $expectedVersion, $by) {
            $c = PlatformCompany::query()->lockForUpdate()->first() ?? PlatformCompany::current();

            if ($expectedVersion !== (int) $c->version) {
                throw ValidationException::withMessages([
                    'version' => 'Another owner saved changes to the company profile while you were editing. Nothing was overwritten — reload the page to see the latest, then re-apply your changes.',
                ]);
            }

            $changes = $this->diff($c, $data);
            if (! $changes) {
                return [];
            }

            $c->fill($data);
            $c->version = (int) $c->version + 1;
            $c->save();

            $this->audit($by, 'updated', 'Changed: ' . implode(', ', array_map(fn ($k) => self::LABELS[$k] ?? $k, array_keys($changes))), $changes);

            return $changes;
        });
    }

    /** Upload a new logo version and make it current. Files written before a failure are removed again. */
    public function uploadLogo(UploadedFile $file, User $by): PlatformCompanyLogo
    {
        $ext = $this->logoExtension($file);
        $mime = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
        $path = 'platform-company/logos/' . Str::uuid() . '.' . $ext;
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        try {
            return DB::transaction(function () use ($file, $by, $path, $mime) {
                $logo = PlatformCompanyLogo::create([
                    'path' => $path, 'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                    'mime' => $mime, 'size' => (int) $file->getSize(), 'uploaded_by' => $by->id,
                ]);
                $c = PlatformCompany::query()->lockForUpdate()->first() ?? PlatformCompany::current();
                $from = $c->logo_id;
                $c->logo_id = $logo->id;
                $c->version = (int) $c->version + 1;
                $c->save();
                $this->audit($by, 'logo_uploaded', 'Uploaded a new logo (' . $logo->original_name . ')', ['logo_id' => ['from' => $from, 'to' => $logo->id]]);

                return $logo;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /** Make an earlier logo (or the built-in CoreX OS logo, $logoId = null) current again. Nothing is deleted. */
    public function restoreLogo(?int $logoId, User $by): void
    {
        DB::transaction(function () use ($logoId, $by) {
            if ($logoId !== null && ! PlatformCompanyLogo::query()->whereKey($logoId)->exists()) {
                throw ValidationException::withMessages(['logo' => 'That logo version no longer exists.']);
            }
            $c = PlatformCompany::query()->lockForUpdate()->first() ?? PlatformCompany::current();
            $from = $c->logo_id;
            if ($from === $logoId) {
                return;
            }
            $c->logo_id = $logoId;
            $c->version = (int) $c->version + 1;
            $c->save();
            $this->audit($by, 'logo_restored', $logoId ? 'Restored an earlier logo' : 'Reverted to the built-in CoreX OS logo', ['logo_id' => ['from' => $from, 'to' => $logoId]]);
        });
    }

    /** Validates type/size/dimensions/safety of an uploaded logo and returns its extension. */
    public function logoExtension(UploadedFile $file): string
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['logo' => $m]);

        if (! $file->isValid()) {
            $fail('The logo upload did not complete. Please try again.');
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            $fail('The logo is larger than 2 MB. Please upload a smaller file.');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (! in_array($ext, ['png', 'jpg', 'svg'], true)) {
            $fail('The logo must be a PNG, JPG or SVG file.');
        }

        if ($ext === 'svg') {
            $svg = (string) file_get_contents($file->getRealPath());
            if ($problem = SafeHtml::svgProblem($svg)) {
                $fail($problem);
            }
            if (! preg_match('/<svg[^>]*\sviewBox\s*=/i', $svg) && ! preg_match('/<svg[^>]*\swidth\s*=[^>]*\sheight\s*=|<svg[^>]*\sheight\s*=[^>]*\swidth\s*=/is', $svg)) {
                $fail("This SVG has no size information, so it cannot be placed reliably. Export it again from your design tool, or upload a PNG.");
            }

            return 'svg';
        }

        $info = @getimagesize($file->getRealPath());
        $type = $info[2] ?? null;
        if (! $info || ! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            $fail('That file is not a valid PNG or JPG image.');
        }
        if ($info[0] < 64 || $info[1] < 32) {
            $fail('The logo is too small — it must be at least 64 pixels wide and 32 pixels tall.');
        }
        if ($info[0] > 6000 || $info[1] > 6000) {
            $fail('The logo is too large — the longest side must be 6000 pixels or less.');
        }

        return $type === IMAGETYPE_PNG ? 'png' : 'jpg';
    }

    public function audit(?User $by, string $action, string $summary, array $changes = []): PlatformCompanyAudit
    {
        return PlatformCompanyAudit::create([
            'user_id' => $by?->id, 'action' => $action, 'summary' => Str::limit($summary, 250, ''),
            'changes' => $changes ?: null, 'created_at' => now(),
        ]);
    }

    /** @return array<string,array{from:mixed,to:mixed}> */
    private function diff(PlatformCompany $c, array $data): array
    {
        $changes = [];
        foreach ($data as $k => $new) {
            $old = $c->getAttribute($k);
            if ($this->same($old, $new)) {
                continue;
            }
            if ($k === 'bank_details') {
                $changes[$k] = ['from' => $old ? 'on file' : 'none', 'to' => $new ? 'updated (values not logged)' : 'removed'];
            } elseif ($k === 'email_signature_html') {
                $changes[$k] = ['from' => Str::limit((string) $old, 1000, '…'), 'to' => Str::limit((string) $new, 1000, '…')];
            } else {
                $changes[$k] = ['from' => $old, 'to' => $new];
            }
        }

        return $changes;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($a ?: null) === json_encode($b ?: null);
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        return (string) $a === (string) $b;
    }
}
