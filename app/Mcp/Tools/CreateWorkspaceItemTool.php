<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityType;
use App\Events\ChatMessageCreated;
use App\Models\Activity;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('権限範囲内のプロジェクトに掲示、ドキュメント、予定、またはチャンネル会話を作成します。')]
class CreateWorkspaceItemTool extends Tool
{
    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'team_slug' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email'],
            'project_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(['message', 'document', 'event', 'chat'])],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:50000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'meeting_url' => ['nullable', 'url', 'max:2048'],
            'channel_id' => ['nullable', 'integer'],
            'parent_message_id' => ['nullable', 'integer'],
        ]);
        [$team, $user, $project] = $this->context($input);

        if (! $team || ! $user || ! $project) {
            return Response::error('チーム、所属ユーザー、または閲覧可能なプロジェクトが見つかりません。');
        }

        if ($input['kind'] !== 'chat' && blank($input['title'] ?? null)) {
            return Response::error('掲示、ドキュメント、予定にはタイトルが必要です。');
        }

        if (in_array($input['kind'], ['message', 'document', 'chat'], true) && blank($input['body'] ?? null)) {
            return Response::error('本文が必要です。');
        }

        if ($input['kind'] === 'event' && (blank($input['starts_at'] ?? null) || blank($input['ends_at'] ?? null))) {
            return Response::error('予定には開始日時と終了日時が必要です。');
        }

        $item = match ($input['kind']) {
            'message' => $this->createMessage($project, $user, $input),
            'document' => $this->createDocument($project, $user, $input),
            'event' => $this->createEvent($project, $user, $input),
            'chat' => $this->createChatMessage($project, $user, $input),
            default => Response::error('対応していない種類です。'),
        };

        if ($item instanceof Response) {
            return $item;
        }

        return Response::json([
            'created' => true,
            'kind' => $input['kind'],
            'id' => $item->getKey(),
            'project_id' => $project->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: Team|null, 1: User|null, 2: Project|null}
     */
    private function context(array $input): array
    {
        $team = Team::query()->where('slug', $input['team_slug'])->first();
        $user = $team?->members()->where('email', strtolower($input['user_email']))->first();
        $project = $team && $user
            ? Project::query()->visibleTo($user, $team)->whereKey($input['project_id'])->first()
            : null;

        return [$team, $user, $project];
    }

    /** @param array<string, mixed> $input */
    private function createMessage(Project $project, User $user, array $input): MessageBoardPost
    {
        return DB::transaction(function () use ($input, $project, $user) {
            $message = $project->messageBoardPosts()->create([
                'user_id' => $user->id,
                'title' => $input['title'],
                'body' => RichText::sanitizeInput($input['body']),
                'published_at' => now(),
            ]);
            $message->subscribers()->syncWithoutDetaching([$user->id]);
            Activity::record($project, $user, ActivityType::MessageCreated, $message, $message->title);

            return $message;
        });
    }

    /** @param array<string, mixed> $input */
    private function createDocument(Project $project, User $user, array $input): Document
    {
        return DB::transaction(function () use ($input, $project, $user) {
            $document = $project->documents()->create([
                'created_by' => $user->id,
                'title' => $input['title'],
                'body' => RichText::sanitizeInput($input['body']),
            ]);
            Activity::record($project, $user, ActivityType::DocumentCreated, $document, $document->title);

            return $document;
        });
    }

    /** @param array<string, mixed> $input */
    private function createEvent(Project $project, User $user, array $input): ScheduleEvent|Response
    {
        if (strtotime($input['ends_at']) <= strtotime($input['starts_at'])) {
            return Response::error('終了日時は開始日時より後にしてください。');
        }

        return DB::transaction(function () use ($input, $project, $user) {
            $event = $project->scheduleEvents()->create([
                'created_by' => $user->id,
                'title' => $input['title'],
                'details' => filled($input['body'] ?? null) ? RichText::sanitizeInput($input['body']) : null,
                'starts_at' => $input['starts_at'],
                'ends_at' => $input['ends_at'],
                'meeting_url' => $input['meeting_url'] ?? null,
                'all_day' => false,
            ]);
            Activity::record($project, $user, ActivityType::ScheduleCreated, $event, $event->title);

            return $event;
        });
    }

    /** @param array<string, mixed> $input */
    private function createChatMessage(Project $project, User $user, array $input): ChatMessage|Response
    {
        $parent = isset($input['parent_message_id'])
            ? $project->chatMessages()->whereNull('parent_id')->find((int) $input['parent_message_id'])
            : null;
        $channel = $parent->channel
            ?? (isset($input['channel_id']) ? $project->chatChannels()->find((int) $input['channel_id']) : null)
            ?? $project->chatChannels()->firstOrCreate(
                ['name' => 'general'],
                ['description' => 'プロジェクト全体の会話', 'created_by' => $user->id],
            );

        if (isset($input['parent_message_id']) && ! $parent) {
            return Response::error('返信先の会話が見つかりません。');
        }

        $message = $project->chatMessages()->create([
            'chat_channel_id' => $channel->id,
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'body' => trim($input['body']),
        ]);
        ChatMessageCreated::dispatch($message);

        return $message;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_slug' => $schema->string()->description('対象チームのslug')->required(),
            'user_email' => $schema->string()->description('操作主体となる所属ユーザーのメールアドレス')->required(),
            'project_id' => $schema->integer()->description('対象プロジェクトID')->required(),
            'kind' => $schema->string()->description('message、document、event、chat のいずれか')->required(),
            'title' => $schema->string()->description('掲示、ドキュメント、予定のタイトル'),
            'body' => $schema->string()->description('本文または会話本文'),
            'starts_at' => $schema->string()->description('予定の開始日時'),
            'ends_at' => $schema->string()->description('予定の終了日時'),
            'meeting_url' => $schema->string()->description('任意のオンライン会議URL'),
            'channel_id' => $schema->integer()->description('任意の会話チャンネルID'),
            'parent_message_id' => $schema->integer()->description('任意のスレッド親メッセージID'),
        ];
    }
}
