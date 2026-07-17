<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatMessage;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ChatThreadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $message = $this->route('chat_message');

        return $project instanceof Project
            && $message instanceof ChatMessage
            && $message->project_id === $project->id
            && $message->parent_id === null
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
            'cursor' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
