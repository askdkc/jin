<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\StoredFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreChatMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && Gate::allows('view', $project);
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
        $channelId = $this->integer('channel_id');
        $userId = $this->user()->id;

        return [
            'channel_id' => [
                'nullable',
                'integer',
                Rule::exists(ChatChannel::class, 'id')
                    ->where(fn (Builder $query): Builder => $query->where('project_id', $projectId)),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists(ChatMessage::class, 'id')
                    ->where(function (Builder $query) use ($projectId, $channelId): void {
                        $query->where('project_id', $projectId)->whereNull('parent_id');

                        if ($channelId > 0) {
                            $query->where('chat_channel_id', $channelId);
                        }
                    }),
            ],
            'body' => ['nullable', 'string', 'max:2000', 'required_without:attachment_ids'],
            'attachment_ids' => [
                'nullable',
                'array',
                'max:'.(int) config('uploads.max_files_per_request'),
            ],
            'attachment_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(StoredFile::class, 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('project_id', $projectId)
                        ->where('uploaded_by', $userId)
                        ->where('is_inline', true)
                        ->whereNull('attachable_type')
                        ->whereNull('attachable_id')),
            ],
        ];
    }
}
