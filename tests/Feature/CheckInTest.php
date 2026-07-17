<?php

use App\Enums\ActivityType;
use App\Enums\CheckInFrequency;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\CheckInDueNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('project participants can create recurring check-ins for project members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => '回答者']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);

    $this->actingAs($member)
        ->post(route('projects.check-ins.store', [$team, $project]), [
            'question' => '今週進んだことは？',
            'frequency' => CheckInFrequency::Weekly->value,
            'timezone' => 'Asia/Tokyo',
            'next_run_at' => '2026-08-03T09:00',
            'target_user_ids' => [$owner->id, $member->id],
        ])
        ->assertRedirect(route('projects.check-ins.index', [$team, $project]));

    $checkIn = CheckIn::query()->firstOrFail();

    expect($checkIn)
        ->created_by->toBe($member->id)
        ->frequency->toBe(CheckInFrequency::Weekly)
        ->timezone->toBe('Asia/Tokyo');
    expect($checkIn->targets()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$owner->id, $member->id]);
    expect(Activity::query()->where('type', ActivityType::CheckInCreated)->exists())->toBeTrue();

    $this->get(route('projects.check-ins.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/check-ins/Index')
            ->where('checkIns.0.question', '今週進んだことは？')
            ->where('checkIns.0.frequency', 'weekly')
            ->where('checkIns.0.targetNames', fn (Collection $names): bool => $names->sort()->values()->all() === collect([$owner->name, '回答者'])->sort()->values()->all()));
});

test('check-in targets must participate in the project', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);

    $this->actingAs($owner)
        ->post(route('projects.check-ins.store', [$team, $project]), [
            'question' => '回答してください',
            'frequency' => 'daily',
            'timezone' => 'Asia/Tokyo',
            'next_run_at' => '2026-08-03T09:00',
            'target_user_ids' => [$outsider->id],
        ])
        ->assertSessionHasErrors('target_user_ids.0');

    expect(CheckIn::query()->exists())->toBeFalse();
});

test('dispatcher creates one prompt per target queues notifications and advances in local time', function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-07-15 09:05', 'Asia/Tokyo'));

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $scheduledFor = CarbonImmutable::parse('2026-07-15 09:00', 'Asia/Tokyo')
        ->setTimezone((string) config('app.timezone'));
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'frequency' => CheckInFrequency::Daily,
        'timezone' => 'Asia/Tokyo',
        'next_run_at' => $scheduledFor,
    ]);
    $checkIn->targets()->attach([$owner->id, $member->id]);

    $this->artisan('check-ins:dispatch')
        ->expectsOutputToContain('Created 2 check-in prompts.')
        ->assertSuccessful();
    $this->artisan('check-ins:dispatch')
        ->expectsOutputToContain('Created 0 check-in prompts.')
        ->assertSuccessful();

    expect($checkIn->prompts()->count())->toBe(2)
        ->and($checkIn->fresh()->next_run_at->timezone('Asia/Tokyo')->format('Y-m-d H:i'))
        ->toBe('2026-07-16 09:00');

    Notification::assertSentTo(
        [$owner, $member],
        CheckInDueNotification::class,
        fn (CheckInDueNotification $notification, array $channels): bool => $notification instanceof ShouldQueue
            && $notification->prompt->check_in_id === $checkIn->id
            && in_array('mail', $channels, true),
    );
});

test('dispatcher skips paused and future check-ins', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $paused = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'next_run_at' => now()->subMinute(),
        'paused_at' => now(),
    ]);
    $future = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'next_run_at' => now()->addMinute(),
    ]);
    $paused->targets()->attach($owner);
    $future->targets()->attach($owner);

    $this->artisan('check-ins:dispatch')->assertSuccessful();

    expect(CheckInPrompt::query()->exists())->toBeFalse();
});

test('only the assigned user can answer a prompt and answers appear in project history', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => '回答者']);
    $other = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($other, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id, $other->id]);
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'question' => '困っていることは？',
    ]);
    $prompt = CheckInPrompt::factory()->for($checkIn)->for($member)->create();

    $this->actingAs($other)
        ->post(route('projects.check-ins.answer', [$team, $project, $checkIn, $prompt]), [
            'answer' => '回答を横取り',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('projects.check-ins.answer', [$team, $project, $checkIn, $prompt]), [
            'answer' => 'レビュー待ちです。',
        ])
        ->assertRedirect();

    expect($prompt->fresh())
        ->answer->toBe('レビュー待ちです。')
        ->answered_at->not->toBeNull();
    expect(Activity::query()->where('type', ActivityType::CheckInAnswered)->exists())->toBeTrue();

    $this->get(route('projects.check-ins.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingPrompts', 0)
            ->where('responses.0.question', '困っていることは？')
            ->where('responses.0.answer', 'レビュー待ちです。')
            ->where('responses.0.userName', '回答者'));
});

test('check-in routes scope schedules and prompts to their project', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $firstProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $secondProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $firstCheckIn = CheckIn::factory()->for($firstProject)->for($owner, 'creator')->create();
    $secondCheckIn = CheckIn::factory()->for($secondProject)->for($owner, 'creator')->create();
    $prompt = CheckInPrompt::factory()->for($secondCheckIn)->for($owner)->create();

    $this->actingAs($owner)
        ->patch(route('projects.check-ins.pause', [$team, $firstProject, $secondCheckIn]))
        ->assertNotFound();

    $this->post(route('projects.check-ins.answer', [$team, $firstProject, $firstCheckIn, $prompt]), [
        'answer' => '別Project',
    ])->assertNotFound();
});

test('check-in creators and project managers can pause or delete schedules', function () {
    $owner = User::factory()->create();
    $creator = User::factory()->create();
    $other = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($creator, ['role' => TeamRole::Member->value]);
    $team->members()->attach($other, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$creator->id, $other->id]);
    $checkIn = CheckIn::factory()->for($project)->for($creator, 'creator')->create();

    $this->actingAs($other)
        ->patch(route('projects.check-ins.pause', [$team, $project, $checkIn]))
        ->assertForbidden();

    $this->actingAs($creator)
        ->patch(route('projects.check-ins.pause', [$team, $project, $checkIn]))
        ->assertRedirect();
    expect($checkIn->fresh()->paused_at)->not->toBeNull();

    $this->actingAs($owner)
        ->delete(route('projects.check-ins.destroy', [$team, $project, $checkIn]))
        ->assertRedirect();
    expect($checkIn->fresh())->toBeNull();
});

test('check-in dispatcher is registered with the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('check-ins:dispatch')
        ->assertSuccessful();
});
