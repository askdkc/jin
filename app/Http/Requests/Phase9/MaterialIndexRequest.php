<?php

namespace App\Http\Requests\Phase9;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MaterialIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && Gate::allows('view', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;

        return [
            'type' => ['nullable', Rule::in(['document', 'file', 'link'])],
            'folder' => [
                'nullable',
                'integer',
                Rule::exists('material_folders', 'id')->where('project_id', $projectId),
            ],
            'updater' => [
                'nullable',
                'integer',
                Rule::exists('project_user', 'user_id')->where('project_id', $projectId),
            ],
        ];
    }
}
