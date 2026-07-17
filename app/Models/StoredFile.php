<?php

namespace App\Models;

use Database\Factories\StoredFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $material_folder_id
 * @property int|null $uploaded_by
 * @property string|null $attachable_type
 * @property int|null $attachable_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $uploader
 * @property-read Model|null $attachable
 * @property-read MaterialFolder|null $materialFolder
 */
#[Fillable(['project_id', 'material_folder_id', 'uploaded_by', 'attachable_type', 'attachable_id', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class StoredFile extends Model
{
    /** @use HasFactory<StoredFileFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $attributes = ['disk' => 'local'];

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
}
