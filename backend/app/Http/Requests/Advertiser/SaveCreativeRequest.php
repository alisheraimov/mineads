<?php

namespace App\Http\Requests\Advertiser;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCreativeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'campaign_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer'],
            'language' => ['required', 'string', Rule::in(config('campaigns.languages'))],
            'message_text' => ['required', 'string', 'max:512', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F]/u'],
            'click_url' => ['nullable', 'url:http,https', 'max:255'],
        ];
    }
}
