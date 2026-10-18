<?php

namespace App\Http\Requests\Publisher;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerRequest extends FormRequest
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
            'host' => ['bail', 'required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                $host = $value;
                $port = null;
                if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $value, $parts)) {
                    $host = $parts[1];
                    $port = $parts[2] ?? null;
                } elseif (substr_count($value, ':') === 1) {
                    [$host, $port] = explode(':', $value, 2);
                }
                $validHost = filter_var($host, FILTER_VALIDATE_IP) || (str_contains($host, '.') && ! preg_match('/^[0-9.]+$/', $host) && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME));
                if (! $validHost || ($port !== null && (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535))) {
                    $fail('Enter a valid domain or IP address, optionally followed by a port.');
                }
            }, Rule::unique('servers', 'host')->where('publisher_account_id', $this->user()->publisherAccount?->id)->whereNull('deleted_at')],
            'category' => ['required', Rule::in(['survival', 'skyblock', 'factions', 'minigames', 'creative', 'anarchy', 'smp', 'other'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $host = $this->input('host');
        if (is_string($host)) {
            $host = strtolower(trim($host));
            if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $host, $parts)
                && filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $host = '['.inet_ntop(inet_pton($parts[1])).']'.(isset($parts[2]) ? ':'.(int) $parts[2] : '');
            }
            if (substr_count($host, ':') === 1 || str_starts_with($host, '[')) {
                $host = preg_replace('/:0*25565$/', '', $host);
            }
            if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $host = '['.inet_ntop(inet_pton(trim($host, '[]'))).']';
            } elseif (preg_match('/^([^:]+)(?::([0-9]+))?$/', $host, $parts)) {
                $host = rtrim($parts[1], '.').(isset($parts[2]) ? ':'.(int) $parts[2] : '');
            }
            $this->merge(['host' => $host]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
