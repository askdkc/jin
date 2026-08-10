<?php

use App\Enums\TeamRole;
use App\Events\ChatMessageCreated;
use App\Events\ChatMessageReactionUpdated;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('project participants can open chat with the latest messages', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);

    foreach (range(1, 55) as $number) {
        ChatMessage::factory()->for($project)->for($user)->create([
            'body' => "Message {$number}",
        ]);
    }

    $this
        ->actingAs($user)
        ->get(route('projects.chat.show', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/chat/Show')
            ->where('project.id', $project->id)
            ->has('channels', 1)
            ->where('selectedChannel.name', 'general')
            ->has('messages', 50)
            ->where('messages.0.body', 'Message 6')
            ->where('messages.49.body', 'Message 55')
            ->where('historyCursor', fn (mixed $cursor): bool => is_string($cursor) && $cursor !== ''),
        );
});

test('chat channels keep project conversations separate', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $general = ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'general']);
    $development = ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'development']);
    ChatMessage::factory()->for($project)->for($general, 'channel')->for($user)->create(['body' => '全体連絡']);
    ChatMessage::factory()->for($project)->for($development, 'channel')->for($user)->create(['body' => '開発連絡']);

    $this
        ->actingAs($user)
        ->get(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $development->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('channels', 2)
            ->where('selectedChannel.id', $development->id)
            ->has('messages', 1)
            ->where('messages.0.body', '開発連絡')
            ->where('messages.0.channelId', $development->id),
        );
});

test('explicit chat channels do not initialize the default channel', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $development = ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'development']);
    ChatMessage::factory()->for($project)->for($development, 'channel')->for($user)->create(['body' => '開発連絡']);

    $this
        ->actingAs($user)
        ->get(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $development->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('channels', 1)
            ->where('selectedChannel.id', $development->id),
        );

    $this
        ->actingAs($user)
        ->getJson(route('projects.chat.messages.index', [
            'current_team' => $team,
            'project' => $project,
            'channel_id' => $development->id,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.body', '開発連絡');

    expect($project->chatChannels()->where('name', 'general')->exists())->toBeFalse();
});

test('an existing default channel does not rerun the legacy message repair', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'general']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this
        ->actingAs($user)
        ->getJson(route('projects.chat.messages.index', [$team, $project]));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk();

    expect(collect($queries)->contains(
        fn (array $query): bool => preg_match('/^update\s+[`"]?chat_messages[`"]?/i', $query['query']) === 1,
    ))->toBeFalse();
});

test('project participants can create chat channels', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);

    $response = $this
        ->actingAs($user)
        ->post(route('projects.chat.channels.store', [$team, $project]), [
            'name' => 'design',
            'description' => 'デザイン相談',
        ]);

    $channel = ChatChannel::query()->where('name', 'design')->firstOrFail();

    $response->assertRedirect(route('projects.chat.show', [
        'current_team' => $team,
        'project' => $project,
        'channel' => $channel->id,
    ]));

    expect($channel->project_id)->toBe($project->id)
        ->and($channel->created_by)->toBe($user->id)
        ->and($channel->description)->toBe('デザイン相談');
});

test('channel management is available to its creator and project managers', function () {
    $creator = User::factory()->create();
    $manager = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($creator, ['role' => TeamRole::Member->value]);
    $team->members()->attach($manager, ['role' => TeamRole::Admin->value]);
    $project = Project::factory()->for($team)->for($creator, 'creator')->create();
    $project->members()->attach([$creator->id, $manager->id]);
    $creatorChannel = ChatChannel::factory()->for($project)->for($creator, 'creator')->create(['name' => 'creator']);
    $managerChannel = ChatChannel::factory()->for($project)->for($manager, 'creator')->create(['name' => 'manager']);

    $this
        ->actingAs($creator)
        ->get(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $creatorChannel->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.id', $creatorChannel->id)
            ->where('channels.0.canManage', true)
            ->where('channels.1.id', $managerChannel->id)
            ->where('channels.1.canManage', false),
        );

    $this
        ->actingAs($manager)
        ->get(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $creatorChannel->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.canManage', true)
            ->where('channels.1.canManage', true),
        );
});

test('channel creators can rename their channels without changing the selected channel', function () {
    $creator = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($creator, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($creator, 'creator')->create();
    $project->members()->attach([$creator->id, $otherMember->id]);
    $selectedChannel = ChatChannel::factory()->for($project)->for($creator, 'creator')->create(['name' => 'general']);
    $channel = ChatChannel::factory()->for($project)->for($creator, 'creator')->create(['name' => 'design']);

    $this
        ->actingAs($creator)
        ->patch(route('projects.chat.channels.update', [$team, $project, $channel]), [
            'name' => 'product-design',
            'selected_channel_id' => $selectedChannel->id,
        ])
        ->assertRedirect(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $selectedChannel->id,
        ]));

    expect($channel->fresh()->name)->toBe('product-design');

    $this
        ->actingAs($creator)
        ->patch(route('projects.chat.channels.update', [$team, $project, $channel]), [
            'name' => 'general',
            'selected_channel_id' => $selectedChannel->id,
        ])
        ->assertSessionHasErrors('name');

    $this
        ->actingAs($otherMember)
        ->patch(route('projects.chat.channels.update', [$team, $project, $channel]), [
            'name' => 'denied',
            'selected_channel_id' => $selectedChannel->id,
        ])
        ->assertForbidden();
});

test('deleting a channel removes its messages and keeps related files and todos valid', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $remainingChannel = ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'general']);
    $deletedChannel = ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'temporary']);
    $message = ChatMessage::factory()->for($project)->for($deletedChannel, 'channel')->for($user)->create();
    $attachment = StoredFile::factory()->for($project)->for($user, 'uploader')->for($message, 'attachable')->create([
        'is_inline' => true,
    ]);
    $todoList = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($todoList)->for($user, 'creator')->create([
        'chat_channel_id' => $deletedChannel->id,
    ]);

    $this
        ->actingAs($user)
        ->delete(route('projects.chat.channels.destroy', [$team, $project, $deletedChannel]), [
            'selected_channel_id' => $deletedChannel->id,
        ])
        ->assertRedirect(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $remainingChannel->id,
        ]));

    $this->assertModelMissing($deletedChannel);
    $this->assertModelMissing($message);
    expect($attachment->fresh()->attachable_type)->toBeNull()
        ->and($attachment->fresh()->attachable_id)->toBeNull()
        ->and($todo->fresh()->chat_channel_id)->toBeNull();
});

test('the last channel cannot be deleted and channel bindings stay scoped to their project', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $firstProject = Project::factory()->for($team)->for($user, 'creator')->create();
    $secondProject = Project::factory()->for($team)->for($user, 'creator')->create();
    $firstProject->members()->attach($user);
    $secondProject->members()->attach($user);
    $onlyChannel = ChatChannel::factory()->for($firstProject)->for($user, 'creator')->create();
    $otherProjectChannel = ChatChannel::factory()->for($secondProject)->for($user, 'creator')->create();

    $this
        ->actingAs($user)
        ->delete(route('projects.chat.channels.destroy', [$team, $firstProject, $onlyChannel]), [
            'selected_channel_id' => $onlyChannel->id,
        ])
        ->assertSessionHasErrors('channel');

    $this->assertModelExists($onlyChannel);

    $this
        ->actingAs($user)
        ->patch(route('projects.chat.channels.update', [$team, $firstProject, $otherProjectChannel]), [
            'name' => 'not-scoped',
            'selected_channel_id' => $onlyChannel->id,
        ])
        ->assertNotFound();
});

test('a removed project participant cannot manage a channel they created', function () {
    $owner = User::factory()->create();
    $formerParticipant = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($formerParticipant, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $channel = ChatChannel::factory()->for($project)->for($formerParticipant, 'creator')->create();

    $this
        ->actingAs($formerParticipant)
        ->patch(route('projects.chat.channels.update', [$team, $project, $channel]), [
            'name' => 'denied',
            'selected_channel_id' => $channel->id,
        ])
        ->assertForbidden();
});

test('chat history uses cursor pagination in chronological display order', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);

    foreach (range(1, 55) as $number) {
        ChatMessage::factory()->for($project)->for($user)->create([
            'body' => "Message {$number}",
        ]);
    }

    $firstPage = $this
        ->actingAs($user)
        ->getJson(route('projects.chat.messages.index', [$team, $project]));

    $firstPage
        ->assertOk()
        ->assertJsonCount(50, 'messages')
        ->assertJsonPath('messages.0.body', 'Message 6')
        ->assertJsonPath('messages.49.body', 'Message 55');

    $cursor = $firstPage->json('nextCursor');

    $this
        ->actingAs($user)
        ->getJson(route('projects.chat.messages.index', [
            'current_team' => $team,
            'project' => $project,
            'cursor' => $cursor,
        ]))
        ->assertOk()
        ->assertJsonCount(5, 'messages')
        ->assertJsonPath('messages.0.body', 'Message 1')
        ->assertJsonPath('messages.4.body', 'Message 5')
        ->assertJsonPath('nextCursor', null);
});

test('chat reconnect sync returns messages after the latest known id', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $firstMessage = ChatMessage::factory()->for($project)->for($user)->create(['body' => 'First']);
    ChatMessage::factory()->for($project)->for($user)->create(['body' => 'Second']);
    ChatMessage::factory()->for($project)->for($user)->create(['body' => 'Third']);

    $this
        ->actingAs($user)
        ->getJson(route('projects.chat.messages.index', [
            'current_team' => $team,
            'project' => $project,
            'after_id' => $firstMessage->id,
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.body', 'Second')
        ->assertJsonPath('messages.1.body', 'Third')
        ->assertJsonPath('hasMore', false);

    $this
        ->actingAs($user)
        ->getJson(route('projects.chat.messages.index', [
            'current_team' => $team,
            'project' => $project,
            'after_id' => 0,
        ]))
        ->assertOk()
        ->assertJsonCount(3, 'messages')
        ->assertJsonPath('messages.0.body', 'First');
});

test('project participants can post a chat message', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    Event::fake([ChatMessageCreated::class]);

    $response = $this
        ->actingAs($user)
        ->postJson(route('projects.chat.messages.store', [$team, $project]), [
            'body' => '進捗を共有します。',
        ]);

    $message = ChatMessage::query()->firstOrFail();

    $response
        ->assertCreated()
        ->assertJsonPath('message.id', $message->id)
        ->assertJsonPath('message.body', '進捗を共有します。')
        ->assertJsonPath('message.user.id', $user->id);

    expect($message->project_id)->toBe($project->id)
        ->and($message->chat_channel_id)->not->toBeNull()
        ->and($message->user_id)->toBe($user->id)
        ->and($message->parent_id)->toBeNull();

    Event::assertDispatched(
        ChatMessageCreated::class,
        fn (ChatMessageCreated $event): bool => $event->message->is($message),
    );
});

test('chat messages can claim uploaded files and expose channel files', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $channel = ChatChannel::factory()->for($project)->for($user, 'creator')->create();
    $attachment = StoredFile::factory()->for($project)->for($user, 'uploader')->create([
        'is_inline' => true,
        'original_name' => '要件定義.pdf',
    ]);
    $unavailableAttachment = StoredFile::factory()->for($project)->for($otherUser, 'uploader')->create([
        'is_inline' => true,
    ]);
    Event::fake([ChatMessageCreated::class]);

    $response = $this
        ->actingAs($user)
        ->postJson(route('projects.chat.messages.store', [$team, $project]), [
            'channel_id' => $channel->id,
            'body' => '',
            'attachment_ids' => [$attachment->id],
        ]);

    $message = ChatMessage::query()->firstOrFail();

    $response
        ->assertCreated()
        ->assertJsonPath('message.body', '')
        ->assertJsonPath('message.attachments.0.id', $attachment->id)
        ->assertJsonPath('message.attachments.0.name', '要件定義.pdf');

    expect($attachment->fresh())
        ->attachable_type->toBe($message->getMorphClass())
        ->attachable_id->toBe($message->id);

    $this
        ->get(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $channel->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.attachments.0.id', $attachment->id)
            ->where('channelFiles.0.id', $attachment->id)
            ->where('channelFiles.0.name', '要件定義.pdf'));

    $this
        ->postJson(route('projects.chat.messages.store', [$team, $project]), [
            'channel_id' => $channel->id,
            'attachment_ids' => [$unavailableAttachment->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attachment_ids.0');
});

test('chat exposes linked and linkable todos for the selected channel', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $channel = ChatChannel::factory()->for($project)->for($user, 'creator')->create();
    $todoList = TodoList::factory()->for($project)->create(['title' => '公開準備']);
    $linkedTodo = Todo::factory()->for($todoList, 'todoList')->for($user, 'creator')->create([
        'chat_channel_id' => $channel->id,
        'title' => 'チャット連携済み',
    ]);
    $linkableTodo = Todo::factory()->for($todoList, 'todoList')->for($user, 'creator')->create([
        'title' => '未連携',
        'position' => 1,
    ]);

    $this
        ->actingAs($user)
        ->get(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $channel->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('todoLists.0.id', $todoList->id)
            ->where('relatedTodos.0.id', $linkedTodo->id)
            ->where('relatedTodos.0.listTitle', '公開準備')
            ->where('linkableTodos.0.id', $linkableTodo->id));
});

test('chat messages support threaded replies', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $channel = ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'general']);
    $parent = ChatMessage::factory()->for($project)->for($channel, 'channel')->for($user)->create(['body' => '確認してください']);
    $existingReply = ChatMessage::factory()->for($project)->for($channel, 'channel')->for($user)->for($parent, 'parent')->create(['body' => '確認しました']);
    Event::fake([ChatMessageCreated::class]);

    $this
        ->actingAs($user)
        ->getJson(route('projects.chat.messages.replies', [$team, $project, $parent]))
        ->assertOk()
        ->assertJsonPath('message.id', $parent->id)
        ->assertJsonPath('message.replyCount', 1)
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.id', $existingReply->id)
        ->assertJsonPath('messages.0.parentId', $parent->id);

    $response = $this
        ->actingAs($user)
        ->postJson(route('projects.chat.messages.store', [$team, $project]), [
            'channel_id' => $channel->id,
            'parent_id' => $parent->id,
            'body' => '追加で返信します',
        ]);

    $reply = ChatMessage::query()->latest('id')->firstOrFail();

    $response
        ->assertCreated()
        ->assertJsonPath('message.parentId', $parent->id)
        ->assertJsonPath('message.channelId', $channel->id);

    expect($reply->parent_id)->toBe($parent->id)
        ->and($reply->chat_channel_id)->toBe($channel->id);

    Event::assertDispatched(ChatMessageCreated::class);
});

test('thread parent must belong to the selected channel', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $general = ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'general']);
    $design = ChatChannel::factory()->for($project)->for($user, 'creator')->create(['name' => 'design']);
    $parent = ChatMessage::factory()->for($project)->for($general, 'channel')->for($user)->create();

    $this
        ->actingAs($user)
        ->postJson(route('projects.chat.messages.store', [$team, $project]), [
            'channel_id' => $design->id,
            'parent_id' => $parent->id,
            'body' => '別チャンネルからの返信',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');
});

test('chat message body is required without attachments and limited to 2000 characters', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);

    $this
        ->actingAs($user)
        ->postJson(route('projects.chat.messages.store', [$team, $project]), ['body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    $this
        ->actingAs($user)
        ->postJson(route('projects.chat.messages.store', [$team, $project]), [
            'body' => str_repeat('あ', 2001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

test('project participants can toggle chat reactions independently', function () {
    $firstUser = User::factory()->create(['name' => 'First User']);
    $secondUser = User::factory()->create(['name' => 'Second User']);
    $team = Team::factory()->create();
    $team->members()->attach($firstUser, ['role' => TeamRole::Member->value]);
    $team->members()->attach($secondUser, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($firstUser, 'creator')->create();
    $project->members()->attach([$firstUser->id, $secondUser->id]);
    $message = ChatMessage::factory()->for($project)->for($firstUser)->create();
    Event::fake([ChatMessageReactionUpdated::class]);

    $this
        ->actingAs($firstUser)
        ->postJson(route('projects.chat.messages.reaction.toggle', [$team, $project, $message]), [
            'reaction' => ' 👍 ',
        ])
        ->assertOk()
        ->assertJsonPath('messageId', $message->id)
        ->assertJsonPath('reactions.0.reaction', '👍')
        ->assertJsonPath('reactions.0.count', 1)
        ->assertJsonPath('reactions.0.reacted', true)
        ->assertJsonPath('reactions.0.names.0', 'First User');

    Event::assertDispatched(
        ChatMessageReactionUpdated::class,
        fn (ChatMessageReactionUpdated $event): bool => $event->message->is($message),
    );
    $this->assertDatabaseHas('chat_message_reactions', [
        'chat_message_id' => $message->id,
        'user_id' => $firstUser->id,
        'reaction' => '👍',
    ]);

    $this
        ->actingAs($secondUser)
        ->postJson(route('projects.chat.messages.reaction.toggle', [$team, $project, $message]), [
            'reaction' => '👍',
        ])
        ->assertOk()
        ->assertJsonPath('reactions.0.count', 2)
        ->assertJsonPath('reactions.0.reacted', true);

    $this
        ->actingAs($firstUser)
        ->postJson(route('projects.chat.messages.reaction.toggle', [$team, $project, $message]), [
            'reaction' => '🎉',
        ])
        ->assertOk()
        ->assertJsonCount(2, 'reactions');

    $this->assertDatabaseHas('chat_message_reactions', [
        'chat_message_id' => $message->id,
        'user_id' => $firstUser->id,
        'reaction' => '👍',
    ]);
    $this->assertDatabaseHas('chat_message_reactions', [
        'chat_message_id' => $message->id,
        'user_id' => $firstUser->id,
        'reaction' => '🎉',
    ]);

    $this
        ->postJson(route('projects.chat.messages.reaction.toggle', [$team, $project, $message]), [
            'reaction' => '🎉',
        ])
        ->assertOk()
        ->assertJsonCount(1, 'reactions')
        ->assertJsonPath('reactions.0.reaction', '👍')
        ->assertJsonPath('reactions.0.count', 2);

    $this->assertDatabaseMissing('chat_message_reactions', [
        'chat_message_id' => $message->id,
        'user_id' => $firstUser->id,
        'reaction' => '🎉',
    ]);
    $this->assertDatabaseHas('chat_message_reactions', [
        'chat_message_id' => $message->id,
        'user_id' => $firstUser->id,
        'reaction' => '👍',
    ]);
});

test('chat reactions validate input and stay scoped to project participants', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $otherProject->members()->attach($owner);
    $message = ChatMessage::factory()->for($project)->for($owner)->create();
    $otherMessage = ChatMessage::factory()->for($otherProject)->for($owner)->create();

    $this
        ->actingAs($owner)
        ->postJson(route('projects.chat.messages.reaction.toggle', [$team, $project, $message]), [
            'reaction' => str_repeat('a', 21),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reaction');

    $this
        ->actingAs($outsider)
        ->postJson(route('projects.chat.messages.reaction.toggle', [$team, $project, $message]), [
            'reaction' => '👍',
        ])
        ->assertForbidden();

    $this
        ->actingAs($owner)
        ->postJson(route('projects.chat.messages.reaction.toggle', [$team, $project, $otherMessage]), [
            'reaction' => '👍',
        ])
        ->assertNotFound();
});

test('non participants cannot view history or post chat messages', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this
        ->actingAs($member)
        ->get(route('projects.chat.show', [$team, $project]))
        ->assertForbidden();

    $this
        ->actingAs($member)
        ->getJson(route('projects.chat.messages.index', [$team, $project]))
        ->assertForbidden();

    $this
        ->actingAs($member)
        ->postJson(route('projects.chat.messages.store', [$team, $project]), ['body' => 'Denied'])
        ->assertForbidden();

    $this
        ->actingAs($member)
        ->post(route('projects.chat.channels.store', [$team, $project]), ['name' => 'denied'])
        ->assertForbidden();
});

test('chat routes scope projects to the current team', function () {
    $owner = User::factory()->create();
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();
    $firstTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $secondTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($secondTeam)->for($owner, 'creator')->create();

    $this
        ->actingAs($owner)
        ->get(route('projects.chat.show', [$firstTeam, $project]))
        ->assertNotFound();
});

test('chat message broadcast uses a private project channel and the default queue', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $message = ChatMessage::factory()->for($project)->for($user)->create();
    $attachment = StoredFile::factory()->for($project)->for($user, 'uploader')->for($message, 'attachable')->create([
        'original_name' => '仕様書.pdf',
    ]);
    $event = new ChatMessageCreated($message->load('user'));

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event)->toBeInstanceOf(ShouldRescue::class)
        ->and($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe("private-projects.{$project->id}.chat")
        ->and($event->broadcastAs())->toBe('chat.message.created')
        ->and($event->tries)->toBe(3)
        ->and($event->timeout)->toBe(60)
        ->and($event->broadcastWith()['message']['id'])->toBe($message->id)
        ->and($event->broadcastWith()['message']['channelId'])->toBe($message->chat_channel_id)
        ->and($event->broadcastWith()['message']['parentId'])->toBeNull()
        ->and($event->broadcastWith()['message']['attachments'][0]['id'])->toBe($attachment->id);

    Queue::fake();
    Event::dispatch($event);

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->queue === null);
});

test('chat reaction broadcast includes grouped reactors on the private project channel', function () {
    $firstUser = User::factory()->create(['name' => 'First User']);
    $secondUser = User::factory()->create(['name' => 'Second User']);
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->for($firstUser, 'creator')->create();
    $message = ChatMessage::factory()->for($project)->for($firstUser)->create();
    $message->reactors()->attach([
        $firstUser->id => ['reaction' => '👍'],
        $secondUser->id => ['reaction' => '👍'],
    ]);
    $event = new ChatMessageReactionUpdated($message);
    $payload = $event->broadcastWith();

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event)->toBeInstanceOf(ShouldRescue::class)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe("private-projects.{$project->id}.chat")
        ->and($event->broadcastAs())->toBe('chat.message.reaction.updated')
        ->and($event->tries)->toBe(3)
        ->and($event->timeout)->toBe(60)
        ->and($payload['messageId'])->toBe($message->id)
        ->and($payload['reactions'][0]['reaction'])->toBe('👍')
        ->and($payload['reactions'][0]['count'])->toBe(2)
        ->and($payload['reactions'][0]['names'])->toBe(['First User', 'Second User'])
        ->and($payload['reactions'][0]['userIds'])->toBe([$firstUser->id, $secondUser->id]);
});
