<?php

namespace App\Http\Requests\Phase9;

use App\Models\Card;
use App\Models\CardTable;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveCardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $cardTable = $this->route('card_table');

        return $cardTable instanceof CardTable
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
        $projectId = $cardTable instanceof CardTable ? $cardTable->project_id : 0;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('project_user', 'user_id')->where('project_id', $projectId),
            ],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'version' => $this->route('card') instanceof Card
                ? ['required', 'integer', 'min:1']
                : ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['description' => RichText::sanitizeInput($this->input('description'))]);
    }
}
