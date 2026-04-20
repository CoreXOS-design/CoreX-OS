<?php

namespace App\Models\Compliance;

use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeScreeningCheck extends Model
{
    // ── Check types ──
    const TYPE_EMPLOYMENT_HISTORY  = 'employment_history_verified';
    const TYPE_QUALIFICATION       = 'qualification_verified';
    const TYPE_REFERENCES          = 'references_checked';
    const TYPE_PPRA_FFC            = 'ppra_ffc_verified';
    const TYPE_CRIMINAL_RECORD     = 'criminal_record_check';
    const TYPE_CREDIT_CHECK        = 'credit_check';
    const TYPE_ID_VERIFICATION     = 'id_verification';
    const TYPE_ADDRESS_VERIFICATION = 'address_verification';
    const TYPE_TFS_SCREENING       = 'tfs_screening';
    const TYPE_PREVIOUS_AML_REVIEW = 'previous_aml_role_review';
    const TYPE_HIGH_RISK_ASSOC     = 'high_risk_association_check';

    // ── Result values ──
    const RESULT_CLEAR          = 'clear';
    const RESULT_CONCERNS       = 'concerns';
    const RESULT_FAIL           = 'fail';
    const RESULT_NOT_APPLICABLE = 'not_applicable';
    const RESULT_PENDING        = 'pending';

    protected $fillable = [
        'employee_screening_id', 'check_type', 'result',
        'checked_on', 'checked_by', 'notes', 'reference_number',
        'supporting_document_id',
    ];

    protected $casts = [
        'checked_on' => 'date',
    ];

    // ── Relationships ──

    public function screening(): BelongsTo
    {
        return $this->belongsTo(EmployeeScreening::class, 'employee_screening_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function supportingDocument(): BelongsTo
    {
        return $this->belongsTo(UserDocument::class, 'supporting_document_id');
    }

    // ── Static ──

    public static function typesForScreening(string $screeningType): array
    {
        return match ($screeningType) {
            'pre_employment' => [
                self::TYPE_EMPLOYMENT_HISTORY, self::TYPE_QUALIFICATION,
                self::TYPE_REFERENCES, self::TYPE_PPRA_FFC,
                self::TYPE_CRIMINAL_RECORD, self::TYPE_CREDIT_CHECK,
                self::TYPE_ID_VERIFICATION, self::TYPE_ADDRESS_VERIFICATION,
                self::TYPE_TFS_SCREENING,
                self::TYPE_PREVIOUS_AML_REVIEW, self::TYPE_HIGH_RISK_ASSOC,
            ],
            'periodic' => [
                self::TYPE_CRIMINAL_RECORD, self::TYPE_CREDIT_CHECK,
                self::TYPE_ID_VERIFICATION, self::TYPE_ADDRESS_VERIFICATION,
                self::TYPE_TFS_SCREENING, self::TYPE_HIGH_RISK_ASSOC,
            ],
            'tfs_list_update' => [
                self::TYPE_TFS_SCREENING,
            ],
            'triggered' => [
                self::TYPE_CRIMINAL_RECORD, self::TYPE_CREDIT_CHECK,
                self::TYPE_TFS_SCREENING, self::TYPE_HIGH_RISK_ASSOC,
            ],
            default => [self::TYPE_TFS_SCREENING],
        };
    }

    public static array $checkTypeLabels = [
        'employment_history_verified' => 'Employment History Verified',
        'qualification_verified'      => 'Qualification Verified',
        'references_checked'          => 'References Checked',
        'ppra_ffc_verified'           => 'PPRA / FFC Verified',
        'criminal_record_check'       => 'Criminal Record Check',
        'credit_check'                => 'Credit Check',
        'id_verification'             => 'ID Verification',
        'address_verification'        => 'Address Verification',
        'tfs_screening'               => 'TFS Screening',
        'previous_aml_role_review'    => 'Previous AML Role Review',
        'high_risk_association_check'  => 'High Risk Association Check',
    ];

    public static array $resultLabels = [
        'clear'          => 'Clear',
        'concerns'       => 'Concerns',
        'fail'           => 'Fail',
        'not_applicable' => 'N/A',
        'pending'        => 'Pending',
    ];
}
