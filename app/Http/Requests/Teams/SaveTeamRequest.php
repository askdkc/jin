<?php

namespace App\Http\Requests\Teams;

use App\Models\Team;
use App\Rules\TeamName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveTeamRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', new TeamName],
            'menu_names' => ['sometimes', 'array:'.implode(',', array_keys(Team::DEFAULT_MENU_NAMES))],
        ];

        foreach (array_keys(Team::DEFAULT_MENU_NAMES) as $key) {
            $rules["menu_names.{$key}"] = ['required_with:menu_names', 'string', 'max:30'];
        }

        return $rules;
    }
}
