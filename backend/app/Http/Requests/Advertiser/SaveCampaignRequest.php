<?php

namespace App\Http\Requests\Advertiser;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'advertiser';
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'daily_budget_micros' => ['required', 'integer', 'min:10000', 'max:1000000000000'],
            'total_budget_micros' => ['required', 'integer', 'gte:daily_budget_micros', 'max:1000000000000'],
            'countries' => ['required', 'array', 'min:1', 'max:249'],
            'countries.*' => ['required', 'string', 'distinct', Rule::in(config('campaigns.countries'))],
            'languages' => ['required', 'array', 'min:1', 'max:20'],
            'languages.*' => ['required', 'string', 'distinct', Rule::in(config('campaigns.languages'))],
            'server_categories' => ['required', 'array', 'min:1', 'max:5'],
            'server_categories.*' => ['required', 'string', 'distinct', Rule::in(config('campaigns.server_categories'))],
        ];
    }
}
