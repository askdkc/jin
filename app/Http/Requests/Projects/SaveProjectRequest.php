<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->route('current_team');
        $project = $this->route('project');

        if (! $team instanceof Team) {
            return false;
        }

        if ($project instanceof Project) {
            return Gate::allows('update', $project);
        }

        return Gate::allows('create', [Project::class, $team]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $team = $this->route('current_team');

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_folder_id' => [
                'nullable',
                'integer',
                Rule::exists('project_folders', 'id')->where('team_id', $team instanceof Team ? $team->id : 0),
            ],
            'lead_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'user_id')->where('team_id', $team instanceof Team ? $team->id : 0),
            ],
            'phase' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['on_track', 'at_risk', 'off_track', 'complete'])],
            'enabled_tools_present' => ['sometimes', 'boolean'],
            'enabled_tools' => ['array', 'list'],
            'enabled_tools.*' => ['string', 'distinct', Rule::in(Project::AVAILABLE_TOOLS)],
            'allows_all_clients' => ['sometimes', 'boolean'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'member_ids' => ['array'],
            'member_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('team_members', 'user_id')->where('team_id', $team instanceof Team ? $team->id : 0),
            ],
        ];
    }
}
