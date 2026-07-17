<?php

namespace App\Http\Requests\PersonalNotes;

use App\Models\PersonalNote;
use App\Models\Team;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavePersonalNoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->route('current_team');
        $note = $this->route('personal_note');

        if (! $team instanceof Team || $this->user()?->teamRole($team) === null) {
            return false;
        }

        return ! $note instanceof PersonalNote
            || ($note->team_id === $team->id && $note->user_id === $this->user()->id);
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
            'body' => ['required', 'string', 'max:50000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => RichText::sanitizeInput($this->input('body'))]);
    }
}
