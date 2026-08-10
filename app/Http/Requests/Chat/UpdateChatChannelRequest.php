<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatChannel;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateChatChannelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $channel = $this->route('chat_channel');

        return $project instanceof Project
            && $channel instanceof ChatChannel
            && $channel->project_id === $project->id
            && Gate::allows('view', $project)
            && ($channel->created_by === $this->user()?->id || Gate::allows('update', $project));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $channel = $this->route('chat_channel');

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique(ChatChannel::class)
                    ->where('project_id', $project instanceof Project ? $project->id : 0)
                    ->ignore($channel instanceof ChatChannel ? $channel->id : 0),
            ],
            'selected_channel_id' => [
                'required',
                'integer',
                Rule::exists(ChatChannel::class, 'id')->where(
                    'project_id',
                    $project instanceof Project ? $project->id : 0,
                ),
            ],
        ];
    }
}
