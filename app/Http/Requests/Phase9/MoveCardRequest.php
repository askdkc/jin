<?php

namespace App\Http\Requests\Phase9;

use App\Models\Card;
use App\Models\CardTable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MoveCardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $cardTable = $this->route('card_table');
        $card = $this->route('card');

        return $cardTable instanceof CardTable
            && $card instanceof Card
            && Gate::allows('update', $cardTable);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $cardTable = $this->route('card_table');

        return [
            'target_column_id' => [
                'required',
                'integer',
                Rule::exists('card_columns', 'id')->where(
                    'card_table_id',
                    $cardTable instanceof CardTable ? $cardTable->id : 0,
                ),
            ],
            'target_position' => ['nullable', 'integer', 'min:0'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
