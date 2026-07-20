<?php

namespace App\Http\Requests\Phase3;

use Illuminate\Foundation\Http\FormRequest;

class SaveMessageBoardPostReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reaction' => ['required', 'string', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reaction' => trim((string) $this->input('reaction')),
        ]);
    }
}
