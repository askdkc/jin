<?php

namespace App\Http\Requests\Phase9;

use App\Enums\CheckInFrequency;
use App\Models\CheckIn;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCheckInRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && Gate::allows('create', [CheckIn::class, $project]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;
        $isDaily = $this->input('frequency') === CheckInFrequency::Daily->value;

        return [
            'question' => ['required', 'string', 'max:255'],
            'frequency' => ['required', Rule::enum(CheckInFrequency::class)],
            'weekdays' => [
                Rule::excludeUnless($isDaily),
                Rule::requiredIf($isDaily),
                'array',
                'min:1',
                'max:7',
            ],
            'weekdays.*' => ['integer', 'distinct', Rule::in(range(1, 7))],
            'timezone' => ['required', 'timezone'],
            'next_run_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'target_user_ids' => ['required', 'array', 'min:1'],
            'target_user_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('project_user', 'user_id')->where('project_id', $projectId),
            ],
        ];
    }

    /** @return list<int>|null */
    public function weekdays(): ?array
    {
        $weekdays = $this->validated('weekdays');

        return is_array($weekdays)
            ? array_values(array_map(static fn (mixed $weekday): int => (int) $weekday, $weekdays))
            : null;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->input('frequency') !== CheckInFrequency::Daily->value
                    || $validator->errors()->has('weekdays')
                    || $validator->errors()->has('weekdays.*')
                    || $validator->errors()->has('next_run_at')
                    || $validator->errors()->has('timezone')
                ) {
                    return;
                }

                $weekdays = array_map('intval', (array) $this->input('weekdays'));
                $nextRunAt = CarbonImmutable::createFromFormat(
                    'Y-m-d\\TH:i',
                    (string) $this->input('next_run_at'),
                    (string) $this->input('timezone'),
                );

                if (! in_array($nextRunAt->dayOfWeekIso, $weekdays, true)) {
                    $validator->errors()->add(
                        'next_run_at',
                        __('The first question date must be one of the selected weekdays.'),
                    );
                }
            },
        ];
    }
}
