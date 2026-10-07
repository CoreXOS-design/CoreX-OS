<?php

namespace App\Models\Platform;

use App\Support\Platform\SafeHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The platform company record (RR Technologies / CoreX OS). EXACTLY ONE ROW, not an agency, no agency_id.
 *
 * STABLE INTERFACE (other lanes call these — do not rename):
 *   PlatformCompany::current()                 the one record (never null)
 *   ->letterheadHtml(string $context = 'web')  'web' | 'pdf' header block, self-contained inline styles
 *   ->letterheadFooterHtml(string $context)    footer text block
 *   ->emailSignatureHtml()                     sanitised signature (generated default when none saved)
 *   ->logoUrl() / ->logoDataUri()              streamed URL / base64 data URI
 *
 * Spec: .ai/specs/platform-company-profile.md §4.
 */
class PlatformCompany extends Model
{
    protected $table = 'platform_company';

    protected $fillable = [
        'legal_name', 'trading_name', 'registration_number', 'vat_registered', 'vat_number', 'directors',
        'physical_address', 'postal_address', 'email_general', 'email_support', 'email_accounts', 'send_from_address', 'send_from_name', 'phones',
        'websites', 'strap_line', 'letterhead_footer', 'email_signature_html', 'bank_details', 'logo_id',
    ];

    protected $casts = [
        'vat_registered' => 'boolean',
        'directors'      => 'array',
        'phones'         => 'array',
        'websites'       => 'array',
        'bank_details'   => 'encrypted:array',
        'version'        => 'integer',
    ];

    /** Values the row is (re)created with if it is ever missing — identical to the migration seed. */
    public const SEED = [
        'singleton'           => 1,
        'legal_name'          => 'RR Technologies (Pty) Ltd',
        'trading_name'        => 'CoreX OS',
        'registration_number' => '2026 / 444132 / 07',
        'vat_registered'      => false,
        'directors'           => [['name' => 'Johan Reichel', 'title' => 'Director'], ['name' => 'Andre Roets', 'title' => 'Director']],
        'physical_address'    => "3123 San Lameer\nR61 Lower South Coast Road\nSouthbroom, KZN\n4277",
        'email_general'       => 'admin@corexos.co.za',
        'email_support'       => 'support@corexos.co.za',
        'send_from_address'   => 'admin@corexos.co.za',
        'send_from_name'      => 'CoreX OS — RR Technologies',
        'phones'              => [
            ['label' => 'Telephone', 'number' => '(039) 004 0125'],
            ['label' => 'Cell', 'number' => '076 618 5578'],
            ['label' => 'Support line', 'number' => '076 423 2426'],
        ],
        'websites'            => ['www.corexweb.co.za', 'www.corexos.co.za'],
        'version'             => 1,
    ];

    public const BUILT_IN_LOGO = 'images/corex-os-logo.svg';

    /** The fields a sent document pins (never bank details — they are not part of any letterhead or contract). */
    public const SNAPSHOT_FIELDS = [
        'legal_name', 'trading_name', 'registration_number', 'vat_registered', 'vat_number', 'directors', 'physical_address',
        'postal_address', 'email_general', 'email_support', 'email_accounts', 'phones', 'websites', 'strap_line',
        'letterhead_footer', 'email_signature_html', 'logo_id',
    ];

    public function logo()
    {
        return $this->belongsTo(PlatformCompanyLogo::class, 'logo_id')->withTrashed();
    }

    /** The one record. Self-heals to the seeded values if the row is somehow missing. */
    public static function current(): static
    {
        return static::query()->first()
            ?? static::query()->forceCreate(static::SEED);
    }

    /** The company as it stands now, as a plain array a sent document stores (spec §7a). */
    public function snapshot(): array
    {
        return $this->only(self::SNAPSHOT_FIELDS);
    }

    /** An unsaved company rebuilt from a stored snapshot — every helper (letterhead, logo, lists) works on it unchanged. */
    public static function fromSnapshot(array $snap): static
    {
        $c = new static();
        $c->forceFill(array_intersect_key($snap, array_flip(self::SNAPSHOT_FIELDS)));

        return $c;
    }

    /** An unsaved copy of the current record with $attrs applied — for the live preview. Never persisted. */
    public static function preview(array $attrs): static
    {
        $copy = static::current()->replicate();
        $copy->logo_id = static::current()->logo_id;
        $copy->fill($attrs);

        return $copy;
    }

    // ── Logo ───────────────────────────────────────────────────────────────

    /**
     * May this logo version be streamed from the PUBLIC logo URL? Only the built-in logo (0), the current logo, and a version a
     * Platform E-Sign document was sent with (its pinned snapshot — old emails keep rendering). Any other uploaded version — a
     * superseded or mistaken upload — is not reachable by guessing sequential ids (A-F3).
     */
    public static function logoIsPublic(int $logoId): bool
    {
        if ($logoId <= 0 || (int) static::current()->logo_id === $logoId) {
            return true;
        }

        return \App\Models\PlatformEsign\Document::withTrashed()->where('company_snapshot->logo_id', $logoId)->exists();
    }

    /** @return array{bytes:string, mime:string} the current logo, falling back to the built-in asset. */
    public function logoFile(): array
    {
        $logo = $this->logo_id ? PlatformCompanyLogo::withTrashed()->find($this->logo_id) : null;
        if ($logo && Storage::disk('local')->exists($logo->path)) {
            return ['bytes' => Storage::disk('local')->get($logo->path), 'mime' => $logo->mime];
        }

        return ['bytes' => (string) @file_get_contents(public_path(self::BUILT_IN_LOGO)), 'mime' => 'image/svg+xml'];
    }

    // ── Letterhead logo sizing — ONE rule for every place the letterhead is used (spec platform-company-profile §4a) ──────────────
    /** Display height on screen (px) and the same height in the PDFs (pt: 60px = 45pt). */
    public const LOGO_HEIGHT_PX = 60;
    public const LOGO_HEIGHT_PT = 45.0;
    /** A logo is never wider than this share of the header. */
    public const LOGO_MAX_SHARE = 0.45;
    /** A4 page content width between the PDF margins (595 − 2×46 pt). */
    public const PDF_HEADER_WIDTH_PT = 503.0;

    /** @return array{ratio:float,nat_w:?int,nat_h:?int} natural size of the logo in pixels (null for SVG, which is vector) and its width:height ratio */
    public function logoMetrics(): array
    {
        $f = $this->logoFile();
        if (str_contains($f['mime'], 'svg')) {
            $ratio = 0.0;
            if (preg_match('/viewBox\s*=\s*"[\s,]*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $f['bytes'], $m) && (float) $m[2] > 0) {
                $ratio = (float) $m[1] / (float) $m[2];
            } elseif (preg_match('/\swidth\s*=\s*"([\d.]+)/i', $f['bytes'], $w) && preg_match('/\sheight\s*=\s*"([\d.]+)/i', $f['bytes'], $h) && (float) $h[1] > 0) {
                $ratio = (float) $w[1] / (float) $h[1];
            }

            return ['ratio' => $ratio > 0 ? $ratio : 3.75, 'nat_w' => null, 'nat_h' => null];
        }
        $info = @getimagesizefromstring($f['bytes']);
        if ($info && $info[0] > 0 && $info[1] > 0) {
            return ['ratio' => $info[0] / $info[1], 'nat_w' => (int) $info[0], 'nat_h' => (int) $info[1]];
        }

        return ['ratio' => 3.75, 'nat_w' => null, 'nat_h' => null];
    }

    /**
     * Letterhead logo size on screen: fixed height (60px, or the image's own height if it is smaller — never upscaled), width follows the shape,
     * capped at 45% of the header so it can never squeeze the company details beside it.
     *
     * @return array{w:int,h:int}
     */
    public function logoSizePx(float $headerWidthPx = 760.0): array
    {
        $m = $this->logoMetrics();
        $h = (float) self::LOGO_HEIGHT_PX;
        if ($m['nat_h'] !== null) {
            $h = min($h, (float) $m['nat_h']);
        }
        $w = $h * $m['ratio'];
        $cap = $headerWidthPx * self::LOGO_MAX_SHARE;
        if ($w > $cap) {
            $h = $h * $cap / $w;
            $w = $cap;
        }

        return ['w' => (int) max(1, round($w)), 'h' => (int) max(1, round($h))];
    }

    /**
     * The same rule for the PDFs, in points: fixed 45pt height (never above the image's natural size at 96dpi), width from the shape, capped at 45%
     * of the header. A raster logo is embedded at its full resolution (DomPDF scales it down), so it stays crisp; an SVG stays vector.
     *
     * @return array{w:float,h:float}
     */
    public function logoBoxPt(?float $height = null, ?float $maxWidth = null): array
    {
        $m = $this->logoMetrics();
        $h = $height ?? self::LOGO_HEIGHT_PT;
        if ($m['nat_h'] !== null) {
            $h = min($h, $m['nat_h'] * 0.75);
        }
        $w = $h * $m['ratio'];
        $cap = $maxWidth ?? (self::PDF_HEADER_WIDTH_PT * self::LOGO_MAX_SHARE);
        if ($w > $cap) {
            return ['w' => round($cap, 2), 'h' => round($cap / $m['ratio'], 2)];
        }

        return ['w' => round($w, 2), 'h' => round($h, 2)];
    }

    /** Absolute, streamed, public. `l` names the exact logo version (0 = built-in), so a pinned document keeps its own logo. */
    public function logoUrl(): string
    {
        return route('platform-company.logo', ['l' => $this->logo_id ?: 0]);
    }

    /** Longest side (px) a raster logo is embedded at in a PDF. The letterhead shows it at 45pt high, so this is still crisp. */
    public const PDF_LOGO_MAX_SIDE_PX = 1000;

    /**
     * Self-contained logo for PDFs — the renderer never has to fetch anything over the network.
     * A raster logo larger than PDF_LOGO_MAX_SIDE_PX is downscaled first, so DomPDF never decodes a huge bitmap (A-F5).
     */
    public function logoDataUri(): string
    {
        $f = $this->logoFile();
        $bytes = $f['bytes'];

        if (! str_contains($f['mime'], 'svg') && function_exists('imagecreatefromstring')) {
            $info = @getimagesizefromstring($bytes);
            $max = self::PDF_LOGO_MAX_SIDE_PX;
            if ($info && max($info[0], $info[1]) > $max) {
                $src = @imagecreatefromstring($bytes);
                if ($src !== false) {
                    $scale = $max / max($info[0], $info[1]);
                    $dst = imagescale($src, max(1, (int) round($info[0] * $scale)), max(1, (int) round($info[1] * $scale)));
                    if ($dst !== false) {
                        imagealphablending($dst, false);
                        imagesavealpha($dst, true);
                        ob_start();
                        $ok = str_contains($f['mime'], 'png') ? imagepng($dst) : imagejpeg($dst, null, 90);
                        $small = (string) ob_get_clean();
                        if ($ok && $small !== '') {
                            $bytes = $small;
                        }
                    }
                }
            }
        }

        return 'data:' . $f['mime'] . ';base64,' . base64_encode($bytes);
    }

    // ── Rendering ──────────────────────────────────────────────────────────

    /**
     * Header block. $context: 'web' (responsive, logo by URL) | 'pdf' (print layout, logo embedded).
     * $embedLogo = false keeps the 'pdf' layout but references the logo by URL — for on-screen previews, which must not
     * carry the logo bytes inside the HTML.
     */
    public function letterheadHtml(string $context = 'web', bool $embedLogo = true): string
    {
        $context = $context === 'pdf' ? 'pdf' : 'web';

        return trim(view('platform-company.letterhead', ['c' => $this, 'context' => $context, 'inline' => $embedLogo])->render());
    }

    public function letterheadFooterHtml(string $context = 'web'): string
    {
        $context = $context === 'pdf' ? 'pdf' : 'web';
        $text = $this->letterheadFooterText();
        if ($text === '') {
            return '';
        }
        $size = $context === 'pdf' ? '8pt' : '11px';

        return '<div style="border-top:1px solid #d1d5db; margin-top:14px; padding-top:6px; font-family:Arial,Helvetica,sans-serif; font-size:'
            . $size . '; line-height:1.5; color:#6b7280; text-align:center;">' . nl2br(e($text)) . '</div>';
    }

    /** Saved footer text, else a generated one from the legal facts (so a footer is never blank). */
    public function letterheadFooterText(): string
    {
        $saved = trim((string) $this->letterhead_footer);
        if ($saved !== '') {
            return $saved;
        }

        $parts = [trim((string) $this->legal_name)];
        if (trim((string) $this->registration_number) !== '') {
            $parts[] = 'Reg. no ' . trim($this->registration_number);
        }
        if ($this->vat_registered && trim((string) $this->vat_number) !== '') {
            $parts[] = 'VAT no ' . trim($this->vat_number);
        }
        $dirs = $this->directorNames();
        if ($dirs) {
            $parts[] = (count($dirs) > 1 ? 'Directors: ' : 'Director: ') . implode(', ', $dirs);
        }

        return implode(' · ', array_filter($parts));
    }

    /**
     * The From of every Platform E-Sign / Subscription Agreement email — the company's own sending address and name, never
     * the box-wide MAIL_FROM_* (that belongs to whichever agency the install was first set up for).
     * Deliberately NOT pinned to a sent document: it is operational (a mailbox that gets changed), so reminders sent later
     * always use today's address.
     */
    public function mailFrom(): \Illuminate\Mail\Mailables\Address
    {
        $address = filter_var(trim((string) $this->send_from_address), FILTER_VALIDATE_EMAIL) ?: static::SEED['send_from_address'];
        $name = trim((string) $this->send_from_name) ?: static::SEED['send_from_name'];

        return new \Illuminate\Mail\Mailables\Address($address, $name);
    }

    public function emailSignatureHtml(): string
    {
        $saved = SafeHtml::clean($this->email_signature_html);

        return $saved !== '' ? $saved : $this->defaultEmailSignatureHtml();
    }

    /** The standard signature built from the record — also what the editor's "Use standard signature" inserts. */
    public function defaultEmailSignatureHtml(): string
    {
        return trim(view('platform-company.email-signature', ['c' => $this])->render());
    }

    // ── Presentation helpers (also used by the views) ──────────────────────

    /** @return list<string> */
    public function directorNames(): array
    {
        return array_values(array_filter(array_map(
            fn ($d) => trim((string) (is_array($d) ? ($d['name'] ?? '') : $d)),
            (array) $this->directors
        )));
    }

    /** @return list<array{label:string, number:string}> phones with a number only. */
    public function phoneList(): array
    {
        $out = [];
        foreach ((array) $this->phones as $p) {
            $number = trim((string) ($p['number'] ?? ''));
            if ($number !== '') {
                $out[] = ['label' => trim((string) ($p['label'] ?? '')), 'number' => $number];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function websiteList(): array
    {
        return array_values(array_filter(array_map(fn ($w) => trim((string) $w), (array) $this->websites)));
    }

    /** @return list<string> non-empty address lines of the physical address. */
    public function addressLines(?string $which = null): array
    {
        $raw = (string) ($which === 'postal' ? $this->postal_address : $this->physical_address);

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
    }

    /** @return list<string> every non-empty email address, general first. */
    public function emailList(): array
    {
        return array_values(array_unique(array_filter(array_map('trim', [
            (string) $this->email_general, (string) $this->email_support, (string) $this->email_accounts,
        ]))));
    }

    public static function websiteHref(string $site): string
    {
        return preg_match('#^https?://#i', $site) ? $site : 'https://' . $site;
    }
}
