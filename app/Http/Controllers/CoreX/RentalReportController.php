<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * .ai/specs/rentals-reports.md — AT-443. One screen (picker left, chosen
 * report right), every report sharing one shell (period/scope/status-ticks/
 * group-by/search/sort/per-page/print/PDF/export) per the master spec §1.1.
 * All data comes from RentalReportService — this controller only resolves
 * request params and renders; it creates, edits, and archives nothing.
 */
class RentalReportController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function index(Request $request, RentalReportService $service, RentalCommandCentreService $cc): View
    {
        $data = $this->buildViewData($request, $service, $cc);

        return view('corex.rentals.reports.index', $data);
    }

    public function print(Request $request, RentalReportService $service, RentalCommandCentreService $cc): View
    {
        $data = $this->buildViewData($request, $service, $cc, forPrint: true);

        return view('corex.rentals.reports.print', $data);
    }

    public function pdf(Request $request, RentalReportService $service, RentalCommandCentreService $cc)
    {
        $data = $this->buildViewData($request, $service, $cc, forPrint: true);

        $pdf = Pdf::loadView('corex.rentals.reports.print', $data + ['forPdf' => true])->setPaper('a4', 'landscape');
        $this->applyPdfOptions($pdf);

        return $pdf->download('rental-report-' . $data['reportKey'] . '-' . now()->format('Y-m-d') . '.pdf');
    }

    public function export(Request $request, RentalReportService $service, RentalCommandCentreService $cc): StreamedResponse
    {
        $data = $this->buildViewData($request, $service, $cc, forPrint: true);
        $format = $request->get('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $filename = 'rental-report-' . $data['reportKey'] . '-' . now()->format('Y-m-d') . '.' . $format;

        $columns = $data['result']['columns'];
        $rows = $data['result']['rows'];

        return new StreamedResponse(function () use ($format, $columns, $rows) {
            $tmp = tempnam(sys_get_temp_dir(), 'rrep');
            $writer = $format === 'csv' ? new CsvWriter() : new XlsxWriter();
            $writer->openToFile($tmp);

            $writer->addRow(Row::fromValues(array_values($columns)));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_map(
                    fn ($key) => $this->exportCell($row[$key] ?? null),
                    array_keys($columns)
                )));
            }

            $writer->close();
            readfile($tmp);
            @unlink($tmp);
        }, 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0, no-store, no-cache, must-revalidate',
        ]);
    }

    /** Property History — its own shape (a timeline, not a flat grid), own print/PDF action. */
    public function propertyHistory(Request $request, RentalReportService $service): View
    {
        $property = $this->resolveAndAuthorizeProperty($request);
        $data = $property ? $service->propertyHistory($request->user(), $property) : ['property' => null, 'timeline' => []];

        return view('corex.rentals.reports.property-history', $data + [
            'properties' => $this->propertyOptions($request->user()),
        ]);
    }

    public function propertyHistoryPrint(Request $request, RentalReportService $service): View
    {
        $property = $this->resolveAndAuthorizeProperty($request, abortOn404: true);
        $data = $service->propertyHistory($request->user(), $property);

        return view('corex.rentals.reports.property-history-print', $data);
    }

    public function propertyHistoryPdf(Request $request, RentalReportService $service)
    {
        $property = $this->resolveAndAuthorizeProperty($request, abortOn404: true);
        $data = $service->propertyHistory($request->user(), $property);

        $pdf = Pdf::loadView('corex.rentals.reports.property-history-print', $data + ['forPdf' => true])->setPaper('a4', 'portrait');
        $this->applyPdfOptions($pdf);

        return $pdf->download('property-history-' . $property->id . '.pdf');
    }

    /** §5 — Landlord property activity report, single-record, PDF only. */
    public function landlordActivityPdf(Request $request, RentalReportService $service)
    {
        $property = $this->resolveAndAuthorizeProperty($request, abortOn404: true);
        $data = $service->landlordPropertyActivity($property, $request->all());

        $pdf = Pdf::loadView('corex.rentals.reports.landlord-activity-pdf', $data)->setPaper('a4', 'portrait');
        $this->applyPdfOptions($pdf);

        return $pdf->download('landlord-activity-' . $property->id . '-' . now()->format('Y-m-d') . '.pdf');
    }

    private function buildViewData(Request $request, RentalReportService $service, RentalCommandCentreService $cc, bool $forPrint = false): array
    {
        $user = $request->user();
        $reportKey = $request->get('report', 'fault-reports');
        if (!array_key_exists($reportKey, RentalReportService::REPORTS)) {
            $reportKey = 'fault-reports';
        }

        $params = $this->paramsFromRequest($request);
        $result = $this->runReport($reportKey, $user, $params, $service, $cc);

        if (!$forPrint) {
            $perPage = $this->resolvePerPage($request);
            $page = max(1, (int) $request->get('page', 1));
            $result['paginated'] = $service->paginateCollection($result['rows'], $perPage, $page, 'page');
        }

        return [
            'reportKey' => $reportKey,
            'reports' => RentalReportService::REPORTS,
            'result' => $result,
            'params' => $params,
            'scopeOptions' => $service->scopeOptionsFor($user),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'agents' => $this->agencyAgents($user),
            'generatedAt' => now(),
            'forPrint' => $forPrint,
        ];
    }

    private function runReport(string $reportKey, User $user, array $params, RentalReportService $service, RentalCommandCentreService $cc): array
    {
        return match ($reportKey) {
            'fault-reports' => $service->faultReports($user, $params),
            'work-orders' => $service->workOrders($user, $params),
            'lease-status' => $service->leaseStatus($user, $params, $cc),
            'lease-expiries' => $service->leaseExpiries($user, $params),
            'inspections' => $service->inspections($user, $params),
            default => $service->faultReports($user, $params),
        };
    }

    private function paramsFromRequest(Request $request): array
    {
        return [
            'scope' => $request->get('scope'),
            'period' => $request->get('period'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'buckets' => (array) $request->get('buckets', []),
            'group_by' => $request->get('group_by') ?: null,
            'q' => $request->get('q'),
            'sort' => $request->get('sort'),
            'direction' => $request->get('direction'),
            'agent_id' => $request->get('agent_id'),
            'branch_id' => $request->get('branch_id'),
            'property_id' => $request->get('property_id'),
            'supplier_id' => $request->get('supplier_id'),
            'trade_type' => $request->get('trade_type'),
            'type' => $request->get('type'),
        ];
    }

    /**
     * Property History / Landlord activity both resolve from the SAME
     * scoped property set every other rentals screen already draws from —
     * direct-URL-by-id re-checks own/branch/agency, never trusts the id
     * came from a link this screen itself rendered.
     */
    private function resolveAndAuthorizeProperty(Request $request, bool $abortOn404 = false): ?Property
    {
        $propertyId = $request->get('property_id');
        if (!$propertyId) {
            if ($abortOn404) {
                abort(404);
            }

            return null;
        }

        $user = $request->user();
        $scope = PermissionService::clampScope($request->get('scope'), PermissionService::getDataScope($user, 'rental_reports') ?? 'own');

        $query = Property::query()->where('id', $propertyId);
        if ($scope === 'branch') {
            $query->where('branch_id', $user->effectiveBranchId());
        } elseif ($scope === 'own') {
            $query->whereIn('agent_id', $user->dataIdentityIds());
        }

        $property = $query->first();
        if (!$property && $abortOn404) {
            abort(404);
        }

        return $property;
    }

    private function propertyOptions(User $user): \Illuminate\Support\Collection
    {
        $scope = PermissionService::getDataScope($user, 'rental_reports') ?? 'own';
        $query = Property::query()->orderBy('title');
        if ($scope === 'branch') {
            $query->where('branch_id', $user->effectiveBranchId());
        } elseif ($scope === 'own') {
            $query->whereIn('agent_id', $user->dataIdentityIds());
        }

        return $query->get(['id', 'title']);
    }

    private function resolvePerPage(Request $request): int
    {
        $raw = $request->integer('per_page', 25);
        foreach (self::PER_PAGE_OPTIONS as $option) {
            if ($raw <= $option) {
                return $option;
            }
        }

        return self::PER_PAGE_OPTIONS[array_key_last(self::PER_PAGE_OPTIONS)];
    }

    private function agencyAgents(User $actor): \Illuminate\Support\Collection
    {
        $agencyId = $actor->effectiveAgencyId();
        $ownerRoles = User::ownerRoleNames();

        return User::query()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->when(!empty($ownerRoles), fn ($q) => $q->whereNotIn('role', $ownerRoles))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->values();
    }

    private function exportCell($value)
    {
        if (is_float($value) || is_int($value)) {
            return $value;
        }
        $value = (string) ($value ?? '');
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /** Same dompdf convention as RentalDocumentPdfService::applyOptions() — one convention, not two. */
    private function applyPdfOptions($pdf): void
    {
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('dpi', 96);

        $fontDir = storage_path('app/dompdf-fonts');
        if (!is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }
        if (is_dir($fontDir) && is_writable($fontDir)) {
            $pdf->setOption('fontDir', $fontDir);
            $pdf->setOption('fontCache', $fontDir);
        }
    }
}
