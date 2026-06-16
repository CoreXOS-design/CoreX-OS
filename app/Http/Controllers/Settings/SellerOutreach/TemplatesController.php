<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\SellerOutreach;

use App\Events\SellerOutreach\TemplateConfigured;
use App\Http\Controllers\Controller;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Services\SellerOutreach\SellerOutreachTemplateValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Outreach Templates CRUD.
 *
 * Mirrors the prospecting setup controller pattern:
 *   - permission middleware on the route group (`outreach_templates.manage`)
 *   - explicit agency assertion at controller boundary
 *   - one DB transaction per write, one TemplateConfigured event per write
 *
 * Spec: .ai/specs/seller-outreach-spec.md S4, 6.3.
 */
final class TemplatesController extends Controller
{
    public function __construct(
        private readonly SellerOutreachTemplateValidator $validator,
    ) {}

    public function index(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $whatsappTemplates = SellerOutreachTemplate::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('channel', SellerOutreachTemplate::CHANNEL_WHATSAPP)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default_for_channel')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $emailTemplates = SellerOutreachTemplate::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('channel', SellerOutreachTemplate::CHANNEL_EMAIL)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default_for_channel')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $tab = $request->query('tab', 'whatsapp');
        $activeTab = in_array($tab, ['whatsapp', 'email'], true) ? $tab : 'whatsapp';

        return view('settings.outreach-templates.index', [
            'whatsappTemplates' => $whatsappTemplates,
            'emailTemplates'    => $emailTemplates,
            'mergeFields'       => SellerOutreachTemplateValidator::KNOWN_MERGE_FIELDS,
            'activeTab'         => $activeTab,
            'agencyId'          => $agencyId,
        ]);
    }

    public function store(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $data = $this->validateRequest($request);

        $result = $this->validator->validate($data['channel'], $data['subject'] ?? null, $data['body'], (bool) ($data['include_tracking_link'] ?? true));
        if ($result->fails()) {
            return back()
                ->withErrors($result->errors)
                ->withInput()
                ->with('tab', $data['channel']);
        }

        $template = DB::transaction(function () use ($data, $agencyId) {
            if (!empty($data['is_default_for_channel'])) {
                SellerOutreachTemplate::withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->where('channel', $data['channel'])
                    ->where('is_default_for_channel', true)
                    ->update(['is_default_for_channel' => false]);
            }

            return SellerOutreachTemplate::create([
                'agency_id'              => $agencyId,
                'name'                   => $data['name'],
                'channel'                => $data['channel'],
                'subject'                => $data['subject'] ?? null,
                'body'                   => $data['body'],
                'description'            => $data['description'] ?? null,
                'is_active'              => (bool) ($data['is_active'] ?? true),
                'is_default_for_channel' => (bool) ($data['is_default_for_channel'] ?? false),
                'include_tracking_link'  => (bool) ($data['include_tracking_link'] ?? true),
            ]);
        });

        event(new TemplateConfigured(
            template:    $template,
            action:      TemplateConfigured::ACTION_CREATED,
            actorUserId: Auth::id(),
            agencyId:    $agencyId,
        ));

        return redirect()
            ->route('settings.outreach-templates.index', ['tab' => $template->channel])
            ->with('status', "Template '{$template->name}' created.");
    }

    public function update(Request $request, SellerOutreachTemplate $template)
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertSameAgency($template, $agencyId);

        $data = $this->validateRequest($request, $template->id);

        $result = $this->validator->validate($data['channel'], $data['subject'] ?? null, $data['body'], (bool) ($data['include_tracking_link'] ?? true));
        if ($result->fails()) {
            return back()
                ->withErrors($result->errors)
                ->withInput()
                ->with('tab', $data['channel']);
        }

        DB::transaction(function () use ($template, $data, $agencyId) {
            if (!empty($data['is_default_for_channel'])) {
                SellerOutreachTemplate::withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->where('channel', $data['channel'])
                    ->where('is_default_for_channel', true)
                    ->where('id', '!=', $template->id)
                    ->update(['is_default_for_channel' => false]);
            }

            $template->update([
                'name'                   => $data['name'],
                'channel'                => $data['channel'],
                'subject'                => $data['subject'] ?? null,
                'body'                   => $data['body'],
                'description'            => $data['description'] ?? null,
                'is_active'              => (bool) ($data['is_active'] ?? false),
                'is_default_for_channel' => (bool) ($data['is_default_for_channel'] ?? false),
                'include_tracking_link'  => (bool) ($data['include_tracking_link'] ?? true),
            ]);
        });

        event(new TemplateConfigured(
            template:    $template->fresh(),
            action:      TemplateConfigured::ACTION_UPDATED,
            actorUserId: Auth::id(),
            agencyId:    $agencyId,
        ));

        return redirect()
            ->route('settings.outreach-templates.index', ['tab' => $template->channel])
            ->with('status', "Template '{$template->name}' updated.");
    }

    public function archive(Request $request, SellerOutreachTemplate $template)
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertSameAgency($template, $agencyId);

        if ($template->is_default_for_channel) {
            return redirect()
                ->route('settings.outreach-templates.index', ['tab' => $template->channel])
                ->with('error', "Cannot archive '{$template->name}' — it's the default {$template->channel} template. Set another template as default first.");
        }

        $template->delete();

        event(new TemplateConfigured(
            template:    $template,
            action:      TemplateConfigured::ACTION_ARCHIVED,
            actorUserId: Auth::id(),
            agencyId:    $agencyId,
        ));

        return redirect()
            ->route('settings.outreach-templates.index', ['tab' => $template->channel])
            ->with('status', "Template '{$template->name}' archived.");
    }

    public function restore(Request $request, int $templateId)
    {
        $agencyId = $this->resolveAgencyId($request);

        $template = SellerOutreachTemplate::withoutGlobalScopes()
            ->withTrashed()
            ->where('id', $templateId)
            ->where('agency_id', $agencyId)
            ->firstOrFail();

        $template->restore();

        event(new TemplateConfigured(
            template:    $template,
            action:      TemplateConfigured::ACTION_RESTORED,
            actorUserId: Auth::id(),
            agencyId:    $agencyId,
        ));

        return redirect()
            ->route('settings.outreach-templates.index', ['tab' => $template->channel])
            ->with('status', "Template '{$template->name}' restored.");
    }

    private function validateRequest(Request $request, ?int $templateId = null): array
    {
        return $request->validate([
            'name'                   => 'required|string|max:150',
            'channel'                => 'required|in:whatsapp,email',
            'subject'                => 'nullable|string|max:255|required_if:channel,email',
            'body'                   => 'required|string',
            'description'            => 'nullable|string|max:1000',
            'is_active'              => 'nullable|boolean',
            'is_default_for_channel' => 'nullable|boolean',
            'include_tracking_link'  => 'nullable|boolean',
        ]);
    }

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $id = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        abort_if($id === null, 403, 'No agency context — super_admin without an active agency cannot edit templates.');
        return (int) $id;
    }

    private function assertSameAgency(SellerOutreachTemplate $template, int $agencyId): void
    {
        if ((int) $template->agency_id !== $agencyId) {
            abort(404);
        }
    }
}
