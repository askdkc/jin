<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard groups incomplete assigned todos by deadline and lists active projects', function () {
    $this->travelTo('2026-07-15 09:00:00');

    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->for($team)->for($user, 'creator')->create([
        'name' => '新サービス開発',
        'description' => '秋の公開に向けた開発プロジェクト',
        'ends_on' => '2026-09-30',
    ]);
    $project->members()->attach($user);
    $list = TodoList::factory()->for($project)->create();
    $overdue = Todo::factory()->for($list, 'todoList')->create([
        'assignee_id' => $user->id,
        'title' => '期限切れのTo-do',
        'due_on' => '2026-07-14',
    ]);
    $today = Todo::factory()->for($list, 'todoList')->create([
        'assignee_id' => $user->id,
        'title' => '今日のTo-do',
        'due_on' => '2026-07-15',
    ]);
    $thisWeek = Todo::factory()->for($list, 'todoList')->create([
        'assignee_id' => $user->id,
        'title' => '今週のTo-do',
        'due_on' => '2026-07-19',
    ]);
    $later = Todo::factory()->for($list, 'todoList')->create([
        'assignee_id' => $user->id,
        'title' => '来週以降のTo-do',
        'due_on' => '2026-07-20',
    ]);
    $withoutDueDate = Todo::factory()->for($list, 'todoList')->create([
        'assignee_id' => $user->id,
        'title' => '期限未設定のTo-do',
        'due_on' => null,
    ]);
    Todo::factory()->for($list, 'todoList')->create([
        'assignee_id' => $user->id,
        'completed_at' => now(),
        'completed_by' => $user->id,
    ]);

    $archivedProject = Project::factory()->archived()->for($team)->for($user, 'creator')->create();
    $archivedList = TodoList::factory()->for($archivedProject)->create();
    Todo::factory()->for($archivedList, 'todoList')->create([
        'assignee_id' => $user->id,
        'due_on' => '2026-07-14',
    ]);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->for($otherTeam)->for($user, 'creator')->create();
    $otherList = TodoList::factory()->for($otherProject)->create();
    Todo::factory()->for($otherList, 'todoList')->create([
        'assignee_id' => $user->id,
        'due_on' => '2026-07-15',
    ]);
    $activity = Activity::factory()->for($project)->for($user, 'actor')->create([
        'type' => ActivityType::TodoCompleted,
        'metadata' => ['label' => '公開準備'],
    ]);
    Activity::factory()->for($otherProject)->for($user, 'actor')->create();

    $this->actingAs($user)
        ->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('myWork.overdue', 1)
            ->where('myWork.overdue.0.id', $overdue->id)
            ->has('myWork.today', 1)
            ->where('myWork.today.0.id', $today->id)
            ->has('myWork.thisWeek', 1)
            ->where('myWork.thisWeek.0.id', $thisWeek->id)
            ->has('myWork.later', 2)
            ->where('myWork.later.0.id', $later->id)
            ->where('myWork.later.1.id', $withoutDueDate->id)
            ->where('myWork.overdue.0.project.id', $project->id)
            ->has('myProjects', 1)
            ->where('myProjects.0.id', $project->id)
            ->where('myProjects.0.name', '新サービス開発')
            ->where('myProjects.0.endsOn', '2026-09-30')
            ->has('myProjects.0.members', 1)
            ->where('myProjects.0.members.0.id', $user->id)
            ->has('recentActivities', 1)
            ->where('recentActivities.0.id', $activity->id)
            ->where('recentActivities.0.type', ActivityType::TodoCompleted->value)
            ->where('recentActivities.0.subjectLabel', '公開準備')
            ->where('recentActivities.0.subjectId', $activity->subject_id)
            ->where('recentActivities.0.project.id', $project->id)
            ->where('permissions.canCreateProject', true)
            ->where('permissions.canInviteMembers', true)
            ->where('permissions.canManageTeam', true)
            ->has('availableRoles', 3)
            ->where('availableRoles.0.value', TeamRole::Admin->value)
            ->missing('myTodos'));
});

test('dashboard hides project work after a member leaves the project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $list = TodoList::factory()->for($project)->create();
    Todo::factory()->for($list, 'todoList')->create(['assignee_id' => $member->id]);
    $project->members()->detach($member);

    $this->actingAs($member)
        ->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('myWork.overdue', 0)
            ->has('myWork.today', 0)
            ->has('myWork.thisWeek', 0)
            ->has('myWork.later', 0)
            ->has('myProjects', 0)
            ->has('availableRoles', 0));
});

test('dashboard includes upcoming events from visible projects for the personal dock', function () {
    $this->travelTo('2026-07-15 09:00:00');

    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->for($team)->for($user, 'creator')->create();
    $project->members()->attach($user);
    $user->favoriteProjects()->attach($project);

    $upcomingEvent = ScheduleEvent::factory()->for($project)->create([
        'title' => '来週の作戦会議',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
    ]);
    ScheduleEvent::factory()->for($project)->create([
        'starts_at' => now()->addDays(8),
        'ends_at' => now()->addDays(8)->addHour(),
    ]);

    $otherOwner = User::factory()->create();
    $otherTeam = $otherOwner->currentTeam;
    $hiddenProject = Project::factory()->for($otherTeam)->for($otherOwner, 'creator')->create();
    ScheduleEvent::factory()->for($hiddenProject)->create([
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('myEvents', 1)
            ->where('myEvents.0.id', $upcomingEvent->id)
            ->where('myEvents.0.title', '来週の作戦会議')
            ->where('myEvents.0.project.id', $project->id)
            ->where('myProjects.0.isFavorite', true));
});
