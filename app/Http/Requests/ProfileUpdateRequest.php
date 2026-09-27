<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * AT-423 — a sub-user's sign-in is a username only an admin changes, so whatever the
     * form posts for `email` is ignored and their current username is kept. This also keeps
     * the "email changed → invite pending again" reset in the profile controllers from ever
     * firing for them. Spec one-email-sub-users.md §6.6 / §8.
     */
    protected function prepareForValidation(): void
    {
        if ($this->user()?->isSubUser()) {
            $this->merge(['email' => $this->user()->email]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'cell' => ['required', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50', 'regex:' . User::SA_MOBILE_REGEX],
            'fax' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'ffc_number' => ['nullable', 'string', 'max:50'],
            'ffc_expiry_date' => ['nullable', 'date', 'after:today'],
            'id_number' => ['nullable', 'string', 'max:20'],
            // Public agent profile (shown on the agency website).
            'about_me' => ['nullable', 'string', 'max:5000'],
            'website_social_facebook' => ['nullable', 'string', 'max:255'],
            'website_social_instagram' => ['nullable', 'string', 'max:255'],
            'website_social_linkedin' => ['nullable', 'string', 'max:255'],
            'website_social_youtube' => ['nullable', 'string', 'max:255'],
        ];
    }
}
