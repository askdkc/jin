<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatMessage;
use App\Models\Project;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateChatMessageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'body' => RichText::sanitizeInput($this->input('body')),
        ]);
    }

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
            && Gate::allows('view', $project)
            && ($message->user_id === $this->user()->id || Gate::allows('update', $project));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $message = $this->route('chat_message');
        $hasAttachments = $message instanceof ChatMessage
            && $message->attachments()->exists();

        return [
            'body' => [$hasAttachments ? 'nullable' : 'required', 'string', 'max:20000'],
        ];
    }
}
