<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile agent-facing view/edit of the profile fields also editable on web
 * (My Portal → Profile): FFC number, cell, WhatsApp, and the two public-page
 * social links. Same `users` row as the web edit — a change on either client
 * is visible on the other on next load, no sync logic needed.
 *
 * Spec: .ai/specs/mobile-agent-profile.md
 */
class MobileProfileController extends Controller
{
    // GET /api/v1/mobile/profile
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->shape($request->user()));
    }

    // PATCH /api/v1/mobile/profile
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('edit_own_profile'), 403);

        $data = $request->validate([
            'cell' => ['required', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50', 'regex:' . User::SA_MOBILE_REGEX],
            'ffc_number' => ['nullable', 'string', 'max:50'],
            'website_social_facebook' => ['nullable', 'string', 'max:255'],
            'website_social_instagram' => ['nullable', 'string', 'max:255'],
        ]);

        $user->fill($data);
        $user->save();

        return response()->json($this->shape($user));
    }

    private function shape(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->roleModel()?->label ?? ucfirst((string) ($user->role ?? 'agent')),
            'cell' => $user->cell,
            'whatsapp_number' => $user->whatsapp_number,
            'ffc_number' => $user->ffc_number,
            'website_social_facebook' => $user->website_social_facebook,
            'website_social_instagram' => $user->website_social_instagram,
            'public_profile_url' => $user->publicProfileUrl(),
            'can_edit' => $user->hasPermission('edit_own_profile'),
        ];
    }
}
