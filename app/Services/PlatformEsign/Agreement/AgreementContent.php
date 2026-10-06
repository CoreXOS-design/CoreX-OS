<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\PlatformEsign\Template;
use App\Models\PlatformEsign\WordingVersion;

/**
 * Seeds the CoreX Subscription Agreement (spec §11.3) from the two signed-off source files, turning blanks into field tokens.
 * Replacements only swap a blank (______, ☐, ……, an empty cell) for a token or insert a token beside existing words;
 * every replacement must match EXACTLY once, so the source can never silently drift from what is stored.
 */
class AgreementContent
{
    public const TEMPLATE_NAME = 'CoreX Subscription Agreement';
    public const VERSION = '1.0';
    public const VERSION_DATE = '2026-09-28';
    public const PARTS = ['intro' => 'Cover', 'part_a' => 'Part A', 'part_b' => 'Part B', 'part_c' => 'Part C', 'part_d' => 'Part D', 'mandate' => 'Debit order mandate'];

    public static function sourcePath(string $file): string
    {
        return resource_path('legal/subscription-agreement/' . $file);
    }

    /** Idempotent: the template + published version 1.0. Safe to call on every request that needs it. */
    public function ensureSeeded(?int $userId = null): WordingVersion
    {
        $tpl = Template::withTrashed()->where('source', 'webdoc')->where('name', self::TEMPLATE_NAME)->first();
        if (!$tpl) {
            $tpl = Template::create([
                'name' => self::TEMPLATE_NAME, 'kind' => 'subscription_agreement', 'source' => 'webdoc', 'body' => null,
                'roles_json' => [['key' => 'r1', 'label' => 'Agency', 'order' => 1], ['key' => 'r2', 'label' => 'Company', 'order' => 2]],
                'version' => 1, 'is_active' => true, 'created_by' => $userId,
            ]);
        }
        $v = WordingVersion::where('template_id', $tpl->id)->where('version', self::VERSION)->first();
        if ($v) {
            return $v;
        }

        return WordingVersion::create([
            'template_id' => $tpl->id, 'version' => self::VERSION, 'version_date' => self::VERSION_DATE,
            'content_json' => $this->buildV1(), 'rates_json' => AgreementPricing::DEFAULT_RATES,
            'is_published' => true, 'published_at' => now(), 'created_by' => $userId,
        ]);
    }

    /** The template of the web document (null until seeded). */
    public static function template(): ?Template
    {
        return Template::where('source', 'webdoc')->where('name', self::TEMPLATE_NAME)->first();
    }

    /** @return array<string,string> part => tokenised markdown */
    public function buildV1(): array
    {
        $agreement = $this->read('agreement-v1.0.md');
        $mandate = $this->read('netcash-mandate-v1.0.md');

        $iA = $this->indexOf($agreement, '# **Part A — Sign-up Form**');
        $iB = $this->indexOf($agreement, '# **Part B — Terms of Service**');
        $iC = $this->indexOf($agreement, '# **Part C — POPIA Operator Terms**');
        $iD = $this->indexOf($agreement, '# **Part D — Support and Acceptable Use**');

        $parts = [
            'intro'   => trim(substr($agreement, 0, $iA)),
            'part_a'  => trim(substr($agreement, $iA, $iB - $iA)),
            'part_b'  => trim(substr($agreement, $iB, $iC - $iB)),
            'part_c'  => trim(substr($agreement, $iC, $iD - $iC)),
            'part_d'  => trim(substr($agreement, $iD)),
            'mandate' => trim($mandate),
        ];
        $parts['part_a'] = $this->tokenisePartA($parts['part_a']);
        $parts['mandate'] = $this->tokeniseMandate($parts['mandate']);

        return $parts;
    }

    private function tokenisePartA(string $s): string
    {
        // The contract reference is shown in Part A (a label + token added directly under the heading; the one addition to Part A).
        $s = $this->once($s, "# **Part A — Sign-up Form**\n", "# **Part A — Sign-up Form**\n\nContract reference number: {{ref}}\n");

        $rows = [
            'Registered name' => '{{f:registered_name}}', 'Trading name' => '{{f:trading_name}}', 'Registration number' => '{{f:reg_no}}',
            'VAT number (if registered)' => '{{f:vat_no}}', 'PPRA Fidelity Fund Certificate number (agency)' => '{{f:ffc_no}}',
            'Physical address (for legal notices)' => '{{f:address}}', 'Email address for notices and invoices' => '{{f:notice_email}}',
            'Principal — full name' => '{{f:principal_name}}', 'Billing contact — name, email, cell' => '{{f:billing_name}} {{f:billing_email}} {{f:billing_cell}}',
            'Number of branches' => '{{f:branches}}', 'Start date' => '{{f:start_date}}',
            'Account holder' => '{{f:da_holder}}', 'Bank and branch code' => '{{f:da_bank}} {{f:da_branch_code}}',
            'Account number' => '{{f:da_account}}', 'Account type' => '{{f:da_type}}',
        ];
        foreach ($rows as $label => $token) {
            $s = $this->once($s, '| **' . $label . '**||', '| **' . $label . '**| ' . $token . '|');
        }

        $s = $this->once($s, '☐ Company, close corporation', '{{o:entity:juristic}} Company, close corporation');
        $s = $this->once($s, '☐ Sole proprietor trading', '{{o:entity:natural}} Sole proprietor trading');
        $s = $this->once($s, '<p>☐ CoreX Team</p>', '<p>{{o:plan:team}} CoreX Team</p>');
        $s = $this->once($s, '<p>☐ CoreX Agency</p>', '<p>{{o:plan:agency}} CoreX Agency</p>');
        $s = $this->once($s, "<td><strong>Branches at start</strong></td>\n<td></td>", "<td><strong>Branches at start</strong></td>\n<td>{{f:branches_start}}</td>");
        $s = $this->once($s, 'recorded here and overrides the published price list:</td>', 'recorded here and overrides the published price list: {{rr:variation_text}}</td>');

        // The number-of-agents control sits in front of the fee table (form modes only; renders nothing in the PDF).
        $s = $this->once($s, "\n|||||\n|---|---|---|---|\n| **Item**|", "\n{{ctl:agents}}\n\n|||||\n|---|---|---|---|\n| **Item**|");

        $fees = [
            '| CoreX Team — per seat (up to 10 seats)| ______| R450| R ______|' => '| CoreX Team — per seat (up to 10 seats)| {{q:team_seats}}| {{rate:team_seat}}| R {{amt:team_seats}}|',
            '| CoreX Agency — base fee| 1| R1 495| R ______|' => '| CoreX Agency — base fee| 1| {{rate:agency_base}}| R {{amt:agency_base}}|',
            '| CoreX Agency — seats 1 to 10| ______| R295| R ______|' => '| CoreX Agency — seats 1 to 10| {{q:agency_t1}}| {{rate:agency_t1}}| R {{amt:agency_t1}}|',
            '| CoreX Agency — seats 11 to 20| ______| R250| R ______|' => '| CoreX Agency — seats 11 to 20| {{q:agency_t2}}| {{rate:agency_t2}}| R {{amt:agency_t2}}|',
            '| CoreX Agency — seats 21 and more| ______| R195| R ______|' => '| CoreX Agency — seats 21 and more| {{q:agency_t3}}| {{rate:agency_t3}}| R {{amt:agency_t3}}|',
            '| Additional branches (Agency plan)| ______| R750| R ______|' => '| Additional branches (Agency plan)| {{q:branches}}| {{rate:branch}}| R {{amt:branches}}|',
            '| Agreed variation or discount (describe in section 3)||| – R ______|' => '| Agreed variation or discount (describe in section 3)||| – R {{rr:variation_amount}}|',
            '| **Monthly total**||| **R ______**|' => '| **Monthly total**||| **R {{amt:total}}**|',
        ];
        foreach ($fees as $from => $to) {
            $s = $this->once($s, $from, $to);
        }
        $s = $this->once($s, 'Agency initials: ______ RR Technologies initials: ______', 'Agency initials: {{ini:agency}} RR Technologies initials: {{ini:rr}}');
        $s = $this->once($s, '☐ 1 month ☐ Other: ______ months', '{{o:term:one_month}} 1 month {{o:term:other}} Other: {{f:term_months}} months');

        // Signature table: the same five lines appear twice — first the Agency's column, then RR's.
        $lines = [
            'Name: ______________________________' => ['Name: {{f:sig_name}}', 'Name: {{rr:rr_name}}'],
            'Capacity: __________________________' => ['Capacity: {{f:sig_capacity}}', 'Capacity: {{rr:rr_capacity}}'],
            'Signature: _________________________' => ['Signature: {{sig:agency}}', 'Signature: {{sig:rr}}'],
            'Date: ______________________________' => ['Date: {{f:sig_date}}', 'Date: {{rr:rr_date}}'],
            'Place: _____________________________' => ['Place: {{f:sig_place}}', 'Place: {{rr:rr_place}}'],
        ];
        foreach ($lines as $from => [$agency, $rr]) {
            $s = $this->nth($s, $from, $agency, 1, 2);
            $s = $this->nth($s, $from, $rr, 1, 1);
        }

        return $s;
    }

    private function tokeniseMandate(string $s): string
    {
        $s = $this->once($s, 'Given by *(name of Accountholder): ______*', 'Given by *(name of Accountholder): {{f:m_holder}}*');
        $s = $this->nth($s, 'Address: ______', 'Address: {{f:m_address}}', 1, 2);
        $s = $this->nth($s, 'Address: ______', 'Address: {{co:address}}', 1, 1);
        $s = $this->once($s, 'Bank Name: ______', 'Bank Name: {{f:m_bank}}');
        $s = $this->once($s, 'Branch Name and Town: ______', 'Branch Name and Town: {{f:m_branch_name_town}}');
        $s = $this->once($s, 'Branch Number: ______', 'Branch Number: {{f:m_branch_no}}');
        $s = $this->once($s, 'Account Number: ______', 'Account Number: {{f:m_account}}');
        $s = $this->once($s, 'Type of Account: Current (cheque) / Savings / Transmission', 'Type of Account: {{o:m_account_type:current}} Current (cheque) / {{o:m_account_type:savings}} Savings / {{o:m_account_type:transmission}} Transmission');
        $s = $this->once($s, "\nDate: ______\n", "\nDate: {{f:m_date}}\n");
        $s = $this->once($s, 'Contact Number: ______', 'Contact Number: {{f:m_contact}}');
        $s = $this->once($s, "\nAmount: ______\n", "\nAmount: {{f:m_amount}}\n");
        $s = $this->once($s, 'To (Name of Beneficiary): ______', 'To (Name of Beneficiary): {{co:name}}');
        $s = $this->once($s, 'Refer to contract reference number ______ (', 'Refer to contract reference number {{ref}} (');
        $s = $this->once($s, 'delivered on ______(date) and thereafter regularly on the', 'delivered on {{f:m_first_payment}}(date) and thereafter regularly on the');
        $s = $this->once($s, "\n\n______of each month.", "\n\n{{f:m_day}}of each month.");
        $s = $this->once($s, 'Signed ……………………………… on this ………………………..day of………………………………………………', 'Signed {{f:m_place}} on this {{auto:day}} day of {{auto:monthyear}}');
        $s = $this->once($s, "\n……………………………………………………………………………………..\n", "\n{{sig:mandate}}\n");
        $s = $this->once($s, "\n………………………………………………………… …………………………………..\n", "\n{{f:m_assisted_by}} {{f:m_capacity}}\n");
        $s = $this->once($s, 'THE AGREEMENT REFERENCE NUMBER IS ……………………………………………….', 'THE AGREEMENT REFERENCE NUMBER IS {{ref}}');

        return $s;
    }

    private function read(string $file): string
    {
        $path = self::sourcePath($file);
        if (!is_file($path)) {
            throw new \RuntimeException('Agreement source file missing: ' . $path);
        }

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    private function indexOf(string $haystack, string $needle): int
    {
        $i = strpos($haystack, $needle);
        if ($i === false) {
            throw new \RuntimeException('Agreement source changed: heading not found: ' . $needle);
        }

        return $i;
    }

    /** Replace $from with $to; $from must occur EXACTLY once. */
    private function once(string $s, string $from, string $to): string
    {
        $n = substr_count($s, $from);
        if ($n !== 1) {
            throw new \RuntimeException('Agreement source changed: expected exactly one "' . substr($from, 0, 60) . '", found ' . $n);
        }

        return str_replace($from, $to, $s);
    }

    /** Replace the $nth (1-based) occurrence of $from, which must occur exactly $expected times. */
    private function nth(string $s, string $from, string $to, int $nth, int $expected): string
    {
        $n = substr_count($s, $from);
        if ($n !== $expected) {
            throw new \RuntimeException('Agreement source changed: expected ' . $expected . ' of "' . substr($from, 0, 60) . '", found ' . $n);
        }
        $pos = -1;
        for ($i = 0; $i < $nth; $i++) {
            $pos = strpos($s, $from, $pos + 1);
        }

        return substr($s, 0, $pos) . $to . substr($s, $pos + strlen($from));
    }
}
