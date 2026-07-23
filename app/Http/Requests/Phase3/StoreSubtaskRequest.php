<?php

namespace App\Http\Requests\Phase3;

use App\Models\Project;
use App\Models\Todo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreSubtaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $todo = $this->route('todo');

        return $project instanceof Project
            && $todo instanceof Todo
            && $todo->parent_id === null
            && Gate::allows('view', $project);
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
            'assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('project_user', 'user_id')->where(
                    'project_id',
                    $project instanceof Project ? $project->id : 0,
                ),
            ],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
