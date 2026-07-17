<?php

namespace App\Http\Requests\Phase3;

use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveScheduleEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $event = $this->route('schedule_event');

        if (! $project instanceof Project || ! Gate::allows('view', $project)) {
            return false;
        }

        return ! $event instanceof ScheduleEvent
            || $event->created_by === $this->user()->id
            || Gate::allows('update', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'meeting_url' => ['nullable', 'url:http,https', 'max:2048'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\\TH:i', 'after_or_equal:starts_at'],
            'all_day' => ['boolean'],
            'recurrence' => ['nullable', Rule::in(ScheduleEvent::RECURRENCES)],
            'recurrence_until' => ['nullable', 'date_format:Y-m-d', 'required_with:recurrence'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'all_day' => $this->boolean('all_day'),
            'details' => RichText::sanitizeInput($this->input('details')),
        ]);
    }
}
