<?php

use App\Enums\TeamRole;
use App\Models\PersonalNote;
use App\Models\PersonalNoteAttachment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('team owners and members can upload private personal-note attachments', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    foreach ([$owner, $member] as $uploader) {
        $name = "{$uploader->id}-note.pdf";

        $response = $this->actingAs($uploader)
            ->postJson(route('personal-note-attachments.store', [$team]), [
                'file' => UploadedFile::fake()->create($name, 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonStructure(['url', 'name', 'mimeType', 'inline'])
            ->assertJsonPath('inline', false);

        $attachment = DB::table('personal_note_attachments')
            ->where('team_id', $team->id)
            ->where('uploaded_by', $uploader->id)
            ->where('original_name', $name)
            ->first();

        expect($attachment)
            ->not->toBeNull()
            ->team_id->toBe($team->id)
            ->uploaded_by->toBe($uploader->id)
            ->disk->toBe('local')
            ->mime_type->toBe('application/pdf')
            ->size->toBe(100 * 1024)
            ->path->not->toContain($name);

        Storage::disk('local')->assertExists($attachment->path);
        expect($response->json('name'))->toBe($name);
    }
});

test('an uploader can preview the exact personal-note attachment response', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $payload = $this->actingAs($owner)
        ->postJson(route('personal-note-attachments.store', [$team]), [
            'file' => UploadedFile::fake()->createWithContent(
                'private-note.txt',
                'private note attachment',
            ),
        ])
        ->assertCreated()
        ->json();

    $this->actingAs($owner)
        ->get($payload['url'])
        ->assertOk()
        ->assertStreamedContent('private note attachment');
});

test('only safe raster personal-note attachments are served inline', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $inlineMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    foreach ([
        ['photo.jpg', 'image/jpeg'],
        ['photo.png', 'image/png'],
        ['photo.gif', 'image/gif'],
        ['photo.webp', 'image/webp'],
        ['photo.avif', 'image/avif'],
        ['document.html', 'text/html'],
        ['vector.svg', 'image/svg+xml'],
    ] as [$name, $mimeType]) {
        $payload = $this->actingAs($owner)
            ->postJson(route('personal-note-attachments.store', [$team]), [
                'file' => UploadedFile::fake()->create($name, 1, $mimeType),
            ])
            ->assertCreated()
            ->json();

        $expectedInline = in_array($mimeType, $inlineMimeTypes, true);

        expect($payload['inline'])->toBe($expectedInline);

        $response = $this->actingAs($owner)
            ->get($payload['url'])
            ->assertOk();

        $expectedDisposition = $expectedInline
            ? 'inline;'
            : 'attachment;';

        expect($response->headers->get('Content-Disposition'))
            ->toStartWith($expectedDisposition)
            ->and($response->headers->get('Cache-Control'))
            ->toContain('private')
            ->toContain('no-store')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }
});

test('only the uploader can preview a personal-note attachment', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $payload = $this->actingAs($owner)
        ->postJson(route('personal-note-attachments.store', [$team]), [
            'file' => UploadedFile::fake()->create('owner-note.txt', 1, 'text/plain'),
        ])
        ->assertCreated()
        ->json();

    $this->actingAs($member)
        ->get($payload['url'])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->get($payload['url'])
        ->assertForbidden();
});

test('nonmembers cannot upload personal-note attachments', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($outsider)
        ->postJson(route('personal-note-attachments.store', [$team]), [
            'file' => UploadedFile::fake()->create('not-allowed.pdf', 1, 'application/pdf'),
        ])
        ->assertForbidden();
});

test('saving a note claims the uploader\'s referenced attachments', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $payload = $this->actingAs($owner)
        ->postJson(route('personal-note-attachments.store', [$team]), [
            'file' => UploadedFile::fake()->create('note.png', 1, 'image/png'),
        ])
        ->assertCreated()
        ->json();

    $this->actingAs($owner)
        ->post(route('notes.store', [$team]), [
            'title' => 'With an attachment',
            'body' => '<p><img src="'.$payload['previewUrl'].'"></p>',
        ])
        ->assertRedirect();

    $note = PersonalNote::query()->sole();
    $attachment = PersonalNoteAttachment::query()->sole();

    expect($attachment->personal_note_id)->toBe($note->id);
});

test('updating a note removes detached files and claims new references', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $upload = fn (string $name): array => $this->actingAs($owner)
        ->postJson(route('personal-note-attachments.store', [$team]), [
            'file' => UploadedFile::fake()->create($name, 1, 'image/png'),
        ])
        ->assertCreated()
        ->json();

    $removedPayload = $upload('removed.png');
    $retainedPayload = $upload('retained.png');
    $removed = PersonalNoteAttachment::query()
        ->where('original_name', 'removed.png')
        ->sole();
    $retained = PersonalNoteAttachment::query()
        ->where('original_name', 'retained.png')
        ->sole();

    $this->actingAs($owner)
        ->post(route('notes.store', [$team]), [
            'title' => 'Before update',
            'body' => '<p><img src="'.$removedPayload['previewUrl'].'"><img src="'.$retainedPayload['previewUrl'].'"></p>',
        ])
        ->assertRedirect();

    $note = PersonalNote::query()->sole();
    $newPayload = $upload('new.png');
    $new = PersonalNoteAttachment::query()
        ->where('original_name', 'new.png')
        ->sole();

    $this->actingAs($owner)
        ->patch(route('notes.update', [$team, $note]), [
            'title' => 'After update',
            'body' => '<p><img src="'.$retainedPayload['previewUrl'].'"><img src="'.$newPayload['previewUrl'].'"></p>',
        ])
        ->assertRedirect();

    expect(PersonalNoteAttachment::find($removed->id))->toBeNull()
        ->and($retained->fresh()->personal_note_id)->toBe($note->id)
        ->and($new->fresh()->personal_note_id)->toBe($note->id);
    Storage::disk('local')->assertMissing($removed->path);
    Storage::disk('local')->assertExists($retained->path);
    Storage::disk('local')->assertExists($new->path);
});

test('deleting a note removes its attachment rows and files', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $payload = $this->actingAs($owner)
        ->postJson(route('personal-note-attachments.store', [$team]), [
            'file' => UploadedFile::fake()->create('delete-me.png', 1, 'image/png'),
        ])
        ->assertCreated()
        ->json();

    $this->actingAs($owner)
        ->post(route('notes.store', [$team]), [
            'title' => 'Delete me',
            'body' => '<p><img src="'.$payload['previewUrl'].'"></p>',
        ])
        ->assertRedirect();

    $note = PersonalNote::query()->sole();
    $attachment = PersonalNoteAttachment::query()->sole();

    $this->actingAs($owner)
        ->delete(route('notes.destroy', [$team, $note]))
        ->assertRedirect();

    expect(PersonalNote::find($note->id))->toBeNull()
        ->and(PersonalNoteAttachment::find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($attachment->path);
});

test('a claimed attachment cannot be stolen by another note, uploader, or team', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $team = $owner->currentTeam;
    $team->members()->attach($otherUser, ['role' => TeamRole::Member->value]);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $ownPayload = $this->actingAs($owner)
        ->postJson(route('personal-note-attachments.store', [$team]), [
            'file' => UploadedFile::fake()->create('owner.png', 1, 'image/png'),
        ])
        ->assertCreated()
        ->json();
    $otherUserPayload = $this->actingAs($otherUser)
        ->postJson(route('personal-note-attachments.store', [$team]), [
            'file' => UploadedFile::fake()->create('member.png', 1, 'image/png'),
        ])
        ->assertCreated()
        ->json();
    $otherTeamPayload = $this->actingAs($owner)
        ->postJson(route('personal-note-attachments.store', [$otherTeam]), [
            'file' => UploadedFile::fake()->create('other-team.png', 1, 'image/png'),
        ])
        ->assertCreated()
        ->json();

    $this->actingAs($owner)
        ->post(route('notes.store', [$team]), [
            'title' => 'Original note',
            'body' => '<p><img src="'.$ownPayload['previewUrl'].'"></p>',
        ])
        ->assertRedirect();
    $originalNote = PersonalNote::query()->sole();

    $this->actingAs($owner)
        ->post(route('notes.store', [$team]), [
            'title' => 'Second note',
            'body' => '<p><img src="'.$ownPayload['previewUrl'].'"></p>',
        ])
        ->assertRedirect();
    $secondNote = PersonalNote::query()->latest('id')->firstOrFail();

    $this->actingAs($owner)
        ->patch(route('notes.update', [$team, $secondNote]), [
            'title' => 'Second note',
            'body' => '<p><img src="'.$ownPayload['previewUrl'].'"><img src="'.$otherUserPayload['previewUrl'].'"><img src="'.$otherTeamPayload['previewUrl'].'"></p>',
        ])
        ->assertRedirect();

    $ownAttachment = PersonalNoteAttachment::query()
        ->where('original_name', 'owner.png')
        ->sole();
    $otherUserAttachment = PersonalNoteAttachment::query()
        ->where('original_name', 'member.png')
        ->sole();
    $otherTeamAttachment = PersonalNoteAttachment::query()
        ->where('original_name', 'other-team.png')
        ->sole();

    expect($ownAttachment->fresh()->personal_note_id)->toBe($originalNote->id)
        ->and($otherUserAttachment->fresh()->personal_note_id)->toBeNull()
        ->and($otherTeamAttachment->fresh()->personal_note_id)->toBeNull();
});

test('attachment prune removes only old unclaimed personal-note attachments', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $note = PersonalNote::factory()->for($team)->for($owner, 'user')->create();
    $makeAttachment = function (string $path, array $attributes = []) use ($owner, $team): PersonalNoteAttachment {
        Storage::disk('local')->put($path, 'private attachment');

        $attachment = PersonalNoteAttachment::create([
            'team_id' => $team->id,
            'uploaded_by' => $owner->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => basename($path),
            'mime_type' => 'image/png',
            'size' => 19,
            ...$attributes,
        ]);

        if ($attributes !== []) {
            $attachment->forceFill($attributes)->save();
        }

        return $attachment;
    };
    $old = $makeAttachment('personal-notes/old.png', [
        'created_at' => now()->subHours(25),
        'updated_at' => now()->subHours(25),
    ]);
    $recent = $makeAttachment('personal-notes/recent.png');
    $claimed = $makeAttachment('personal-notes/claimed.png', [
        'personal_note_id' => $note->id,
        'created_at' => now()->subHours(25),
        'updated_at' => now()->subHours(25),
    ]);

    $this->artisan('personal-note-attachments:prune')->assertSuccessful();

    expect(PersonalNoteAttachment::find($old->id))->toBeNull()
        ->and(PersonalNoteAttachment::find($recent->id))->not->toBeNull()
        ->and(PersonalNoteAttachment::find($claimed->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($recent->path);
    Storage::disk('local')->assertExists($claimed->path);
});

test('deleting a user and force deleting a team removes attachment files, but soft delete preserves them', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $userTeam = Team::factory()->create();
    $userTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);
    Storage::disk('local')->put('personal-notes/user.png', 'user attachment');
    $userAttachment = PersonalNoteAttachment::create([
        'team_id' => $userTeam->id,
        'uploaded_by' => $user->id,
        'disk' => 'local',
        'path' => 'personal-notes/user.png',
        'original_name' => 'user.png',
        'mime_type' => 'image/png',
        'size' => 15,
    ]);

    $user->delete();

    expect(PersonalNoteAttachment::find($userAttachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($userAttachment->path);

    $teamOwner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($teamOwner, ['role' => TeamRole::Owner->value]);
    Storage::disk('local')->put('personal-notes/team.png', 'team attachment');
    $teamAttachment = PersonalNoteAttachment::create([
        'team_id' => $team->id,
        'uploaded_by' => $teamOwner->id,
        'disk' => 'local',
        'path' => 'personal-notes/team.png',
        'original_name' => 'team.png',
        'mime_type' => 'image/png',
        'size' => 16,
    ]);

    $team->delete();

    expect(PersonalNoteAttachment::find($teamAttachment->id))->not->toBeNull();
    Storage::disk('local')->assertExists($teamAttachment->path);

    $team->forceDelete();

    expect(PersonalNoteAttachment::find($teamAttachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($teamAttachment->path);
});

test('every Svelte rich-text editor supplies a personal or project attachment upload URL', function () {
    $editors = [];
    $nonSelfClosingEditors = [];
    $missingUploadUrls = [];
    $svelteFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js')),
    );

    foreach ($svelteFiles as $page) {
        if (! $page->isFile() || $page->getExtension() !== 'svelte') {
            continue;
        }

        $source = file_get_contents($page->getPathname());
        preg_match_all('/<RichTextEditor\b/', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$component, $offset]) {
            $tagEnd = strpos($source, '>', $offset);
            $component = $tagEnd === false
                ? substr($source, $offset)
                : substr($source, $offset, $tagEnd - $offset + 1);
            $relativePath = str_replace(base_path().'/', '', $page->getPathname());
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $location = "{$relativePath}:{$line}";

            $editors[] = [$page->getPathname(), $component, $offset];

            if ($tagEnd === false || ! str_ends_with(rtrim($component), '/>')) {
                $nonSelfClosingEditors[] = $location;
            }

            if (! str_contains($component, 'attachmentUploadUrl')) {
                $missingUploadUrls[] = $location;
            }
        }
    }

    expect($editors)->not->toBeEmpty()
        ->and($nonSelfClosingEditors)->toBeEmpty()
        ->and($missingUploadUrls)->toBeEmpty();
});
