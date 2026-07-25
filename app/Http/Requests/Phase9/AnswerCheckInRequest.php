<?php

namespace App\Http\Requests\Phase9;

use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Support\RichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AnswerCheckInRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $checkIn = $this->route('check_in');
        $prompt = $this->route('prompt');

        if (! $checkIn instanceof CheckIn || ! Gate::allows('view', $checkIn)) {
            return false;
        }

        if ($prompt instanceof CheckInPrompt) {
            return $prompt->user_id === $this->user()->id;
        }

        return $checkIn->targets()->whereKey($this->user()->id)->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'answer' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['answer' => RichText::sanitizeInput($this->input('answer'))]);
    }
}
