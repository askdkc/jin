<?php

namespace App\Models;

use App\Support\StoredFilePreviewDispatcher;
use App\Support\StoredFilePreviewGenerator;
use Database\Factories\StoredFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $material_folder_id
 * @property int|null $uploaded_by
 * @property string|null $attachable_type
 * @property int|null $attachable_id
 * @property bool $is_inline
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int $size
 * @property string|null $checksum
 * @property string|null $preview_path
 * @property string|null $preview_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $uploader
 * @property-read Model|null $attachable
 * @property-read MaterialFolder|null $materialFolder
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, User> $reactors
 */
#[Fillable(['project_id', 'material_folder_id', 'uploaded_by', 'attachable_type', 'attachable_id', 'is_inline', 'disk', 'path', 'original_name', 'mime_type', 'size', 'checksum', 'preview_path', 'preview_status'])]
class StoredFile extends Model
{
    /** @use HasFactory<StoredFileFactory> */
    use HasFactory;

    /** @var array<string, bool|string> */
    protected $attributes = [
        'disk' => 'local',
        'is_inline' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (StoredFile $storedFile): void {
            if ($storedFile->checksum === null) {
                $disk = Storage::disk($storedFile->disk);

                if ($disk->exists($storedFile->path)) {
                    $storedFile->checksum = self::checksumFor($disk, $storedFile->path);
                    $storedFile->size = $disk->size($storedFile->path);
                }
            }

            if (StoredFilePreviewGenerator::supports($storedFile->original_name)) {
                $storedFile->preview_path = null;
                $storedFile->preview_status = 'pending';
            } else {
                $storedFile->preview_path = null;
                $storedFile->preview_status = null;
            }
        });

        static::created(function (StoredFile $storedFile): void {
            if ($storedFile->preview_status === 'pending') {
                app(StoredFilePreviewDispatcher::class)
                    ->dispatchAfterCommit($storedFile->id, $storedFile->path);
            }
        });
    }

    public static function checksumFor(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('File checksum could not be calculated.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  Builder<StoredFile>  $query
     * @return Builder<StoredFile>
     */
    public function scopeInMaterialLibrary(Builder $query): Builder
    {
        return $query
            ->where('is_inline', false)
            ->where(function (Builder $query): void {
                $query->where('path', 'not like', '%/attachments/%')
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('attachable_type')
                            ->whereNull('attachable_id');
                    });
            });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<MaterialFolder, $this> */
    public function materialFolder(): BelongsTo
    {
        return $this->belongsTo(MaterialFolder::class);
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return BelongsToMany<User, $this> */
    public function reactors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'stored_file_reactions')
            ->withPivot('reaction')
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_inline' => 'boolean',
        ];
    }
}
