<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\WordingVersion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Blade;

/**
 * Builds the contract PDF (spec §11.6): letterhead and footer on every page, one page division per planned page,
 * and — for the sealed copy — the signing record. "Page x of y" comes from a two-pass render.
 */
class AgreementPdf
{
    public function __construct(private AgreementRenderer $renderer)
    {
    }

    /** @return array<int,array{part:string,no:int,blocks:string[]}> */
    public function pages(WordingVersion $v, array $layout, string $mode, array $ctx): array
    {
        $pages = [];
        $no = 0;
        foreach (array_keys(AgreementContent::PARTS) as $part) {
            $blocks = $this->renderer->blocks($v, $part, $mode, $ctx);
            $counts = $layout['parts'][$part] ?? [count($blocks)];
            if (array_sum($counts) !== count($blocks)) {
                $counts = [count($blocks)]; // layout is stale for this content — render the part as one flow rather than lose text
            }
            $at = 0;
            foreach ($counts as $c) {
                $pages[] = ['part' => $part, 'no' => ++$no, 'blocks' => array_slice($blocks, $at, $c)];
                $at += $c;
            }
        }

        return $pages;
    }

    /** Physical page count of the contract alone (no signing record) — used by calibration. */
    public function countContractPages(WordingVersion $v, array $layout, array $ctx): int
    {
        return self::countPdfPages($this->render($v, $layout, 'pdf', $ctx, '00'));
    }

    /**
     * @param array{doc:Document,consent:string,pages_initialled:int}|null $cert
     */
    public function render(WordingVersion $v, array $layout, string $mode, array $ctx, string|int $total = '00', ?array $cert = null): string
    {
        $pages = $this->pages($v, $layout, $mode, $ctx);

        $co = $ctx['company'] ?? app(AgreementCompany::class);

        return Pdf::loadView('platform-esign.pdf.agreement', [
            'title' => 'CoreX OS Subscription Agreement', 'logo' => $co->logoDataUri(), 'logoBox' => $co->logoBoxPt(), 'letterhead' => $co->letterhead(), 'brand' => $co->brand(), 'versionLabel' => $v->label(),
            'total' => $total, 'pages' => $pages, 'mode' => $mode, 'initials' => $ctx['initials'] ?? [], 'cert' => $cert,
        ])->setPaper('a4')->output();
    }

    /** Two-pass: first pass finds the physical page count, second prints it as "of y". */
    public function renderWithTotal(WordingVersion $v, array $layout, string $mode, array $ctx, ?array $cert = null): array
    {
        $first = $this->render($v, $layout, $mode, $ctx, '00', $cert);
        $count = self::countPdfPages($first);

        return [$this->render($v, $layout, $mode, $ctx, $count, $cert), $count];
    }

    public static function countPdfPages(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);
    }
}
