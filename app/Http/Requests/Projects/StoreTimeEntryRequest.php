<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\TodoList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTimeEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->route('current_team');
        $project = $this->route('project');

        return $team instanceof Team
            && $project instanceof Project
            && $project->team_id === $team->id
            && $project->usesTool('timesheets')
            && Gate::allows('view', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'worked_on' => ['required', 'date_format:Y-m-d'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'todo_id' => [
                'nullable',
                'integer',
                Rule::exists('todos', 'id')->where(fn (Builder $query) => $query->whereIn(
                    'todo_list_id',
                    TodoList::query()
                        ->where('project_id', $project instanceof Project ? $project->id : 0)
                        ->select('id'),
                )),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['worked_on', 'minutes'])) {
                return;
            }

            $workedOn = $this->input('worked_on');
            $minutes = $this->input('minutes');
            $user = $this->user();

            if (! is_string($workedOn) || ! is_numeric($minutes) || $user === null) {
                return;
            }

            // Keep the indexed DATE column bare and match Eloquent's SQLite serialization.
            $databaseDate = (new TimeEntry)->fromDateTime($workedOn);
            $loggedMinutes = (int) TimeEntry::query()
                ->where('user_id', $user->id)
                ->where('worked_on', $databaseDate)
                ->sum('minutes');

            if ($loggedMinutes + (int) $minutes > 1440) {
                $validator->errors()->add('minutes', __('Daily time entries cannot exceed 24 hours.'));
            }
        }];
    }
}
