<?php

namespace App\Support\Compliance;

use App\Models\FicaSubmission;
use Illuminate\Support\Arr;

/**
 * The online FICA questionnaire as the client SAW it — one ordered, grouped list of
 * questions, so the review screens (agent / RO / CO) can show the question next to the
 * answer.
 *
 * WHY THIS EXISTS. The public form (resources/views/fica/form.blade.php) is hard-coded
 * Blade; a submission stores only the validated answers as keyed JSON in
 * fica_submissions.form_data — never the question wording and never a form version. So
 * the wording the client saw can only be recovered from a record of it, and this class
 * is that record.
 *
 * HOW THE WORDING IS KEPT HONEST (do not skip this when editing the public form):
 *  1. Every label below is copied verbatim from the public form. The question-bearing
 *     lines of that form have been byte-identical since 2026-03-26 (verified from git
 *     history; only CSS and a hidden field changed since), and every online completion
 *     on record is dated after that.
 *  2. tests/Feature/Compliance/FicaQuestionnaireWordingTest.php compares this list with
 *     the form's own Blade in BOTH directions and fails the build if either changes
 *     alone. If you change a question on the public form you must, in the same commit,
 *     (a) KEEP the old wording here under its own version with the date it stops being
 *     asked, and (b) add the new wording as a new version from its go-live date —
 *     versionFor() picks the version by the submission's signed_at. Never edit a
 *     version's wording in place: that would silently rewrite what every earlier client
 *     was asked.
 *  3. A submission signed before a version's effective date is flagged on screen
 *     ("wording may differ") instead of being shown with wording it never saw.
 *
 * Read-only presentation data: this class never writes anything.
 */
final class FicaQuestionnaire
{
    /** The only version so far: the wording asked from 2026-03-26 (form first deployed) to date. */
    public const VERSION = '2026-03-26';
    public const EFFECTIVE_FROM = '2026-03-26';

    public const ENTITY_TYPES = [
        'natural'     => 'Natural Person (Individual)',
        'company'     => 'Company / Close Corporation',
        'trust'       => 'Trust',
        'partnership' => 'Partnership',
    ];

    private const YESNO = ['yes' => 'Yes', 'no' => 'No'];

    private const PURPOSES = [
        'sell'     => 'I/We wish to sell a property',
        'purchase' => 'I/We wish to purchase a property',
        'let_out'  => 'I/We wish to let out a property',
        'rent'     => 'I/We wish to rent a property',
        'other'    => 'Other',
    ];

    private const BO_METHODS = [
        'method_1' => 'Method 1: Natural persons who individually or collectively own 5%+ of shares/interests',
        'method_2' => 'Method 2: Natural persons who individually or collectively control the company',
        'method_3' => 'Method 3: Executive managers of the company',
    ];

    private const FOREIGN_PEP = [
        'head_of_state'    => 'Head of state',
        'royal_family'     => 'Member of the royal family',
        'cabinet_member'   => 'Cabinet member',
        'political_party'  => 'Senior member of a political party',
        'judicial_officer' => 'Senior judicial officer',
        'soe_executive'    => 'Senior executive of a state-owned entity',
        'military'         => 'High rank in the military',
    ];

    private const DOMESTIC_PEP = [
        'president'          => 'President or Deputy President of South Africa',
        'cabinet_minister'   => 'Cabinet Minister or Deputy Minister',
        'premier'            => 'Premier of a province',
        'mec'                => 'MEC of a province',
        'mayor'              => 'Mayor of a municipality',
        'political_leader'   => 'Leader of a political party',
        'royal_family'       => 'Member of a royal family',
        'traditional_leader' => 'Senior traditional leader',
        'dept_head'          => 'Head, accounting officer or CFO of a national or provincial department',
        'municipal_manager'  => 'Manager or CFO of a municipality',
        'public_entity'      => 'Chairperson, CEO, accounting authority, CFO or chief investment officer of a public entity',
        'judge'              => 'Judge',
        'ambassador'         => 'Ambassador, high commissioner or other senior representative of a foreign country based in SA',
        'govt_business'      => 'Chairperson of board, chairperson of audit committee, executive officer or CFO of a company doing significant business with government',
    ];

    public const DECLARATION_TEXT = 'I hereby declare that the information provided above is true, correct and complete. I understand that providing false or misleading information may constitute a criminal offence under the Financial Intelligence Centre Act (Act 38 of 2001) as amended.';

    /** Section 2/3 repeating-person field labels, exactly as the form words them. */
    private const PERSON_FIELDS_3 = ['name' => 'Full Name', 'id_number' => 'SA ID / Passport', 'address' => 'Residential Address'];
    private const PERSON_FIELDS_5 = ['name' => 'Full Name', 'id_number' => 'SA ID / Passport', 'address' => 'Residential Address', 'phone' => 'Telephone', 'email' => 'Email'];

    /** The wording version that applies to a submission (by when the client signed). Single version today. */
    public static function versionFor(?\DateTimeInterface $signedAt): string
    {
        return self::VERSION;
    }

    /**
     * Caution to print when the wording on file may not be what this client saw
     * (submitted before the first recorded version went live). Null = no caution.
     */
    public static function wordingCaution(?\DateTimeInterface $signedAt): ?string
    {
        if ($signedAt && $signedAt->format('Y-m-d') < self::EFFECTIVE_FROM) {
            return 'Completed before ' . date('j M Y', strtotime(self::EFFECTIVE_FROM)) . ', the first recorded version of this form — the wording the client saw may differ from what is shown.';
        }

        return null;
    }

    /**
     * Every question label this version can show, flattened — for the drift test.
     *
     * @return array<int,string>
     */
    public static function allLabels(): array
    {
        $labels = [];
        foreach (array_keys(self::ENTITY_TYPES) as $type) {
            foreach (self::definition($type) as $section) {
                $labels[] = $section['title'];
                foreach ($section['rows'] as $row) {
                    $labels[] = $row['label'];
                    foreach (($row['options'] ?? []) as $o) {
                        $labels[] = $o;
                    }
                    foreach (($row['fields'] ?? []) as $f) {
                        $labels[] = $f;
                    }
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Build the ordered, grouped question-and-answer view of one submission.
     *
     * @return array{
     *   version:string, caution:?string, entity_type:string, has_answers:bool,
     *   sections:array<int,array{title:string,rows:array<int,array<string,mixed>>}>,
     *   other_answers:array<int,array{key:string,value:string}>,
     *   other_documents:\Illuminate\Support\Collection
     * }
     */
    public static function forSubmission(FicaSubmission $submission): array
    {
        $data = is_array($submission->form_data) ? $submission->form_data : [];
        $entityType = $data['entity_type'] ?? $submission->entity_type ?: 'natural';
        if (! isset(self::ENTITY_TYPES[$entityType])) {
            $entityType = 'natural';
        }

        $docsByType = $submission->documents->groupBy('document_type');
        $usedDocTypes = [];
        $consumed = ['signature_data' => true, 'entity_type' => true];

        $sections = [];
        foreach (self::definition($entityType) as $def) {
            $rows = [];
            foreach ($def['rows'] as $row) {
                if (isset($row['when']) && ! ($row['when'])($data)) {
                    continue; // the client was never shown this follow-up question
                }
                $rows[] = self::resolveRow($row, $data, $docsByType, $usedDocTypes, $consumed, $submission);
            }
            $sections[] = ['title' => $def['title'], 'rows' => $rows];
        }

        // Nothing the client typed is ever hidden: answers the questionnaire does not
        // account for are listed by their stored key; documents not tied to a question
        // (staff uploads, retired slots) are listed apart.
        $other = [];
        foreach (Arr::dot($data) as $path => $value) {
            $base = preg_replace('/\.\d+(\.|$)/', '.*$1', (string) $path);
            if (isset($consumed[$path]) || isset($consumed[$base]) || self::startsWithConsumed((string) $path, $consumed)) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $other[] = ['key' => (string) $path, 'value' => is_scalar($value) ? (string) $value : json_encode($value)];
        }

        $otherDocs = $submission->documents->reject(fn ($d) => isset($usedDocTypes[$d->document_type]))->values();

        return [
            'version'         => self::versionFor($submission->signed_at),
            'caution'         => self::wordingCaution($submission->signed_at),
            'entity_type'     => $entityType,
            'has_answers'     => $data !== [],
            'sections'        => $sections,
            'other_answers'   => $other,
            'other_documents' => $otherDocs,
        ];
    }

    private static function startsWithConsumed(string $path, array $consumed): bool
    {
        // Repeating blocks are consumed as a whole ("entity.trustees" covers "entity.trustees.0.name").
        foreach ($consumed as $c => $_) {
            if (str_starts_with($path, $c . '.')) {
                return true;
            }
        }

        return false;
    }

    private static function resolveRow(array $row, array $data, $docsByType, array &$usedDocTypes, array &$consumed, FicaSubmission $submission): array
    {
        $type = $row['type'];
        $base = [
            'label'    => $row['label'],
            'type'     => $type,
            'followUp' => (bool) ($row['followUp'] ?? false),
            'flag'     => false,
            'answered' => false,
            'text'     => null,
            'list'     => [],
            'items'    => [],
            'docs'     => collect(),
        ];

        if ($type === 'doc') {
            $usedDocTypes[$row['slot']] = true;
            $docs = $docsByType->get($row['slot'], collect());
            $base['docs'] = $docs->values();
            $base['answered'] = $docs->isNotEmpty();

            return $base;
        }

        if ($type === 'signature') {
            $base['answered'] = (bool) $submission->signature_data;
            $base['text'] = $submission->signed_at ? $submission->signed_at->format('d M Y H:i') : null;

            return $base;
        }

        $path = $row['path'];
        $consumed[$path] = true;
        $value = Arr::get($data, $path);

        switch ($type) {
            case 'list':
                $items = [];
                foreach ((array) $value as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $parts = [];
                    foreach ($row['fields'] as $key => $label) {
                        $v = trim((string) ($item[$key] ?? ''));
                        if ($v !== '') {
                            $parts[] = ['label' => $label, 'value' => $v];
                        }
                    }
                    if ($parts) {
                        $items[] = $parts;
                    }
                }
                $base['items'] = $items;
                $base['answered'] = $items !== [];
                break;

            case 'multi':
                $picked = array_values(array_filter((array) $value, fn ($v) => is_string($v) && $v !== ''));
                $base['list'] = array_map(fn ($v) => $row['options'][$v] ?? $v, $picked);
                $base['answered'] = $picked !== [];
                break;

            case 'yesno':
            case 'choice':
                $v = is_scalar($value) ? trim((string) $value) : '';
                if ($v !== '') {
                    $base['text'] = $row['options'][$v] ?? $v;
                    $base['answered'] = true;
                    $base['flag'] = ($row['flagOn'] ?? null) !== null && $v === $row['flagOn'];
                }
                break;

            default: // text
                $v = is_scalar($value) ? trim((string) $value) : '';
                if ($v !== '') {
                    $base['text'] = $v;
                    $base['answered'] = true;
                }
        }

        return $base;
    }

    // ───────────────────────── the questionnaire itself ─────────────────────────

    private static function t(string $path, string $label, array $extra = []): array
    {
        return ['path' => $path, 'label' => $label, 'type' => 'text'] + $extra;
    }

    private static function yn(string $path, string $label, array $extra = []): array
    {
        return ['path' => $path, 'label' => $label, 'type' => 'yesno', 'options' => self::YESNO] + $extra;
    }

    private static function when(string $path, string $equals): \Closure
    {
        return fn (array $d) => Arr::get($d, $path) === $equals;
    }

    /**
     * Sections in the order — and with the numbering — the client saw them.
     * Natural persons see 1,2,4,5,6,7,8,9; every other entity sees 1,2,3,4,5,6,7.
     *
     * @return array<int,array{title:string,rows:array<int,array<string,mixed>>}>
     */
    public static function definition(string $entityType): array
    {
        $natural = $entityType === 'natural';
        $n = fn (int $natural_no, int $other_no) => $natural ? $natural_no : $other_no;

        $sections = [];

        $sections[] = ['title' => '1. Entity Type', 'rows' => [
            ['path' => 'entity_type', 'label' => 'Entity Type', 'type' => 'choice', 'options' => self::ENTITY_TYPES],
        ]];

        $sections[] = ['title' => '2. Person Completing This Form', 'rows' => array_values(array_filter([
            self::t('personal.full_name', 'Full Name'),
            self::t('personal.id_number', 'SA Identity Number / Foreign Passport Number'),
            self::yn('personal.sa_citizen', 'Are you a South African citizen / permanent resident?'),
            self::t('personal.residential_address', 'Residential Address'),
            self::t('personal.phone', 'Telephone Number'),
            self::t('personal.email', 'Email Address'),
            $natural ? self::t('personal.tax_number', 'SA Income Tax Number') : null,
        ]))];

        if ($entityType === 'company') {
            $sections[] = ['title' => '3. Company / Close Corporation Details', 'rows' => [
                self::t('entity.company_name', 'Company / CC Name'),
                self::t('entity.company_reg_number', 'Registration Number'),
                self::t('entity.company_sa_presence', 'Does the company have a presence in South Africa? If yes, provide details'),
                self::t('entity.company_stock_exchange', 'Is the company listed on a stock exchange? If so, which?'),
                self::t('entity.company_tax_number', 'SARS Income Tax Number'),
                self::t('entity.company_vat_number', 'VAT Registration Number'),
                self::t('entity.company_address', 'Registered Address'),
                self::t('entity.company_authority_source', 'Source of authority to act on behalf of the company'),
                self::t('entity.company_business_description', "Describe the company's business — industry, products/services"),
                self::t('entity.company_ownership_structure', 'Describe the ownership and control structure'),
                ['path' => 'entity.beneficial_owner_method', 'label' => 'Method of Identification', 'type' => 'choice', 'options' => self::BO_METHODS],
                ['path' => 'entity.beneficial_owners', 'label' => 'Beneficial Owners', 'type' => 'list', 'fields' => self::PERSON_FIELDS_5],
            ]];
        }

        if ($entityType === 'trust') {
            $sections[] = ['title' => '3. Trust Details', 'rows' => [
                self::t('entity.trust_name', 'Trust Name'),
                self::t('entity.trust_master_ref', "Master's Reference Number"),
                self::t('entity.trust_sa_presence', 'Does the trust have a presence in South Africa? If yes, provide details'),
                self::t('entity.trust_master_court', 'Which Master of the High Court administers the trust?'),
                self::t('entity.trust_tax_number', 'SARS Income Tax Number (if registered)'),
                self::t('entity.trust_vat_number', 'VAT Number (if registered)'),
                self::t('entity.trust_authority_source', 'Source of authority to act on behalf of the trust'),
                self::t('entity.trust_purpose', "Describe the trust's purpose or business"),
                self::t('entity.donor_name', 'Full Name of the Donor'),
                self::t('entity.donor_id_number', 'SA ID / Passport Number'),
                self::t('entity.donor_address', 'Residential Address'),
                ['path' => 'entity.trustees', 'label' => 'Trustees', 'type' => 'list', 'fields' => self::PERSON_FIELDS_3],
                self::yn('entity.has_named_beneficiaries', 'Are there named beneficiaries?'),
                ['path' => 'entity.beneficiaries', 'label' => 'Beneficiaries', 'type' => 'list', 'fields' => self::PERSON_FIELDS_3,
                    'followUp' => true, 'when' => self::when('entity.has_named_beneficiaries', 'yes')],
                self::t('entity.beneficiary_determination', 'How are the beneficiaries determined?',
                    ['followUp' => true, 'when' => self::when('entity.has_named_beneficiaries', 'no')]),
            ]];
        }

        if ($entityType === 'partnership') {
            $sections[] = ['title' => '3. Partnership Details', 'rows' => [
                self::t('entity.partnership_name', 'Partnership Identifying Name / Trading Name'),
                self::t('entity.partnership_sa_presence', 'Does the partnership have a presence in South Africa? If yes, provide details'),
                self::t('entity.partnership_authority_source', 'Source of authority to act on behalf of the partnership'),
                self::t('entity.partnership_business_description', "Describe the partnership's business — industry, products/services"),
                self::yn('entity.is_professional_partnership', 'Is this a professional partnership?'),
                self::t('entity.executive_partners', 'Who are the executive partners controlling day-to-day operations?',
                    ['followUp' => true, 'when' => self::when('entity.is_professional_partnership', 'yes')]),
                self::t('entity.partnership_ownership_structure', 'Ownership and control structure'),
                self::t('entity.partnership_tax_number', 'SARS Income Tax Number (if registered)'),
                self::t('entity.partnership_vat_number', 'VAT Number (if registered)'),
                ['path' => 'entity.partners', 'label' => 'Partners', 'type' => 'list', 'fields' => self::PERSON_FIELDS_5],
            ]];
        }

        if ($natural) {
            $yes = self::when('principal.acting_on_behalf', 'yes');
            $sections[] = ['title' => '4. Principal', 'rows' => [
                self::yn('principal.acting_on_behalf', 'Are you dealing with us on behalf of another person?'),
                self::t('principal.full_name', "Principal's Full Name", ['followUp' => true, 'when' => $yes]),
                self::t('principal.id_number', "Principal's SA ID / Passport Number", ['followUp' => true, 'when' => $yes]),
                self::yn('principal.sa_citizen', 'Is the Principal a South African citizen / permanent resident?', ['followUp' => true, 'when' => $yes]),
                self::t('principal.residential_address', "Principal's Residential Address", ['followUp' => true, 'when' => $yes]),
                self::t('principal.phone', "Principal's Telephone", ['followUp' => true, 'when' => $yes]),
                self::t('principal.email', "Principal's Email", ['followUp' => true, 'when' => $yes]),
                self::t('principal.tax_number', "Principal's SA Income Tax Number", ['followUp' => true, 'when' => $yes]),
                self::t('principal.authority_source', 'Source of your authority to act on their behalf', ['followUp' => true, 'when' => $yes]),
            ]];

            $rep = self::when('representative.has_representative', 'yes');
            $sections[] = ['title' => '5. Representative', 'rows' => [
                self::yn('representative.has_representative', 'Will someone else deal with us on your behalf going forward?'),
                self::t('representative.full_name', "Representative's Full Name", ['followUp' => true, 'when' => $rep]),
                self::t('representative.id_number', "Representative's SA ID / Passport Number", ['followUp' => true, 'when' => $rep]),
                self::t('representative.authority_source', "Source of representative's authority", ['followUp' => true, 'when' => $rep]),
            ]];
        }

        $sections[] = ['title' => $n(6, 4) . '. Service & Payment', 'rows' => [
            ['path' => 'service.transaction_purpose', 'label' => 'Purpose of Transaction', 'type' => 'choice', 'options' => self::PURPOSES],
            self::t('service.purpose_other', 'Please specify...', ['followUp' => true, 'when' => self::when('service.transaction_purpose', 'other')]),
            self::t('service.payment_method', 'How will payments be financed?'),
            self::yn('service.cash_over_50k', 'Will any payment involve R50,000 or more in cash?', ['flagOn' => 'yes']),
        ]];

        $anyPep = fn (array $d) => Arr::get($d, 'pep.is_foreign_pep') === 'yes'
            || Arr::get($d, 'pep.is_domestic_pep') === 'yes'
            || Arr::get($d, 'pep.is_family_associate') === 'yes';
        $sections[] = ['title' => $n(7, 5) . '. Politically Exposed Person (PEP)', 'rows' => [
            self::yn('pep.is_foreign_pep', 'Do you now occupy, or have you in the past 12 months occupied, any prominent public position in a country OTHER than South Africa?', ['flagOn' => 'yes']),
            ['path' => 'pep.foreign_pep', 'label' => 'Please indicate which position(s):', 'type' => 'multi', 'options' => self::FOREIGN_PEP,
                'followUp' => true, 'when' => self::when('pep.is_foreign_pep', 'yes')],
            self::yn('pep.is_domestic_pep', 'Do you now occupy, or have you in the past 12 months occupied, any prominent public position in South Africa?', ['flagOn' => 'yes']),
            ['path' => 'pep.domestic_pep', 'label' => 'Please indicate which position(s):', 'type' => 'multi', 'options' => self::DOMESTIC_PEP,
                'followUp' => true, 'when' => self::when('pep.is_domestic_pep', 'yes')],
            self::yn('pep.is_family_associate', 'Are you a family member or close associate of any person described above?', ['flagOn' => 'yes']),
            self::t('pep.family_associate_details', 'Name the person and indicate their position',
                ['followUp' => true, 'when' => self::when('pep.is_family_associate', 'yes')]),
            self::t('pep.source_of_wealth', 'Please indicate your source of wealth', ['followUp' => true, 'when' => $anyPep]),
        ]];

        $sections[] = ['title' => $n(8, 6) . '. Document Uploads', 'rows' => self::documentRows($entityType)];

        $sections[] = ['title' => $n(9, 7) . '. Declaration & Signature', 'rows' => [
            self::t('declaration.signed_at_location', 'Signed at (location)'),
            ['label' => 'Your Signature', 'type' => 'signature'],
        ]];

        return $sections;
    }

    /** Upload slots, with the same show-conditions as the form's computedUploadTypes(). */
    private static function documentRows(string $entityType): array
    {
        $d = fn (string $slot, string $label, ?\Closure $when = null) => array_filter([
            'type' => 'doc', 'slot' => $slot, 'label' => $label, 'when' => $when,
        ], fn ($v) => $v !== null);

        $rows = [
            $d('id_copy', 'Copy of ID document / Passport of person completing form'),
            $d('proof_of_address', 'Proof of residential address (less than 2 months old)'),
        ];

        if ($entityType === 'natural') {
            $acting = self::when('principal.acting_on_behalf', 'yes');
            $rows[] = $d('principal_id', "Principal's copy of ID document / Passport", $acting);
            $rows[] = $d('principal_address', "Principal's proof of address", $acting);
            $rows[] = $d('principal_authority', 'Authority document (to act on behalf of principal)', $acting);
            $rows[] = $d('representative_authority', "Representative's authority document", self::when('representative.has_representative', 'yes'));
        }
        if ($entityType === 'company') {
            $rows[] = $d('company_registration', 'Proof of company/CC existence — registration document');
            $rows[] = $d('company_authority', 'Authority document to act on behalf of company');
            $rows[] = $d('beneficial_owner_ids', 'Copy of ID documents of all beneficial owners');
            $rows[] = $d('beneficial_owner_addresses', 'Proof of address of all beneficial owners');
        }
        if ($entityType === 'trust') {
            $rows[] = $d('trust_deed', 'Trust deed or letters of authority');
            $rows[] = $d('trust_authority', 'Authority document — trust resolution etc');
            $rows[] = $d('donor_id', "Donor's copy of ID document");
            $rows[] = $d('donor_address', "Donor's proof of address");
            $rows[] = $d('trustee_ids', 'Copy of ID documents of all trustees');
            $rows[] = $d('trustee_addresses', 'Proof of address of all trustees');
            $named = self::when('entity.has_named_beneficiaries', 'yes');
            $rows[] = $d('beneficiary_ids', 'Copy of ID documents of named beneficiaries', $named);
            $rows[] = $d('beneficiary_addresses', 'Proof of address of named beneficiaries', $named);
        }
        if ($entityType === 'partnership') {
            $rows[] = $d('partnership_authority', 'Authority document');
            $rows[] = $d('partner_ids', 'Copy of ID documents of all partners');
            $rows[] = $d('partner_addresses', 'Proof of address of all partners');
        }

        return $rows;
    }
}
