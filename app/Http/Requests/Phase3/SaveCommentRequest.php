<?php

namespace App\Http\Requests\Phase3;

use App\Models\Comment;
use App\Models\Project;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project || ! Gate::allows('view', $project)) {
            return false;
        }

        $comment = $this->route('comment');

        return ! $comment instanceof Comment
            || $comment->user_id === $this->user()->id
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
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => RichText::sanitizeInput($this->input('body'))]);
    }
}
