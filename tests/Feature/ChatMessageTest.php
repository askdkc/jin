<?php

use App\Enums\TeamRole;
use App\Events\ChatMessageCreated;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
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

test('chat message body is required and limited to 2000 characters', function () {
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

test('chat message broadcast uses a private project channel and a dedicated queue', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $message = ChatMessage::factory()->for($project)->for($user)->create();
    $event = new ChatMessageCreated($message->load('user'));

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event)->toBeInstanceOf(ShouldRescue::class)
        ->and($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe("private-projects.{$project->id}.chat")
        ->and($event->broadcastAs())->toBe('chat.message.created')
        ->and($event->broadcastQueue())->toBe('broadcasts')
        ->and($event->broadcastWith()['message']['id'])->toBe($message->id)
        ->and($event->broadcastWith()['message']['channelId'])->toBe($message->chat_channel_id)
        ->and($event->broadcastWith()['message']['parentId'])->toBeNull();
});
