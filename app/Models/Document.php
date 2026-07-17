<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $material_folder_id
 * @property int|null $created_by
 * @property string $title
 * @property string $body
 * @property string|null $public_token
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $creator
 * @property-read MaterialFolder|null $materialFolder
 */
#[Fillable(['project_id', 'material_folder_id', 'created_by', 'title', 'body', 'public_token', 'published_at'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<MaterialFolder, $this> */
    public function materialFolder(): BelongsTo
    {
        return $this->belongsTo(MaterialFolder::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'body' => SanitizedRichText::class,
            'published_at' => 'datetime',
        ];
    }
}
