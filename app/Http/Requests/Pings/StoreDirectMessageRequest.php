<?php

namespace App\Http\Requests\Pings;

use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDirectMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->route('current_team');

        return $team instanceof Team && $this->user()?->belongsToTeam($team);
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
            'recipient_id' => [
                'required',
                'integer',
                Rule::notIn([$this->user()?->id]),
                Rule::exists('team_members', 'user_id')->where(
                    'team_id',
                    $team instanceof Team ? $team->id : 0,
                ),
            ],
            'body' => ['required', 'string', 'max:4000'],
        ];

    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }
}
