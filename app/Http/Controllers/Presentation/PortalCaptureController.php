<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\PortalCapture;
use App\Models\Presentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortalCaptureController extends Controller
{
    /**
     * POST /portal-captures/ingest
     * Receives capture payload from the Chrome extension.
     */
    public function ingest(Request $request)
    {
        $validated = $request->validate([
            'source_site'        => 'required|string|max:100',
            'page_type'          => 'required|string|in:search,property,unknown',
            'source_url'         => 'required|url|max:2000',
            'final_url'          => 'required|url|max:2000',
            'page_title'         => 'nullable|string|max:500',
            'captured_at'        => 'required|date',
            'extractor_version'  => 'required|string|max:50',
            'html'               => 'required|string',
            'screenshot'         => 'nullable|string', // base64 PNG
            'presentation_id'    => 'nullable|integer|exists:presentations,id',
            'parse_status'       => 'required|string|in:parsed,unparsed_jsonld_missing,unparsed_error',
            'extracted_fields'   => 'nullable|array',
            'jsonld'             => 'nullable|array',
            'found_image_urls'   => 'nullable|array',
        ]);

        $html      = $validated['html'];
        $htmlBytes  = strlen($html);
        $domHash    = hash('sha256', $html);

        $capture = PortalCapture::create([
            'user_id'                => $request->user()->id,
            'presentation_id'        => $validated['presentation_id'] ?? null,
            'source_site'            => $validated['source_site'],
            'page_type'              => $validated['page_type'],
            'source_url'             => $validated['source_url'],
            'final_url'              => $validated['final_url'],
            'page_title'             => $validated['page_title'] ?? null,
            'captured_at'            => $validated['captured_at'],
            'extractor_version'      => $validated['extractor_version'],
            'dom_hash_sha256'        => $domHash,
            'html_bytes'             => $htmlBytes,
            'raw_html_path'          => '',  // set after ID assigned
            'screenshot_path'        => null,
            'parse_status'           => $validated['parse_status'],
            'extracted_fields_json'  => $validated['extracted_fields'] ?? null,
            'jsonld_json'            => $validated['jsonld'] ?? null,
            'found_image_urls_json'  => $validated['found_image_urls'] ?? null,
        ]);

        // Store raw HTML
        $htmlPath = 'portal_captures/' . $capture->id . '.html';
        Storage::disk('local')->put($htmlPath, $html);
        $capture->raw_html_path = $htmlPath;

        // Store screenshot if provided
        if (!empty($validated['screenshot'])) {
            $pngData = base64_decode($validated['screenshot'], true);
            if ($pngData !== false) {
                $screenshotPath = 'portal_captures/' . $capture->id . '.png';
                Storage::disk('local')->put($screenshotPath, $pngData);
                $capture->screenshot_path = $screenshotPath;
            }
        }

        $capture->save();

        return response()->json([
            'success'    => true,
            'capture_id' => $capture->id,
            'dom_hash'   => $domHash,
            'html_bytes' => $htmlBytes,
        ], 201);
    }

    /**
     * GET /presentations/{presentation}/portal-captures
     * Returns captures for a presentation + user's unattached (JSON).
     */
    public function index(Presentation $presentation)
    {
        $attached = PortalCapture::where('presentation_id', $presentation->id)
            ->orderByDesc('captured_at')
            ->get([
                'id', 'source_site', 'page_type', 'source_url', 'page_title',
                'captured_at', 'extractor_version', 'html_bytes', 'dom_hash_sha256',
                'parse_status', 'extracted_fields_json', 'screenshot_path',
            ]);

        $unattached = PortalCapture::where('user_id', auth()->id())
            ->whereNull('presentation_id')
            ->orderByDesc('captured_at')
            ->limit(20)
            ->get([
                'id', 'source_site', 'page_type', 'source_url', 'page_title',
                'captured_at', 'extractor_version', 'html_bytes', 'dom_hash_sha256',
                'parse_status', 'extracted_fields_json', 'screenshot_path',
            ]);

        return response()->json([
            'attached'   => $attached,
            'unattached' => $unattached,
        ]);
    }

    /**
     * POST /presentations/{presentation}/portal-captures/{capture}/attach
     */
    public function attach(Presentation $presentation, PortalCapture $capture)
    {
        $capture->update(['presentation_id' => $presentation->id]);

        return response()->json(['success' => true, 'capture_id' => $capture->id]);
    }
}
