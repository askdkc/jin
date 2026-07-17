<?php

namespace App\Http\Requests\Chat;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ChatHistoryRequest extends FormRequest
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
        return [
            'channel_id' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:1000', 'prohibits:after_id'],
            'after_id' => ['nullable', 'integer', 'min:0', 'prohibits:cursor'],
        ];
    }
}
