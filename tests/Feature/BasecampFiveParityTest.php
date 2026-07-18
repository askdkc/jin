<?php

use App\Enums\TeamRole;
use App\Events\DirectMessageCreated;
use App\Models\BubbleUp;
use App\Models\CardColumn;
use App\Models\CardTable;
use App\Models\Comment;
use App\Models\DirectMessage;
use App\Models\Document;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\TodoActivityNotification;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

function basecampFiveWorkspace(): array
{
    $owner = User::factory()->create(['current_team_id' => null]);
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $owner->update(['current_team_id' => $team->id]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);

    return [$owner, $member, $team, $project];
}

test('global my bar exposes tasks events pings bubble ups and meeting reminder', function () {
    [$owner, $member, $team, $project] = basecampFiveWorkspace();
    $list = TodoList::factory()->for($project)->create();
    Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'title' => '自分の任務',
        'assignee_id' => $owner->id,
    ]);
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => 'まもなく始まる会議',
        'starts_at' => now()->addMinutes(10),
        'ends_at' => now()->addMinutes(40),
        'meeting_url' => 'https://meet.example.test/room',
        'all_day' => false,
    ]);
    DirectMessage::factory()->for($team)->create([
        'sender_id' => $member->id,
        'recipient_id' => $owner->id,
        'body' => '確認してください',
    ]);
    BubbleUp::factory()->for($team)->for($owner)->create([
        'label' => '明日確認',
        'remind_at' => now()->subMinute(),
    ]);

    $this->actingAs($owner)
        ->get(route('projects.documents.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('workspaceBar.tasks.0.title', '自分の任務')
            ->where('workspaceBar.events.0.meetingUrl', 'https://meet.example.test/room')
            ->where('workspaceBar.pings.0.body', '確認してください')
            ->where('workspaceBar.unreadPings', 1)
            ->where('workspaceBar.bubbleUps.0.due', true)
            ->where('workspaceBar.reminder.title', 'まもなく始まる会議'));
});

test('pings and bubble ups stay inside the current team and user', function () {
    Event::fake([DirectMessageCreated::class]);
    [$owner, $member, $team] = basecampFiveWorkspace();

    $this->actingAs($owner)
        ->post(route('pings.store', $team), [
            'recipient_id' => $member->id,
            'body' => '今から確認できますか',
        ])
        ->assertRedirect();

    expect(DirectMessage::query()->firstOrFail())
        ->team_id->toBe($team->id)
        ->sender_id->toBe($owner->id)
        ->recipient_id->toBe($member->id);
    Event::assertDispatched(DirectMessageCreated::class);

    $this->post(route('bubble-ups.store', $team), [
        'label' => '通知を再確認',
        'url' => '/'.$team->slug.'/notifications',
        'remind_at' => now()->addDay()->toDateTimeLocalString(),
    ])->assertRedirect();

    $bubbleUp = BubbleUp::query()->firstOrFail();
    expect($bubbleUp)->user_id->toBe($owner->id);

    $this->patch(route('bubble-ups.complete', [$team, $bubbleUp]))->assertRedirect();
    expect($bubbleUp->fresh()->completed_at)->not->toBeNull();
});

test('new for you lets a recipient reply to a notification without leaving the page', function () {
    [$owner, $member, $team, $project] = basecampFiveWorkspace();
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($member, 'creator')->create([
        'assignee_id' => $owner->id,
        'title' => '通知から確認する任務',
    ]);
    $owner->notify(new TodoActivityNotification(
        TodoActivityNotification::Assigned,
        $member,
        $project,
        $todo,
    ));
    $notification = $owner->notifications()->firstOrFail();

    $this->actingAs($owner)
        ->post(route('notifications.reply', [$team, $notification]), [
            'body' => '<strong>確認しました</strong>',
        ])
        ->assertRedirect();

    expect(Comment::query()->firstOrFail())
        ->body->toContain('<strong>確認しました</strong>')
        ->and($notification->fresh()->read_at)->not->toBeNull();

    $this->actingAs($member)
        ->post(route('notifications.reply', [$team, $notification]), ['body' => '横取り返信'])
        ->assertNotFound();
});

test('loose todo list protection and card column collapse are persisted', function () {
    [$owner, , $team, $project] = basecampFiveWorkspace();
    $looseList = TodoList::factory()->for($project)->create([
        'title' => '未分類の任務',
        'is_loose' => true,
    ]);
    $table = CardTable::factory()->for($project)->create();
    $column = CardColumn::factory()->for($table)->create(['is_collapsed' => false]);

    $this->actingAs($owner)
        ->delete(route('projects.todo-lists.destroy', [$team, $project, $looseList]))
        ->assertUnprocessable();
    expect($looseList->fresh())->not->toBeNull();

    $this->patch(route('projects.card-columns.collapse', [$team, $project, $table, $column]))
        ->assertRedirect();
    expect($column->fresh()->is_collapsed)->toBeTrue();

    $this->get(route('projects.card-tables.index', [$team, $project]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tables.0.columns.0.collapsed', true));
});

test('events keep meeting links and public documents work without login', function () {
    [$owner, , $team, $project] = basecampFiveWorkspace();

    $this->actingAs($owner)
        ->post(route('projects.schedule.store', [$team, $project]), [
            'title' => 'オンライン会議',
            'meeting_url' => 'https://zoom.example.test/j/123',
            'starts_at' => '2026-08-03T10:00',
            'ends_at' => '2026-08-03T11:00',
        ])
        ->assertRedirect();
    expect(ScheduleEvent::query()->firstOrFail()->meeting_url)
        ->toBe('https://zoom.example.test/j/123');

    $document = Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '公開手順',
        'body' => '<table><thead><tr><th>担当</th></tr></thead><tbody><tr><td>田中</td></tr></tbody></table>',
    ]);
    $this->patch(route('projects.documents.public-link', [$team, $project, $document]))
        ->assertRedirect();
    $document->refresh();

    expect($document->public_token)->not->toBeNull()
        ->and($document->body)->toContain('<table>')
        ->and($document->body)->toContain('<th>担当</th>');

    auth()->logout();
    $this->get(route('public-documents.show', $document->public_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/DocumentShow')
            ->where('document.title', '公開手順'));
});
