<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
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
