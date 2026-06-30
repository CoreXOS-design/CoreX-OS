<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyAdTemplate;
use Illuminate\Http\Request;

class PropertyAdTemplateController extends Controller
{
    public function builder(Request $request, PropertyAdTemplate $template = null)
    {
        if ($template) {
            $this->authorizeTemplate($template);
        }

        // Optional property context (?property={id}) — the builder previews this
        // property's real data and offers "Use on this property →".
        // AgencyScope on Property keeps cross-agency ids unresolvable.
        $property     = null;
        $propertyData = null;
        if ($request->filled('property')) {
            $property = Property::find((int) $request->query('property'));
            if ($property) {
                $property->load(['agent', 'branch', 'agency']);
                $propertyData = $property->adData();
            }
        }

        return view('corex.properties.ad-builder', compact('template', 'property', 'propertyData'));
    }

    /**
     * Upload a custom image or video for an Ad Builder "Custom Image/Video"
     * element. Stored on the public disk; the returned URL is saved into the
     * element's layout_json. Spec: ad-manager.md §9 (Ad Builder range).
     */
    public function uploadMedia(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            // 40 MB ceiling — covers short clips without letting the builder
            // become a video host. Mimes are checked server-side, never trusted.
            'file' => 'required|file|max:40960|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime',
        ]);

        $file = $request->file('file');
        $kind = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: ($kind === 'video' ? 'mp4' : 'jpg'));

        $path = $file->storeAs(
            'ad-media/' . (auth()->user()?->effectiveAgencyId() ?? 0),
            uniqid($kind . '_') . '.' . $ext,
            'public'
        );

        return response()->json([
            'ok'   => true,
            'kind' => $kind,
            'url'  => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'layout_json' => 'required|array',
        ]);

        $tpl = PropertyAdTemplate::create([
            'user_id'     => auth()->id(),
            'name'        => $data['name'],
            'layout_json' => $data['layout_json'],
            // is_global is deprecated (caused a cross-agency leak); visibility is
            // now strictly agency-scoped via AgencyScope. See ad-manager.md §3.
            'is_global'   => false,
        ]);

        return response()->json(['id' => $tpl->id, 'name' => $tpl->name]);
    }

    public function update(Request $request, PropertyAdTemplate $template)
    {
        $this->authorizeTemplate($template);

        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'layout_json' => 'required|array',
        ]);

        $template->update([
            'name'        => $data['name'],
            'layout_json' => $data['layout_json'],
        ]);

        return response()->json(['id' => $template->id, 'name' => $template->name]);
    }

    public function destroy(PropertyAdTemplate $template)
    {
        $this->authorizeTemplate($template);
        $template->delete();
        return redirect()->back()->with('success', 'Template archived.');
    }

    /**
     * Edit/delete gate (spec ad-manager.md §6): creator always; otherwise the
     * member needs `properties.ad_templates.manage`. AgencyScope already 404s
     * cross-agency templates at route-model binding, so this only decides
     * rights within the current agency.
     */
    private function authorizeTemplate(PropertyAdTemplate $template): void
    {
        if (! $template->canBeManagedBy(auth()->user())) {
            abort(403);
        }
    }
}
