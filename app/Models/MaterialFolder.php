<?php

namespace App\Models;

use Database\Factories\MaterialFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $created_by
 * @property string $name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $creator
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, StoredFile> $storedFiles
 * @property-read Collection<int, ExternalLink> $externalLinks
 */
#[Fillable(['project_id', 'created_by', 'name', 'position'])]
class MaterialFolder extends Model
{
    /** @use HasFactory<MaterialFolderFactory> */
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

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<StoredFile, $this> */
    public function storedFiles(): HasMany
    {
        return $this->hasMany(StoredFile::class);
    }

    /** @return HasMany<ExternalLink, $this> */
    public function externalLinks(): HasMany
    {
        return $this->hasMany(ExternalLink::class);
    }
}
