<?php

namespace App\Http\Requests\Phase3;

use App\Models\Project;
use App\Models\TodoList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateTodoListProgressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $todoList = $this->route('todo_list');

        return $project instanceof Project
            && $todoList instanceof TodoList
            && $todoList->project_id === $project->id
            && Gate::allows('view', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hill_position' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
