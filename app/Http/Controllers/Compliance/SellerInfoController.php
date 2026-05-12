<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Mail\Compliance\SellerInfoMail;
use App\Models\Agency;
use App\Models\Compliance\SellerInfoShareLink;
use App\Models\Compliance\WhistleblowEmailLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SellerInfoController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $properties = \App\Models\Property::withoutGlobalScopes()
            ->where('agency_id', $user->effectiveAgencyId() ?? $user->agency_id)
            ->whereNotNull('address')
            ->orderBy('address')
            ->select('id', 'address', 'suburb', 'title')
            ->limit(200)
            ->get();

        return view('compliance.seller-info.index', compact('properties'));
    }

    public function preview(Request $request)
    {
        $request->validate([
            'tier'          => 'required|in:tier_1,tier_2,tier_3',
            'seller_name'   => 'nullable|string|max:255',
            'agent_message' => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $agency = Agency::withoutGlobalScopes()->find($user->effectiveAgencyId() ?? $user->agency_id);

        $html = view('emails.compliance.seller-info.' . str_replace('tier_', 'tier', $request->tier), [
            'agency'       => $agency,
            'agentMessage' => $request->agent_message ?? '',
            'sellerName'   => $request->seller_name ?? 'Seller',
        ])->render();

        return response()->json(['html' => $html]);
    }

    public function send(Request $request)
    {
        $request->validate([
            'tier'          => 'required|in:tier_1,tier_2,tier_3',
            'seller_email'  => 'required|email|max:255',
            'seller_name'   => 'nullable|string|max:255',
            'agent_message' => 'nullable|string|max:500',
            'property_id'   => 'nullable|integer|exists:properties,id',
            'contact_id'    => 'nullable|integer|exists:contacts,id',
        ]);

        $user = Auth::user();
        $agency = Agency::withoutGlobalScopes()->find($user->effectiveAgencyId() ?? $user->agency_id);
        $sellerName = $request->seller_name ?: 'Valued Seller';

        $mailable = new SellerInfoMail($agency, $request->tier, $sellerName, $request->agent_message ?? '');

        // Pre-render for log
        $renderedHtml = $mailable->render();
        $renderedText = strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>'], "\n", $renderedHtml));
        $agencyShort = $agency->trading_name ?? $agency->name;
        $emailSubject = "[{$agencyShort}] " . match($request->tier) {
            'tier_1' => 'Why Proper Paperwork Protects YOU',
            'tier_2' => 'Why an FFC Matters When Choosing an Agent',
            'tier_3' => "Important: Verifying Your Agent's Credentials",
        };

        try {
            Mail::to($request->seller_email)->send($mailable);

            WhistleblowEmailLog::create([
                'complaint_id'    => null,
                'sent_at'         => now(),
                'email_type'      => 'seller_info_email',
                'subject'         => $emailSubject,
                'recipients_to'   => [$request->seller_email],
                'recipients_cc'   => [],
                'rendered_html'   => $renderedHtml,
                'rendered_text'   => $renderedText,
                'sent_by_user_id' => $user->id,
                'status'          => 'sent',
            ]);

            return redirect()->route('compliance.seller-info.index')
                ->with('success', "Seller information email sent to {$request->seller_email}.");
        } catch (\Throwable $e) {
            WhistleblowEmailLog::create([
                'complaint_id'    => null,
                'sent_at'         => now(),
                'email_type'      => 'seller_info_email',
                'subject'         => $emailSubject,
                'recipients_to'   => [$request->seller_email],
                'recipients_cc'   => [],
                'rendered_html'   => $renderedHtml ?? '',
                'rendered_text'   => $renderedText ?? '',
                'sent_by_user_id' => $user->id,
                'status'          => 'failed',
                'error_message'   => $e->getMessage(),
            ]);

            return redirect()->route('compliance.seller-info.index')
                ->with('error', 'Failed to send email: ' . $e->getMessage());
        }
    }

    public function generateWhatsappLink(Request $request)
    {
        $request->validate([
            'tier'          => 'required|in:tier_1,tier_2,tier_3',
            'seller_name'   => 'nullable|string|max:255',
            'seller_email'  => 'nullable|email|max:255',
            'agent_message' => 'nullable|string|max:500',
            'property_id'   => 'nullable|integer|exists:properties,id',
            'contact_id'    => 'nullable|integer|exists:contacts,id',
        ]);

        $user = Auth::user();
        $agency = Agency::withoutGlobalScopes()->find($user->effectiveAgencyId() ?? $user->agency_id);

        $link = SellerInfoShareLink::create([
            'tier'             => $request->tier,
            'seller_name'      => $request->seller_name,
            'seller_email'     => $request->seller_email,
            'agent_message'    => $request->agent_message,
            'property_id'      => $request->property_id,
            'contact_id'       => $request->contact_id,
            'sent_by_user_id'  => $user->id,
            'agency_id'        => $agency->id,
            'token'            => Str::random(32),
            'expires_at'       => now()->addDays(90),
        ]);

        // Log as WhatsApp link generation
        WhistleblowEmailLog::create([
            'complaint_id'    => null,
            'sent_at'         => now(),
            'email_type'      => 'seller_info_whatsapp_link',
            'subject'         => 'WhatsApp shareable link generated for ' . ($request->seller_name ?? 'seller'),
            'recipients_to'   => ['WhatsApp link'],
            'recipients_cc'   => [],
            'rendered_html'   => '',
            'rendered_text'   => '',
            'sent_by_user_id' => $user->id,
            'status'          => 'sent',
        ]);

        $url = url('/info/' . $link->token);

        return response()->json(['url' => $url, 'token' => $link->token]);
    }
}
