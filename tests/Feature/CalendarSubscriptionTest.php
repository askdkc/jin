<?php

use App\Enums\TeamRole;
use App\Models\CalendarSubscription;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

test('team members can issue named calendar subscription URLs', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->post(route('calendar-subscriptions.store', $team), [
            'name' => '仕事用カレンダー',
        ])
        ->assertRedirect(route('calendar.index', $team));

    $subscription = CalendarSubscription::query()->sole();

    expect($subscription->team_id)->toBe($team->id)
        ->and($subscription->user_id)->toBe($owner->id)
        ->and($subscription->name)->toBe('仕事用カレンダー')
        ->and($subscription->revoked_at)->toBeNull();

    $this->get(route('calendar.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions', 1)
            ->where('subscriptions.0.id', $subscription->id)
            ->where('subscriptions.0.name', '仕事用カレンダー')
            ->where('subscriptions.0.revoked', false)
            ->where('subscriptions.0.url', fn (string $url): bool => str_contains(
                $url,
                route('calendar-subscriptions.show', $subscription),
            ) && str_contains($url, 'signature=')));
});

test('calendar feed contains only projects currently visible to its client owner', function () {
    $owner = User::factory()->create();
    $client = User::factory()->create();
    $team = Team::factory()->create(['name' => '共有,チーム']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($client, ['role' => TeamRole::Client->value]);

    $allClientsProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '全体共有',
        'allows_all_clients' => true,
    ]);
    $individualProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '個別共有',
    ]);
    $individualProject->members()->attach($client);
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '社内限定',
    ]);

    ScheduleEvent::factory()->for($allClientsProject)->for($owner, 'creator')->create([
        'title' => '打合せ,確認;A\\B',
        'details' => "1行目\n2行目",
        'starts_at' => '2026-08-05 10:00:00',
        'ends_at' => '2026-08-05 11:00:00',
    ]);
    ScheduleEvent::factory()->for($individualProject)->for($owner, 'creator')->create([
        'title' => '個別予定',
        'starts_at' => '2026-08-06 00:00:00',
        'ends_at' => '2026-08-06 00:00:00',
        'all_day' => true,
    ]);
    ScheduleEvent::factory()->for($hiddenProject)->for($owner, 'creator')->create([
        'title' => '社内機密予定',
    ]);
    $subscription = CalendarSubscription::factory()->for($team)->for($client)->create();
    $url = URL::signedRoute('calendar-subscriptions.show', ['calendar_subscription' => $subscription]);

    $response = $this->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'text/calendar; charset=utf-8');

    $calendar = $response->getContent();

    expect($response->headers->get('cache-control'))
        ->toContain('private')
        ->toContain('no-store')
        ->and($calendar)
        ->toContain("BEGIN:VCALENDAR\r\n")
        ->toContain('X-WR-CALNAME:共有\,チーム')
        ->toContain('SUMMARY:打合せ\,確認\;A\\\\B')
        ->toContain('DESCRIPTION:全体共有\n1行目\n2行目')
        ->toContain('DTSTART:20260805T010000Z')
        ->toContain('DTEND:20260805T020000Z')
        ->toContain('SUMMARY:個別予定')
        ->toContain('DTSTART;VALUE=DATE:20260806')
        ->toContain('DTEND;VALUE=DATE:20260807')
        ->not->toContain('社内機密予定');
});

test('project access changes are reflected by an existing feed URL immediately', function () {
    $owner = User::factory()->create();
    $client = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($client, ['role' => TeamRole::Client->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'allows_all_clients' => true,
    ]);
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '失効確認予定',
    ]);
    $subscription = CalendarSubscription::factory()->for($team)->for($client)->create();
    $url = URL::signedRoute('calendar-subscriptions.show', ['calendar_subscription' => $subscription]);

    $this->get($url)->assertSee('失効確認予定', false);

    $project->update(['allows_all_clients' => false]);

    $this->get($url)
        ->assertOk()
        ->assertDontSee('失効確認予定', false);
});

test('subscription owners can revoke URLs and invalid signatures return not found', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $subscription = CalendarSubscription::factory()->for($team)->for($member)->create();
    $url = URL::signedRoute('calendar-subscriptions.show', ['calendar_subscription' => $subscription]);

    $this->get(route('calendar-subscriptions.show', $subscription))->assertNotFound();
    $this->get($url.'&changed=1')->assertNotFound();

    $this->actingAs($owner)
        ->delete(route('calendar-subscriptions.destroy', [$team, $subscription]))
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('calendar-subscriptions.destroy', [$team, $subscription]))
        ->assertRedirect(route('calendar.index', $team));

    expect($subscription->fresh()->revoked_at)->not->toBeNull();
    $this->get($url)->assertNotFound();
});
