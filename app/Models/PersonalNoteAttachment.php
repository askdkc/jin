<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $team_id
 * @property int $uploaded_by
 * @property int|null $personal_note_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PersonalNote|null $personalNote
 * @property-read Team $team
 * @property-read User $uploader
 */
#[Fillable(['team_id', 'uploaded_by', 'personal_note_id', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class PersonalNoteAttachment extends Model
{
    use Prunable;

    protected static function booted(): void
    {
        static::deleting(function (PersonalNoteAttachment $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    /** @return BelongsTo<PersonalNote, $this> */
    public function personalNote(): BelongsTo
    {
        return $this->belongsTo(PersonalNote::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNull('personal_note_id')
            ->where('created_at', '<', now()->subHours(24));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'personal_note_id' => 'integer',
            'size' => 'integer',
        ];
    }
}
