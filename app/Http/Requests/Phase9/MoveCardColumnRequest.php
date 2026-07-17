<?php

namespace App\Http\Requests\Phase9;

use App\Models\CardColumn;
use App\Models\CardTable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class MoveCardColumnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $cardTable = $this->route('card_table');
        $cardColumn = $this->route('card_column');

        return $cardTable instanceof CardTable
            && $cardColumn instanceof CardColumn
            && Gate::allows('update', $cardTable);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target_position' => ['required', 'integer', 'min:0'],
        ];
    }
}
