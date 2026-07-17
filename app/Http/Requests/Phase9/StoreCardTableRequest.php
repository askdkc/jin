<?php

namespace App\Http\Requests\Phase9;

use App\Models\CardTable;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreCardTableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $cardTable = $this->route('card_table');

        if ($cardTable instanceof CardTable) {
            return Gate::allows('update', $cardTable);
        }

        return $project instanceof Project
            && Gate::allows('create', [CardTable::class, $project]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
