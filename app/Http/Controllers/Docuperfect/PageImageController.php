<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PageImageController extends Controller
{
    public function show(Request $request, $id, $page)
    {
        $template = Template::findOrFail($id);

        $page = (int) $page;
        if ($page < 0 || $page >= $template->page_count) {
            abort(404);
        }

        $path = "docuperfect/templates/{$id}/page-{$page}.png";

        if (!Storage::exists($path)) {
            abort(404);
        }

        return response(Storage::get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
