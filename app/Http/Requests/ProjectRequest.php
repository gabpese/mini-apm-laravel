<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates creating and updating a project. Ownership is checked by the
 * controller through the ProjectPolicy.
 */
class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'min_ram_mb' => ['nullable', 'integer', 'min:0', 'max:1048576'],
            'min_os' => ['nullable', 'string', 'max:100'],
            'regression_ratio' => ['sometimes', 'numeric', 'min:1', 'max:99'],
            'regression_min_sessions' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
