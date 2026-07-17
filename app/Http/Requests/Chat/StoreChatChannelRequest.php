<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatChannel;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreChatChannelRequest extends FormRequest
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

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique(ChatChannel::class)->where(
                    'project_id',
                    $project instanceof Project ? $project->id : 0,
                ),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
