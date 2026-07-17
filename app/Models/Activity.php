<?php

namespace App\Models;

use App\Enums\ActivityType;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $actor_id
 * @property ActivityType $type
 * @property string $subject_type
 * @property int $subject_id
 * @property array{label: string} $metadata
 * @property Carbon|null $created_at
 * @property-read Project $project
 * @property-read User|null $actor
 */
#[Fillable(['project_id', 'actor_id', 'type', 'subject_type', 'subject_id', 'metadata'])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public static function record(
        Project $project,
        User $actor,
        ActivityType $type,
        Model $subject,
        string $label,
    ): self {
        return $project->activities()->create([
            'actor_id' => $actor->id,
            'type' => $type,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (int) $subject->getKey(),
            'metadata' => ['label' => Str::limit($label, 160)],
        ]);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
