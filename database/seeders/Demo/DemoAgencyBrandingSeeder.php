<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Configuration sweep (2026-09-02, webinar prep, Johan) — "we seeded RECORDS
 * all week and never checked CONFIGURATION." Agency-level company/branding
 * fields were entirely NULL despite the identity numbers (reg_no/vat_no/
 * ffc_no/fic_no) being set — reg/vat/ffc/fic came from an earlier stage of
 * DemoDataSeeder, but tagline/address/phone/email/logo/proforma banking
 * were never touched by anything. Confirmed live: every generated CDS
 * document's letterhead (company-header.blade.php) rendered "Email
 * Address:" and "Cell:" with nothing after the label, and the settings page
 * showed a bare file picker with no logo.
 *
 * IDEMPOTENT BY CONSTRUCTION — every field is only ever set when its current
 * value is null/empty, so a manual edit by Johan (or a re-run after
 * demo:seed) is never overwritten. Logo generation is skipped once
 * logo_path is set AND the file already exists on disk.
 */
class DemoAgencyBrandingSeeder
{
    /** @return array{fields_set:int, logo_generated:bool, banking_set:bool, note:string} */
    public function run(int $agencyId = 1): array
    {
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        if (!$agency) {
            return ['fields_set' => 0, 'logo_generated' => false, 'banking_set' => false, 'note' => "Skipped — agency {$agencyId} not found."];
        }

        $margateBranchId = DB::table('branches')->where('agency_id', $agencyId)->where('name', 'Margate')->value('id');

        $desired = [
            'tagline'                  => 'Your Trusted Partner on the KZN South Coast',
            'address'                  => '14 Marine Drive, Margate, KwaZulu-Natal, 4275',
            'phone'                    => '039 312 0000',
            'phone_label'              => 'Office',
            'fax'                      => '039 312 0001',
            'email'                    => 'info@corexdemorealty.co.za',
            'ppra_number'              => 'PPRA-DEMO-000001',
            'ncc_registration_number'  => 'NCC-DEMO-00001',
            'public_contact'           => 'Client Services, CoreX Demo Realty — 039 312 0000',
            'email_disclaimer'         => 'This email and any attachments are confidential and intended solely for the '
                . 'addressee. If you are not the intended recipient, please notify the sender and delete this message. '
                . 'CoreX Demo Realty (Pty) Ltd accepts no liability for any loss or damage arising from reliance on this '
                . 'communication.',
            'popi_url'                 => 'https://corexdemorealty.co.za/privacy-policy',
            'privacy_policy_markdown'  => "# Privacy Policy — CoreX Demo Realty\n\n"
                . "CoreX Demo Realty (Pty) Ltd is committed to protecting your personal information in line with the "
                . "Protection of Personal Information Act (POPIA).\n\n"
                . "## What we collect\nContact details, property preferences, and transaction records you share with us "
                . "as part of buying, selling, or renting property through our agency.\n\n"
                . "## How we use it\nTo match you with suitable properties, process transactions, meet our FICA/PPRA "
                . "compliance obligations, and communicate with you about your enquiry or listing.\n\n"
                . "## Your rights\nYou may request access to, correction of, or deletion of your personal information "
                . "at any time by contacting our Information Officer.\n",
        ];
        if ($margateBranchId) {
            $desired['default_branch_id'] = $margateBranchId;
        }

        $update = [];
        foreach ($desired as $field => $value) {
            $current = $agency->{$field} ?? null;
            if ($current === null || trim((string) $current) === '') {
                $update[$field] = $value;
            }
        }

        $fieldsSet = 0;
        if (!empty($update)) {
            $update['updated_at'] = now();
            DB::table('agencies')->where('id', $agencyId)->update($update);
            $fieldsSet = count($update) - 1; // exclude updated_at from the count
        }

        // Privacy policy needs a token + published_at to actually be reachable —
        // set alongside the markdown, only when both are currently unset.
        if (isset($update['privacy_policy_markdown']) && empty($agency->privacy_policy_token)) {
            DB::table('agencies')->where('id', $agencyId)->update([
                'privacy_policy_token'        => bin2hex(random_bytes(16)),
                'privacy_policy_published_at' => now(),
            ]);
        }

        $logoGenerated = $this->ensureLogo($agencyId);

        $bankingSet = $this->ensureProformaBanking($agencyId);

        $note = "Agency branding: {$fieldsSet} fields set, logo " . ($logoGenerated ? 'generated' : 'already present') . ', banking ' . ($bankingSet ? 'set' : 'already present') . '.';

        return ['fields_set' => $fieldsSet, 'logo_generated' => $logoGenerated, 'banking_set' => $bankingSet, 'note' => $note];
    }

    /** @return bool true if a logo was newly generated this run */
    private function ensureLogo(int $agencyId): bool
    {
        $existingPath = DB::table('agencies')->where('id', $agencyId)->value('logo_path');
        if ($existingPath && Storage::disk('public')->exists($existingPath)) {
            return false;
        }

        if (!function_exists('imagecreatetruecolor')) {
            return false; // GD not available on this PHP build — skip, don't fail the whole seed run
        }

        $width = 760;
        $height = 200;
        $im = imagecreatetruecolor($width, $height);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);

        // Brand colours already configured on the agency (sidebar/default).
        $navy = imagecolorallocate($im, 11, 42, 74);   // #0b2a4a — default_color
        $sky  = imagecolorallocate($im, 14, 165, 233);  // #0ea5e9 — sidebar/icon/button color

        // Simple mark: a rounded navy square with a sky-blue roofline triangle
        // (a house glyph), plus the wordmark text — a generic, obviously-
        // synthetic real-estate crest, nothing borrowed from any real brand.
        imagefilledrectangle($im, 20, 30, 140, 150, $navy);
        $roof = [20, 60, 80, 15, 140, 60];
        imagefilledpolygon($im, $roof, 3, $sky);
        imagefilledrectangle($im, 60, 90, 100, 150, $sky);

        $font = $this->firstExistingFont([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ]);

        if ($font) {
            imagettftext($im, 30, 0, 175, 95, $navy, $font, 'CoreX Demo Realty');
            imagettftext($im, 14, 0, 175, 130, $sky, $font, 'Your Trusted KZN South Coast Agency');
        } else {
            imagestring($im, 5, 175, 85, 'CoreX Demo Realty', $navy);
            imagestring($im, 3, 175, 115, 'Demo Property Group', $sky);
        }

        $path = "agencies/{$agencyId}/logo.png";
        $tmp = tempnam(sys_get_temp_dir(), 'demo_logo_') . '.png';
        imagepng($im, $tmp);
        imagedestroy($im);

        Storage::disk('public')->put($path, file_get_contents($tmp));
        @unlink($tmp);

        DB::table('agencies')->where('id', $agencyId)->update(['logo_path' => $path, 'updated_at' => now()]);

        return true;
    }

    private function firstExistingFont(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /** @return bool true if bank_details was newly set this run */
    private function ensureProformaBanking(int $agencyId): bool
    {
        $row = DB::table('agency_proforma_settings')->where('agency_id', $agencyId)->first();
        if (!$row) {
            return false; // AgencyProformaSettings::forAgency() creates this lazily elsewhere; nothing to top up yet
        }
        if (!empty(trim((string) ($row->bank_details ?? '')))) {
            return false;
        }

        $bankDetails = "Bank: First National Bank (FNB)\n"
            . "Account Name: CoreX Demo Realty Trust Account\n"
            . "Account Number: 000000000\n"
            . "Branch Code: 250655\n"
            . "Reference: Invoice number";

        DB::table('agency_proforma_settings')->where('agency_id', $agencyId)->update([
            'bank_details' => $bankDetails,
            'updated_at'   => now(),
        ]);

        return true;
    }
}
