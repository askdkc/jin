<?php

namespace App\Http\Requests\Phase9;

use App\Models\Project;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class WorkspaceCalendarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->route('current_team');

        return $team instanceof Team
            && Gate::allows('viewAny', [Project::class, $team]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'view' => ['nullable', 'in:day,week,month,gantt'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'range' => ['nullable', 'in:week,month,quarter,half-year,year,custom'],
            'start' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d'],
            'end' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->string('range')->toString() !== 'custom'
                    || $validator->errors()->has('start')
                    || $validator->errors()->has('end')) {
                    return;
                }

                $start = CarbonImmutable::createFromFormat('!Y-m-d', $this->string('start')->toString());
                $end = CarbonImmutable::createFromFormat('!Y-m-d', $this->string('end')->toString());

                if ($start !== null && $end !== null && $end->greaterThan($start->addYear()->subDay())) {
                    $validator->errors()->add('end', __('The selected date range may not exceed one year.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start' => __('Start date'),
            'end' => __('End date'),
        ];
    }
}
