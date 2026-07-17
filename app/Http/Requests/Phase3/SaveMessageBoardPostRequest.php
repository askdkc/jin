<?php

namespace App\Http\Requests\Phase3;

use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveMessageBoardPostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $post = $this->route('message_board_post');

        if (! $project instanceof Project || ! Gate::allows('view', $project)) {
            return false;
        }

        return ! $post instanceof MessageBoardPost
            || $post->user_id === $this->user()->id
            || Gate::allows('update', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => RichText::sanitizeInput($this->input('body'))]);
    }
}
