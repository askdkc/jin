<?php

namespace App\Http\Requests\Phase3;

use App\Models\Project;
use App\Models\Todo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MoveTodoRequest extends FormRequest
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
            'target_list_id' => [
                'required',
                'integer',
                Rule::exists('todo_lists', 'id')->where('project_id', $project instanceof Project ? $project->id : 0),
            ],
            'target_position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
