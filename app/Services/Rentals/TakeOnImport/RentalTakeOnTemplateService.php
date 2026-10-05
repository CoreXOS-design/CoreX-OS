<?php

namespace App\Services\Rentals\TakeOnImport;

use App\Models\Agency;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * .ai/specs/rental-takeon-import.md §3 — the downloadable take-on template.
 * Uses PhpSpreadsheet directly (not the OpenSpout writer
 * app/Http/Controllers/Concerns/ExportsRentalList.php already uses
 * elsewhere) because this is the one export in CoreX that needs a second
 * sheet AND real dropdown data validation — OpenSpout's writer supports
 * neither.
 */
class RentalTakeOnTemplateService
{
    public function build(Agency $agency): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $this->buildDataSheet($spreadsheet, $agency);
        $this->buildInstructionsSheet($spreadsheet);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function write(Spreadsheet $spreadsheet, string $absolutePath): void
    {
        (new Xlsx($spreadsheet))->save($absolutePath);
    }

    private function buildDataSheet(Spreadsheet $spreadsheet, Agency $agency): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Take-on data');

        $fields = RentalTakeOnFieldSchema::fields();

        foreach ($fields as $i => $field) {
            $col = $i + 1;
            $header = ($field['required'] ? '*' : '') . $field['label'];
            $sheet->setCellValue([$col, 1], $header);
        }

        $headerStyle = $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $headerStyle->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A2');

        // Dropdown validation, applied down 500 rows — a take-on book is
        // tens to a few hundred tenancies; 500 gives real headroom without
        // generating an unbounded number of validation ranges.
        $branchNames = $agency->branches()->pluck('name')->filter()->values()->all();

        foreach ($fields as $i => $field) {
            $col = $i + 1;
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);

            $options = match ($field['type']) {
                'dropdown:property_type' => RentalTakeOnFieldSchema::PROPERTY_TYPE_OPTIONS,
                'dropdown:lease_type' => RentalTakeOnFieldSchema::LEASE_TYPE_OPTIONS,
                // Multi-agency (non-negotiable #9) — never a hardcoded branch
                // list. An agency with no branches configured simply gets no
                // dropdown on this column; free text still parses fine.
                'dropdown:branch' => $branchNames,
                default => null,
            };

            if ($options === null || $options === []) {
                continue;
            }

            for ($row = 2; $row <= 500; $row++) {
                $validation = $sheet->getCell("{$colLetter}{$row}")->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_WARNING);
                $validation->setAllowBlank(true);
                $validation->setShowInputMessage(true);
                $validation->setShowErrorMessage(true);
                $validation->setShowDropDown(true);
                $validation->setFormula1('"' . implode(',', $options) . '"');
            }
        }

        foreach (range(1, count($fields)) as $col) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setWidth(20);
        }
    }

    private function buildInstructionsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Instructions');

        $sheet->setCellValue('A1', 'Column');
        $sheet->setCellValue('B1', 'What it is for');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        $notes = [
            'street_name' => 'Used, with Suburb, to find or create the property. Required.',
            'suburb' => 'Used, with Street name (or Erf number), to find or create the property. Required.',
            'erf_number' => 'Alternative to Street name for matching the property — useful on a complex/estate with no clear street address.',
            'property_type' => 'Pick from the dropdown.',
            'landlord1_name' => 'The owner on the lease. Required. A company landlord can go here too — use the ID/reg field for its registration number.',
            'landlord1_id_or_reg' => 'ID number for a person, or company registration number for an entity.',
            'landlord2_name' => 'A second, joint owner — optional.',
            'tenant1_name' => 'Required. At least one tenant is needed per lease.',
            'tenant2_name' => 'A joint tenant — optional. Up to 4 tenants per lease.',
            'lease_start_date' => 'Format YYYY-MM-DD. Required.',
            'lease_end_date' => 'Format YYYY-MM-DD. Required unless Lease type is Month-to-month.',
            'lease_type' => 'Pick Fixed term or Month-to-month from the dropdown. Required.',
            'monthly_rental_amount' => 'Rands, numbers only (no "R" or commas). Required.',
            'escalation_percent' => 'The agreed annual increase, if any — e.g. 8 for 8%.',
            'next_escalation_date' => 'When the next increase is due, format YYYY-MM-DD.',
            'deposit_held' => 'Rands, numbers only.',
            'arrears_opening_balance' => 'Any amount the tenant owed on the day you took this lease on. This is recorded for your records only — CoreX does not yet post rental money, so this amount is not added to any ledger.',
            'management_fee_percent' => 'Use this OR the amount column below, not both.',
            'management_fee_amount' => 'Use this OR the % column above, not both.',
            'last_inspection_date' => 'Format YYYY-MM-DD. Recorded for your records only.',
            'agent_email' => 'The email address of the agent at your agency who manages this tenancy. Leave blank to assign it to the person running this import.',
            'branch' => 'Pick from the dropdown if your agency has more than one branch.',
            'notes' => 'Anything else worth keeping — free text.',
        ];

        $row = 2;
        foreach (RentalTakeOnFieldSchema::fields() as $field) {
            $note = $notes[$field['key']] ?? '';
            if ($note === '') {
                continue;
            }
            $sheet->setCellValue('A' . $row, $field['label']);
            $sheet->setCellValue('B' . $row, $note);
            $row++;
        }

        $sheet->setCellValue('A' . ($row + 1), 'Importing a tenancy here never emails or messages the landlord or tenant.');
        $sheet->getStyle('A' . ($row + 1))->getFont()->setItalic(true);
        $sheet->setCellValue('A' . ($row + 2), 'Do not reorder, rename, insert, or delete any columns on the Take-on data sheet — the import reads them by position.');
        $sheet->getStyle('A' . ($row + 2))->getFont()->setItalic(true);

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(90);
        $sheet->getStyle('B')->getAlignment()->setWrapText(true);
    }
}
