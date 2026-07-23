<?php

namespace App\Http\Requests\Phase3;

use App\Models\Project;
use App\Models\Todo;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveTodoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $todo = $this->route('todo');

        if (! $project instanceof Project || ! Gate::allows('view', $project)) {
            return false;
        }

        return ! $todo instanceof Todo
            || $todo->created_by === $this->user()->id
            || Gate::allows('update', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('project_user', 'user_id')->where('project_id', $project instanceof Project ? $project->id : 0),
            ],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'completion_notify_ids' => ['nullable', 'array', 'max:50'],
            'completion_notify_ids.*' => [
                'integer',
                Rule::exists('project_user', 'user_id')->where('project_id', $project instanceof Project ? $project->id : 0),
            ],
            'subtasks' => ['nullable', 'array', 'max:50'],
            'subtasks.*' => ['required', 'array:title,assignee_id,due_on'],
            'subtasks.*.title' => ['required', 'string', 'max:255'],
            'subtasks.*.assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('project_user', 'user_id')->where('project_id', $project instanceof Project ? $project->id : 0),
            ],
            'subtasks.*.due_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['details' => RichText::sanitizeInput($this->input('details'))]);
    }
}
