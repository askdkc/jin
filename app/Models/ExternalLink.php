<?php

namespace App\Models;

use Database\Factories\ExternalLinkFactory;
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
 * @property string $url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read MaterialFolder|null $materialFolder
 * @property-read User|null $creator
 */
#[Fillable(['project_id', 'material_folder_id', 'created_by', 'title', 'url'])]
class ExternalLink extends Model
{
    /** @use HasFactory<ExternalLinkFactory> */
    use HasFactory;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<MaterialFolder, $this> */
    public function materialFolder(): BelongsTo
    {
        return $this->belongsTo(MaterialFolder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
