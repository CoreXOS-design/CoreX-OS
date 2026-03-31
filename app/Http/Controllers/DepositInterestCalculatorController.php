<?php

namespace App\Http\Controllers;

use App\Models\DepositInterestCalculation;
use App\Models\DepositTrustInterest;
use App\Services\DepositInterestCalculatorService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DepositInterestCalculatorController extends Controller
{
    public function __construct(private DepositInterestCalculatorService $calculator)
    {
    }

    public function index()
    {
        abort_unless(auth()->user()?->hasPermission('access_deposit_calculator'), 403);

        $dateRange = $this->getDateRange();

        return view('deposit-interest-calculator.index', [
            'minDate' => $dateRange['min'],
            'maxDate' => $dateRange['max'],
            'result' => null,
            'input' => null,
            'topupsJson' => [],
        ]);
    }

    public function calculate(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_deposit_calculator'), 403);

        $data = $this->validateInput($request);
        $topups = $this->parseTopups($data['topups'] ?? []);

        $result = $this->calculator->calculate(
            (float) $data['deposit_amount'],
            $data['invest_date'],
            $data['refund_date'],
            $topups,
        );

        $dateRange = $this->getDateRange();

        return view('deposit-interest-calculator.index', [
            'minDate' => $dateRange['min'],
            'maxDate' => $dateRange['max'],
            'result' => $result,
            'input' => $data,
            'topupsJson' => $this->topupsForJson($data['topups'] ?? []),
        ]);
    }

    public function save(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_deposit_calculator'), 403);

        $data = $this->validateInput($request);
        $topups = $this->parseTopups($data['topups'] ?? []);

        $result = $this->calculator->calculate(
            (float) $data['deposit_amount'],
            $data['invest_date'],
            $data['refund_date'],
            $topups,
        );

        // Serialize breakdown: convert Carbon dates to strings for JSON storage
        $breakdownForStorage = array_map(function ($row) {
            $row['date'] = $row['date'] instanceof Carbon ? $row['date']->format('Y-m-d') : $row['date'];
            return $row;
        }, $result['breakdown']);

        DepositInterestCalculation::create([
            'user_id' => auth()->id(),
            'property_name' => $data['property_name'],
            'deposit_amount' => $data['deposit_amount'],
            'invest_date' => $data['invest_date'],
            'refund_date' => $data['refund_date'],
            'topups' => $topups ?: null,
            'total_deposited' => $result['total_deposited'],
            'total_interest' => $result['total_interest'],
            'grand_total' => $result['grand_total'],
            'breakdown' => $breakdownForStorage,
        ]);

        return back()->with('status', 'Calculation saved to history.');
    }

    public function history(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_deposit_calc_history'), 403);

        $query = DepositInterestCalculation::with('user')->orderBy('created_at', 'desc');

        // Admin sees all; others see only their own
        if (!auth()->user()->is_admin) {
            $query->where('user_id', auth()->id());
        }

        // Search by property name
        if ($search = $request->input('search')) {
            $query->where('property_name', 'like', '%' . $search . '%');
        }

        $calculations = $query->paginate(20)->withQueryString();

        return view('deposit-interest-calculator.history', compact('calculations'));
    }

    public function show(DepositInterestCalculation $calculation)
    {
        abort_unless(auth()->user()?->hasPermission('access_deposit_calc_history'), 403);

        // Non-admin can only view own
        if (!auth()->user()->is_admin && $calculation->user_id !== auth()->id()) {
            abort(403);
        }

        $dateRange = $this->getDateRange();

        // Rebuild result from stored data
        $result = [
            'deposit_amount' => (float) $calculation->deposit_amount,
            'topups_total' => collect($calculation->topups ?? [])->sum('amount'),
            'total_deposited' => (float) $calculation->total_deposited,
            'total_interest' => (float) $calculation->total_interest,
            'grand_total' => (float) $calculation->grand_total,
            'breakdown' => array_map(function ($row) {
                $row['date'] = Carbon::parse($row['date']);
                return $row;
            }, $calculation->breakdown),
        ];

        $input = [
            'property_name' => $calculation->property_name,
            'deposit_amount' => $calculation->deposit_amount,
            'invest_date' => $calculation->invest_date->format('Y-m-d'),
            'refund_date' => $calculation->refund_date->format('Y-m-d'),
            'topups' => $calculation->topups ?? [],
        ];

        return view('deposit-interest-calculator.index', [
            'minDate' => $dateRange['min'],
            'maxDate' => $dateRange['max'],
            'result' => $result,
            'input' => $input,
            'topupsJson' => $this->topupsForJson($calculation->topups ?? []),
        ]);
    }

    public function destroy(DepositInterestCalculation $calculation)
    {
        abort_unless(auth()->user()?->hasPermission('access_deposit_calc_history'), 403);

        if (!auth()->user()->is_admin && $calculation->user_id !== auth()->id()) {
            abort(403);
        }

        $calculation->delete();

        return back()->with('status', 'Calculation deleted.');
    }

    public function downloadPdf(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_deposit_calculator'), 403);

        $data = $this->validateInput($request);
        $topups = $this->parseTopups($data['topups'] ?? []);

        $result = $this->calculator->calculate(
            (float) $data['deposit_amount'],
            $data['invest_date'],
            $data['refund_date'],
            $topups,
        );

        // Get agency for header
        $agency = \App\Models\Agency::first();
        $logoBase64 = null;
        if ($agency && $agency->logo_path) {
            $logoFullPath = storage_path('app/public/' . $agency->logo_path);
            if (file_exists($logoFullPath)) {
                $ext = pathinfo($agency->logo_path, PATHINFO_EXTENSION);
                $logoBase64 = 'data:image/' . $ext . ';base64,' . base64_encode(file_get_contents($logoFullPath));
            }
        }

        // Render self-contained HTML
        $html = view('deposit-interest-calculator.pdf', [
            'result' => $result,
            'input' => $data,
            'agency' => $agency,
            'logoBase64' => $logoBase64,
            'generatedDate' => now()->format('d F Y'),
        ])->render();

        // Write HTML to temp file
        $htmlPath = storage_path('app/tmp_deposit_interest_' . uniqid() . '.html');
        file_put_contents($htmlPath, $html);

        // Convert to PDF via Puppeteer
        $pdfPath = $this->convertHtmlToPdf($htmlPath);

        // Clean up temp HTML
        @unlink($htmlPath);

        $filename = 'Deposit Interest Statement - ' . ($data['property_name'] ?? 'Statement') . '.pdf';

        return response()->download($pdfPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    public function downloadTenantPdf(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_deposit_calculator'), 403);

        $data = $this->validateInput($request);
        $topups = $this->parseTopups($data['topups'] ?? []);

        $result = $this->calculator->calculate(
            (float) $data['deposit_amount'],
            $data['invest_date'],
            $data['refund_date'],
            $topups,
        );

        $agency = \App\Models\Agency::first();
        $logoBase64 = null;
        if ($agency && $agency->logo_path) {
            $logoFullPath = storage_path('app/public/' . $agency->logo_path);
            if (file_exists($logoFullPath)) {
                $ext = pathinfo($agency->logo_path, PATHINFO_EXTENSION);
                $logoBase64 = 'data:image/' . $ext . ';base64,' . base64_encode(file_get_contents($logoFullPath));
            }
        }

        $html = view('deposit-interest-calculator.pdf-tenant', [
            'result' => $result,
            'input' => $data,
            'agency' => $agency,
            'logoBase64' => $logoBase64,
            'generatedDate' => now()->format('d F Y'),
        ])->render();

        $htmlPath = storage_path('app/tmp_deposit_interest_tenant_' . uniqid() . '.html');
        file_put_contents($htmlPath, $html);

        $pdfPath = $this->convertHtmlToPdf($htmlPath);

        @unlink($htmlPath);

        $filename = 'Deposit Interest Statement - ' . ($data['property_name'] ?? 'Statement') . '.pdf';

        return response()->download($pdfPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'property_name' => ['required', 'string', 'max:255'],
            'deposit_amount' => ['required', 'numeric', 'min:1'],
            'invest_date' => ['required', 'date'],
            'refund_date' => ['required', 'date', 'after_or_equal:invest_date'],
            'topups' => ['nullable', 'array'],
            'topups.*.date' => ['required', 'date', 'after:invest_date', 'before_or_equal:refund_date'],
            'topups.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);
    }

    private function parseTopups(array $topups): array
    {
        return array_map(fn ($t) => [
            'date' => $t['date'],
            'amount' => (float) $t['amount'],
        ], $topups);
    }

    private function topupsForJson(array $topups): array
    {
        return array_values(array_map(fn ($t) => [
            'date' => $t['date'] ?? '',
            'amount' => $t['amount'] ?? '',
        ], $topups));
    }

    private function getDateRange(): array
    {
        $min = DepositTrustInterest::orderBy('interest_date')->value('interest_date');
        $max = DepositTrustInterest::orderBy('interest_date', 'desc')->value('interest_date');

        return [
            'min' => $min ? Carbon::parse($min)->format('Y-m-d') : null,
            'max' => $max ? Carbon::parse($max)->format('Y-m-d') : null,
        ];
    }

    private function convertHtmlToPdf(string $htmlFilePath): string
    {
        $tmpPath = storage_path('app/tmp_deposit_interest_' . uniqid() . '.pdf');
        $scriptPath = base_path('scripts/html-to-pdf.mjs');

        $wrapper = env('PDF_NODE_WRAPPER', '');
        $browserPath = env('PUPPETEER_BROWSER_PATH', '');
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        $scriptArg = escapeshellarg(str_replace('\\', '/', $scriptPath));
        $htmlArg   = escapeshellarg(str_replace('\\', '/', $htmlFilePath));
        $outArg    = escapeshellarg(str_replace('\\', '/', $tmpPath));

        if ($wrapper) {
            $command = sprintf('sudo %s %s %s %s 2>&1', escapeshellarg($wrapper), $scriptArg, $htmlArg, $outArg);
        } else {
            $envPrefix = '';
            if (!$isWindows) {
                $envPrefix = 'HOME=/tmp';
                if ($browserPath) {
                    $envPrefix .= sprintf(' PUPPETEER_BROWSER_PATH=%s', escapeshellarg($browserPath));
                }
                $envPrefix .= ' ';
            }
            $command = sprintf('%snode %s %s %s 2>&1', $envPrefix, $scriptArg, $htmlArg, $outArg);
        }

        $output = shell_exec($command);

        if (!file_exists($tmpPath)) {
            $errorMsg = $output ?: 'unknown error';
            logger()->error('Deposit interest PDF generation failed', ['command' => $command, 'output' => $errorMsg]);
            abort(500, 'PDF generation failed. Check that Node.js and a Chromium-based browser are installed.');
        }

        return $tmpPath;
    }
}
