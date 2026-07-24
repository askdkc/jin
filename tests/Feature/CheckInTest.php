<?php

use App\Enums\ActivityType;
use App\Enums\CheckInFrequency;
use App\Enums\TeamRole;
use App\Jobs\QueueCheckInDueNotification;
use App\Models\Activity;
use App\Models\CheckIn;
use App\Models\CheckInNotificationDelivery;
use App\Models\CheckInPrompt;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\CheckInDueNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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
            ->where('checkIns.0.canManage', true)
            ->where('checkIns.0.canDelete', true)
            ->where('checkIns.0.targetNames', fn (Collection $names): bool => $names->sort()->values()->all() === collect([$owner->name, '回答者'])->sort()->values()->all()));
});

test('daily check-ins require weekdays and a matching first question date', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $payload = [
        'question' => '今日の進捗は？',
        'frequency' => CheckInFrequency::Daily->value,
        'timezone' => 'Asia/Tokyo',
        'next_run_at' => '2026-08-03T09:00',
        'target_user_ids' => [$owner->id],
    ];

    $this->actingAs($owner)
        ->post(route('projects.check-ins.store', [$team, $project]), $payload)
        ->assertSessionHasErrors('weekdays');

    $this->post(route('projects.check-ins.store', [$team, $project]), [
        ...$payload,
        'next_run_at' => '2026-08-02T09:00',
        'weekdays' => [1, 2, 3, 4, 5],
    ])->assertSessionHasErrors([
        'next_run_at' => '初回の質問日は、選択した曜日のいずれかにしてください。',
    ]);

    $this->post(route('projects.check-ins.store', [$team, $project]), [
        ...$payload,
        'weekdays' => [1, 2, 3, 4, 5],
    ])->assertRedirect();

    expect(CheckIn::query()->firstOrFail()->weekdays)->toBe([1, 2, 3, 4, 5]);
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
        ])
        ->assertSessionHasErrors([
            'target_user_ids' => '回答を依頼する人は必ず指定してください。',
        ]);

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

test('dispatcher creates prompts and advances to the next selected weekday in local time', function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-07-17 09:05', 'Asia/Tokyo'));

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $scheduledFor = CarbonImmutable::parse('2026-07-17 09:00', 'Asia/Tokyo')
        ->setTimezone((string) config('app.timezone'));
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'frequency' => CheckInFrequency::Daily,
        'weekdays' => [1, 2, 3, 4, 5],
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
        ->toBe('2026-07-20 09:00');

    Notification::assertSentTo(
        [$owner, $member],
        CheckInDueNotification::class,
        fn (CheckInDueNotification $notification, array $channels): bool => $notification instanceof ShouldQueue
            && $notification->prompt->check_in_id === $checkIn->id
            && $notification->viaQueues() === [
                'database' => 'notifications',
                'mail' => 'notifications',
            ]
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
    $answeredAt = $prompt->fresh()->answered_at;
    expect(Activity::query()->where('type', ActivityType::CheckInAnswered)->exists())->toBeTrue();

    $this->get(route('projects.check-ins.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingPrompts', 0)
            ->where('responses.0.checkInId', $checkIn->id)
            ->where('responses.0.question', '困っていることは？')
            ->where('responses.0.answer', 'レビュー待ちです。')
            ->where('responses.0.userName', '回答者')
            ->where('responses.0.canEdit', true));

    $this->actingAs($other)
        ->patch(route('projects.check-ins.answers.update', [$team, $project, $checkIn, $prompt]), [
            'answer' => '他人の回答を編集',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->patch(route('projects.check-ins.answers.update', [$team, $project, $checkIn, $prompt]), [
            'answer' => '編集後の回答です。',
        ])
        ->assertRedirect();

    expect($prompt->fresh()->answer)->toBe('編集後の回答です。')
        ->and($prompt->fresh()->answered_at?->equalTo($answeredAt))->toBeTrue();

    $this->post(route('projects.check-ins.comments.store', [$team, $project, $checkIn, $prompt]), [
        'body' => '確認しました。',
    ])->assertRedirect();

    $this->actingAs($other)
        ->post(route('projects.check-ins.reactions.toggle', [$team, $project, $checkIn, $prompt]), [
            'reaction' => '🚀',
        ])
        ->assertRedirect();

    $this->actingAs($member)
        ->post(route('projects.check-ins.reactions.toggle', [$team, $project, $checkIn, $prompt]), [
            'reaction' => '🚀',
        ])
        ->assertRedirect();

    $this->get(route('projects.check-ins.show', [$team, $project, $checkIn]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('responses.0.comments.0.body', '確認しました。')
            ->where('responses.0.comments.0.authorName', '回答者')
            ->where('responses.0.reactions.0.reaction', '🚀')
            ->where('responses.0.reactions.0.count', 2)
            ->where('responses.0.reactions.0.reacted', true));

    $this->post(route('projects.check-ins.reactions.toggle', [$team, $project, $checkIn, $prompt]), [
        'reaction' => '🚀',
    ])->assertRedirect();

    expect($prompt->reactors()->count())->toBe(1);
});

test('project members can open a check-in detail but outsiders cannot', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => '回答者']);
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'question' => '何かに困っている？',
    ]);
    $checkIn->targets()->attach($member);
    $pendingPrompt = CheckInPrompt::factory()->for($checkIn)->for($member)->create();
    CheckInPrompt::factory()->for($checkIn)->for($owner)->create([
        'answer' => '確認待ちです。',
        'answered_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('projects.check-ins.show', [$team, $project, $checkIn]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/check-ins/Show')
            ->where('checkIn.question', '何かに困っている？')
            ->where('checkIn.targetIds', [$member->id])
            ->where('checkIn.canAnswer', true)
            ->where('checkIn.isBookmarked', false)
            ->where('pendingPrompt.id', $pendingPrompt->id)
            ->where('responses.0.answer', '確認待ちです。'));

    $this->actingAs($outsider)
        ->get(route('projects.check-ins.show', [$team, $project, $checkIn]))
        ->assertForbidden();
});

test('assigned users can answer from check-in detail before a prompt is dispatched', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create();
    $checkIn->targets()->attach($member);

    $this->actingAs($member)
        ->get(route('projects.check-ins.show', [$team, $project, $checkIn]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checkIn.canAnswer', true)
            ->where('pendingPrompt', null));

    $this->post(route('projects.check-ins.respond', [$team, $project, $checkIn]), [
        'answer' => '先に回答します。',
    ])->assertRedirect();

    expect($checkIn->prompts()->sole())
        ->user_id->toBe($member->id)
        ->answer->toBe('先に回答します。')
        ->answered_at->not->toBeNull();

    $this->get(route('projects.check-ins.show', [$team, $project, $checkIn]))
        ->assertInertia(fn (Assert $page) => $page->where('checkIn.canAnswer', true));

    $this->post(route('projects.check-ins.respond', [$team, $project, $checkIn]), [
        'answer' => '追加の回答です。',
    ])->assertRedirect();

    expect($checkIn->prompts()->count())->toBe(2)
        ->and($checkIn->prompts()->pluck('answer')->all())
        ->toBe(['先に回答します。', '追加の回答です。']);

    $this->actingAs($owner)
        ->post(route('projects.check-ins.respond', [$team, $project, $checkIn]), [
            'answer' => '対象外回答',
        ])->assertForbidden();
});

test('check-in creators can update the question schedule and project targets', function () {
    $owner = User::factory()->create();
    $creator = User::factory()->create();
    $target = User::factory()->create();
    $other = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($creator, ['role' => TeamRole::Member->value]);
    $team->members()->attach($target, ['role' => TeamRole::Member->value]);
    $team->members()->attach($other, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$creator->id, $target->id, $other->id]);
    $checkIn = CheckIn::factory()->for($project)->for($creator, 'creator')->create();
    $checkIn->targets()->attach($creator);

    $payload = [
        'question' => '来月の課題は？',
        'frequency' => CheckInFrequency::Daily->value,
        'weekdays' => [1, 3, 5],
        'timezone' => 'UTC',
        'next_run_at' => '2026-09-02T08:30',
        'target_user_ids' => [$target->id],
    ];

    $this->actingAs($other)
        ->patch(route('projects.check-ins.update', [$team, $project, $checkIn]), $payload)
        ->assertForbidden();

    $this->actingAs($creator)
        ->patch(route('projects.check-ins.update', [$team, $project, $checkIn]), [
            ...$payload,
            'target_user_ids' => [$outsider->id],
        ])
        ->assertSessionHasErrors('target_user_ids.0');

    $this->patch(route('projects.check-ins.update', [$team, $project, $checkIn]), $payload)
        ->assertRedirect();

    expect($checkIn->fresh())
        ->question->toBe('来月の課題は？')
        ->frequency->toBe(CheckInFrequency::Daily)
        ->weekdays->toBe([1, 3, 5])
        ->timezone->toBe('UTC')
        ->and($checkIn->targets()->pluck('users.id')->all())
        ->toBe([$target->id]);
});

test('project members can bookmark a check-in for themselves', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create();

    $this->actingAs($member)
        ->patch(route('projects.check-ins.bookmark', [$team, $project, $checkIn]))
        ->assertRedirect();

    expect($member->bookmarkedCheckIns()->whereKey($checkIn->id)->exists())->toBeTrue();

    $this->get(route('projects.check-ins.show', [$team, $project, $checkIn]))
        ->assertInertia(fn (Assert $page) => $page->where('checkIn.isBookmarked', true));

    $this->patch(route('projects.check-ins.bookmark', [$team, $project, $checkIn]))
        ->assertRedirect();
    expect($member->bookmarkedCheckIns()->whereKey($checkIn->id)->exists())->toBeFalse();

    $this->actingAs($outsider)
        ->patch(route('projects.check-ins.bookmark', [$team, $project, $checkIn]))
        ->assertForbidden();
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

    $this->get(route('projects.check-ins.show', [$team, $firstProject, $secondCheckIn]))
        ->assertNotFound();

    $this->patch(route('projects.check-ins.bookmark', [$team, $firstProject, $secondCheckIn]))
        ->assertNotFound();

    $this->post(route('projects.check-ins.answer', [$team, $firstProject, $firstCheckIn, $prompt]), [
        'answer' => '別Project',
    ])->assertNotFound();

    $this->patch(route('projects.check-ins.answers.update', [$team, $firstProject, $firstCheckIn, $prompt]), [
        'answer' => '別Projectから編集',
    ])->assertNotFound();

    $this->post(route('projects.check-ins.comments.store', [$team, $firstProject, $firstCheckIn, $prompt]), [
        'body' => '別Projectからコメント',
    ])->assertNotFound();

    $this->post(route('projects.check-ins.reactions.toggle', [$team, $firstProject, $firstCheckIn, $prompt]), [
        'reaction' => '🚀',
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

test('check-in notification delivery remains durable while the queue is asynchronous', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'next_run_at' => now()->subMinute(),
    ]);
    $checkIn->targets()->attach($owner);

    $this->artisan('check-ins:dispatch')->assertSuccessful();
    $this->artisan('check-ins:dispatch')->assertSuccessful();

    expect(CheckInNotificationDelivery::query()->count())->toBe(1)
        ->and(CheckInNotificationDelivery::query()->sole()->check_in_prompt_id)
        ->toBe(CheckInPrompt::query()->sole()->id);
    Queue::assertPushed(QueueCheckInDueNotification::class, 1);
});

test('check-in dispatcher is registered with the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('check-ins:dispatch')
        ->assertSuccessful();
});

test('check-in notification jobs reclaim stale processing without sending twice', function () {
    Notification::fake();
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create();
    $checkIn->targets()->attach($owner);
    $prompt = CheckInPrompt::factory()->for($checkIn)->for($owner)->create();
    $delivery = CheckInNotificationDelivery::query()->create([
        'check_in_prompt_id' => $prompt->id,
        'dispatched_at' => now(),
        'processing_at' => now(),
    ]);
    $job = new QueueCheckInDueNotification($delivery->id);

    $job->handle();
    Notification::assertNothingSent();

    $this->travel(5)->minutes();
    $job->handle();
    $job->handle();

    expect(Notification::sent($owner, CheckInDueNotification::class))->toHaveCount(1)
        ->and($delivery->refresh()->processing_at)->toBeNull()
        ->and($delivery->queued_at)->not->toBeNull()
        ->and($delivery->skipped_at)->toBeNull();
});

test('check-in notification jobs skip users removed from the target list', function () {
    Notification::fake();
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create();
    $prompt = CheckInPrompt::factory()->for($checkIn)->for($owner)->create();
    $delivery = CheckInNotificationDelivery::query()->create([
        'check_in_prompt_id' => $prompt->id,
        'dispatched_at' => now(),
    ]);

    (new QueueCheckInDueNotification($delivery->id))->handle();

    Notification::assertNothingSent();
    expect($delivery->refresh()->queued_at)->toBeNull()
        ->and($delivery->skipped_at)->not->toBeNull()
        ->and($delivery->skip_reason)->toBe('target_removed');
});

test('check-in dispatch failures return a failed command and keep the delivery pending', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'next_run_at' => now()->addDay(),
    ]);
    $checkIn->targets()->attach($owner);
    $prompt = CheckInPrompt::factory()->for($checkIn)->for($owner)->create();
    $delivery = CheckInNotificationDelivery::query()->create([
        'check_in_prompt_id' => $prompt->id,
    ]);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));
    $this->app->instance(Dispatcher::class, $bus);

    $this->artisan('check-ins:dispatch')
        ->expectsOutputToContain('Failed to queue 1 check-in notification deliveries.')
        ->assertFailed();

    expect($delivery->refresh()->dispatched_at)->toBeNull()
        ->and($delivery->last_error)->toBe('queue unavailable');
});
