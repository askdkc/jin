<?php

namespace App\Http\Requests\Phase10;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EverythingIndexRequest extends FormRequest
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
        $team = $this->route('current_team');
        $teamId = $team instanceof Team ? $team->id : 0;
        $user = $this->user();

        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['message', 'todo', 'document', 'file', 'link', 'comment'])],
            'project' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where(fn (Builder $query) => $query
                    ->where('team_id', $teamId)
                    ->whereNull('archived_at')
                    ->whereIn(
                        'id',
                        $team instanceof Team && $user !== null
                            ? Project::query()->visibleTo($user, $team)->select('projects.id')
                            : Project::query()->whereRaw('1 = 0')->select('projects.id'),
                    )),
            ],
        ];
    }
}
