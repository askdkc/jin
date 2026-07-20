<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'scope' => ['required', Rule::in(['everything', 'mentions'])],
            'event_reminders' => ['required', 'boolean'],
            'assignment_reminders' => ['required', 'boolean'],
            'in_app' => ['required', 'boolean'],
            'email' => ['required', 'boolean'],
            'show_unread_count' => ['required', 'boolean'],
            'schedule' => ['required', Rule::in(['always', 'work_hours'])],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
        ];
    }
}
