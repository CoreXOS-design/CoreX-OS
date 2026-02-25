<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Pack;
use App\Models\Docuperfect\Template;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $documents = Document::active()
            ->visibleTo($user)
            ->with(['template', 'owner'])
            ->orderByDesc('updated_at')
            ->get();

        return view('docuperfect.dashboard', compact('documents', 'user'));
    }

    public function create(Request $request)
    {
        $user = $request->user();

        $templates = Template::active()
            ->visibleTo($user)
            ->where('page_count', '>', 0)
            ->with(['documentType', 'branches'])
            ->orderBy('name')
            ->get();

        $documentTypes = DocumentType::orderBy('sort_order')->get();

        $packs = Pack::visibleTo($user)
            ->with(['templates', 'slots', 'branches', 'owner'])
            ->orderBy('name')
            ->get();

        return view('docuperfect.create', compact('templates', 'documentTypes', 'packs', 'user'));
    }
}
