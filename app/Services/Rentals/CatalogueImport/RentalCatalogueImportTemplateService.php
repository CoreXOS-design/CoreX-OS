<?php

namespace App\Services\Rentals\CatalogueImport;

use App\Models\Agency;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalVatType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * .ai/specs/rental-work-orders.md §14.19 — the downloadable catalogue
 * import template. Same PhpSpreadsheet-with-dropdown-validation technique
 * as App\Services\Rentals\TakeOnImport\RentalTakeOnTemplateService (chosen
 * there because OpenSpout's writer supports neither a second sheet nor real
 * dropdown data validation). The Type/Unit/VAT type dropdowns are built
 * from THIS agency's own configured lists (multi-agency always, non-
 * negotiable #9) — never a hardcoded Labour/Part/Each list.
 */
class RentalCatalogueImportTemplateService
{
    private const HEADERS = ['*Code', '*Description', '*Type', '*Unit', 'VAT type', 'Price (excl VAT)', 'Price (incl VAT)'];

    public function build(Agency $agency): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $this->buildDataSheet($spreadsheet, $agency);
        $this->buildInstructionsSheet($spreadsheet, $agency);

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
        $sheet->setTitle('Catalogue items');

        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        $headerStyle = $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $headerStyle->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A2');

        $typeNames = RentalCatalogueItemType::active()->where('agency_id', $agency->id)->orderBy('sort_order')->pluck('name')->filter()->values()->all();
        $unitNames = RentalCatalogueUnit::active()->where('agency_id', $agency->id)->orderBy('sort_order')->pluck('name')->filter()->values()->all();
        $vatTypeNames = $agency->vat_registered
            ? RentalVatType::active()->where('agency_id', $agency->id)->orderBy('sort_order')->pluck('name')->filter()->values()->all()
            : [];

        // Column index (1-based) => dropdown option list. A price list is
        // hundreds to low thousands of items for a working agency; 1000 rows
        // gives real headroom without generating an unbounded validation range.
        $dropdowns = [3 => $typeNames, 4 => $unitNames, 5 => $vatTypeNames];

        foreach ($dropdowns as $col => $options) {
            if ($options === []) {
                continue;
            }
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            for ($row = 2; $row <= 1000; $row++) {
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

        foreach (range(1, count(self::HEADERS)) as $col) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setWidth(20);
        }
    }

    private function buildInstructionsSheet(Spreadsheet $spreadsheet, Agency $agency): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Instructions');

        $sheet->setCellValue('A1', 'Column');
        $sheet->setCellValue('B1', 'What it is for');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        $notes = [
            'Code' => 'A short code you recognise it by — must be unique across your active catalogue. Required.',
            'Description' => 'The full description that prints on the job card and quote. Required.',
            'Type' => 'Pick from the dropdown — one of your own Labour/Part types (Settings > Parts & Labour Catalogue > Manage types & units). Required.',
            'Unit' => 'Pick from the dropdown — one of your own units (Each, Hour, ...). Required.',
            'VAT type' => 'Pick from the dropdown, or leave blank to use your default VAT type.',
            'Price (excl VAT)' => 'Rands, numbers only. Fill this OR the incl-VAT column, not necessarily both — leave both blank if this item has no standard price.',
            'Price (incl VAT)' => 'Rands, numbers only. Fill this OR the excl-VAT column — whichever you know. If both are filled, the excl-VAT amount wins.',
        ];

        $row = 2;
        foreach ($notes as $label => $note) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $note);
            $row++;
        }

        $row++;
        $sheet->setCellValue('A' . $row, 'A code that already exists in your catalogue:');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        $sheet->setCellValue('A' . $row, 'you choose, on the upload screen, whether matching rows update the existing item or are skipped — your whole file is treated the same way.');
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Do not reorder, rename, insert, or delete any columns on the Catalogue items sheet — the import reads them by position.');
        $sheet->getStyle('A' . $row)->getFont()->setItalic(true);

        if (! $agency->vat_registered) {
            $row++;
            $sheet->setCellValue('A' . $row, 'Your agency is not VAT registered — the VAT type and incl-VAT columns are ignored; only Price (excl VAT) is used as the plain price.');
            $sheet->getStyle('A' . $row)->getFont()->setItalic(true);
        }

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(90);
        $sheet->getStyle('B')->getAlignment()->setWrapText(true);
    }
}
