<?php

namespace App\Http\Requests\Phase9;

use App\Enums\CheckInFrequency;
use App\Models\CheckIn;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreCheckInRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && Gate::allows('create', [CheckIn::class, $project]);
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
            'question' => ['required', 'string', 'max:255'],
            'frequency' => ['required', Rule::enum(CheckInFrequency::class)],
            'timezone' => ['required', 'timezone'],
            'next_run_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'target_user_ids' => ['required', 'array', 'min:1'],
            'target_user_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('project_user', 'user_id')->where('project_id', $projectId),
            ],
        ];
    }
}
